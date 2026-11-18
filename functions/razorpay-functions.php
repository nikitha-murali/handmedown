<?php
require_once __DIR__ . '/sell-functions.php';

if (!function_exists('hmd_razorpay_credentials')) {
    function hmd_razorpay_credentials()
    {
        $keyId = getenv('HMD_RAZORPAY_KEY_ID');
        $secret = getenv('HMD_RAZORPAY_KEY_SECRET');
        if (!is_string($keyId) || !is_string($secret)
            || strpos($keyId, 'rzp_test_') !== 0 || trim($secret) === '') {
            throw new RuntimeException('Razorpay test mode is not configured.');
        }
        return [$keyId, $secret];
    }
}

if (!function_exists('hmd_razorpay_request')) {
    function hmd_razorpay_request($method, $path, $payload = null)
    {
        list($keyId, $secret) = hmd_razorpay_credentials();
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Razorpay checkout requires the PHP cURL extension.');
        }
        $handle = curl_init('https://api.razorpay.com/v1/' . ltrim($path, '/'));
        if (!$handle) {
            throw new RuntimeException('Razorpay checkout could not be started.');
        }
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_USERPWD => $keyId . ':' . $secret,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 20,
        ];
        if ($payload !== null) {
            $json = json_encode($payload);
            if ($json === false) {
                curl_close($handle);
                throw new RuntimeException('Razorpay checkout could not be prepared.');
            }
            $options[CURLOPT_POSTFIELDS] = $json;
        }
        curl_setopt_array($handle, $options);
        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);
        if (!is_string($body) || $status < 200 || $status >= 300) {
            error_log('Razorpay API request failed (HTTP ' . $status . '): ' . substr((string) $curlError, 0, 300));
            throw new RuntimeException('Razorpay could not complete the test checkout. Please try again.');
        }
        $response = json_decode($body, true);
        if (!is_array($response)) {
            throw new RuntimeException('Razorpay returned an invalid response.');
        }
        return $response;
    }
}

if (!function_exists('hmd_razorpay_paise')) {
    function hmd_razorpay_paise($price)
    {
        $paise = round((float) $price * 100);
        if (!is_finite($paise) || $paise < 1 || $paise > 4294967295) {
            throw new RuntimeException('The checkout total is outside the supported payment range.');
        }
        return (int) $paise;
    }
}

if (!function_exists('hmd_release_expired_razorpay_holds')) {
    function hmd_release_expired_razorpay_holds($connection)
    {
        $statement = hmd_prepare($connection,
            "UPDATE hmd_payment_attempts SET status = 'expired'
             WHERE status IN ('creating', 'created') AND expires_at <= NOW()");
        hmd_execute($statement);
        $statement->close();
    }
}

if (!function_exists('hmd_create_razorpay_cart_order')) {
    function hmd_create_razorpay_cart_order($connection, array $listingIds, $buyerId)
    {
        list($keyId) = hmd_razorpay_credentials();
        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds), static function ($id) { return $id > 0; })));
        if (!$ids) {
            throw new RuntimeException('Your cart is empty.');
        }
        sort($ids, SORT_NUMERIC);
        $connection = hmd_require_database($connection);
        $connection->begin_transaction();
        $attemptId = 0;
        $totalPaise = 0;
        try {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $statement = hmd_prepare($connection,
                "SELECT id, seller_id, asking_price, status FROM hmd_listings
                 WHERE id IN ({$placeholders}) ORDER BY id FOR UPDATE");
            $values = $ids;
            hmd_bind_values($statement, str_repeat('i', count($ids)), $values);
            hmd_execute($statement);
            $result = $statement->get_result();
            $listings = [];
            while ($result && ($row = $result->fetch_assoc())) {
                $listings[(int) $row['id']] = $row;
            }
            $statement->close();
            if (count($listings) !== count($ids)) {
                throw new RuntimeException('One or more items are no longer available.');
            }
            $reserved = hmd_reserved_listing_ids($connection, $ids);
            foreach ($ids as $id) {
                $listing = $listings[$id];
                if ($listing['status'] !== 'active') {
                    throw new RuntimeException('One or more items are no longer available.');
                }
                if ((int) $listing['seller_id'] === (int) $buyerId) {
                    throw new RuntimeException('You cannot buy your own listing.');
                }
                if (in_array($id, $reserved, true)) {
                    throw new RuntimeException('An item in your cart is reserved during another checkout. Please try again shortly.');
                }
                $totalPaise += hmd_razorpay_paise($listing['asking_price']);
                if ($totalPaise > 4294967295) {
                    throw new RuntimeException('The checkout total is outside the supported payment range.');
                }
            }
            $primaryId = $ids[0];
            $buyer = (int) $buyerId;
            $expiry = date('Y-m-d H:i:s', time() + 15 * 60);
            $create = hmd_prepare($connection,
                "INSERT INTO hmd_payment_attempts
                 (listing_id, buyer_id, amount_paise, status, expires_at)
                 VALUES (?, ?, ?, 'creating', ?)");
            $create->bind_param('iiis', $primaryId, $buyer, $totalPaise, $expiry);
            hmd_execute($create);
            $attemptId = (int) $create->insert_id;
            $create->close();
            foreach ($ids as $id) {
                $amount = hmd_razorpay_paise($listings[$id]['asking_price']);
                $item = hmd_prepare($connection,
                    'INSERT INTO hmd_payment_attempt_items (attempt_id, listing_id, amount_paise) VALUES (?, ?, ?)');
                $item->bind_param('iii', $attemptId, $id, $amount);
                hmd_execute($item);
                $item->close();
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }

        try {
            $gateway = hmd_razorpay_request('POST', 'orders', [
                'amount' => $totalPaise,
                'currency' => 'INR',
                'receipt' => 'hmd-' . $attemptId,
                'notes' => ['hmd_attempt_id' => (string) $attemptId],
            ]);
            $gatewayId = (string) ($gateway['id'] ?? '');
            if ($gatewayId === '' || strlen($gatewayId) > 64 || (int) ($gateway['amount'] ?? 0) !== $totalPaise) {
                throw new RuntimeException('Razorpay returned an invalid test order.');
            }
            $update = hmd_prepare($connection,
                "UPDATE hmd_payment_attempts SET gateway_order_id = ?, status = 'created'
                 WHERE id = ? AND status = 'creating' AND expires_at > NOW()");
            $update->bind_param('si', $gatewayId, $attemptId);
            hmd_execute($update);
            $updated = $update->affected_rows === 1;
            $update->close();
            if (!$updated) {
                throw new RuntimeException('The checkout reservation expired. Please start checkout again.');
            }
            return [
                'key_id' => $keyId,
                'amount' => $totalPaise,
                'currency' => 'INR',
                'name' => 'HandMeDown',
                'description' => count($ids) === 1 ? 'Campus marketplace item' : 'Campus marketplace cart',
                'order_id' => $gatewayId,
            ];
        } catch (Throwable $exception) {
            if ($attemptId > 0) {
                try {
                    $fail = hmd_prepare($connection,
                        "UPDATE hmd_payment_attempts SET status = 'failed'
                         WHERE id = ? AND status = 'creating'");
                    $fail->bind_param('i', $attemptId);
                    hmd_execute($fail);
                    $fail->close();
                } catch (Throwable $ignored) {
                    error_log('Unable to release failed Razorpay reservation ' . $attemptId . '.');
                }
            }
            throw $exception;
        }
    }
}

if (!function_exists('hmd_finalize_razorpay_payment')) {
    function hmd_finalize_razorpay_payment($connection, $orderId, $paymentId, $buyerId)
    {
        if (!is_string($orderId) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $orderId)
            || !is_string($paymentId) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $paymentId)) {
            throw new RuntimeException('Razorpay returned invalid payment details.');
        }
        $payment = hmd_razorpay_request('GET', 'payments/' . rawurlencode($paymentId));
        if (($payment['status'] ?? '') !== 'captured'
            || (string) ($payment['order_id'] ?? '') !== $orderId
            || strtoupper((string) ($payment['currency'] ?? '')) !== 'INR') {
            throw new RuntimeException('The Razorpay test payment has not been captured.');
        }

        $connection = hmd_require_database($connection);
        $connection->begin_transaction();
        try {
            $attemptStatement = hmd_prepare($connection,
                'SELECT * FROM hmd_payment_attempts WHERE gateway_order_id = ? AND buyer_id = ? FOR UPDATE');
            $buyer = (int) $buyerId;
            $attemptStatement->bind_param('si', $orderId, $buyer);
            hmd_execute($attemptStatement);
            $attempt = $attemptStatement->get_result()->fetch_assoc();
            $attemptStatement->close();
            if (!$attempt) {
                throw new RuntimeException('This payment attempt was not found for your account.');
            }
            if ($attempt['status'] === 'paid') {
                if ((string) $attempt['gateway_payment_id'] !== $paymentId) {
                    throw new RuntimeException('This payment attempt has already been completed.');
                }
                $connection->commit();
                return true;
            }
            if ($attempt['status'] !== 'created' || strtotime($attempt['expires_at']) <= time()) {
                throw new RuntimeException('This checkout reservation expired. Contact support if your payment was captured.');
            }
            if ((int) ($payment['amount'] ?? 0) !== (int) $attempt['amount_paise']) {
                throw new RuntimeException('The captured payment amount does not match your order.');
            }
            $itemsStatement = hmd_prepare($connection,
                'SELECT i.listing_id, i.amount_paise, l.seller_id, l.asking_price, l.status
                 FROM hmd_payment_attempt_items i
                 JOIN hmd_listings l ON l.id = i.listing_id
                 WHERE i.attempt_id = ? ORDER BY i.listing_id FOR UPDATE');
            $attemptId = (int) $attempt['id'];
            $itemsStatement->bind_param('i', $attemptId);
            hmd_execute($itemsStatement);
            $itemsResult = $itemsStatement->get_result();
            $items = $itemsResult ? $itemsResult->fetch_all(MYSQLI_ASSOC) : [];
            $itemsStatement->close();
            if (!$items) {
                throw new RuntimeException('The checkout has no reserved items.');
            }
            $itemIds = array_map(static function ($item) { return (int) $item['listing_id']; }, $items);
            $otherReservations = hmd_reserved_listing_ids($connection, $itemIds, $attemptId);
            $total = 0;
            foreach ($items as $item) {
                if ($item['status'] !== 'active' || (int) $item['seller_id'] === $buyer
                    || in_array((int) $item['listing_id'], $otherReservations, true)
                    || hmd_razorpay_paise($item['asking_price']) !== (int) $item['amount_paise']) {
                    throw new RuntimeException('A reserved item is no longer available at the checkout price.');
                }
                $total += (int) $item['amount_paise'];
            }
            if ($total !== (int) $attempt['amount_paise']) {
                throw new RuntimeException('The checkout total does not match the reserved items.');
            }
            $updateAttempt = hmd_prepare($connection,
                "UPDATE hmd_payment_attempts SET status = 'paid', gateway_payment_id = ?
                 WHERE id = ? AND status = 'created'");
            $updateAttempt->bind_param('si', $paymentId, $attemptId);
            hmd_execute($updateAttempt);
            if ($updateAttempt->affected_rows !== 1) {
                $updateAttempt->close();
                throw new RuntimeException('The payment attempt could not be confirmed.');
            }
            $updateAttempt->close();
            foreach ($items as $item) {
                $listingId = (int) $item['listing_id'];
                $price = (float) $item['amount_paise'] / 100;
                $insert = hmd_prepare($connection,
                    "INSERT INTO hmd_orders (listing_id, buyer_id, price, source, payment_method, payment_status)
                     VALUES (?, ?, ?, 'buy', 'razorpay', 'paid')");
                $insert->bind_param('iid', $listingId, $buyer, $price);
                hmd_execute($insert);
                $insert->close();
                $sold = hmd_prepare($connection, "UPDATE hmd_listings SET status = 'sold' WHERE id = ? AND status = 'active'");
                $sold->bind_param('i', $listingId);
                hmd_execute($sold);
                if ($sold->affected_rows !== 1) {
                    $sold->close();
                    throw new RuntimeException('A reserved item could not be marked as sold.');
                }
                $sold->close();
                $reject = hmd_prepare($connection,
                    "UPDATE hmd_bids SET status = 'rejected' WHERE listing_id = ? AND status = 'pending'");
                $reject->bind_param('i', $listingId);
                hmd_execute($reject);
                $reject->close();
            }
            $connection->commit();
            return true;
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('hmd_cancel_razorpay_attempt')) {
    function hmd_cancel_razorpay_attempt($connection, $orderId, $buyerId)
    {
        if (!is_string($orderId) || !preg_match('/^[A-Za-z0-9_]{1,64}$/', $orderId)) {
            throw new RuntimeException('Invalid Razorpay order.');
        }
        $buyer = (int) $buyerId;
        $lookup = hmd_prepare($connection,
            'SELECT id, status FROM hmd_payment_attempts WHERE gateway_order_id = ? AND buyer_id = ? LIMIT 1');
        $lookup->bind_param('si', $orderId, $buyer);
        hmd_execute($lookup);
        $attempt = $lookup->get_result()->fetch_assoc();
        $lookup->close();
        if (!$attempt) {
            throw new RuntimeException('This payment attempt was not found for your account.');
        }
        if ($attempt['status'] === 'paid') {
            return 'paid';
        }
        try {
            $response = hmd_razorpay_request('GET', 'orders/' . rawurlencode($orderId) . '/payments');
        } catch (Throwable $exception) {
            return 'pending';
        }
        foreach (($response['items'] ?? []) as $payment) {
            if (($payment['status'] ?? '') === 'captured'
                && (string) ($payment['order_id'] ?? '') === $orderId) {
                hmd_finalize_razorpay_payment($connection, $orderId, (string) ($payment['id'] ?? ''), $buyer);
                return 'paid';
            }
            if (($payment['status'] ?? '') === 'authorized') {
                return 'pending';
            }
        }
        $cancel = hmd_prepare($connection,
            "UPDATE hmd_payment_attempts SET status = 'failed'
             WHERE id = ? AND buyer_id = ? AND status IN ('creating', 'created')");
        $id = (int) $attempt['id'];
        $cancel->bind_param('ii', $id, $buyer);
        hmd_execute($cancel);
        $cancel->close();
        return 'cancelled';
    }
}
