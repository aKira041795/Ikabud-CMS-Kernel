<?php
declare(strict_types=1);

$registryPath = __DIR__ . '/../storage/modules.json';
$originalRegistry = is_file($registryPath) ? (string)file_get_contents($registryPath) : null;
$registry = [];
if ($originalRegistry !== null && $originalRegistry !== '') {
    $decoded = json_decode($originalRegistry, true);
    if (is_array($decoded)) {
        $registry = $decoded;
    }
}

foreach (['ehr-core', 'patient-registry', 'encounters', 'clinical-notes', 'privacy-consent'] as $moduleId) {
    $entry = $registry[$moduleId] ?? [];
    if (!is_array($entry)) {
        $entry = [];
    }
    $entry['enabled'] = true;
    $registry[$moduleId] = $entry;
}

$dir = dirname($registryPath);
if (!is_dir($dir)) {
    @mkdir($dir, 0775, true);
}
file_put_contents($registryPath, json_encode($registry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);

register_shutdown_function(static function () use ($registryPath, $originalRegistry): void {
    if ($originalRegistry === null) {
        @unlink($registryPath);
        return;
    }
    file_put_contents($registryPath, $originalRegistry, LOCK_EX);
});

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

function ehrNoteAccessExecSqlFile(PDO $db, string $path): void
{
    $sql = trim((string)file_get_contents($path));
    if ($sql === '') {
        return;
    }
    $sql = preg_replace('/^--.*$/m', '', $sql) ?? $sql;
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }
}

function ehrNoteAccessDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Note Access Policy ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_note_versions', 'ehr_notes', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrNoteAccessDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_note_versions', 'ehr_notes', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrNoteAccessDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrNoteAccessExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrNoteAccessExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrNoteAccessExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrNoteAccessExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrNoteAccessExecSqlFile($db, modulePathForId('clinical-notes') . '/database/migrations/001_initial.sql');
ehrNoteAccessExecSqlFile($db, modulePathForId('privacy-consent') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

function createRestrictedNoteForPatient(string $mrn, string $firstName, string $lastName): array
{
    $patientResult = app()->cap()->call('ehr.patient.create@1', [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'birth_date' => '1988-04-12',
        'sex' => 'female',
        'identifiers' => [
            ['type' => 'mrn', 'value' => $mrn, 'is_primary' => true],
        ],
    ], ['caller_module' => 'patient-registry']);
    $patientId = (int)($patientResult['patient']['id'] ?? 0);

    $encounterResult = app()->cap()->call('ehr.encounter.create@1', [
        'patient_id' => $patientId,
        'encounter_type' => 'outpatient',
        'reason_for_visit' => 'Restricted note review',
    ], ['caller_module' => 'encounters']);
    $encounterId = (int)($encounterResult['encounter']['id'] ?? 0);

    $noteCreate = app()->cap()->call('ehr.note.create@1', [
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'note_type' => 'psychotherapy',
        'body_text' => 'Restricted session note.',
        'restricted_flag' => true,
        'actor_user_id' => 1701,
    ], ['caller_module' => 'clinical-notes']);

    return [
        'patient' => $patientResult,
        'encounter' => $encounterResult,
        'note' => $noteCreate,
        'patient_id' => $patientId,
        'encounter_id' => $encounterId,
        'note_id' => (int)($noteCreate['note']['id'] ?? 0),
    ];
}

$consentCase = createRestrictedNoteForPatient('MRN-9301', 'Clara', 'Barton');
t('restricted note create succeeds', !empty($consentCase['note']['ok']) && $consentCase['note_id'] > 0, json_encode($consentCase['note']));

$deniedConsent = app()->cap()->call('ehr.note.view@1', ['id' => $consentCase['note_id']], ['caller_module' => 'clinical-notes']);
t('restricted note denied without override', is_array($deniedConsent) && empty($deniedConsent['ok']) && ($deniedConsent['reason'] ?? '') === 'restricted_note', is_array($deniedConsent) ? json_encode($deniedConsent) : 'not array');

$consentRecord = app()->cap()->call('ehr.consent.record@1', [
    'patient_id' => $consentCase['patient_id'],
    'consent_type' => 'restricted-record-access',
    'status' => 'granted',
], ['caller_module' => 'privacy-consent']);
t('patient-scoped consent recorded', is_array($consentRecord) && !empty($consentRecord['ok']), is_array($consentRecord) ? json_encode($consentRecord) : 'not array');

$allowedConsent = app()->cap()->call('ehr.note.view@1', ['id' => $consentCase['note_id']], ['caller_module' => 'clinical-notes']);
t('restricted note allowed with consent', is_array($allowedConsent) && !empty($allowedConsent['ok']), is_array($allowedConsent) ? json_encode($allowedConsent) : 'not array');

$breakGlassCase = createRestrictedNoteForPatient('MRN-9302', 'Ida', 'B Wells');
t('second restricted note create succeeds', !empty($breakGlassCase['note']['ok']) && $breakGlassCase['note_id'] > 0, json_encode($breakGlassCase['note']));

$deniedBreakGlass = app()->cap()->call('ehr.note.view@1', ['id' => $breakGlassCase['note_id']], ['caller_module' => 'clinical-notes']);
t('second restricted note denied without override', is_array($deniedBreakGlass) && empty($deniedBreakGlass['ok']) && ($deniedBreakGlass['reason'] ?? '') === 'restricted_note', is_array($deniedBreakGlass) ? json_encode($deniedBreakGlass) : 'not array');

$breakGlass = app()->cap()->call('ehr.break_glass.request@1', [
    'patient_id' => $breakGlassCase['patient_id'],
    'object_type' => 'note',
    'object_id' => (string)$breakGlassCase['note_id'],
    'reason' => 'Emergency psychiatric review',
], ['caller_module' => 'privacy-consent']);
t('note break-glass request succeeds', is_array($breakGlass) && !empty($breakGlass['ok']), is_array($breakGlass) ? json_encode($breakGlass) : 'not array');

$allowedBreakGlass = app()->cap()->call('ehr.note.view@1', ['id' => $breakGlassCase['note_id']], ['caller_module' => 'clinical-notes']);
t('restricted note allowed with break-glass', is_array($allowedBreakGlass) && !empty($allowedBreakGlass['ok']), is_array($allowedBreakGlass) ? json_encode($allowedBreakGlass) : 'not array');

$auditStmt = $db->prepare('SELECT action, COUNT(*) AS aggregate_count FROM audit_logs WHERE entity_type = :entity_type GROUP BY action');
$auditStmt->execute([':entity_type' => 'ehr_note']);
$auditActions = $auditStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
t('note denials audited', (int)($auditActions['ehr.note.access_denied'] ?? 0) === 2, json_encode($auditActions));
t('note views audited', (int)($auditActions['ehr.note.viewed'] ?? 0) === 2, json_encode($auditActions));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);