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

foreach (['ehr-core', 'patient-registry', 'encounters', 'audit'] as $moduleId) {
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

function ehrAuditExecSqlFile(PDO $db, string $path): void
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

function ehrAuditDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Audit Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrAuditDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrAuditDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrAuditExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrAuditExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrAuditExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrAuditExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('audit search capability registered', app()->capabilities()->has('ehr.audit.search@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Florence',
    'last_name' => 'Nightingale',
    'birth_date' => '1981-08-22',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-7001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'service_line' => 'ambulatory',
    'reason_for_visit' => 'Chart review',
], ['caller_module' => 'encounters']);

$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$patientAudit = app()->cap()->call('ehr.audit.search@1', [
    'patient_id' => $patientId,
    'limit' => 10,
], ['caller_module' => 'audit']);

$patientEntries = is_array($patientAudit['entries'] ?? null) ? $patientAudit['entries'] : [];
$patientModules = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['module'] ?? ''), $patientEntries)));
t('patient audit search succeeds', is_array($patientAudit) && !empty($patientAudit['ok']) && count($patientEntries) >= 2, is_array($patientAudit) ? json_encode($patientAudit) : 'not array');
t('patient audit includes patient-registry event', in_array('patient-registry', $patientModules, true));
t('patient audit includes encounters event', in_array('encounters', $patientModules, true));
t('patient audit context exposes patient id', (($patientEntries[0]['context']['patient_id'] ?? 0) === $patientId), json_encode($patientEntries[0] ?? []));

$encounterAudit = app()->cap()->call('ehr.audit.search@1', [
    'encounter_id' => $encounterId,
    'limit' => 10,
], ['caller_module' => 'audit']);

$encounterEntries = is_array($encounterAudit['entries'] ?? null) ? $encounterAudit['entries'] : [];
t('encounter audit search succeeds', is_array($encounterAudit) && !empty($encounterAudit['ok']) && count($encounterEntries) >= 1, is_array($encounterAudit) ? json_encode($encounterAudit) : 'not array');
t('encounter audit resolves encounter id in context', (($encounterEntries[0]['context']['encounter_id'] ?? 0) === $encounterId), json_encode($encounterEntries[0] ?? []));

$moduleAudit = app()->cap()->call('ehr.audit.search@1', [
    'module' => 'encounters',
    'action' => 'ehr.encounter.started',
    'entity_type' => 'ehr_encounter',
    'entity_id' => (string)$encounterId,
], ['caller_module' => 'audit']);

$moduleEntries = is_array($moduleAudit['entries'] ?? null) ? $moduleAudit['entries'] : [];
t('module and entity filters succeed', is_array($moduleAudit) && !empty($moduleAudit['ok']) && count($moduleEntries) === 1, is_array($moduleAudit) ? json_encode($moduleAudit) : 'not array');
t('pagination total reported', (int)($moduleAudit['pagination']['total'] ?? 0) === 1, json_encode($moduleAudit['pagination'] ?? []));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);