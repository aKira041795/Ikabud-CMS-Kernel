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

foreach (['ehr-core', 'patient-registry', 'privacy-consent'] as $moduleId) {
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

function ehrPcExecSqlFile(PDO $db, string $path): void
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

function ehrPcDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Privacy Consent Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrPcDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrPcDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrPcExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrPcExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrPcExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrPcExecSqlFile($db, modulePathForId('privacy-consent') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('consent record capability registered', app()->capabilities()->has('ehr.consent.record@1'));
t('consent view capability registered', app()->capabilities()->has('ehr.consent.view@1'));
t('break-glass request capability registered', app()->capabilities()->has('ehr.break_glass.request@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Mary',
    'last_name' => 'Jackson',
    'birth_date' => '1982-05-14',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-5001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$consentRecord = app()->cap()->call('ehr.consent.record@1', [
    'patient_id' => $patientId,
    'consent_type' => 'release-of-information',
    'status' => 'granted',
    'scope' => ['target' => 'portal', 'documents' => ['lab', 'radiology']],
    'captured_by_user_id' => 1001,
], ['caller_module' => 'privacy-consent']);

t('consent record succeeds', is_array($consentRecord) && !empty($consentRecord['ok']), is_array($consentRecord) ? json_encode($consentRecord) : 'not array');
$consentId = (int)($consentRecord['consent']['id'] ?? 0);
t('consent scope persisted', (($consentRecord['consent']['scope_json']['target'] ?? '') === 'portal'));

$consentView = app()->cap()->call('ehr.consent.view@1', ['id' => $consentId], ['caller_module' => 'privacy-consent']);
t('consent view succeeds', is_array($consentView) && !empty($consentView['ok']) && (int)($consentView['consent']['id'] ?? 0) === $consentId);

$breakGlass = app()->cap()->call('ehr.break_glass.request@1', [
    'patient_id' => $patientId,
    'object_type' => 'patient',
    'object_id' => (string)$patientId,
    'reason' => 'Emergency department urgent access',
    'requested_by_user_id' => 1002,
    'duration_minutes' => 45,
    'request_context' => ['department' => 'ER', 'shift' => 'night']
], ['caller_module' => 'privacy-consent']);

t('break-glass request succeeds', is_array($breakGlass) && !empty($breakGlass['ok']), is_array($breakGlass) ? json_encode($breakGlass) : 'not array');
$eventId = (int)($breakGlass['event']['id'] ?? 0);
t('break-glass status active', (string)($breakGlass['event']['status'] ?? '') === 'active');
t('break-glass expiry set', !empty($breakGlass['event']['granted_until'] ?? null));

$breakGlassView = app()->cap()->call('ehr.break_glass.view@1', ['id' => $eventId], ['caller_module' => 'privacy-consent']);
t('break-glass view succeeds', is_array($breakGlassView) && !empty($breakGlassView['ok']) && (int)($breakGlassView['event']['id'] ?? 0) === $eventId);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);