<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function schedPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $list = scheduling_cap_ehr_appointment_list_1(['limit' => 12], 'ehr.appointment.list@1', 'scheduling');
    $appointments = is_array($list) && !empty($list['ok']) && is_array($list['appointments'] ?? null)
        ? array_values($list['appointments'])
        : [];

    $statusCounts = schedDb()->query('SELECT status, COUNT(*) AS total FROM ehr_appointments GROUP BY status ORDER BY total DESC, status ASC')->fetchAll(PDO::FETCH_ASSOC);

    echo ehrRender('modules/scheduling/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_scheduling', ['page_title' => 'Appointments']),
        [
            'appointments' => $appointments,
            'result_count' => count($appointments),
            'status_counts' => is_array($statusCounts) ? $statusCounts : [],
        ]
    ));
}