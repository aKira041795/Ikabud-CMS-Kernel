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

foreach (['ehr-core', 'patient-registry', 'encounters', 'documents'] as $moduleId) {
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

function ehrDocExecSqlFile(PDO $db, string $path): void
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

function ehrDocDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Documents Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrDocDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrDocDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrDocExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrDocExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrDocExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrDocExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrDocExecSqlFile($db, modulePathForId('documents') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('document upload capability registered', app()->capabilities()->has('ehr.document.upload@1'));
t('document view capability registered', app()->capabilities()->has('ehr.document.view@1'));
t('document print capability registered', app()->capabilities()->has('ehr.document.print@1'));
t('document export capability registered', app()->capabilities()->has('ehr.document.export@1'));
t('document restrict capability registered', app()->capabilities()->has('ehr.document.restrict@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Katherine',
    'last_name' => 'Johnson',
    'birth_date' => '1983-08-26',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-4001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'reason_for_visit' => 'Follow-up imaging review',
], ['caller_module' => 'scheduling']);

$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$uploadResult = app()->cap()->call('ehr.document.upload@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'storage_key' => 'ehr/patient-' . $patientId . '/report-001.pdf',
    'mime_type' => 'application/pdf',
    'title' => 'Radiology Report',
    'document_type' => 'report',
    'file_size' => 204800,
    'source' => 'manual-upload',
    'tags' => ['radiology', 'follow-up'],
    'sensitivity_level' => 'standard',
    'uploaded_by_user_id' => 901,
], ['caller_module' => 'documents']);

t('document upload succeeds', is_array($uploadResult) && !empty($uploadResult['ok']), is_array($uploadResult) ? json_encode($uploadResult) : 'not array');
$documentId = (int)($uploadResult['document']['id'] ?? 0);
t('document has generated policy', (int)($uploadResult['document']['access_policy_id'] ?? 0) > 0);
t('document tags persisted', count($uploadResult['document']['tag_json'] ?? []) === 2);

$viewResult = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('document view succeeds', is_array($viewResult) && !empty($viewResult['ok']) && (int)($viewResult['document']['id'] ?? 0) === $documentId);

$printResult = app()->cap()->call('ehr.document.print@1', [
    'id' => $documentId,
    'print_format' => 'pdf',
], ['caller_module' => 'documents']);
t('document print succeeds', is_array($printResult) && !empty($printResult['ok']) && (string)($printResult['print_format'] ?? '') === 'pdf', is_array($printResult) ? json_encode($printResult) : 'not array');

$exportResult = app()->cap()->call('ehr.document.export@1', [
    'id' => $documentId,
    'export_format' => 'fhir-binary',
], ['caller_module' => 'documents']);
t('document export succeeds', is_array($exportResult) && !empty($exportResult['ok']) && (string)($exportResult['export_format'] ?? '') === 'fhir-binary', is_array($exportResult) ? json_encode($exportResult) : 'not array');

$restrictResult = app()->cap()->call('ehr.document.restrict@1', [
    'document_id' => $documentId,
    'policy_type' => 'restricted-chart',
    'sensitivity_level' => 'restricted',
    'department_scope' => ['radiology', 'medical-records'],
    'provider_scope' => ['provider-22'],
    'consent_required_flag' => true,
    'break_glass_only_flag' => true,
], ['caller_module' => 'documents']);

t('document restrict succeeds', is_array($restrictResult) && !empty($restrictResult['ok']), is_array($restrictResult) ? json_encode($restrictResult) : 'not array');
t('document sensitivity updated', (string)($restrictResult['document']['sensitivity_level'] ?? '') === 'restricted');
t('policy type updated', (string)($restrictResult['document']['policy']['policy_type'] ?? '') === 'restricted-chart');
t('break-glass flag set', (int)($restrictResult['document']['policy']['break_glass_only_flag'] ?? 0) === 1);
t('department scope persisted', count($restrictResult['document']['policy']['department_scope_json'] ?? []) === 2);

$auditStmt = $db->prepare(
    'SELECT action, COUNT(*) AS aggregate_count FROM audit_logs WHERE entity_type = :entity_type AND entity_id = :entity_id GROUP BY action'
);
$auditStmt->execute([
    ':entity_type' => 'ehr_document',
    ':entity_id' => (string)$documentId,
]);
$auditActions = $auditStmt->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
t('document print audited', (int)($auditActions['ehr.document.printed'] ?? 0) === 1, json_encode($auditActions));
t('document export audited', (int)($auditActions['ehr.document.exported'] ?? 0) === 1, json_encode($auditActions));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);