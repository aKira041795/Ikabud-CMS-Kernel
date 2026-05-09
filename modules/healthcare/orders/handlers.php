<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function ordPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrHydrateRecordSummaries')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $rows = ordDb()->query(
        'SELECT o.id, o.order_uuid, o.patient_id, o.encounter_id, o.order_type, o.priority, o.status, o.ordered_at, o.destination_module, o.clinical_question, '
        . '(SELECT COUNT(*) FROM ehr_order_items oi WHERE oi.order_id = o.id) AS item_count, '
        . '(SELECT item_label FROM ehr_order_items oi WHERE oi.order_id = o.id ORDER BY oi.id ASC LIMIT 1) AS first_item_label '
        . 'FROM ehr_orders o ORDER BY o.ordered_at DESC, o.id DESC LIMIT 12'
    )->fetchAll(PDO::FETCH_ASSOC);

    $orders = ehrHydrateRecordSummaries(is_array($rows) ? $rows : [], 'orders');

    echo ehrRender('modules/orders/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_orders', ['page_title' => 'Orders']),
        [
            'orders' => $orders,
            'result_count' => count($orders),
        ]
    ));
}