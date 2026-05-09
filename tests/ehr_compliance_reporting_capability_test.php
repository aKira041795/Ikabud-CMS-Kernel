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

foreach (['ehr-core', 'patient-registry', 'encounters', 'clinical-notes', 'documents', 'orders', 'results', 'privacy-consent', 'reporting'] as $moduleId) {
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

function ehrCmpExecSqlFile(PDO $db, string $path): void
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

function ehrCmpDropIfExists(PDO $db, string $table): void
{
    $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
}

echo "=== EHR Compliance Reporting Capability ===\n\n";

$db = app()->db();
$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_note_versions', 'ehr_notes', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
    ehrCmpDropIfExists($db, $table);
}
$db->exec('SET FOREIGN_KEY_CHECKS=1');

register_shutdown_function(static function () use ($db): void {
    try {
        $db->exec('SET FOREIGN_KEY_CHECKS=0');
        foreach (['audit_logs', 'ehr_break_glass_events', 'ehr_consents', 'ehr_lab_results', 'ehr_order_items', 'ehr_orders', 'ehr_note_versions', 'ehr_notes', 'ehr_documents', 'ehr_access_policies', 'ehr_vitals', 'ehr_encounters', 'ehr_patient_identifiers', 'ehr_patients'] as $table) {
            ehrCmpDropIfExists($db, $table);
        }
        $db->exec('SET FOREIGN_KEY_CHECKS=1');
    } catch (Throwable $ignored) {
    }
});

ehrCmpExecSqlFile($db, __DIR__ . '/../database/migrations/007_kernel_runtime_tables.sql');
ehrCmpExecSqlFile($db, __DIR__ . '/../database/migrations/018_audit_logs_actor_columns_ensure.sql');
ehrCmpExecSqlFile($db, modulePathForId('patient-registry') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('encounters') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('clinical-notes') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('documents') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('orders') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('results') . '/database/migrations/001_initial.sql');
ehrCmpExecSqlFile($db, modulePathForId('results') . '/database/migrations/002_add_restricted_flag.sql');
ehrCmpExecSqlFile($db, modulePathForId('privacy-consent') . '/database/migrations/001_initial.sql');

loadModuleRoutes([]);

t('compliance reporting capability registered', app()->capabilities()->has('ehr.reporting.compliance@1'));

$patientResult = app()->cap()->call('ehr.patient.create@1', [
    'first_name' => 'Mary',
    'last_name' => 'Seacole',
    'birth_date' => '1984-10-03',
    'sex' => 'female',
    'identifiers' => [
        ['type' => 'mrn', 'value' => 'MRN-9101', 'is_primary' => true],
    ],
], ['caller_module' => 'patient-registry']);
$patientId = (int)($patientResult['patient']['id'] ?? 0);
t('patient create succeeds', is_array($patientResult) && !empty($patientResult['ok']) && $patientId > 0, is_array($patientResult) ? json_encode($patientResult) : 'not array');

$encounterResult = app()->cap()->call('ehr.encounter.create@1', [
    'patient_id' => $patientId,
    'encounter_type' => 'outpatient',
    'reason_for_visit' => 'Sensitive records review',
], ['caller_module' => 'encounters']);
$encounterId = (int)($encounterResult['encounter']['id'] ?? 0);
t('encounter create succeeds', is_array($encounterResult) && !empty($encounterResult['ok']) && $encounterId > 0, is_array($encounterResult) ? json_encode($encounterResult) : 'not array');

$upload = app()->cap()->call('ehr.document.upload@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'storage_key' => 'ehr/patient-' . $patientId . '/psych-note-001.pdf',
    'mime_type' => 'application/pdf',
    'title' => 'Psychiatry Consult',
    'document_type' => 'consult-note',
], ['caller_module' => 'documents']);
$documentId = (int)($upload['document']['id'] ?? 0);
t('document upload succeeds', is_array($upload) && !empty($upload['ok']) && $documentId > 0, is_array($upload) ? json_encode($upload) : 'not array');

$restrict = app()->cap()->call('ehr.document.restrict@1', [
    'document_id' => $documentId,
    'policy_type' => 'restricted-chart',
    'sensitivity_level' => 'restricted',
    'consent_required_flag' => true,
    'break_glass_only_flag' => false,
], ['caller_module' => 'documents']);
t('document restrict succeeds', is_array($restrict) && !empty($restrict['ok']), is_array($restrict) ? json_encode($restrict) : 'not array');

$deniedView = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document denial recorded', is_array($deniedView) && empty($deniedView['ok']) && ($deniedView['reason'] ?? '') === 'consent_required', is_array($deniedView) ? json_encode($deniedView) : 'not array');

$deniedPrint = app()->cap()->call('ehr.document.print@1', ['id' => $documentId, 'print_format' => 'pdf'], ['caller_module' => 'documents']);
t('restricted document print denial recorded', is_array($deniedPrint) && empty($deniedPrint['ok']) && ($deniedPrint['reason'] ?? '') === 'consent_required', is_array($deniedPrint) ? json_encode($deniedPrint) : 'not array');

$deniedExport = app()->cap()->call('ehr.document.export@1', ['id' => $documentId, 'export_format' => 'ccd'], ['caller_module' => 'documents']);
t('restricted document export denial recorded', is_array($deniedExport) && empty($deniedExport['ok']) && ($deniedExport['reason'] ?? '') === 'consent_required', is_array($deniedExport) ? json_encode($deniedExport) : 'not array');

$noteCreate = app()->cap()->call('ehr.note.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'note_type' => 'behavioral-health',
    'body_text' => 'Restricted psychotherapy note.',
    'restricted_flag' => true,
    'actor_user_id' => 1401,
], ['caller_module' => 'clinical-notes']);
$noteId = (int)($noteCreate['note']['id'] ?? 0);
t('restricted note create succeeds', is_array($noteCreate) && !empty($noteCreate['ok']) && $noteId > 0, is_array($noteCreate) ? json_encode($noteCreate) : 'not array');

$noteDenied = app()->cap()->call('ehr.note.view@1', ['id' => $noteId], ['caller_module' => 'clinical-notes']);
t('restricted note denial recorded', is_array($noteDenied) && empty($noteDenied['ok']) && ($noteDenied['reason'] ?? '') === 'restricted_note', is_array($noteDenied) ? json_encode($noteDenied) : 'not array');

$orderCreate = app()->cap()->call('ehr.order.create@1', [
    'patient_id' => $patientId,
    'encounter_id' => $encounterId,
    'order_type' => 'lab',
    'items' => [
        [
            'item_code' => 'TSH',
            'code_system' => 'local',
            'item_label' => 'TSH',
        ],
    ],
], ['caller_module' => 'orders']);
$orderId = (int)($orderCreate['order']['id'] ?? 0);
$orderItemId = (int)($orderCreate['order']['items'][0]['id'] ?? 0);
t('restricted result order create succeeds', is_array($orderCreate) && !empty($orderCreate['ok']) && $orderId > 0 && $orderItemId > 0, is_array($orderCreate) ? json_encode($orderCreate) : 'not array');

$resultEnter = app()->cap()->call('ehr.result.enter@1', [
    'order_id' => $orderId,
    'order_item_id' => $orderItemId,
    'value_numeric' => 2.1,
    'unit' => 'mIU/L',
    'restricted_flag' => true,
    'entered_by_user_id' => 1402,
], ['caller_module' => 'results']);
$resultId = (int)($resultEnter['result']['id'] ?? 0);
t('restricted result create succeeds', is_array($resultEnter) && !empty($resultEnter['ok']) && $resultId > 0, is_array($resultEnter) ? json_encode($resultEnter) : 'not array');

$resultDenied = app()->cap()->call('ehr.result.view@1', ['id' => $resultId], ['caller_module' => 'results']);
t('restricted result denial recorded', is_array($resultDenied) && empty($resultDenied['ok']) && ($resultDenied['reason'] ?? '') === 'restricted_result', is_array($resultDenied) ? json_encode($resultDenied) : 'not array');

$consent = app()->cap()->call('ehr.consent.record@1', [
    'patient_id' => $patientId,
    'consent_type' => 'release-of-information',
    'status' => 'granted',
], ['caller_module' => 'privacy-consent']);
t('document consent record succeeds', is_array($consent) && !empty($consent['ok']), is_array($consent) ? json_encode($consent) : 'not array');

$view = app()->cap()->call('ehr.document.view@1', ['id' => $documentId], ['caller_module' => 'documents']);
t('restricted document view succeeds', is_array($view) && !empty($view['ok']), is_array($view) ? json_encode($view) : 'not array');

$print = app()->cap()->call('ehr.document.print@1', [
    'id' => $documentId,
    'print_format' => 'pdf',
], ['caller_module' => 'documents']);
t('restricted document print succeeds', is_array($print) && !empty($print['ok']) && (string)($print['print_format'] ?? '') === 'pdf', is_array($print) ? json_encode($print) : 'not array');

$export = app()->cap()->call('ehr.document.export@1', [
    'id' => $documentId,
    'export_format' => 'ccd',
], ['caller_module' => 'documents']);
t('restricted document export succeeds', is_array($export) && !empty($export['ok']) && (string)($export['export_format'] ?? '') === 'ccd', is_array($export) ? json_encode($export) : 'not array');

$noteView = app()->cap()->call('ehr.note.view@1', ['id' => $noteId], ['caller_module' => 'clinical-notes']);
t('restricted note view succeeds', is_array($noteView) && !empty($noteView['ok']), is_array($noteView) ? json_encode($noteView) : 'not array');

$resultView = app()->cap()->call('ehr.result.view@1', ['id' => $resultId], ['caller_module' => 'results']);
t('restricted result view succeeds', is_array($resultView) && !empty($resultView['ok']), is_array($resultView) ? json_encode($resultView) : 'not array');

$breakGlass = app()->cap()->call('ehr.break_glass.request@1', [
    'patient_id' => $patientId,
    'object_type' => 'document',
    'object_id' => (string)$documentId,
    'reason' => 'Urgent after-hours access',
    'requested_by_user_id' => 1201,
], ['caller_module' => 'privacy-consent']);
t('break-glass request succeeds', is_array($breakGlass) && !empty($breakGlass['ok']), is_array($breakGlass) ? json_encode($breakGlass) : 'not array');

$portalActorSql = 'UPDATE audit_logs SET actor_source = :actor_source, actor_module_user_id = :actor_module_user_id '
    . 'WHERE entity_type = :entity_type AND entity_id = :entity_id '
    . 'AND action IN (:denied, :viewed, :printed, :exported)';
$portalActorStmt = $db->prepare($portalActorSql);
$portalActorStmt->execute([
    ':actor_source' => 'patient-portal',
    ':actor_module_user_id' => 501,
    ':entity_type' => 'ehr_document',
    ':entity_id' => (string)$documentId,
    ':denied' => 'ehr.document.access_denied',
    ':viewed' => 'ehr.document.viewed',
    ':printed' => 'ehr.document.printed',
    ':exported' => 'ehr.document.exported',
]);

$kernelActorStmt = $db->prepare(
    'UPDATE audit_logs SET actor_source = :actor_source, actor_user_id = :actor_user_id WHERE entity_type = :entity_type AND entity_id = :entity_id AND action = :action'
);
$kernelActorStmt->execute([
    ':actor_source' => 'kernel',
    ':actor_user_id' => 77,
    ':entity_type' => 'ehr_document',
    ':entity_id' => (string)$documentId,
    ':action' => 'ehr.document.restricted',
]);

$report = app()->cap()->call('ehr.reporting.compliance@1', [
    'patient_id' => $patientId,
    'limit' => 20,
], ['caller_module' => 'reporting']);

$summary = is_array($report['summary'] ?? null) ? $report['summary'] : [];
$entries = is_array($report['entries'] ?? null) ? $report['entries'] : [];
$categories = array_values(array_map(static fn(array $row): string => (string)($row['category'] ?? ''), $entries));

$portalReport = app()->cap()->call('ehr.reporting.compliance@1', [
    'patient_id' => $patientId,
    'actor_source' => 'patient-portal',
    'actor_module_user_id' => 501,
    'limit' => 20,
], ['caller_module' => 'reporting']);
$portalSummary = is_array($portalReport['summary'] ?? null) ? $portalReport['summary'] : [];
$portalEntries = is_array($portalReport['entries'] ?? null) ? $portalReport['entries'] : [];
$portalCategories = array_values(array_map(static fn(array $row): string => (string)($row['category'] ?? ''), $portalEntries));

$kernelReport = app()->cap()->call('ehr.reporting.compliance@1', [
    'patient_id' => $patientId,
    'actor_source' => 'kernel',
    'actor_user_id' => 77,
    'limit' => 20,
], ['caller_module' => 'reporting']);
$kernelSummary = is_array($kernelReport['summary'] ?? null) ? $kernelReport['summary'] : [];
$kernelEntries = is_array($kernelReport['entries'] ?? null) ? $kernelReport['entries'] : [];
$kernelCategories = array_values(array_map(static fn(array $row): string => (string)($row['category'] ?? ''), $kernelEntries));

t('compliance report succeeds', is_array($report) && !empty($report['ok']) && count($entries) === 12, is_array($report) ? json_encode($report) : 'not array');
t('denied access counted', (int)($summary['denied_access_events'] ?? 0) === 5, json_encode($summary));
t('denied view counted', (int)($summary['denied_view_events'] ?? 0) === 3, json_encode($summary));
t('denied print counted', (int)($summary['denied_print_events'] ?? 0) === 1, json_encode($summary));
t('denied export counted', (int)($summary['denied_export_events'] ?? 0) === 1, json_encode($summary));
t('restricted view counted', (int)($summary['restricted_record_views'] ?? 0) === 3 && (int)($summary['restricted_document_views'] ?? 0) === 1 && (int)($summary['restricted_note_views'] ?? 0) === 1 && (int)($summary['restricted_result_views'] ?? 0) === 1, json_encode($summary));
t('print counted', (int)($summary['print_events'] ?? 0) === 1, json_encode($summary));
t('export counted', (int)($summary['export_events'] ?? 0) === 1, json_encode($summary));
t('policy change counted', (int)($summary['restricted_policy_changes'] ?? 0) === 1, json_encode($summary));
t('break-glass counted', (int)($summary['break_glass_events'] ?? 0) === 1, json_encode($summary));
t('compliance categories present', in_array('restricted_document_view_denial', $categories, true) && in_array('restricted_note_view_denial', $categories, true) && in_array('restricted_result_view_denial', $categories, true) && in_array('restricted_document_print_denial', $categories, true) && in_array('restricted_document_export_denial', $categories, true) && in_array('restricted_document_access', $categories, true) && in_array('restricted_note_access', $categories, true) && in_array('restricted_result_access', $categories, true) && in_array('record_print', $categories, true) && in_array('record_export', $categories, true) && in_array('restricted_policy_change', $categories, true) && in_array('break_glass', $categories, true), json_encode($entries));
t('portal actor filter succeeds', is_array($portalReport) && !empty($portalReport['ok']) && count($portalEntries) === 6, is_array($portalReport) ? json_encode($portalReport) : 'not array');
t('portal actor denied count filtered', (int)($portalSummary['denied_access_events'] ?? 0) === 3, json_encode($portalSummary));
t('portal actor allowed activity filtered', (int)($portalSummary['restricted_record_views'] ?? 0) === 1 && (int)($portalSummary['restricted_document_views'] ?? 0) === 1 && (int)($portalSummary['print_events'] ?? 0) === 1 && (int)($portalSummary['export_events'] ?? 0) === 1, json_encode($portalSummary));
t('portal actor categories filtered', in_array('restricted_document_view_denial', $portalCategories, true) && in_array('restricted_document_print_denial', $portalCategories, true) && in_array('restricted_document_export_denial', $portalCategories, true) && in_array('restricted_document_access', $portalCategories, true) && in_array('record_print', $portalCategories, true) && in_array('record_export', $portalCategories, true), json_encode($portalEntries));
t('kernel actor filter succeeds', is_array($kernelReport) && !empty($kernelReport['ok']) && count($kernelEntries) === 1, is_array($kernelReport) ? json_encode($kernelReport) : 'not array');
t('kernel actor summary filtered', (int)($kernelSummary['restricted_policy_changes'] ?? 0) === 1 && (int)($kernelSummary['total_sensitive_events'] ?? 0) === 1, json_encode($kernelSummary));
t('kernel actor category filtered', count($kernelCategories) === 1 && ($kernelCategories[0] ?? '') === 'restricted_policy_change', json_encode($kernelEntries));

echo "\n{$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "Failures:\n";
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
}

exit($fail > 0 ? 1 : 0);