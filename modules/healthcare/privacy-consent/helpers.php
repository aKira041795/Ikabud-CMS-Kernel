<?php
declare(strict_types=1);

function pcCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('privacy-consent');
    if (!$ctx) {
        throw new \RuntimeException('Privacy Consent module context unavailable');
    }

    return $ctx;
}

function pcDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return pcCtx()->db();
}

function pcNormalizeScope(mixed $scope): array
{
    if (!is_array($scope)) {
        return [];
    }

    $normalized = [];
    foreach ($scope as $key => $value) {
        if (is_string($key) && $key !== '') {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

function pcFetchConsent(int $consentId = 0): ?array
{
    $consent = pcDb()->query('SELECT * FROM ehr_consents WHERE id = :id LIMIT 1', [':id' => $consentId])->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($consent)) {
        return null;
    }

    $consent['scope_json'] = !empty($consent['scope_json']) ? json_decode((string)$consent['scope_json'], true) : [];
    return $consent;
}

function pcFetchBreakGlass(int $eventId = 0): ?array
{
    $event = pcDb()->query('SELECT * FROM ehr_break_glass_events WHERE id = :id LIMIT 1', [':id' => $eventId])->fetch(\PDO::FETCH_ASSOC);
    return is_array($event) ? $event : null;
}

function pcFetchActiveConsent(int $patientId, ?int $documentId = null, string $consentType = ''): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $where = [
        'patient_id = :patient_id',
        'status = :status',
        'revoked_at IS NULL',
        '(expires_at IS NULL OR expires_at >= NOW())',
    ];
    $params = [
        ':patient_id' => $patientId,
        ':status' => 'granted',
    ];

    if ($documentId !== null && $documentId > 0) {
        $where[] = '(document_id IS NULL OR document_id = :document_id)';
        $params[':document_id'] = $documentId;
    }

    $consentType = trim($consentType);
    if ($consentType !== '') {
        $where[] = 'consent_type = :consent_type';
        $params[':consent_type'] = $consentType;
    }

    $consent = pcDb()->query(
        'SELECT * FROM ehr_consents WHERE ' . implode(' AND ', $where) . ' ORDER BY CASE WHEN document_id IS NULL THEN 1 ELSE 0 END ASC, granted_at DESC, id DESC LIMIT 1',
        $params
    )->fetch(\PDO::FETCH_ASSOC);

    if (!is_array($consent)) {
        return null;
    }

    $consent['scope_json'] = !empty($consent['scope_json']) ? json_decode((string)$consent['scope_json'], true) : [];
    return $consent;
}

function pcFetchActiveBreakGlass(int $patientId, string $objectType = '', string $objectId = ''): ?array
{
    if ($patientId <= 0) {
        return null;
    }

    $where = [
        'patient_id = :patient_id',
        'status = :status',
        'granted_until >= NOW()',
    ];
    $params = [
        ':patient_id' => $patientId,
        ':status' => 'active',
        ':patient_object_id' => (string)$patientId,
    ];

    $objectType = trim($objectType);
    $objectId = trim($objectId);
    if ($objectType !== '' && $objectId !== '') {
        $where[] = '((object_type = :object_type AND object_id = :object_id) OR (object_type = :patient_type AND object_id = :patient_object_id))';
        $params[':object_type'] = $objectType;
        $params[':object_id'] = $objectId;
        $params[':patient_type'] = 'patient';
    }

    $event = pcDb()->query(
        'SELECT * FROM ehr_break_glass_events WHERE ' . implode(' AND ', $where) . ' ORDER BY granted_until DESC, id DESC LIMIT 1',
        $params
    )->fetch(\PDO::FETCH_ASSOC);

    return is_array($event) ? $event : null;
}

function privacy_consent_cap_ehr_consent_record_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $consentType = trim((string)($data['consent_type'] ?? 'general'));
    $status = trim((string)($data['status'] ?? 'granted'));
    if ($patientId <= 0 || $consentType === '') {
        return ['ok' => false, 'error' => 'patient_id and consent_type are required'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'privacy-consent']);
    if (!is_array($patient) || empty($patient['ok'])) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    try {
        pcDb()->execute(
            'INSERT INTO ehr_consents '
            . '(patient_id, consent_type, status, scope_json, granted_at, expires_at, captured_by_user_id, document_id, revoked_at, revoked_by_user_id, created_at, updated_at) '
            . 'VALUES (:patient_id, :consent_type, :status, :scope_json, NOW(), :expires_at, :captured_by_user_id, :document_id, NULL, NULL, NOW(), NOW())',
            [
                ':patient_id' => $patientId,
                ':consent_type' => $consentType,
                ':status' => $status,
                ':scope_json' => ($scope = pcNormalizeScope($data['scope'] ?? [])) !== [] ? json_encode($scope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':expires_at' => trim((string)($data['expires_at'] ?? '')) ?: null,
                ':captured_by_user_id' => isset($data['captured_by_user_id']) ? (int)$data['captured_by_user_id'] : null,
                ':document_id' => isset($data['document_id']) ? (int)$data['document_id'] : null,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Consent record failed', 'details' => $e->getMessage()];
    }

    $consentId = (int)pcDb()->lastInsertId();
    $consent = pcFetchConsent($consentId);
    ehcAudit('privacy-consent', 'ehr.consent.recorded', 'ehr_consent', $consentId, $consent ?? []);
    app()->events()->fire('ehr.consent.recorded', [
        'consent_id' => $consentId,
        'patient_id' => $patientId,
        'consent_type' => $consentType,
    ]);

    return ['ok' => true, 'consent' => $consent];
}

function privacy_consent_cap_ehr_consent_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $consent = pcFetchConsent((int)($data['id'] ?? 0));
    if (!$consent) {
        return ['ok' => false, 'error' => 'Consent not found'];
    }

    return ['ok' => true, 'consent' => $consent];
}

function privacy_consent_cap_ehr_consent_active_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }

    $consent = pcFetchActiveConsent(
        $patientId,
        isset($data['document_id']) ? (int)$data['document_id'] : null,
        trim((string)($data['consent_type'] ?? ''))
    );

    return ['ok' => true, 'active' => $consent !== null, 'consent' => $consent];
}

function privacy_consent_cap_ehr_break_glass_request_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $reason = trim((string)($data['reason'] ?? ''));
    if ($patientId <= 0 || $reason === '') {
        return ['ok' => false, 'error' => 'patient_id and reason are required'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'privacy-consent']);
    if (!is_array($patient) || empty($patient['ok'])) {
        return ['ok' => false, 'error' => 'Patient not found'];
    }

    $grantedAt = date('Y-m-d H:i:s');
    $durationMinutes = max(1, min(240, (int)($data['duration_minutes'] ?? 30)));
    $grantedUntil = date('Y-m-d H:i:s', strtotime('+' . $durationMinutes . ' minutes', strtotime($grantedAt)));

    try {
        pcDb()->execute(
            'INSERT INTO ehr_break_glass_events '
            . '(patient_id, object_type, object_id, requested_by_user_id, reason_text, status, granted_at, granted_until, request_context_json, created_at, updated_at) '
            . 'VALUES (:patient_id, :object_type, :object_id, :requested_by_user_id, :reason_text, :status, :granted_at, :granted_until, :request_context_json, NOW(), NOW())',
            [
                ':patient_id' => $patientId,
                ':object_type' => trim((string)($data['object_type'] ?? 'patient')),
                ':object_id' => trim((string)($data['object_id'] ?? (string)$patientId)) ?: null,
                ':requested_by_user_id' => isset($data['requested_by_user_id']) ? (int)$data['requested_by_user_id'] : null,
                ':reason_text' => $reason,
                ':status' => 'active',
                ':granted_at' => $grantedAt,
                ':granted_until' => $grantedUntil,
                ':request_context_json' => ($ctx = pcNormalizeScope($data['request_context'] ?? [])) !== [] ? json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Break-glass request failed', 'details' => $e->getMessage()];
    }

    $eventId = (int)pcDb()->lastInsertId();
    $event = pcFetchBreakGlass($eventId);
    ehcAudit('privacy-consent', 'ehr.break_glass.accessed', 'ehr_break_glass_event', $eventId, $event ?? []);
    app()->events()->fire('ehr.break_glass.accessed', [
        'event_id' => $eventId,
        'patient_id' => $patientId,
        'object_type' => (string)($event['object_type'] ?? 'patient'),
        'object_id' => (string)($event['object_id'] ?? ''),
    ]);

    return ['ok' => true, 'event' => $event];
}

function privacy_consent_cap_ehr_break_glass_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $event = pcFetchBreakGlass((int)($data['id'] ?? 0));
    if (!$event) {
        return ['ok' => false, 'error' => 'Break-glass event not found'];
    }

    return ['ok' => true, 'event' => $event];
}

function privacy_consent_cap_ehr_break_glass_active_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }

    $event = pcFetchActiveBreakGlass(
        $patientId,
        trim((string)($data['object_type'] ?? '')),
        trim((string)($data['object_id'] ?? ''))
    );

    return ['ok' => true, 'active' => $event !== null, 'event' => $event];
}
function privacy_consent_cap_ehr_consent_revoke_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $consentId = (int)($data['id'] ?? $data['consent_id'] ?? 0);
    if ($consentId <= 0) {
        return ['ok' => false, 'error' => 'consent id is required'];
    }
    $consent = pcFetchConsent($consentId);
    if (!$consent) {
        return ['ok' => false, 'error' => 'Consent not found'];
    }
    if (!empty($consent['revoked_at'])) {
        return ['ok' => true, 'consent' => $consent];
    }
    $actor = isset($data['actor_user_id']) ? (int)$data['actor_user_id'] : null;
    try {
        pcDb()->execute(
            'UPDATE ehr_consents SET status = :status, revoked_at = NOW(), revoked_by_user_id = :revoked_by, updated_at = NOW() WHERE id = :id',
            [':status' => 'revoked', ':revoked_by' => $actor, ':id' => $consentId]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Consent revoke failed', 'details' => $e->getMessage()];
    }
    $updated = pcFetchConsent($consentId);
    ehcAudit('privacy-consent', 'ehr.consent.revoked', 'ehr_consent', $consentId, $updated ?? [], $consent);
    app()->events()->fire('ehr.consent.revoked', [
        'consent_id' => $consentId,
        'patient_id' => (int)($consent['patient_id'] ?? 0),
        'consent_type' => (string)($consent['consent_type'] ?? ''),
    ]);
    return ['ok' => true, 'consent' => $updated];
}

function privacy_consent_cap_ehr_break_glass_revoke_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $eventId = (int)($data['id'] ?? $data['event_id'] ?? 0);
    if ($eventId <= 0) {
        return ['ok' => false, 'error' => 'break-glass event id is required'];
    }
    $event = pcFetchBreakGlass($eventId);
    if (!$event) {
        return ['ok' => false, 'error' => 'Break-glass event not found'];
    }
    if ((string)($event['status'] ?? '') !== 'active') {
        return ['ok' => true, 'event' => $event];
    }
    try {
        pcDb()->execute(
            'UPDATE ehr_break_glass_events SET status = :status, granted_until = NOW(), updated_at = NOW() WHERE id = :id',
            [':status' => 'revoked', ':id' => $eventId]
        );
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Break-glass revoke failed', 'details' => $e->getMessage()];
    }
    $updated = pcFetchBreakGlass($eventId);
    ehcAudit('privacy-consent', 'ehr.break_glass.revoked', 'ehr_break_glass_event', $eventId, $updated ?? [], $event);
    app()->events()->fire('ehr.break_glass.revoked', [
        'event_id' => $eventId,
        'patient_id' => (int)($event['patient_id'] ?? 0),
    ]);
    return ['ok' => true, 'event' => $updated];
}
