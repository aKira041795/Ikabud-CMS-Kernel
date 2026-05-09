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

foreach (['ehr-core', 'patient-registry', 'encounters', 'documents', 'privacy-consent'] as $moduleId) {
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

function ehrDocAccessExecSqlFile(PDO $db, string $path): void
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

function ehrDocAccessDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Document Access Policy ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrDocAccessDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrDocAccessDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrDocAccessExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrDocAccessExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrDocAccessExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrDocAccessExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrDocAccessExecSqlFile($db, modulePathForId('documents') . '/database/migrations/001_initial.sql');
ehrDocAccessExecSqlFile($db, modulePathForId('privacy-consent') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Harriet',
    'last_name' => 'Tubman',
    'birth_date' => '1986-02-14',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-9201', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);
$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'reason_for_visit' => 'Protected chart review',
], ['caller_module' => 'encounters']);
$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$upload = app()->cap()->call('ehr.document.upload@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'storage_key' => 'ehr/patient-' . $patientId . '/confidential-001.pdf',
    'mime_type' => 'application/pdf',
    'title' => 'Confidential Behavioral Note',
    'document_type' => 'note',
], ['caller_module' => 'documents']);
$documentId = (int)($upload['document']['id'] ?? 0);
t('document upload succeeds', is_array($upload) && !empty($upload['ok']) && $documentId > 0, is_array($upload) ? json_encode($upload) : 'not array');

$restrictConsent = app()->cap()->call('ehr.document.restrict@1', [
    'document_id' => $documentId,
    'policy_type' => 'restricted-chart',
    'sensitivity_level' => 'restricted',
    'consent_required_flag' => true,
    'break_glass_only_flag' => false,
], ['caller_module' => 'documents']);
t('consent-based restriction succeeds', is_array($restrictConsent) && !empty($restrictConsent['ok']), is_array($restrictConsent) ? json_encode($restrictConsent) : 'not array');

$viewDeniedConsent = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document denied without consent', is_array($viewDeniedConsent) && empty($viewDeniedConsent['ok']) && ($viewDeniedConsent['reason'] ?? '') === 'consent_required', is_array($viewDeniedConsent) ? json_encode($viewDeniedConsent) : 'not array');

$printDeniedConsent = app()->cap()->call('ehr.document.print@1', ['id' => $documentId, 'print_format' => 'pdf'], ['caller_module' => 'documents']);
t('restricted document print denied without consent', is_array($printDeniedConsent) && empty($printDeniedConsent['ok']) && ($printDeniedConsent['reason'] ?? '') === 'consent_required', is_array($printDeniedConsent) ? json_encode($printDeniedConsent) : 'not array');

$exportDeniedConsent = app()->cap()->call('ehr.document.export@1', ['id' => $documentId, 'export_format' => 'ccd'], ['caller_module' => 'documents']);
t('restricted document export denied without consent', is_array($exportDeniedConsent) && empty($exportDeniedConsent['ok']) && ($exportDeniedConsent['reason'] ?? '') === 'consent_required', is_array($exportDeniedConsent) ? json_encode($exportDeniedConsent) : 'not array');

$consent = app()->cap()->call('ehr.consent.record@1', [
    'patient_id' => $patientId,
    'document_id' => $documentId,
    'consent_type' => 'release-of-information',
    'status' => 'granted',
], ['caller_module' => 'privacy-consent']);
t('document consent record succeeds', is_array($consent) && !empty($consent['ok']), is_array($consent) ? json_encode($consent) : 'not array');

$viewAllowedConsent = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document allowed with consent', is_array($viewAllowedConsent) && !empty($viewAllowedConsent['ok']), is_array($viewAllowedConsent) ? json_encode($viewAllowedConsent) : 'not array');

$restrictBreakGlass = app()->cap()->call('ehr.document.restrict@1', [
    'document_id' => $documentId,
    'policy_type' => 'restricted-chart',
    'sensitivity_level' => 'restricted',
    'consent_required_flag' => false,
    'break_glass_only_flag' => true,
], ['caller_module' => 'documents']);
t('break-glass restriction succeeds', is_array($restrictBreakGlass) && !empty($restrictBreakGlass['ok']), is_array($restrictBreakGlass) ? json_encode($restrictBreakGlass) : 'not array');

$viewDeniedBreakGlass = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document denied without break-glass', is_array($viewDeniedBreakGlass) && empty($viewDeniedBreakGlass['ok']) && ($viewDeniedBreakGlass['reason'] ?? '') === 'break_glass_required', is_array($viewDeniedBreakGlass) ? json_encode($viewDeniedBreakGlass) : 'not array');

$breakGlass = app()->cap()->call('ehr.break_glass.request@1', [
    'patient_id' => $patientId,
    'object_type' => 'document',
    'object_id' => (string)$documentId,
    'reason' => 'Emergency review',
], ['caller_module' => 'privacy-consent']);
t('break-glass request succeeds', is_array($breakGlass) && !empty($breakGlass['ok']), is_array($breakGlass) ? json_encode($breakGlass) : 'not array');

$viewAllowedBreakGlass = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document allowed with break-glass', is_array($viewAllowedBreakGlass) && !empty($viewAllowedBreakGlass['ok']), is_array($viewAllowedBreakGlass) ? json_encode($viewAllowedBreakGlass) : 'not array');

$deniedAuditStmt = $db->prepare(
    'SELECT new_data FROM audit_logs WHERE action = :action AND entity_type = :entity_type AND entity_id = :entity_id ORDER BY id ASC'
);
$deniedAuditStmt->execute([
    ':action' => 'ehr.document.access_denied',
    ':entity_type' => 'ehr_document',
    ':entity_id' => (string)$documentId,
]);
$deniedAuditRows = $deniedAuditStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
$deniedAuditPayloads = array_map(
    static fn($value): array => is_array($decoded = json_decode((string)$value, true)) ? $decoded : [],
    $deniedAuditRows
);
$deniedReasons = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['denial_reason'] ?? ''), $deniedAuditPayloads)));
$attemptedActions = array_values(array_filter(array_map(static fn(array $row): string => (string)($row['attempted_action'] ?? ''), $deniedAuditPayloads)));
t('denied access audited', count($deniedAuditPayloads) === 4, json_encode($deniedAuditPayloads));
t('denial reasons audited', in_array('consent_required', $deniedReasons, true) && in_array('break_glass_required', $deniedReasons, true), json_encode($deniedAuditPayloads));
t('attempted actions audited', in_array('view', $attemptedActions, true) && in_array('print', $attemptedActions, true) && in_array('export', $attemptedActions, true), json_encode($deniedAuditPayloads));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);