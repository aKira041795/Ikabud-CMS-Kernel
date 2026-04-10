<?php

declare(strict_types=1);

function ecAdminReviews(): void
{
    $user = ecRequireAdmin();
    $input = ecInput();
    $status = trim((string)($input['status'] ?? 'pending'));
    $search = trim((string)($input['search'] ?? ''));
    $page = max(1, (int)($input['page'] ?? 1));
    $limit = 20;
    $offset = ($page - 1) * $limit;

    $result = ecReviewList([
        'status' => $status === 'all' ? '' : $status,
        'search' => $search,
        'limit' => $limit,
        'offset' => $offset,
    ]);

    $ctx = ecAdminContext($user, 'reviews', [
        'reviews' => $result['items'],
        'total' => (int)$result['total'],
        'total_pages' => max(1, (int)ceil(((int)$result['total']) / $limit)),
        'page' => $page,
        'status' => $status,
        'search' => $search,
        'review_counts' => ecReviewAdminCounts(),
        'message' => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/reviews.disyl', $ctx);
}

function ecAdminReviewAction(array $params = []): void
{
    $user = ecRequireAdmin();
    csrf_verify();

    $reviewId = (int)($params['id'] ?? 0);
    $action = trim((string)($params['action'] ?? ''));
    $status = match ($action) {
        'approve' => 'approved',
        'reject' => 'rejected',
        'spam' => 'spam',
        'pending' => 'pending',
        default => '',
    };

    if ($status === '' || !ecReviewSetStatus($reviewId, $status, (int)($user['id'] ?? 0))) {
        $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Review update failed.'];
    } else {
        $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Review updated.'];
    }

    $redirect = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
    if ($redirect === '' || !str_contains($redirect, '/ecommerce/admin/reviews')) {
        $redirect = '/ecommerce/admin/reviews';
    }

    header('Location: ' . $redirect);
    exit;
}
