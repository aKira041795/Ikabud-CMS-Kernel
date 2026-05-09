<?php
declare(strict_types=1);

function cnCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('clinical-notes');
    if (!$ctx) {
        throw new \RuntimeException('Clinical Notes module context unavailable');
    }

    return $ctx;
}

function cnDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cnCtx()->db();
}

function cnNoteStatusAllowed(string $status): bool
{
    $result = app()->cap()->call('ehr.core.status.catalog@1', ['domain' => 'note'], ['caller_module' => 'clinical-notes']);
    $statuses = is_array($result) && !empty($result['ok']) && is_array($result['statuses'] ?? null)
        ? $result['statuses']
        : [];

    return in_array($status, $statuses, true);
}

function cnCanonicalNotePayload(array $payload): array
{
    $bodyText = trim((string)($payload['body_text'] ?? ''));
    $bodyJson = $payload['body_json'] ?? null;

    return [
        'body_text' => $bodyText,
        'body_json' => is_array($bodyJson) ? $bodyJson : null,
    ];
}

function cnVersionHash(array $payload): string
{
    return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

function cnFetchNote(int $noteId = 0, string $noteUuid = ''): ?array
{
    $sql = 'SELECT * FROM ehr_notes WHERE id = :id LIMIT 1';
    $params = [':id' => $noteId];

    if ($noteId <= 0 && $noteUuid !== '') {
        $sql = 'SELECT * FROM ehr_notes WHERE note_uuid = :note_uuid LIMIT 1';
        $params = [':note_uuid' => $noteUuid];
    }

    $note = cnDb()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($note)) {
        return null;
    }

    $versions = cnDb()->query(
        'SELECT * FROM ehr_note_versions WHERE note_id = :note_id ORDER BY version_no ASC, id ASC',
        [':note_id' => (int)$note['id']]
    )->fetchAll(\PDO::FETCH_ASSOC);

    $note['versions'] = is_array($versions) ? array_map(static function (array $row): array {
        $row['body_json'] = !empty($row['body_json']) ? json_decode((string)$row['body_json'], true) : null;
        return $row;
    }, $versions) : [];

    return $note;
}

function cnNextVersionNo(int $noteId): int
{
    $row = cnDb()->query(
        'SELECT COALESCE(MAX(version_no), 0) AS version_no FROM ehr_note_versions WHERE note_id = :note_id',
        [':note_id' => $noteId]
    )->fetch(\PDO::FETCH_ASSOC);

    return ((int)($row['version_no'] ?? 0)) + 1;
}

function cnInsertVersion(
    int $noteId,
    int $versionNo,
    string $versionKind,
    array $content,
    ?int $authoredByUserId,
    ?string $signReason = null,
    ?string $amendmentReason = null,
    ?int $supersedesVersionId = null,
    ?string $lockedAt = null
): int {
    cnDb()->execute(
        'INSERT INTO ehr_note_versions '
        . '(note_id, version_no, version_kind, body_text, body_json, authored_at, authored_by_user_id, sign_reason, amendment_reason, supersedes_version_id, hash, locked_at, created_at, updated_at) '
        . 'VALUES (:note_id, :version_no, :version_kind, :body_text, :body_json, NOW(), :authored_by_user_id, :sign_reason, :amendment_reason, :supersedes_version_id, :hash, :locked_at, NOW(), NOW())',
        [
            ':note_id' => $noteId,
            ':version_no' => $versionNo,
            ':version_kind' => $versionKind,
            ':body_text' => $content['body_text'] !== '' ? $content['body_text'] : null,
            ':body_json' => is_array($content['body_json']) ? json_encode($content['body_json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
            ':authored_by_user_id' => $authoredByUserId,
            ':sign_reason' => $signReason,
            ':amendment_reason' => $amendmentReason,
            ':supersedes_version_id' => $supersedesVersionId,
            ':hash' => cnVersionHash($content),
            ':locked_at' => $lockedAt,
        ]
    );

    return (int)cnDb()->lastInsertId();
}

function cnResolveActorUserId(array $payload = []): ?int
{
    if (isset($payload['actor_user_id']) && (int)$payload['actor_user_id'] > 0) {
        return (int)$payload['actor_user_id'];
    }

    $user = ehcCurrentUser();
    $userId = is_array($user) ? (int)($user['id'] ?? $user['sub'] ?? 0) : 0;
    return $userId > 0 ? $userId : null;
}

function cnRestrictedAccessDecision(array $note): array
{
    return ehcRestrictedAccessDecision([
        'patient_id' => (int)($note['patient_id'] ?? 0),
        'object_type' => 'note',
        'object_id' => (string)(int)($note['id'] ?? 0),
        'caller_module' => 'clinical-notes',
        'allow_if_any' => true,
        'fallback_reason' => 'restricted_note',
    ]);
}

function cnEmitAuditEvent(string $action, array $note, array $extra = []): void
{
    $noteId = (int)($note['id'] ?? 0);
    ehcAudit('clinical-notes', $action, 'ehr_note', $noteId, array_merge($note, $extra));
    app()->events()->fire($action, array_merge([
        'note_id' => $noteId,
        'patient_id' => (int)($note['patient_id'] ?? 0),
        'encounter_id' => (int)($note['encounter_id'] ?? 0),
    ], $extra));
}

function clinical_notes_cap_ehr_note_create_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $patientId = (int)($data['patient_id'] ?? 0);
    $encounterId = (int)($data['encounter_id'] ?? 0);
    $noteType = trim((string)($data['note_type'] ?? 'progress'));
    $status = strtolower(trim((string)($data['status'] ?? 'draft')));
    $content = cnCanonicalNotePayload($data);

    if ($patientId <= 0 || $encounterId <= 0) {
        return ['ok' => false, 'error' => 'patient_id and encounter_id are required'];
    }

    if ($content['body_text'] === '' && $content['body_json'] === null) {
        return ['ok' => false, 'error' => 'body_text or body_json is required'];
    }

    if ($status !== 'draft' || !cnNoteStatusAllowed($status)) {
        return ['ok' => false, 'error' => 'Clinical note creation must start in draft status'];
    }

    $patient = app()->cap()->call('ehr.patient.view@1', ['id' => $patientId], ['caller_module' => 'clinical-notes']);
    $encounter = app()->cap()->call('ehr.encounter.view@1', ['id' => $encounterId], ['caller_module' => 'clinical-notes']);
    if (!is_array($patient) || empty($patient['ok']) || !is_array($encounter) || empty($encounter['ok'])) {
        return ['ok' => false, 'error' => 'Patient or encounter not found'];
    }

    if ((int)($encounter['encounter']['patient_id'] ?? 0) !== $patientId) {
        return ['ok' => false, 'error' => 'Encounter does not belong to patient'];
    }

    $authorUserId = cnResolveActorUserId($data);
    $noteUuid = ehcGenerateRecordKey('note');

    cnDb()->beginTransaction();
    try {
        cnDb()->execute(
            'INSERT INTO ehr_notes '
            . '(note_uuid, patient_id, encounter_id, note_type, current_version_id, author_user_id, authored_provider_id, status, signed_at, signed_by_user_id, cosign_required, restricted_flag, created_at, updated_at) '
            . 'VALUES (:note_uuid, :patient_id, :encounter_id, :note_type, NULL, :author_user_id, :authored_provider_id, :status, NULL, NULL, :cosign_required, :restricted_flag, NOW(), NOW())',
            [
                ':note_uuid' => $noteUuid,
                ':patient_id' => $patientId,
                ':encounter_id' => $encounterId,
                ':note_type' => $noteType,
                ':author_user_id' => $authorUserId,
                ':authored_provider_id' => isset($data['authored_provider_id']) ? (int)$data['authored_provider_id'] : null,
                ':status' => 'draft',
                ':cosign_required' => !empty($data['cosign_required']) ? 1 : 0,
                ':restricted_flag' => !empty($data['restricted_flag']) ? 1 : 0,
            ]
        );

        $noteId = (int)cnDb()->lastInsertId();
        $versionId = cnInsertVersion($noteId, 1, 'draft', $content, $authorUserId);
        cnDb()->execute(
            'UPDATE ehr_notes SET current_version_id = :current_version_id WHERE id = :id',
            [':current_version_id' => $versionId, ':id' => $noteId]
        );
        cnDb()->commit();
    } catch (\Throwable $e) {
        if (cnDb()->inTransaction()) {
            cnDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Note creation failed', 'details' => $e->getMessage()];
    }

    $note = cnFetchNote($noteId);
    ehcAudit('clinical-notes', 'ehr.note.created', 'ehr_note', $noteId, $note ?? []);
    app()->events()->fire('ehr.note.created', [
        'note_id' => $noteId,
        'note_uuid' => $noteUuid,
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
    ]);

    return ['ok' => true, 'note' => $note];
}

function clinical_notes_cap_ehr_note_view_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $note = cnFetchNote((int)($data['id'] ?? 0), trim((string)($data['note_uuid'] ?? '')));
    if (!$note) {
        return ['ok' => false, 'error' => 'Note not found'];
    }

    if (!empty($note['restricted_flag'])) {
        $decision = cnRestrictedAccessDecision($note);
        if (empty($decision['allowed'])) {
            $reason = (string)($decision['reason'] ?? 'restricted_note');
            cnEmitAuditEvent('ehr.note.access_denied', $note, [
                'attempted_action' => 'view',
                'denial_reason' => $reason,
            ]);

            return [
                'ok' => false,
                'error' => 'Note access denied',
                'reason' => $reason,
            ];
        }
    }

    cnEmitAuditEvent('ehr.note.viewed', $note);

    return ['ok' => true, 'note' => $note];
}

function clinical_notes_cap_ehr_note_sign_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $noteId = (int)($data['note_id'] ?? 0);
    $signReason = trim((string)($data['sign_reason'] ?? ''));
    if ($noteId <= 0) {
        return ['ok' => false, 'error' => 'note_id is required'];
    }

    $note = cnFetchNote($noteId);
    if (!$note) {
        return ['ok' => false, 'error' => 'Note not found'];
    }
    if ((string)($note['status'] ?? '') !== 'draft') {
        return ['ok' => false, 'error' => 'Only draft notes can be signed'];
    }

    $currentVersionId = (int)($note['current_version_id'] ?? 0);
    $currentVersion = null;
    foreach ($note['versions'] as $version) {
        if ((int)($version['id'] ?? 0) === $currentVersionId) {
            $currentVersion = $version;
            break;
        }
    }
    if (!is_array($currentVersion)) {
        return ['ok' => false, 'error' => 'Current note version not found'];
    }

    $content = [
        'body_text' => (string)($currentVersion['body_text'] ?? ''),
        'body_json' => is_array($currentVersion['body_json'] ?? null) ? $currentVersion['body_json'] : null,
    ];
    $actorUserId = cnResolveActorUserId($data);
    $lockedAt = date('Y-m-d H:i:s');

    cnDb()->beginTransaction();
    try {
        $versionId = cnInsertVersion(
            $noteId,
            cnNextVersionNo($noteId),
            'signed',
            $content,
            $actorUserId,
            $signReason !== '' ? $signReason : null,
            null,
            $currentVersionId > 0 ? $currentVersionId : null,
            $lockedAt
        );

        cnDb()->execute(
            'UPDATE ehr_notes SET current_version_id = :current_version_id, status = :status, signed_at = :signed_at, signed_by_user_id = :signed_by_user_id, updated_at = NOW() WHERE id = :id',
            [
                ':current_version_id' => $versionId,
                ':status' => 'signed',
                ':signed_at' => $lockedAt,
                ':signed_by_user_id' => $actorUserId,
                ':id' => $noteId,
            ]
        );
        cnDb()->commit();
    } catch (\Throwable $e) {
        if (cnDb()->inTransaction()) {
            cnDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Note signing failed', 'details' => $e->getMessage()];
    }

    $signedNote = cnFetchNote($noteId);
    ehcAudit('clinical-notes', 'ehr.note.signed', 'ehr_note', $noteId, $signedNote ?? [], $note);
    app()->events()->fire('ehr.note.signed', [
        'note_id' => $noteId,
        'patient_id' => (int)$note['patient_id'],
        'encounter_id' => (int)$note['encounter_id'],
    ]);

    return ['ok' => true, 'note' => $signedNote];
}

function clinical_notes_cap_ehr_note_amend_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $noteId = (int)($data['note_id'] ?? 0);
    $amendmentReason = trim((string)($data['amendment_reason'] ?? ''));
    $content = cnCanonicalNotePayload($data);

    if ($noteId <= 0) {
        return ['ok' => false, 'error' => 'note_id is required'];
    }
    if ($amendmentReason === '') {
        return ['ok' => false, 'error' => 'amendment_reason is required'];
    }
    if ($content['body_text'] === '' && $content['body_json'] === null) {
        return ['ok' => false, 'error' => 'body_text or body_json is required'];
    }

    $note = cnFetchNote($noteId);
    if (!$note) {
        return ['ok' => false, 'error' => 'Note not found'];
    }
    if ((string)($note['status'] ?? '') !== 'signed' && (string)($note['status'] ?? '') !== 'amended') {
        return ['ok' => false, 'error' => 'Only signed or amended notes can be amended'];
    }

    $actorUserId = cnResolveActorUserId($data);
    $lockedAt = date('Y-m-d H:i:s');
    $supersedesVersionId = (int)($note['current_version_id'] ?? 0);

    cnDb()->beginTransaction();
    try {
        $versionId = cnInsertVersion(
            $noteId,
            cnNextVersionNo($noteId),
            'amendment',
            $content,
            $actorUserId,
            null,
            $amendmentReason,
            $supersedesVersionId > 0 ? $supersedesVersionId : null,
            $lockedAt
        );

        cnDb()->execute(
            'UPDATE ehr_notes SET current_version_id = :current_version_id, status = :status, updated_at = NOW() WHERE id = :id',
            [
                ':current_version_id' => $versionId,
                ':status' => 'amended',
                ':id' => $noteId,
            ]
        );
        cnDb()->commit();
    } catch (\Throwable $e) {
        if (cnDb()->inTransaction()) {
            cnDb()->rollBack();
        }
        return ['ok' => false, 'error' => 'Note amendment failed', 'details' => $e->getMessage()];
    }

    $amendedNote = cnFetchNote($noteId);
    ehcAudit('clinical-notes', 'ehr.note.amended', 'ehr_note', $noteId, $amendedNote ?? [], $note);
    app()->events()->fire('ehr.note.amended', [
        'note_id' => $noteId,
        'patient_id' => (int)$note['patient_id'],
        'encounter_id' => (int)$note['encounter_id'],
    ]);

    return ['ok' => true, 'note' => $amendedNote];
}