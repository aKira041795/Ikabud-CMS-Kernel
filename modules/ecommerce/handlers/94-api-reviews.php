<?php

declare(strict_types=1);

function ecApiProductReviewsList(array $params = []): void
{
    $productId = (int)($params['id'] ?? 0);
    $product = ecProductGet($productId);

    if (!is_array($product) || (string)($product['status'] ?? '') !== 'published') {
        ecJsonError('Product not found', 404);
    }

    $limit = min(50, max(1, (int)(ecInput()['limit'] ?? 10)));
    $result = ecReviewList([
        'product_id' => $productId,
        'status' => 'approved',
        'limit' => $limit,
        'offset' => max(0, (int)(ecInput()['offset'] ?? 0)),
    ]);

    ecJsonOk([
        'summary' => ecReviewSummary($productId),
        'reviews' => $result['items'],
        'total' => (int)$result['total'],
    ]);
}

function ecApiProductReviewSubmit(array $params = []): void
{
    $productId = (int)($params['id'] ?? 0);

    try {
        $result = ecReviewCreate($productId, ecInput(), is_array(app()->user()) ? app()->user() : null);
        ecJsonOk([
            'review_id' => $result['review_id'],
            'status' => $result['status'],
            'verified_purchase' => $result['verified_purchase'],
            'message' => $result['status'] === 'approved'
                ? 'Review published.'
                : 'Review submitted for moderation.',
        ], 201);
    } catch (\InvalidArgumentException $e) {
        ecJsonError($e->getMessage(), 422);
    } catch (\Throwable $e) {
        write_log('ecApiProductReviewSubmit error: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
        ecJsonError('Could not submit review.', 500);
    }
}
