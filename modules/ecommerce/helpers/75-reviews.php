<?php

declare(strict_types=1);

function ecReviewDefaultSummary(): array
{
    return [
        'average_rating' => 0.0,
        'average_rating_formatted' => '0.0',
        'approved_count' => 0,
        'has_reviews' => false,
        'rating_percentage' => 0,
        'label' => 'No reviews yet',
    ];
}

function ecReviewStorageAvailable(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }

    $ready = ecTableExists('ec_reviews');
    return $ready;
}

function ecReviewNormalizeSummary(array $summary): array
{
    $approvedCount = max(0, (int)($summary['approved_count'] ?? 0));
    $averageRating = $approvedCount > 0
        ? max(0.0, min(5.0, round((float)($summary['average_rating'] ?? 0.0), 1)))
        : 0.0;

    return [
        'average_rating' => $averageRating,
        'average_rating_formatted' => number_format($averageRating, 1),
        'approved_count' => $approvedCount,
        'has_reviews' => $approvedCount > 0,
        'rating_percentage' => (int)round(($averageRating / 5) * 100),
        'label' => $approvedCount > 0
            ? number_format($averageRating, 1) . ' out of 5 from ' . $approvedCount . ($approvedCount === 1 ? ' review' : ' reviews')
            : 'No reviews yet',
    ];
}

function ecReviewInvalidateCaches(int $productId, string $slug = ''): void
{
    if ($productId <= 0 || !function_exists('cmsCacheInvalidateByTags')) {
        return;
    }

    $tags = [
        'cms:content:' . $productId,
        'cms:type:product',
    ];

    $slug = trim($slug);
    if ($slug !== '') {
        $tags[] = 'cms:entity:product:' . $slug;
    }

    cmsCacheInvalidateByTags(array_values(array_unique($tags)));
}

function ecReviewSummaryForProducts(array $productIds, string $status = 'approved'): array
{
    $productIds = array_values(array_filter(array_map(static fn($id): int => (int)$id, $productIds), static fn(int $id): bool => $id > 0));
    if ($productIds === []) {
        return [];
    }

    $defaultMap = [];
    foreach ($productIds as $productId) {
        $defaultMap[$productId] = ecReviewDefaultSummary();
    }

    if (!ecReviewStorageAvailable()) {
        return $defaultMap;
    }

    $placeholders = implode(', ', array_fill(0, count($productIds), '?'));
    $params = array_merge([$status], $productIds);

    try {
        $rows = ecDb()->query(
            "SELECT product_id, COUNT(*) AS approved_count, AVG(rating) AS average_rating
             FROM ec_reviews
             WHERE status = ? AND product_id IN ($placeholders)
             GROUP BY product_id",
            $params
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        write_log('ecReviewSummaryForProducts error: ' . $e->getMessage(), 'warning', ['module' => 'ecommerce']);
        return $defaultMap;
    }

    foreach ($rows as $row) {
        $productId = (int)($row['product_id'] ?? 0);
        if ($productId <= 0) {
            continue;
        }

        $defaultMap[$productId] = ecReviewNormalizeSummary($row);
    }

    return $defaultMap;
}

function ecReviewSummary(int $productId, string $status = 'approved'): array
{
    if ($productId <= 0) {
        return ecReviewDefaultSummary();
    }

    $map = ecReviewSummaryForProducts([$productId], $status);
    return $map[$productId] ?? ecReviewDefaultSummary();
}

function ecReviewResolvedUserEmail(array $user): string
{
    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email !== '') {
        return $email;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return '';
    }

    try {
        $row = ecDb()->query('SELECT email FROM cms_users WHERE id = ? LIMIT 1', [$userId])->fetch(\PDO::FETCH_ASSOC);
        return strtolower(trim((string)($row['email'] ?? '')));
    } catch (\Throwable $e) {
        return '';
    }
}

function ecReviewResolvedUserName(array $user): string
{
    $displayName = trim((string)($user['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    $displayName = trim((string)($user['name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    $displayName = trim((string)(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? '')));
    if ($displayName !== '') {
        return $displayName;
    }

    return trim((string)($user['username'] ?? ''));
}

function ecReviewHasVerifiedPurchase(int $productId, ?int $customerId = null, string $guestEmail = ''): bool
{
    if ($productId <= 0) {
        return false;
    }

    $db = ecDb();

    try {
        if (($customerId ?? 0) > 0) {
            $row = $db->query(
                "SELECT o.id
                 FROM ec_orders o
                 INNER JOIN ec_order_items oi ON oi.order_id = o.id
                 WHERE oi.product_id = ?
                   AND o.customer_id = ?
                   AND o.status <> 'cancelled'
                 LIMIT 1",
                [$productId, (int)$customerId]
            )->fetch(\PDO::FETCH_ASSOC);

            if (is_array($row)) {
                return true;
            }
        }

        $guestEmail = strtolower(trim($guestEmail));
        if ($guestEmail === '') {
            return false;
        }

        $row = $db->query(
            "SELECT o.id
             FROM ec_orders o
             INNER JOIN ec_order_items oi ON oi.order_id = o.id
             LEFT JOIN ec_order_meta om ON om.order_id = o.id AND om.meta_key = 'billing_email'
             WHERE oi.product_id = ?
               AND o.status <> 'cancelled'
               AND (LOWER(COALESCE(o.guest_email, '')) = ? OR LOWER(COALESCE(om.meta_value, '')) = ?)
             LIMIT 1",
            [$productId, $guestEmail, $guestEmail]
        )->fetch(\PDO::FETCH_ASSOC);

        return is_array($row);
    } catch (\Throwable $e) {
        return false;
    }
}

function ecReviewNormalizeRow(array $row): array
{
    $guestName = trim((string)($row['guest_name'] ?? ''));
    $userDisplayName = trim((string)($row['user_display_name'] ?? ''));
    $reviewerName = $userDisplayName !== '' ? $userDisplayName : ($guestName !== '' ? $guestName : 'Customer');
    $rating = max(1, min(5, (int)($row['rating'] ?? 0)));

    $row['reviewer_name'] = $reviewerName;
    $row['guest_email'] = strtolower(trim((string)($row['guest_email'] ?? '')));
    $row['review_body'] = trim((string)($row['review_body'] ?? ''));
    $row['rating'] = $rating;
    $row['verified_purchase'] = !empty($row['verified_purchase']);
    $row['status'] = trim((string)($row['status'] ?? 'pending')) ?: 'pending';

    return $row;
}

function ecReviewList(array $filters = []): array
{
    if (!ecReviewStorageAvailable()) {
        return ['items' => [], 'total' => 0];
    }

    $productId = (int)($filters['product_id'] ?? 0);
    $status = trim((string)($filters['status'] ?? 'approved'));
    $search = trim((string)($filters['search'] ?? ''));
    $limit = min(100, max(1, (int)($filters['limit'] ?? 10)));
    $offset = max(0, (int)($filters['offset'] ?? 0));

    $where = ['1 = 1'];
    $params = [];

    if ($productId > 0) {
        $where[] = 'r.product_id = ?';
        $params[] = $productId;
    }

    if ($status !== '') {
        $where[] = 'r.status = ?';
        $params[] = $status;
    }

    if ($search !== '') {
        $where[] = '(r.review_body LIKE ? OR r.guest_name LIKE ? OR r.guest_email LIKE ? OR p.title LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    $whereClause = implode(' AND ', $where);
    $db = ecDb();

    try {
        $total = (int)$db->query(
            "SELECT COUNT(*)
             FROM ec_reviews r
             INNER JOIN cms_content p ON p.id = r.product_id
             WHERE $whereClause",
            $params
        )->fetchColumn();

        $rows = $db->query(
            "SELECT r.*, p.title AS product_title, p.slug AS product_slug, u.display_name AS user_display_name
             FROM ec_reviews r
             INNER JOIN cms_content p ON p.id = r.product_id
             LEFT JOIN cms_users u ON u.id = r.customer_id
             WHERE $whereClause
             ORDER BY CASE r.status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END, r.created_at DESC
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        write_log('ecReviewList error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        return ['items' => [], 'total' => 0];
    }

    $items = array_map('ecReviewNormalizeRow', $rows);
    return ['items' => $items, 'total' => $total];
}

function ecReviewAdminCounts(): array
{
    if (!ecReviewStorageAvailable()) {
        return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'spam' => 0];
    }

    try {
        $rows = ecDb()->query(
            'SELECT status, COUNT(*) AS total FROM ec_reviews GROUP BY status'
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'spam' => 0];
    }

    $counts = ['pending' => 0, 'approved' => 0, 'rejected' => 0, 'spam' => 0];
    foreach ($rows as $row) {
        $status = trim((string)($row['status'] ?? ''));
        if (!array_key_exists($status, $counts)) {
            continue;
        }
        $counts[$status] = (int)($row['total'] ?? 0);
    }

    return $counts;
}

function ecReviewGet(int $reviewId): ?array
{
    if ($reviewId <= 0 || !ecReviewStorageAvailable()) {
        return null;
    }

    try {
        $row = ecDb()->query(
            "SELECT r.*, p.slug AS product_slug, u.display_name AS user_display_name
             FROM ec_reviews r
             INNER JOIN cms_content p ON p.id = r.product_id
             LEFT JOIN cms_users u ON u.id = r.customer_id
             WHERE r.id = ?
             LIMIT 1",
            [$reviewId]
        )->fetch(\PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;
    }

    return is_array($row) ? ecReviewNormalizeRow($row) : null;
}

function ecReviewCreate(int $productId, array $input, ?array $actorUser = null): array
{
    if (!ecReviewStorageAvailable()) {
        throw new RuntimeException('Review storage is unavailable.');
    }

    $product = ecProductGet($productId);
    if (!is_array($product) || (string)($product['status'] ?? '') !== 'published') {
        throw new InvalidArgumentException('Product not found.');
    }

    $rating = max(0, min(5, (int)($input['rating'] ?? 0)));
    if ($rating < 1 || $rating > 5) {
        throw new InvalidArgumentException('Rating must be between 1 and 5.');
    }

    $reviewBody = trim((string)($input['review_body'] ?? $input['body'] ?? ''));
    if ($reviewBody === '' || mb_strlen($reviewBody) < 10) {
        throw new InvalidArgumentException('Review body must be at least 10 characters.');
    }

    $user = is_array($actorUser) ? $actorUser : (is_array(app()->user()) ? app()->user() : null);
    $customerId = null;
    $guestName = trim((string)($input['guest_name'] ?? ''));
    $guestEmail = strtolower(trim((string)($input['guest_email'] ?? '')));

    if (is_array($user) && (($user['source'] ?? '') === 'cms' || !empty($user['id']))) {
        $customerId = (int)($user['id'] ?? 0) ?: null;
        if ($guestName === '') {
            $guestName = ecReviewResolvedUserName($user);
        }
        if ($guestEmail === '') {
            $guestEmail = ecReviewResolvedUserEmail($user);
        }
    }

    if ($guestName === '') {
        $guestName = 'Customer';
    }

    if ($customerId === null && !filter_var($guestEmail, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('A valid email address is required.');
    }

    $verifiedPurchase = ecReviewHasVerifiedPurchase($productId, $customerId, $guestEmail);
    $status = (is_array($user) && in_array(strtolower(trim((string)($user['role'] ?? ''))), ['administrator', 'superadmin'], true))
        ? 'approved'
        : 'pending';

    $db = ecDb();
    $db->execute(
        "INSERT INTO ec_reviews (
            product_id, customer_id, guest_name, guest_email, rating, review_body,
            verified_purchase, status, created_at, updated_at
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())",
        [
            $productId,
            $customerId,
            mb_substr($guestName, 0, 120),
            mb_substr($guestEmail, 0, 191),
            $rating,
            $reviewBody,
            $verifiedPurchase ? 1 : 0,
            $status,
        ]
    );

    $reviewId = (int)$db->lastInsertId();
    ecReviewInvalidateCaches($productId, (string)($product['slug'] ?? ''));

    return [
        'review_id' => $reviewId,
        'status' => $status,
        'verified_purchase' => $verifiedPurchase,
    ];
}

function ecReviewSetStatus(int $reviewId, string $status, int $moderatedByUserId = 0): bool
{
    if ($reviewId <= 0 || !in_array($status, ['approved', 'rejected', 'spam', 'pending'], true) || !ecReviewStorageAvailable()) {
        return false;
    }

    $review = ecReviewGet($reviewId);
    if (!is_array($review)) {
        return false;
    }

    ecDb()->execute(
        'UPDATE ec_reviews SET status = ?, moderated_by_user_id = ?, moderated_at = NOW(), updated_at = NOW() WHERE id = ? LIMIT 1',
        [$status, $moderatedByUserId > 0 ? $moderatedByUserId : null, $reviewId]
    );

    ecReviewInvalidateCaches((int)($review['product_id'] ?? 0), (string)($review['product_slug'] ?? ''));
    return true;
}
