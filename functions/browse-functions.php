<?php
require_once __DIR__ . '/../includes/config.php';

if (!function_exists('hmd_escape')) {
    function hmd_escape($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('hmd_is_academic_category')) {
    function hmd_is_academic_category($category)
    {
        $category = function_exists('mb_strtolower') ? mb_strtolower((string) $category, 'UTF-8') : strtolower((string) $category);
        foreach (['book', 'textbook', 'note', 'study material', 'course material', 'academic'] as $keyword) {
            if (strpos($category, $keyword) !== false) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('hmd_csrf_token')) {
    function hmd_csrf_token()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        if (empty($_SESSION['hmd_csrf_token'])) {
            try {
                $_SESSION['hmd_csrf_token'] = bin2hex(random_bytes(32));
            } catch (Throwable $exception) {
                $_SESSION['hmd_csrf_token'] = hash('sha256', uniqid((string) mt_rand(), true));
            }
        }
        return $_SESSION['hmd_csrf_token'];
    }
}

if (!function_exists('hmd_fetch_listings')) {
    function hmd_fetch_listings($conn, array $filters = [])
    {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return [];
        }

        $where = ["status = 'active'"];
        $values = [];
        $types = '';
        $add = function ($type, $value) use (&$types, &$values) {
            $types .= $type;
            $values[] = $value;
        };

        $query = trim((string) ($filters['q'] ?? ''));
        if ($query !== '') {
            $where[] = '(title LIKE ? OR description LIKE ? OR category LIKE ?)';
            $like = '%' . $query . '%';
            $add('s', $like);
            $add('s', $like);
            $add('s', $like);
        }
        foreach (['category' => 'category', 'course' => 'course', 'semester' => 'semester'] as $filter => $column) {
            $value = trim((string) ($filters[$filter] ?? ''));
            if ($value !== '') {
                $where[] = $column . ' = ?';
                $add('s', $value);
            }
        }
        $maxPrice = $filters['max_price'] ?? '';
        if ($maxPrice !== '' && is_numeric($maxPrice) && (float) $maxPrice >= 0) {
            $where[] = 'asking_price <= ?';
            $add('d', (float) $maxPrice);
        }
        $condition = $filters['condition'] ?? '';
        if ($condition !== '' && is_numeric($condition)) {
            $where[] = 'condition_score >= ?';
            $add('d', max(1, min(5, (float) $condition)));
        }
        if (!empty($filters['bids_open'])) {
            $where[] = 'allow_bids = 1';
        }

        $orderBy = 'created_at DESC, id DESC';
        if (($filters['sort'] ?? '') === 'price_low') {
            $orderBy = 'asking_price ASC, id DESC';
        } elseif (($filters['sort'] ?? '') === 'price_high') {
            $orderBy = 'asking_price DESC, id DESC';
        }
        $limit = isset($filters['limit']) && is_numeric($filters['limit']) ? (int) $filters['limit'] : 100;
        $limit = max(1, min(100, $limit));
        $sql = 'SELECT id, seller_id, title, category, course, semester, description, story, pickup_location, condition_score, age_months, original_price, price_min, price_max, asking_price, predicted_price, deal_badge, image, allow_bids, status, created_at FROM hmd_listings WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $orderBy . ' LIMIT ' . $limit;

        try {
            $stmt = @$conn->prepare($sql);
            if (!$stmt) {
                return [];
            }
            if ($values) {
                $params = [$types];
                foreach ($values as $index => $value) {
                    $params[] = &$values[$index];
                }
                call_user_func_array([$stmt, 'bind_param'], $params);
            }
            if (!$stmt->execute()) {
                $stmt->close();
                return [];
            }
            $result = $stmt->get_result();
            $listings = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
            $stmt->close();
            return $listings;
        } catch (Throwable $exception) {
            if (isset($stmt) && $stmt instanceof mysqli_stmt) {
                @$stmt->close();
            }
            return [];
        }
    }
}

if (!function_exists('hmd_get_listing_categories')) {
    function hmd_get_listing_categories($conn)
    {
        if (!($conn instanceof mysqli) || $conn->connect_errno) {
            return [];
        }
        try {
            $result = @$conn->query("SELECT DISTINCT category FROM hmd_listings WHERE status = 'active' AND category <> '' ORDER BY category");
            if (!$result) {
                return [];
            }
            $categories = [];
            while ($row = $result->fetch_assoc()) {
                $categories[] = $row['category'];
            }
            $result->free();
            return $categories;
        } catch (Throwable $exception) {
            return [];
        }
    }
}

if (!function_exists('hmd_render_condition_rating')) {
    function hmd_render_condition_rating($score)
    {
        $score = max(0, min(5, (float) $score));
        echo '<p class="condition-rating">Condition: ' . hmd_escape(number_format($score, 1)) . ' / 5</p>';
    }
}
