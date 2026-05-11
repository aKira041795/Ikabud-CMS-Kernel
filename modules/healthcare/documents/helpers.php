<?php
declare(strict_types=1);

function docCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('documents');
    if (!$ctx) {
        throw new \RuntimeException('Documents module context unavailable');
    }

    return $ctx;
}

function docDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return docCtx()->db();
}

function docNormalizeTags(mixed $tags): array
{
    if (!is_array($tags)) {
        return [];
    }

    $clean = [];
    foreach ($tags as $tag) {
        $value = trim((string)$tag);
        if ($value !== '') {
            $clean[] = $value;
        }
    }

    return array_values(array_unique($clean));
}

function docPolicyPayload(array $data): array
{
    return [
        'policy_type' => trim((string)($data['policy_type'] ?? 'document')), 
        'sensitivity_level' => trim((string)($data['sensitivity_level'] ?? 'standard')),
        'department_scope_json' => is_array($data['department_scope'] ?? null) ? $data['department_scope'] : [],
        'provider_scope_json' => is_array($data['provider_scope'] ?? null) ? $data['provider_scope'] : [],
        'consent_required_flag' => !empty($data['consent_required_flag']) ? 1 : 0,
        'break_glass_only_flag' => !empty($data['break_glass_only_flag']) ? 1 : 0,
        'active_flag' => array_key_exists('active_flag', $data) ? (!empty($data['active_flag']) ? 1 : 0) : 1,
    ];
}

function docInsertPolicy(int $patientId, ?int $documentId, array $policy): int
{
    docDb()->execute(
        'INSERT INTO ehr_access_policies '
        . '(patient_id, document_id, policy_type, sensitivity_level, department_scope_json, provider_scope_json, consent_required_flag, break_glass_only_flag, active_flag, created_at, updated_at) '
        . 'VALUES (:patient_id, :document_id, :policy_type, :sensitivity_level, :department_scope_json, :provider_scope_json, :consent_required_flag, :break_glass_only_flag, :active_flag, NOW(), NOW())',
        [
            ':patient_id' => $patientId,
            ':document_id' => $documentId,
            ':policy_type' => $policy['policy_type'],
            ':sensitivity_level' => $policy['sensitivity_level'],
            ':department_scope_json' => $policy['department_scope_json'] !== [] ? json_encode($policy['department_scope_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':provider_scope_json' => $policy['provider_scope_json'] !== [] ? json_encode($policy['provider_scope_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':consent_required_flag' => $policy['consent_required_flag'],
            ':break_glass_only_flag' => $policy['break_glass_only_flag'],
            ':active_flag' => $policy['active_flag'],
        ]
    );

    return (int)docDb()->lastInsertId();
}

function docHydratePolicy(?int $policyId): ?array
{
    if ($policyId === null || $policyId <= 0) {
        return null;
    }

    $policy = docDb()->query('SELECT * FROM ehr_access_policies WHERE id = :id LIMIT 1', [':id' => $policyId])->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($policy)) {
        return null;
    }

    $policy['department_scope_json'] = !empty($policy['department_scope_json']) ? json_decode((string)$policy['department_scope_json'], true) : [];
    $policy['provider_scope_json'] = !empty($policy['provider_scope_json']) ? json_decode((string)$policy['provider_scope_json'], true) : [];
    return $policy;
}

function docFetchDocument(int $documentId = 0, string $documentUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_documents WHERE id = :id LIMIT 1';
    $params = [':id' => $documentId];

    if ($documentId <= 0 && $documentUuid !== '') {
        $sql = 'SELECT * FROM ehr_documents WHERE document_uuid = :document_uuid LIMIT 1';
        $params = [':document_uuid' => $documentUuid];
    }

    $document = docDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($document)) {
        return null;
    }

    $document['tag_json'] = !empty($document['tag_json']) ? json_decode((string)$document['tag_json'], true) : [];
    $document['policy'] = docHydratePolicy(isset($document['access_policy_id']) ? (int)$document['access_policy_id'] : null);
    return $document;
}

function docPolicyRequiresGuard(array $document): bool
{
    $policy = is_array($document['policy'] ?? null) ? $document['policy'] : [];
    if ($policy === [] || empty($policy['active_flag'])) {
        return false;
    }

    $policyType = strtolower(trim((string)($policy['policy_type'] ?? '')));
    $sensitivity = strtolower(trim((string)($document['sensitivity_level'] ?? $policy['sensitivity_level'] ?? '')));

    return !empty($policy['consent_required_flag'])
        || !empty($policy['break_glass_only_flag'])
        || $policyType === 'restricted-chart'
        || $sensitivity === 'restricted';
}

function docRestrictedAccessDecision(array $document): array
{
    $policy = is_array($document['policy'] ?? null) ? $document['policy'] : [];
    $patientId = (int)($document['patient_id'] ?? 0);
    $documentId = (int)($document['id'] ?? 0);
    $consentActive = false;
    $breakGlassActive = false;

    if (app()->capabilities()->has('ehr.consent.active@1')) {
        $consent = app()->cap()->call('ehr.consent.active@1', [
            'patient_id' => $patientId,
            'document_id' => $documentId,
        ], ['caller_module' => 'documents']);
        $consentActive = is_array($consent) && !empty($consent['ok']) && !empty($consent['active']);
    }

    if (app()->capabilities()->has('ehr.break_glass.active@1')) {
        $breakGlass = app()->cap()->call('ehr.break_glass.active@1', [
            'patient_id' => $patientId,
            'object_type' => 'document',
            'object_id' => (string)$documentId,
        ], ['caller_module' => 'documents']);
        $breakGlassActive = is_array($breakGlass) && !empty($breakGlass['ok']) && !empty($breakGlass['active']);
    }

    if (!empty($policy['break_glass_only_flag']) && !empty($policy['consent_required_flag'])) {
        return ['allowed' => $consentActive && $breakGlassActive, 'reason' => 'consent_and_break_glass_required'];
    }
    if (!empty($policy['break_glass_only_flag'])) {
        return ['allowed' => $breakGlassActive, 'reason' => 'break_glass_required'];
    }
    if (!empty($policy['consent_required_flag'])) {
        return ['allowed' => $consentActive, 'reason' => 'consent_required'];
    }

    return ['allowed' => $consentActive || $breakGlassActive, 'reason' => 'restricted_document'];
}

function docResolveAccessibleDocument(array $data, string $accessAction = 'view'): array
{
    $document = docFetchDocument((int)($data['id'] ?? 0), trim((string)($data['document_uuid'] ?? '')));
    if (!$document) {
        return ['ok' => false, 'error' => 'Document not found'];
    }

    if (docPolicyRequiresGuard($document)) {
        $decision = docRestrictedAccessDecision($document);
        if (empty($decision['allowed'])) {
            $reason = (string)($decision['reason'] ?? 'restricted_document');
            docEmitAuditEvent('ehr.document.access_denied', $document, [
                'attempted_action' => trim($accessAction) !== '' ? $accessAction : 'view',
                'denial_reason' => $reason,
            ]);
            return [
                'ok' => false,
                'error' => 'Document access denied',
                'reason' => $reason,
            ];
        }
    }

    return ['ok' => true, 'document' => $document];
}

function docEmitAuditEvent(string $action, array $document, array $extra = []): void
{
    $documentId = (int)($document['id'] ?? 0);
    $auditData = array_merge($document, $extra);
    ehcAudit('documents', $action, 'ehr_document', $documentId, $auditData);
    app()->events()->fire($action, array_merge([
        'document_id' => $documentId,
        'patient_id' => (int)($document['patient_id'] ?? 0),
        'encounter_id' => (int)($document['encounter_id'] ?? 0),
    ], $extra));
}

function documents_cap_ehr_document_upload_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $encounterId = (int)($data['encounter_id'] ?? 0);
    $storageKey = trim((string)($data['storage_key'] ?? ''));
    $mimeType = trim((string)($data['mime_type'] ?? ''));
    $title = trim((string)($data['title'] ?? ''));
    $documentType = trim((string)($data['document_type'] ?? 'attachment'));

    if ($patientId <= 0 || $encounterId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and encounter_id are required'];
    }
    if ($storageKey === '' || $mimeType === '' || $title === '') {
        return ['ok' => false, 'error' => 'storage_key, mime_type, and title are required'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'documents']);
    $encounter = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => 'documents']);
    if (!is_array($patient) || empty($patient['ok']) || !is_array($encounter) || empty($encounter['ok'])) {
        return ['ok' => false, 'error' => 'Patient or encounter not found'];
    }
    if ((int)($encounter['encounter']['patient_id'] ?? 0) !== $patientId) {
        return ['ok' => false, 'error' => 'Encounter does not belong to patient'];
    }

    $documentUuid = ehcGenerateRecordKey('doc');
    $policy = docPolicyPayload($data);

    docDb()->beginTransaction();
    try {
        $policyId = docInsertPolicy($patientId, null, $policy);
        docDb()->execute(
            'INSERT INTO ehr_documents '
            . '(document_uuid, patient_id, encounter_id, related_order_id, related_result_id, storage_key, document_type, title, mime_type, file_size, source, tag_json, sensitivity_level, access_policy_id, uploaded_by_user_id, uploaded_at, created_at, updated_at) '
            . 'VALUES (:document_uuid, :patient_id, :encounter_id, :related_order_id, :related_result_id, :storage_key, :document_type, :title, :mime_type, :file_size, :source, :tag_json, :sensitivity_level, :access_policy_id, :uploaded_by_user_id, NOW(), NOW(), NOW())',
            [
                ':document_uuid' => $documentUuid,
                ':patient_id' => $patientId,
                ':encounter_id' => $encounterId,
                ':related_order_id' => isset($data['related_order_id']) ? (int)$data['related_order_id'] : null,
                ':related_result_id' => isset($data['related_result_id']) ? (int)$data['related_result_id'] : null,
                ':storage_key' => $storageKey,
                ':document_type' => $documentType,
                ':title' => $title,
                ':mime_type' => $mimeType,
                ':file_size' => isset($data['file_size']) ? (int)$data['file_size'] : null,
                ':source' => trim((string)($data['source'] ?? 'ehr-upload')),
                ':tag_json' => ($tags = docNormalizeTags($data['tags'] ?? [])) !== [] ? json_encode($tags, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
                ':sensitivity_level' => $policy['sensitivity_level'],
                ':access_policy_id' => $policyId,
                ':uploaded_by_user_id' => isset($data['uploaded_by_user_id']) ? (int)$data['uploaded_by_user_id'] : null,
            ]
        );

        $documentId = (int)docDb()->lastInsertId();
        docDb()->execute('UPDATE ehr_access_policies SET document_id = :document_id WHERE id = :id', [':document_id' => $documentId, ':id' => $policyId]);
        docDb()->commit();
    } catch (\Throwable $e) {
        if (docDb()->inTransaction()) {
            docDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Document registration failed', 'details' => $e->getMessage()];
    }

    $document = docFetchDocument($documentId);
    ehcAudit('documents', 'ehr.document.uploaded', 'ehr_document', $documentId, $document ?? []);
    app()->events()->fire('ehr.document.uploaded', [
        'document_id' => $documentId,
        'document_uuid' => $documentUuid,
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
    ]);

    return ['ok' => true, 'document' => $document];
}

function documents_cap_ehr_document_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $access = docResolveAccessibleDocument($data, 'view');
    if (empty($access['ok'])) {
        return $access;
    }

    $document = $access['document'];
    docEmitAuditEvent('ehr.document.viewed', $document);

    return ['ok' => true, 'document' => $document];
}

function documents_cap_ehr_document_list_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    if ($patientId <= 0) {
        return ['ok' => false, 'error' => 'patient_id is required'];
    }
    $limit = max(1, min(100, (int)($data['limit'] ?? 25)));
    $excludeRestricted = !empty($data['exclude_restricted']) || (string)($data['caller_module'] ?? '') === 'patient-portal';

    $where = ['patient_id = :pid'];
    $params = [':pid' => $patientId];
    if ($excludeRestricted) {
        $where[] = "(sensitivity_level IS NULL OR sensitivity_level NOT IN ('restricted','sensitive'))";
        $where[] = 'access_policy_id IS NULL';
    }

    $sql = 'SELECT id, document_uuid, patient_id, encounter_id, title, document_type, mime_type, file_size, sensitivity_level, created_at '
         . 'FROM ehr_documents WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . $limit;
    $rows = docDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    return ['ok' => true, 'documents' => $rows];
}

function documents_cap_ehr_document_print_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $access = docResolveAccessibleDocument($data, 'print');
    if (empty($access['ok'])) {
        return $access;
    }

    $document = $access['document'];
    $printFormat = trim((string)($data['print_format'] ?? 'pdf'));
    docEmitAuditEvent('ehr.document.printed', $document, ['print_format' => $printFormat]);

    return [
        'ok' => true,
        'document' => $document,
        'print_format' => $printFormat,
    ];
}

function documents_cap_ehr_document_export_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $access = docResolveAccessibleDocument($data, 'export');
    if (empty($access['ok'])) {
        return $access;
    }

    $document = $access['document'];
    $exportFormat = trim((string)($data['export_format'] ?? 'pdf'));
    docEmitAuditEvent('ehr.document.exported', $document, ['export_format' => $exportFormat]);

    return [
        'ok' => true,
        'document' => $document,
        'export_format' => $exportFormat,
    ];
}

function documents_cap_ehr_document_restrict_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $documentId = (int)($data['document_id'] ?? 0);
    if ($documentId <= 0) {
        return ['ok' => false, 'error' => 'document_id is required'];
    }

    $document = docFetchDocument($documentId);
    if (!$document) {
        return ['ok' => false, 'error' => 'Document not found'];
    }

    $policy = docPolicyPayload($data);
    $policyId = (int)($document['access_policy_id'] ?? 0);
    if ($policyId <= 0) {
        return ['ok' => false, 'error' => 'Document has no access policy'];
    }

    docDb()->execute(
        'UPDATE ehr_access_policies SET policy_type = :policy_type, sensitivity_level = :sensitivity_level, department_scope_json = :department_scope_json, provider_scope_json = :provider_scope_json, consent_required_flag = :consent_required_flag, break_glass_only_flag = :break_glass_only_flag, active_flag = :active_flag, updated_at = NOW() WHERE id = :id',
        [
            ':policy_type' => $policy['policy_type'],
            ':sensitivity_level' => $policy['sensitivity_level'],
            ':department_scope_json' => $policy['department_scope_json'] !== [] ? json_encode($policy['department_scope_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':provider_scope_json' => $policy['provider_scope_json'] !== [] ? json_encode($policy['provider_scope_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':consent_required_flag' => $policy['consent_required_flag'],
            ':break_glass_only_flag' => $policy['break_glass_only_flag'],
            ':active_flag' => $policy['active_flag'],
            ':id' => $policyId,
        ]
    );
    docDb()->execute(
        'UPDATE ehr_documents SET sensitivity_level = :sensitivity_level, updated_at = NOW() WHERE id = :id',
        [
            ':sensitivity_level' => $policy['sensitivity_level'],
            ':id' => $documentId,
        ]
    );

    $restricted = docFetchDocument($documentId);
    ehcAudit('documents', 'ehr.document.restricted', 'ehr_document', $documentId, $restricted ?? [], $document);
    app()->events()->fire('ehr.document.restricted', [
        'document_id' => $documentId,
        'patient_id' => (int)$document['patient_id'],
        'encounter_id' => (int)$document['encounter_id'],
    ]);

    return ['ok' => true, 'document' => $restricted];
}

function docMaxUploadBytes(): int
{
    if (function_exists('cmsMediaMaxUploadBytes')) {
        return max(1048576, (int)cmsMediaMaxUploadBytes());
    }
    return 25 * 1024 * 1024;
}

function docAllowedMimeTypes(): array
{
    return [
        'application/pdf', 'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/tiff',
        'text/plain', 'text/csv',
        'application/dicom', 'application/hl7-v2', 'application/xml', 'text/xml',
        'application/json',
    ];
}

function docStorageBaseDir(): string
{
    $tenantId = app()->tenant()->current();
    $tenantSegment = $tenantId !== null ? ('/t' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$tenantId)) : '';
    return BASE_PATH . '/storage/private/ehr-documents' . $tenantSegment;
}

function docPersistUploadedFile(array $file, int $patientId): array
{
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('Upload a document file first.');
    }
    $tmp = trim((string)($file['tmp_name'] ?? ''));
    if ($tmp === '' || !is_file($tmp)) {
        throw new InvalidArgumentException('Uploaded document file is not available.');
    }
    if (PHP_SAPI !== 'cli' && function_exists('is_uploaded_file') && !is_uploaded_file($tmp)) {
        throw new InvalidArgumentException('Document upload did not arrive through the HTTP upload pipeline.');
    }
    $size = (int)($file['size'] ?? 0);
    if ($size <= 0) { $size = (int)(@filesize($tmp) ?: 0); }
    if ($size <= 0) {
        throw new InvalidArgumentException('Uploaded document file is empty.');
    }
    if ($size > docMaxUploadBytes()) {
        throw new InvalidArgumentException('Uploaded document exceeds the maximum allowed size.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = strtolower((string)($finfo->file($tmp) ?: ''));
    if ($mimeType === '' || !in_array($mimeType, docAllowedMimeTypes(), true)) {
        throw new InvalidArgumentException('Document file type is not allowed.');
    }

    $originalName = trim((string)($file['name'] ?? 'document'));
    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if ($ext === '' || strlen($ext) > 8 || !preg_match('/^[a-z0-9]+$/', $ext)) {
        $ext = match ($mimeType) {
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/tiff' => 'tif',
            'text/plain' => 'txt',
            'text/csv' => 'csv',
            'application/dicom' => 'dcm',
            'application/xml', 'text/xml' => 'xml',
            'application/json' => 'json',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }

    $subDir = 'p' . max(0, $patientId) . '/' . date('Y') . '/' . date('m');
    $filename = 'doc_' . date('Ymd_His') . '_' . substr(bin2hex(random_bytes(6)), 0, 12) . '.' . $ext;
    $relative = $subDir . '/' . $filename;
    $destDir = docStorageBaseDir() . '/' . $subDir;
    $destPath = $destDir . '/' . $filename;

    if (!kernelEnsureDirectory($destDir)) {
        throw new RuntimeException('Unable to prepare document storage directory.');
    }
    if (!kernelCopyFile($tmp, $destPath)) {
        throw new RuntimeException('Unable to persist uploaded document.');
    }
    @chmod($destPath, 0640);

    return [
        'storage_key' => 'ehr-documents/' . $relative,
        'absolute_path' => $destPath,
        'mime_type' => $mimeType,
        'file_size' => $size,
        'original_name' => $originalName,
    ];
}

function docResolveStoragePath(string $storageKey): ?string
{
    $key = ltrim($storageKey, '/');
    if ($key === '' || str_contains($key, '..')) return null;
    if (!str_starts_with($key, 'ehr-documents/')) return null;
    $relative = substr($key, strlen('ehr-documents/'));
    $path = docStorageBaseDir() . '/' . $relative;
    return is_file($path) ? $path : null;
}