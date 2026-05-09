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

foreach (['ehr-core', 'patient-registry', 'encounters', 'clinical-notes'] as $moduleId) {
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

function ehrTestExecSqlFile(PDO $db, string $path): void
{
    $sql = trim((string)file_get_contents($path));
    if ($sql === '') {
        return;
    }
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql) ?: [];
    foreach ($statements as $statement) {
        $statement = trim($statement);
        if ($statement === '') {
            continue;
        }
        $db->exec($statement);
    }
}

function ehrDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Clinical Notes Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['ehr_note_versions', 'ehr_notes', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ehr_note_versions', 'ehr_notes', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrTestExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrTestExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrTestExecSqlFile($db, modulePathForId('clinical-notes') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('patient create capability registered', app()->capabilities()->has('ehr.patient.create@1'));
t('encounter create capability registered', app()->capabilities()->has('ehr.encounter.create@1'));
t('note create capability registered', app()->capabilities()->has('ehr.note.create@1'));
t('note sign capability registered', app()->capabilities()->has('ehr.note.sign@1'));
t('note amend capability registered', app()->capabilities()->has('ehr.note.amend@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Ada',
    'last_name' => 'Lovelace',
    'birth_date' => '1985-10-10',
    'sex' => 'female',
    'primary_phone' => '555-0100',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-1001', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);

t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']), is_array($patientResult) ? json_encode($patientResult) : 'not array');
$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient id returned', $patientId > 0);

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'service_line' => 'general-medicine',
    'reason_for_visit' => 'Follow-up consultation',
], ['caller_module' => 'scheduling']);

t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']), is_array($encounterResult) ? json_encode($encounterResult) : 'not array');
$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter linked to patient', (int)($encounterResult['encounter']['patient_id'] ?? 0) === $patientId);

$noteCreate = app()->cap()->call('ehr.note.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'note_type' => 'soap',
    'body_text' => "S: Patient feels better.\nO: Stable vitals.\nA: Improving.\nP: Continue plan.",
    'actor_user_id' => 501,
], ['caller_module' => 'clinical-notes']);

t('note create succeeds', is_array($noteCreate) && !empty($noteCreate['ok']), is_array($noteCreate) ? json_encode($noteCreate) : 'not array');
$noteId = (int)($noteCreate['note']['id'] ?? 0);
t('note starts in draft status', (string)($noteCreate['note']['status'] ?? '') === 'draft');
t('note has initial version', count($noteCreate['note']['versions'] ?? []) === 1);

$noteSign = app()->cap()->call('ehr.note.sign@1', [
    'note_id' => $noteId,
    'sign_reason' => 'Completed consultation note',
    'actor_user_id' => 502,
], ['caller_module' => 'clinical-notes']);

t('note sign succeeds', is_array($noteSign) && !empty($noteSign['ok']), is_array($noteSign) ? json_encode($noteSign) : 'not array');
t('note status becomes signed', (string)($noteSign['note']['status'] ?? '') === 'signed');
t('signed note has two versions', count($noteSign['note']['versions'] ?? []) === 2);

$signedVersions = $noteSign['note']['versions'] ?? [];
$signedVersion = is_array($signedVersions) ? end($signedVersions) : false;
t('latest signed version is locked', is_array($signedVersion) && (string)($signedVersion['version_kind'] ?? '') === 'signed' && !empty($signedVersion['locked_at']));

$noteAmend = app()->cap()->call('ehr.note.amend@1', [
    'note_id' => $noteId,
    'amendment_reason' => 'Added medication adherence detail',
    'body_text' => "S: Patient feels better and confirms medication adherence.\nO: Stable vitals.\nA: Improving.\nP: Continue plan.",
    'actor_user_id' => 503,
], ['caller_module' => 'clinical-notes']);

t('note amend succeeds', is_array($noteAmend) && !empty($noteAmend['ok']), is_array($noteAmend) ? json_encode($noteAmend) : 'not array');
t('note status becomes amended', (string)($noteAmend['note']['status'] ?? '') === 'amended');
t('amended note has three versions', count($noteAmend['note']['versions'] ?? []) === 3);

$versions = $noteAmend['note']['versions'] ?? [];
$latest = is_array($versions) ? end($versions) : false;
t('latest version is amendment', is_array($latest) && (string)($latest['version_kind'] ?? '') === 'amendment');
t('amendment reason persisted', is_array($latest) && (string)($latest['amendment_reason'] ?? '') === 'Added medication adherence detail');

$noteView = app()->cap()->call('ehr.note.view@1', ['id' => $noteId], ['caller_module' => 'clinical-notes']);
t('note view returns same note', is_array($noteView) && !empty($noteView['ok']) && (int)($noteView['note']['id'] ?? 0) === $noteId);

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);