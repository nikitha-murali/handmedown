<?php
require_once __DIR__ . '/browse-functions.php';

if (!defined('HMD_COLLEGE_EMAIL_DOMAINS')) {
    $domains = getenv('HMD_COLLEGE_EMAIL_DOMAINS');
    $domains = $domains === false || trim($domains) === ''
        ? ['presidencyuniversity.in']
        : preg_split('/[\s,;]+/', strtolower(trim($domains)), -1, PREG_SPLIT_NO_EMPTY);
    define('HMD_COLLEGE_EMAIL_DOMAINS', array_values(array_unique(array_map('strtolower', $domains))));
}

if (!function_exists('hmd_current_user_id')) {
    function hmd_current_user_id()
    {
        return isset($_SESSION['id']) && filter_var($_SESSION['id'], FILTER_VALIDATE_INT) && (int) $_SESSION['id'] > 0
            ? (int) $_SESSION['id']
            : 0;
    }
}

if (!function_exists('hmd_require_login')) {
    function hmd_require_login()
    {
        if (hmd_current_user_id() < 1) {
            hmd_redirect('login.php', 'Please sign in to continue.');
        }
    }
}

if (!function_exists('hmd_redirect')) {
    function hmd_redirect($path, $message = '', $kind = 'error')
    {
        $separator = strpos($path, '?') === false ? '?' : '&';
        if ($message !== '') {
            $key = $kind === 'success' ? 'success' : 'error';
            $path .= $separator . $key . '=' . rawurlencode((string) $message);
        }
        header('Location: ' . $path);
        exit();
    }
}

if (!function_exists('hmd_verify_csrf_token')) {
    function hmd_verify_csrf_token($token)
    {
        return is_string($token) && isset($_SESSION['hmd_csrf_token'])
            && is_string($_SESSION['hmd_csrf_token'])
            && hash_equals($_SESSION['hmd_csrf_token'], $token);
    }
}

if (!function_exists('hmd_require_database')) {
    function hmd_require_database($connection)
    {
        if (!($connection instanceof mysqli) || $connection->connect_errno) {
            throw new RuntimeException('The marketplace database is unavailable. Check the database configuration.');
        }
        return $connection;
    }
}

if (!function_exists('hmd_prepare')) {
    function hmd_prepare($connection, $sql)
    {
        hmd_require_database($connection);
        $statement = $connection->prepare($sql);
        if (!$statement) {
            error_log('HandMeDown query preparation failed: ' . $connection->error);
            throw new RuntimeException('The marketplace database is not ready. Apply the HandMeDown schema and try again.');
        }
        return $statement;
    }
}

if (!function_exists('hmd_execute')) {
    function hmd_execute($statement)
    {
        if (!$statement->execute()) {
            $message = $statement->error;
            $statement->close();
            error_log('HandMeDown query execution failed: ' . $message);
            throw new RuntimeException('The marketplace request could not be completed. Please try again.');
        }
    }
}

if (!function_exists('hmd_bind_values')) {
    function hmd_bind_values($statement, $types, array &$values)
    {
        if ($values) {
            $parameters = [$types];
            foreach ($values as $index => &$value) {
                $parameters[] = &$value;
            }
            if (!call_user_func_array([$statement, 'bind_param'], $parameters)) {
                $statement->close();
                throw new RuntimeException('The marketplace request could not be prepared.');
            }
        }
    }
}

if (!function_exists('hmd_get_listing')) {
    function hmd_get_listing($connection, $listingId)
    {
        if ((int) $listingId < 1) {
            return null;
        }
        $statement = hmd_prepare($connection,
            "SELECT l.*, c.customer_fname AS seller_name
             FROM hmd_listings l LEFT JOIN customer c ON c.customer_id = l.seller_id
             WHERE l.id = ? AND l.status IN ('active', 'sold') LIMIT 1");
        $id = (int) $listingId;
        $statement->bind_param('i', $id);
        hmd_execute($statement);
        $result = $statement->get_result();
        $listing = $result ? $result->fetch_assoc() : null;
        $statement->close();
        return $listing ?: null;
    }
}

if (!function_exists('hmd_get_recommendations')) {
    function hmd_get_recommendations($connection, $listing)
    {
        if (!is_array($listing) || empty($listing['id'])) {
            return [];
        }
        try {
            $statement = hmd_prepare($connection,
                "SELECT l.* FROM hmd_recommendations r
                 JOIN hmd_listings l ON l.id = r.similar_id
                 WHERE r.listing_id = ? AND l.status = 'active'
                 ORDER BY r.score DESC, l.id DESC LIMIT 4");
            $id = (int) $listing['id'];
            $statement->bind_param('i', $id);
            hmd_execute($statement);
            $result = $statement->get_result();
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $statement->close();
            if ($rows) {
                return $rows;
            }
        } catch (Throwable $exception) {
            // Recommendations are optional; the listing itself remains available.
        }
        $statement = hmd_prepare($connection,
            "SELECT * FROM hmd_listings
             WHERE status = 'active' AND id <> ? AND category = ?
             ORDER BY created_at DESC, id DESC LIMIT 4");
        $id = (int) $listing['id'];
        $category = (string) $listing['category'];
        $statement->bind_param('is', $id, $category);
        hmd_execute($statement);
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }
}

if (!function_exists('hmd_get_seller_listing')) {
    function hmd_get_seller_listing($connection, $listingId, $sellerId)
    {
        $statement = hmd_prepare($connection, 'SELECT * FROM hmd_listings WHERE id = ? AND seller_id = ? LIMIT 1');
        $id = (int) $listingId;
        $owner = (int) $sellerId;
        $statement->bind_param('ii', $id, $owner);
        hmd_execute($statement);
        $result = $statement->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $statement->close();
        return $row ?: null;
    }
}

if (!function_exists('hmd_get_seller_listings')) {
    function hmd_get_seller_listings($connection, $sellerId)
    {
        $statement = hmd_prepare($connection,
            'SELECT id, title, asking_price, status, created_at FROM hmd_listings
             WHERE seller_id = ? ORDER BY created_at DESC, id DESC');
        $owner = (int) $sellerId;
        $statement->bind_param('i', $owner);
        hmd_execute($statement);
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }
}

if (!function_exists('hmd_validate_listing_input')) {
    function hmd_validate_listing_input(array $input)
    {
        $limits = [
            'title' => 200, 'category' => 100, 'course' => 100,
            'semester' => 50, 'pickup_location' => 255,
        ];
        $data = [];
        foreach ($limits as $field => $limit) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($value === '' || strlen($value) > $limit) {
                throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
            }
            $data[$field] = $value;
        }
        foreach (['description', 'story'] as $field) {
            $value = trim((string) ($input[$field] ?? ''));
            if ($field === 'description' && $value === '') {
                throw new InvalidArgumentException('Enter an item description.');
            }
            if (strlen($value) > 20000) {
                throw new InvalidArgumentException('The ' . $field . ' is too long.');
            }
            $data[$field] = $value === '' ? null : $value;
        }
        $condition = filter_var($input['condition_score'] ?? null, FILTER_VALIDATE_FLOAT);
        $age = filter_var($input['age_months'] ?? null, FILTER_VALIDATE_INT);
        if ($condition === false || $condition < 1 || $condition > 5 || $age === false || $age < 0) {
            throw new InvalidArgumentException('Enter a condition rating from 1 to 5 and a valid item age.');
        }
        $data['condition_score'] = (float) $condition;
        $data['age_months'] = (int) $age;
        foreach (['original_price', 'price_min', 'price_max', 'asking_price'] as $field) {
            $value = filter_var($input[$field] ?? null, FILTER_VALIDATE_FLOAT);
            if ($value === false || !is_finite((float) $value) || $value <= 0 || $value > 99999999.99) {
                throw new InvalidArgumentException('Enter valid positive prices.');
            }
            $data[$field] = round((float) $value, 2);
        }
        if ($data['price_min'] > $data['price_max']
            || $data['asking_price'] < $data['price_min']
            || $data['asking_price'] > $data['price_max']) {
            throw new InvalidArgumentException('The asking price must be within the minimum and maximum price range.');
        }
        $data['allow_bids'] = !empty($input['allow_bids']) ? 1 : 0;
        return $data;
    }
}

if (!function_exists('hmd_predict_price')) {
    function hmd_predict_price(array $data)
    {
        $modelPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'ml' . DIRECTORY_SEPARATOR . 'model.json';
        if (!is_file($modelPath) || !is_readable($modelPath)) {
            return ['predicted_price' => null, 'deal_badge' => null];
        }
        $model = json_decode((string) file_get_contents($modelPath), true);
        if (!is_array($model) || !isset($model['intercept'], $model['coefficients'])
            || !is_array($model['coefficients'])) {
            return ['predicted_price' => null, 'deal_badge' => null];
        }
        $features = [
            'original_price' => $data['original_price'],
            'condition_score' => $data['condition_score'],
            'age_months' => $data['age_months'],
        ];
        $estimate = (float) $model['intercept'];
        foreach ($features as $key => $value) {
            if (isset($model['coefficients'][$key]) && is_numeric($model['coefficients'][$key])) {
                $estimate += (float) $model['coefficients'][$key] * $value;
            }
        }
        if (!is_finite($estimate) || $estimate <= 0) {
            return ['predicted_price' => null, 'deal_badge' => null];
        }
        $estimate = round($estimate, 2);
        $ratio = $estimate > 0 ? $data['asking_price'] / $estimate : 1;
        $badge = $ratio <= 0.7 ? 'Great deal' : ($ratio <= 0.9 ? 'Fair price' : null);
        return ['predicted_price' => $estimate, 'deal_badge' => $badge];
    }
}

if (!function_exists('hmd_create_listing')) {
    function hmd_create_listing($connection, $sellerId, array $data, array $prediction, $image)
    {
        $statement = hmd_prepare($connection,
            'INSERT INTO hmd_listings
             (seller_id, title, category, course, semester, description, story, pickup_location,
              condition_score, age_months, original_price, price_min, price_max, asking_price,
              predicted_price, deal_badge, image, allow_bids, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, \'active\')');
        $seller = (int) $sellerId;
        $condition = $data['condition_score'];
        $age = $data['age_months'];
        $original = $data['original_price'];
        $minimum = $data['price_min'];
        $maximum = $data['price_max'];
        $asking = $data['asking_price'];
        $predicted = $prediction['predicted_price'] ?? null;
        $badge = $prediction['deal_badge'] ?? null;
        $bids = $data['allow_bids'];
        $image = basename((string) $image);
        $statement->bind_param('isssssssiddidddssis', $seller, $data['title'], $data['category'], $data['course'], $data['semester'], $data['description'], $data['story'], $data['pickup_location'], $condition, $age, $original, $minimum, $maximum, $asking, $predicted, $badge, $image, $bids);
        hmd_execute($statement);
        $id = (int) $statement->insert_id;
        $statement->close();
        return $id;
    }
}

if (!function_exists('hmd_update_listing')) {
    function hmd_update_listing($connection, $listingId, $sellerId, array $data, array $prediction)
    {
        $statement = hmd_prepare($connection,
            "UPDATE hmd_listings SET title = ?, category = ?, course = ?, semester = ?, description = ?,
             story = ?, pickup_location = ?, condition_score = ?, age_months = ?, original_price = ?,
             price_min = ?, price_max = ?, asking_price = ?, predicted_price = ?, deal_badge = ?, allow_bids = ?
             WHERE id = ? AND seller_id = ? AND status = 'active'
             AND NOT EXISTS (SELECT 1 FROM hmd_orders WHERE listing_id = hmd_listings.id)");
        $condition = $data['condition_score'];
        $age = $data['age_months'];
        $original = $data['original_price'];
        $minimum = $data['price_min'];
        $maximum = $data['price_max'];
        $asking = $data['asking_price'];
        $predicted = $prediction['predicted_price'] ?? null;
        $badge = $prediction['deal_badge'] ?? null;
        $bids = $data['allow_bids'];
        $id = (int) $listingId;
        $owner = (int) $sellerId;
        $statement->bind_param('sssssssiddidddsiii', $data['title'], $data['category'], $data['course'], $data['semester'], $data['description'], $data['story'], $data['pickup_location'], $condition, $age, $original, $minimum, $maximum, $asking, $predicted, $badge, $bids, $id, $owner);
        hmd_execute($statement);
        $ok = $statement->affected_rows > 0;
        $statement->close();
        return $ok;
    }
}

if (!function_exists('hmd_remove_listing')) {
    function hmd_remove_listing($connection, $listingId, $sellerId)
    {
        $statement = hmd_prepare($connection,
            "UPDATE hmd_listings SET status = 'removed'
             WHERE id = ? AND seller_id = ? AND status = 'active'
             AND NOT EXISTS (SELECT 1 FROM hmd_orders WHERE listing_id = hmd_listings.id)");
        $id = (int) $listingId;
        $owner = (int) $sellerId;
        $statement->bind_param('ii', $id, $owner);
        hmd_execute($statement);
        $ok = $statement->affected_rows === 1;
        $statement->close();
        return $ok;
    }
}

if (!function_exists('hmd_place_bid')) {
    function hmd_place_bid($connection, $listingId, $bidderId, $amount)
    {
        $connection = hmd_require_database($connection);
        $connection->begin_transaction();
        try {
            $listingStatement = hmd_prepare($connection, 'SELECT * FROM hmd_listings WHERE id = ? FOR UPDATE');
            $id = (int) $listingId;
            $listingStatement->bind_param('i', $id);
            hmd_execute($listingStatement);
            $listing = $listingStatement->get_result()->fetch_assoc();
            $listingStatement->close();
            if (!$listing || $listing['status'] !== 'active' || (int) $listing['allow_bids'] !== 1) {
                throw new RuntimeException('Bidding is not available for this item.');
            }
            if ((int) $listing['seller_id'] === (int) $bidderId) {
                throw new RuntimeException('You cannot bid on your own listing.');
            }
            $highestStatement = hmd_prepare($connection,
                "SELECT MAX(amount) AS highest FROM hmd_bids WHERE listing_id = ? AND status = 'pending'");
            $highestStatement->bind_param('i', $id);
            hmd_execute($highestStatement);
            $highest = $highestStatement->get_result()->fetch_assoc();
            $highestStatement->close();
            $minimum = max((float) $listing['price_min'], $highest['highest'] === null ? 0 : (float) $highest['highest'] + 0.01);
            $bidAmount = round((float) $amount, 2);
            if (!is_finite($bidAmount) || $bidAmount < $minimum || $bidAmount > (float) $listing['price_max']) {
                throw new RuntimeException('Your bid must be at least ₹' . number_format($minimum, 2) . ' and within the seller’s price range.');
            }
            $bidder = (int) $bidderId;
            $insert = hmd_prepare($connection,
                "INSERT INTO hmd_bids (listing_id, bidder_id, amount, status) VALUES (?, ?, ?, 'pending')");
            $insert->bind_param('iid', $id, $bidder, $bidAmount);
            hmd_execute($insert);
            $insert->close();
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('hmd_accept_bid')) {
    function hmd_accept_bid($connection, $listingId, $bidId, $sellerId)
    {
        $connection = hmd_require_database($connection);
        $connection->begin_transaction();
        try {
            $id = (int) $listingId;
            $bid = (int) $bidId;
            $seller = (int) $sellerId;
            $listingStatement = hmd_prepare($connection, 'SELECT * FROM hmd_listings WHERE id = ? AND seller_id = ? FOR UPDATE');
            $listingStatement->bind_param('ii', $id, $seller);
            hmd_execute($listingStatement);
            $listing = $listingStatement->get_result()->fetch_assoc();
            $listingStatement->close();
            if (!$listing || $listing['status'] !== 'active') {
                throw new RuntimeException('This listing is no longer available for bid acceptance.');
            }
            $bidStatement = hmd_prepare($connection,
                "SELECT * FROM hmd_bids WHERE id = ? AND listing_id = ? AND status = 'pending' FOR UPDATE");
            $bidStatement->bind_param('ii', $bid, $id);
            hmd_execute($bidStatement);
            $selected = $bidStatement->get_result()->fetch_assoc();
            $bidStatement->close();
            if (!$selected || (int) $selected['bidder_id'] === $seller) {
                throw new RuntimeException('That bid is no longer available.');
            }
            $buyer = (int) $selected['bidder_id'];
            $price = (float) $selected['amount'];
            $insert = hmd_prepare($connection,
                "INSERT INTO hmd_orders (listing_id, buyer_id, price, source, payment_method, payment_status)
                 VALUES (?, ?, ?, 'bid', 'cash_on_pickup', 'pending')");
            $insert->bind_param('iid', $id, $buyer, $price);
            hmd_execute($insert);
            $insert->close();
            $accepted = hmd_prepare($connection,
                "UPDATE hmd_bids SET status = CASE WHEN id = ? THEN 'accepted' ELSE 'rejected' END
                 WHERE listing_id = ? AND status = 'pending'");
            $accepted->bind_param('ii', $bid, $id);
            hmd_execute($accepted);
            $accepted->close();
            $sold = hmd_prepare($connection, "UPDATE hmd_listings SET status = 'sold' WHERE id = ? AND status = 'active'");
            $sold->bind_param('i', $id);
            hmd_execute($sold);
            if ($sold->affected_rows !== 1) {
                $sold->close();
                throw new RuntimeException('The listing has already been sold.');
            }
            $sold->close();
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('hmd_reserved_listing_ids')) {
    function hmd_reserved_listing_ids($connection, array $ids, $excludeAttemptId = 0)
    {
        if (!$ids) {
            return [];
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) { return $id > 0; })));
        if (!$ids) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT DISTINCT i.listing_id FROM hmd_payment_attempt_items i
                JOIN hmd_payment_attempts a ON a.id = i.attempt_id
                WHERE i.listing_id IN ({$placeholders}) AND a.status IN ('creating', 'created')
                AND a.expires_at > NOW()";
        $types = str_repeat('i', count($ids));
        $values = $ids;
        if ((int) $excludeAttemptId > 0) {
            $sql .= ' AND a.id <> ?';
            $types .= 'i';
            $values[] = (int) $excludeAttemptId;
        }
        $statement = hmd_prepare($connection, $sql);
        hmd_bind_values($statement, $types, $values);
        hmd_execute($statement);
        $result = $statement->get_result();
        $reserved = [];
        while ($result && ($row = $result->fetch_assoc())) {
            $reserved[] = (int) $row['listing_id'];
        }
        $statement->close();
        return $reserved;
    }
}

if (!function_exists('hmd_buy_cart')) {
    function hmd_buy_cart($connection, array $listingIds, $buyerId, $paymentMethod = 'cash_on_pickup')
    {
        if (!in_array($paymentMethod, ['cash_on_pickup', 'upi_demo'], true)) {
            throw new RuntimeException('Choose a supported payment method.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $listingIds), static function ($id) { return $id > 0; })));
        if (!$ids) {
            throw new RuntimeException('Your cart is empty.');
        }
        $connection = hmd_require_database($connection);
        sort($ids, SORT_NUMERIC);
        $connection->begin_transaction();
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
            }
            foreach ($ids as $id) {
                $buyer = (int) $buyerId;
                $price = (float) $listings[$id]['asking_price'];
                $insert = hmd_prepare($connection,
                    "INSERT INTO hmd_orders (listing_id, buyer_id, price, source, payment_method, payment_status)
                     VALUES (?, ?, ?, 'buy', ?, 'pending')");
                $insert->bind_param('iids', $id, $buyer, $price, $paymentMethod);
                hmd_execute($insert);
                $insert->close();
                $sold = hmd_prepare($connection, "UPDATE hmd_listings SET status = 'sold' WHERE id = ? AND status = 'active'");
                $sold->bind_param('i', $id);
                hmd_execute($sold);
                if ($sold->affected_rows !== 1) {
                    $sold->close();
                    throw new RuntimeException('An item was checked out by someone else. No orders were placed.');
                }
                $sold->close();
                $rejected = hmd_prepare($connection,
                    "UPDATE hmd_bids SET status = 'rejected' WHERE listing_id = ? AND status = 'pending'");
                $rejected->bind_param('i', $id);
                hmd_execute($rejected);
                $rejected->close();
            }
            $connection->commit();
        } catch (Throwable $exception) {
            $connection->rollback();
            throw $exception;
        }
    }
}

if (!function_exists('hmd_buy_listing')) {
    function hmd_buy_listing($connection, $listingId, $buyerId, $paymentMethod = 'cash_on_pickup')
    {
        hmd_buy_cart($connection, [(int) $listingId], $buyerId, $paymentMethod);
    }
}

if (!function_exists('hmd_get_stories')) {
    function hmd_get_stories($connection)
    {
        $statement = hmd_prepare($connection,
            "SELECT id, title, story, image, category FROM hmd_listings
             WHERE status IN ('active', 'sold') AND story IS NOT NULL AND story <> ''
             ORDER BY created_at DESC, id DESC");
        hmd_execute($statement);
        $result = $statement->get_result();
        $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        $statement->close();
        return $rows;
    }
}
