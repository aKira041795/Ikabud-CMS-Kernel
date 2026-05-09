<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function pcPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrPatientSummary')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $consents = pcDb()->query(
        'SELECT id, patient_id, consent_type, status, granted_at, expires_at, revoked_at, document_id '
        . 'FROM ehr_consents ORDER BY granted_at DESC, id DESC LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC);
    $breakGlass = pcDb()->query(
        'SELECT id, patient_id, object_type, object_id, reason_text, status, granted_at, granted_until '
        . 'FROM ehr_break_glass_events ORDER BY granted_at DESC, id DESC LIMIT 10'
    )->fetchAll(PDO::FETCH_ASSOC);

    $consents = is_array($consents) ? $consents : [];
    foreach ($consents as &$consent) {
        if (is_array($consent)) {
            $consent['patient_summary'] = ehrPatientSummary((int)($consent['patient_id'] ?? 0), 'privacy-consent');
        }
    }
    unset($consent);

    $breakGlass = is_array($breakGlass) ? $breakGlass : [];
    foreach ($breakGlass as &$event) {
        if (is_array($event)) {
            $event['patient_summary'] = ehrPatientSummary((int)($event['patient_id'] ?? 0), 'privacy-consent');
        }
    }
    unset($event);

    echo ehrRender('modules/privacy-consent/admin/index.disyl', array_merge(
        ehrAdminContext($user, 'ehr_privacy_consent', ['page_title' => 'Privacy & Consent']),
        [
            'consents' => $consents,
            'break_glass_events' => $breakGlass,
        ]
    ));
}