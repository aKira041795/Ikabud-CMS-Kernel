<?php

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

function pcPageState(array $user, array $input = [], ?string $formError = null): array
{
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

    $consentCounts = ['granted' => 0, 'revoked' => 0, 'expired' => 0, 'pending' => 0];
    $nowTs = time();
    foreach ($consents as $c) {
        $st = strtolower((string)($c['status'] ?? ''));
        $expiresAt = (string)($c['expires_at'] ?? '');
        if (!empty($c['revoked_at']) || $st === 'revoked') {
            $consentCounts['revoked']++;
        } elseif ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < $nowTs) {
            $consentCounts['expired']++;
        } elseif ($st === 'granted' || $st === 'active') {
            $consentCounts['granted']++;
        } else {
            $consentCounts['pending']++;
        }
    }
    $bgCounts = ['active' => 0, 'expired' => 0];
    foreach ($breakGlass as $e) {
        $until = (string)($e['granted_until'] ?? '');
        if ($until !== '' && strtotime($until) !== false && strtotime($until) >= $nowTs) {
            $bgCounts['active']++;
        } else {
            $bgCounts['expired']++;
        }
    }

    $patientSearch = app()->cap()->call('ehr.patient.search@1', ['limit' => 50], ['caller_module' => 'privacy-consent']);
    $patientOptions = is_array($patientSearch) && !empty($patientSearch['ok']) && is_array($patientSearch['results'] ?? null)
        ? array_values($patientSearch['results']) : [];

    return array_merge(
        ehrAdminContext($user, 'ehr_privacy_consent', ['page_title' => 'Consent']),
        [
            'consents' => $consents,
            'break_glass_events' => $breakGlass,
            'consent_counts' => $consentCounts,
            'break_glass_counts' => $bgCounts,
            'patient_options' => $patientOptions,
            'form_error' => $formError !== null ? $formError : (trim((string)($input['error'] ?? '')) !== '' ? (string)$input['error'] : null),
            'form_notice' => trim((string)($input['notice'] ?? '')),
            'form_values' => [
                'patient_id' => (int)($input['patient_id'] ?? 0),
                'consent_type' => (string)($input['consent_type'] ?? 'general'),
                'status' => (string)($input['status'] ?? 'granted'),
                'expires_at' => (string)($input['expires_at'] ?? ''),
                'reason' => (string)($input['reason'] ?? ''),
                'duration_minutes' => (int)($input['duration_minutes'] ?? 30),
            ],
        ]
    );
}

function pcPageIndex(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrPatientSummary')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    echo ehrRender('modules/privacy-consent/admin/index.disyl', pcPageState($user, app()->input()));
}

function pcSaveConsent(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrPatientSummary')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'consent_type' => trim((string)($input['consent_type'] ?? 'general')),
        'status' => trim((string)($input['status'] ?? 'granted')),
        'expires_at' => trim((string)($input['expires_at'] ?? '')),
        'captured_by_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.consent.record@1', $payload, ['caller_module' => 'privacy-consent']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['consent'] ?? null)) {
        app()->redirect('/admin/ehr/privacy?notice=consent_recorded');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to record consent.'));
    echo ehrRender('modules/privacy-consent/admin/index.disyl', pcPageState($user, $input, $error));
}

function pcSaveBreakGlass(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin') || !function_exists('ehrRender') || !function_exists('ehrAdminContext') || !function_exists('ehrPatientSummary')) {
        http_response_code(503);
        echo 'EHR admin runtime unavailable';
        return;
    }

    $user = ehrRequireAdmin();
    $input = app()->input();
    $payload = [
        'patient_id' => max(0, (int)($input['patient_id'] ?? 0)),
        'reason' => trim((string)($input['reason'] ?? '')),
        'duration_minutes' => max(1, min(240, (int)($input['duration_minutes'] ?? 30))),
        'requested_by_user_id' => (int)($user['id'] ?? 0),
    ];

    $result = app()->cap()->call('ehr.break_glass.request@1', $payload, ['caller_module' => 'privacy-consent']);
    if (is_array($result) && !empty($result['ok']) && is_array($result['event'] ?? null)) {
        app()->redirect('/admin/ehr/privacy?notice=break_glass');
        return;
    }

    $error = trim((string)($result['error'] ?? 'Unable to request break-glass access.'));
    echo ehrRender('modules/privacy-consent/admin/index.disyl', pcPageState($user, $input, $error));
}
function pcRevokeConsent(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR runtime unavailable'; return; }
    $user = ehrRequireAdmin();
    app()->csrfEnforce();
    $input = app()->input();
    $payload = ['id' => (int)($input['id'] ?? 0), 'actor_user_id' => (int)($user['id'] ?? 0)];
    $result = app()->cap()->call('ehr.consent.revoke@1', $payload, ['caller_module' => 'privacy-consent']);
    $ok = is_array($result) && !empty($result['ok']);
    $qs = $ok ? '?notice=consent_revoked' : ('?error=' . rawurlencode((string)($result['error'] ?? 'Revoke failed')));
    app()->redirect('/admin/ehr/privacy' . $qs);
}

function pcRevokeBreakGlass(array $params = []): void
{
    if (!function_exists('ehrRequireAdmin')) { http_response_code(503); echo 'EHR runtime unavailable'; return; }
    $user = ehrRequireAdmin();
    app()->csrfEnforce();
    $input = app()->input();
    $payload = ['id' => (int)($input['id'] ?? 0), 'actor_user_id' => (int)($user['id'] ?? 0)];
    $result = app()->cap()->call('ehr.break_glass.revoke@1', $payload, ['caller_module' => 'privacy-consent']);
    $ok = is_array($result) && !empty($result['ok']);
    $qs = $ok ? '?notice=break_glass_revoked' : ('?error=' . rawurlencode((string)($result['error'] ?? 'Revoke failed')));
    app()->redirect('/admin/ehr/privacy' . $qs);
}
