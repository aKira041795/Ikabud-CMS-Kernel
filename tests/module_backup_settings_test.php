<?php

declare(strict_types=1);

/**
 * Module settings must survive a backup.
 *
 * Module settings are NOT module tables: they live in the kernel table `tenant_module_settings`,
 * keyed (tenant_id, module_id, setting_key), so a prefix scan over the manifest's owns_tables cannot
 * reach them. Every backup taken before 2026-10-09 restored its dl_* rows and came back with the
 * module's whole configuration missing (feature flags, consignee settings, role permissions,
 * branding) while still looking complete. Measured on tenant 207: 25 rows, none ever carried.
 *
 * The dangerous direction is the one that must REFUSE:
 *   - a section aimed at a database the rest of the file does not target makes the WHOLE import fail
 *     with 1146, which is worse than a backup that merely lacks settings;
 *   - an unscoped DELETE would wipe another tenant's settings in a shared database;
 *   - without a tenant the rows cannot be scoped, so nothing may be written at all.
 *
 * Part A drives appendSettingsSection() directly against the real 25 rows (a memory stream, so it
 * needs no filesystem permissions). Part B runs the whole generate() end to end through a real file,
 * using a synthetic module id so the test owns its backup directory instead of writing into a
 * production one owned by the web user.
 */
ob_start();
require_once '/var/www/html/applicationostest/tests/harness/TestHarness.php';
$h = new TestHarness('module-backup-settings', TestHarness::MODE_INTEGRATION, 'baronledger.test');
ob_end_clean();
// generate() audits every backup it writes, by design. Declared rather than suppressed, so a warning
// or error mixed in with it still fails the run.
$h->allowLogLines('daily_ledger.backup.created');

$base = $h->basePath();
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/kernel/Services/ModuleBackupService.php';

use Ikabud\Kernel\Contracts\ModuleContext;
use Ikabud\Kernel\Contracts\ModuleDB;
use Ikabud\Kernel\Services\ModuleBackupService;

$tenantId = 207;
$escalation = 'Ikabud\Kernel\Database\KernelPDO';

app()->tenant()->setTenantId($tenantId);
$ctx = modulePushContext('daily-ledger');

/**
 * Read settings the way the kernel does. Once a module context is active, app()->db() is a KernelPDO
 * that enforces table access, so the kernel settings table is unreadable without escalation -- which
 * is exactly why the service must escalate to read it.
 */
$readSettings = function (string $moduleId) use ($tenantId, $escalation): array {
    $escalation::kernelEscalationEnter();
    try {
        $stmt = app()->db()->prepare(
            'SELECT setting_key, setting_value FROM tenant_module_settings '
            . 'WHERE tenant_id = :tid AND module_id = :mid ORDER BY setting_key'
        );
        $stmt->execute([':tid' => $tenantId, ':mid' => $moduleId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } finally {
        $escalation::kernelEscalationLeave();
    }
};

$deleteSettings = function (string $moduleId) use ($tenantId, $escalation): void {
    $escalation::kernelEscalationEnter();
    try {
        $stmt = app()->db()->prepare('DELETE FROM tenant_module_settings WHERE tenant_id = :tid AND module_id = :mid');
        $stmt->execute([':tid' => $tenantId, ':mid' => $moduleId]);
    } finally {
        $escalation::kernelEscalationLeave();
    }
};

$quote = new ReflectionMethod(ModuleBackupService::class, 'sqlQuote');
$append = new ReflectionMethod(ModuleBackupService::class, 'appendSettingsSection');

// ══ Part A: the real module's rows ═══════════════════════════════════════════
$dbSettings = $readSettings('daily-ledger');
$h->test(
    'daily-ledger has settings to carry at all (otherwise the assertions below are vacuous)',
    count($dbSettings) > 0,
    'rows=' . count($dbSettings)
);

$sink = fopen('php://memory', 'w+b');
$sectionResult = $append->invoke(null, $sink, $ctx, 'baronledger');
rewind($sink);
$section = (string) stream_get_contents($sink);

$h->test(
    'the settings section is written for the real module, with the real row count',
    ($sectionResult['included'] ?? false) === true && (int) ($sectionResult['rows'] ?? -1) === count($dbSettings),
    'result=' . json_encode($sectionResult) . ' db_rows=' . count($dbSettings)
);

// The DELETE must be tenant-scoped. An unscoped one would remove another tenant's settings from a
// shared database, so its absence is asserted directly rather than inferred from the scoped form.
$h->test(
    'the settings DELETE is scoped to this tenant AND this module',
    str_contains($section, 'DELETE FROM `tenant_module_settings` WHERE `tenant_id` = ' . $tenantId . " AND `module_id` = 'daily-ledger';")
        && !str_contains($section, 'DELETE FROM `tenant_module_settings` WHERE `module_id`')
        && !str_contains($section, 'DELETE FROM `tenant_module_settings`;'),
    'delete line: ' . (preg_match('/DELETE FROM `tenant_module_settings`[^;]*;/', $section, $m) === 1 ? $m[0] : '(none)')
);

// Value fidelity, row by row, built with the service's own quoting so the comparison is exact.
$expectedFor = static function (array $row) use ($tenantId, $quote): string {
    return '(' . $tenantId
        . ', ' . $quote->invoke(null, 'daily-ledger')
        . ', ' . $quote->invoke(null, $row['setting_key'])
        . ', ' . $quote->invoke(null, $row['setting_value']) . ')';
};
$missing = [];
foreach ($dbSettings as $row) {
    if (!str_contains($section, $expectedFor($row))) {
        $missing[] = (string) $row['setting_key'];
    }
}
$h->test(
    'every settings row is dumped with its exact stored value',
    $missing === [],
    'missing=' . json_encode($missing) . ' of ' . count($dbSettings)
);

// Falsifiability: the comparison above must be able to say no. Corrupt one value and require the same
// comparison to report it missing, or the assertion is decorative.
$corrupt = $dbSettings[0];
$corruptedSection = str_replace(
    $quote->invoke(null, $corrupt['setting_value']),
    $quote->invoke(null, '__corrupted__'),
    $section
);
$h->test(
    'that value comparison is falsifiable (a corrupted value is reported missing)',
    !str_contains($corruptedSection, $expectedFor($corrupt)),
    'key=' . (string) $corrupt['setting_key']
);

// ══ Part A: the refuses ══════════════════════════════════════════════════════
// A fresh stream per call: reusing one would carry the previous run's bytes over, which would make the
// "nothing was written" half of these asserts pass for the wrong reason.
$mismatchSink = fopen('php://memory', 'w+b');
$mismatched = $append->invoke(null, $mismatchSink, $ctx, 'some_other_database');
rewind($mismatchSink);
$mismatchedBytes = (string) stream_get_contents($mismatchSink);
$h->test(
    'MUST REFUSE: settings that live in another database are skipped, not written',
    ($mismatched['included'] ?? true) === false
        && $mismatchedBytes === ''
        && str_contains((string) ($mismatched['reason'] ?? ''), 'not in the dumped database'),
    'result=' . json_encode($mismatched) . ' bytes=' . strlen($mismatchedBytes)
);

$unknownSink = fopen('php://memory', 'w+b');
$unknown = $append->invoke(null, $unknownSink, $ctx, '');
rewind($unknownSink);
$unknownBytes = (string) stream_get_contents($unknownSink);
$h->test(
    'MUST REFUSE: an unknown target database is skipped, not written',
    ($unknown['included'] ?? true) === false && $unknownBytes === '',
    'result=' . json_encode($unknown) . ' bytes=' . strlen($unknownBytes)
);

// ══ Part B: end to end through generate(), with a real file ══════════════════
// A synthetic module id over the same manifest and database, so the test can create its own backup
// directory (storage/backups/daily-ledger belongs to the web user and must not be written into).
// The prefix limits the dump to the dl_consignee tables, so this stays small and quick.
$syntheticId = 'daily-ledger-test';
$backupDir = $base . '/storage/backups/' . $syntheticId;
$manifest = $ctx->manifest();
$owns = is_array($manifest['owns_tables'] ?? null) ? $manifest['owns_tables'] : [];
$reads = is_array($manifest['reads_tables'] ?? null) ? $manifest['reads_tables'] : [];
$synthetic = new ModuleContext(
    app(),
    $syntheticId,
    new ModuleDB(app()->db(), $syntheticId, $owns, $reads),
    $manifest
);

$generated = [];
$host = $_SERVER['HTTP_HOST'] ?? null;
$options = ['retention_days' => 30, 'event' => 'daily_ledger.backup.created', 'by_user' => 1];

try {
    saveTenantModuleSettings($syntheticId, ['zz_probe_text' => 'hello settings', 'zz_probe_nested' => ['a' => 1, 'b' => 'two']]);
    $seeded = $readSettings($syntheticId);
    $h->test(
        'two settings rows are seeded for the end-to-end run',
        count($seeded) === 2,
        'seeded=' . json_encode(array_column($seeded, 'setting_key'))
    );

    // Built from the values actually stored, so this stays exact even though the writer
    // JSON-encodes every value (a plain string is stored WITH its quotes).
    $seedExpectations = [];
    foreach ($seeded as $row) {
        $seedExpectations[(string) $row['setting_key']] = '(' . $tenantId
            . ', ' . $quote->invoke(null, $syntheticId)
            . ', ' . $quote->invoke(null, $row['setting_key'])
            . ', ' . $quote->invoke(null, $row['setting_value']) . ')';
    }

    $res = ModuleBackupService::generate($synthetic, 'dl_consignee', 'settings-carry-test', $options);
    $file = $backupDir . '/' . $res['file_name'];
    $generated[] = $file;
    $contents = (string) @file_get_contents($file);

    $missingSeeded = array_values(array_filter(
        $seedExpectations,
        static fn (string $expected): bool => !str_contains($contents, $expected)
    ));
    $h->test(
        'generate() carries the settings into the written backup file',
        is_file($file)
            && str_contains($contents, '-- Module settings: tenant_module_settings (rows: 2)')
            && $missingSeeded === [],
        'missing=' . json_encode($missingSeeded) . ' file=' . $res['file_name']
            . ' expectations=' . json_encode($seedExpectations)
    );
    $h->test(
        'generate() reports the carried settings separately from the data row count',
        ($res['settings']['included'] ?? false) === true
            && (int) ($res['settings']['rows'] ?? -1) === 2
            && (int) $res['total_rows'] > 0,
        'settings=' . json_encode($res['settings'] ?? null) . ' total_rows=' . ($res['total_rows'] ?? '?')
    );
    $h->test(
        'the settings section sits inside the FOREIGN_KEY_CHECKS guard',
        ($sectionAt = strpos($contents, '-- Module settings:')) !== false
            && ($fkAt = strpos($contents, 'SET FOREIGN_KEY_CHECKS=1;')) !== false
            && $sectionAt < $fkAt,
        'section_at=' . var_export($sectionAt ?? null, true) . ' fk_restore_at=' . var_export($fkAt ?? null, true)
    );

    // ── the restore direction ────────────────────────────────────────────────
    // Asserted by APPLYING the dumped SQL, not by reading it: a section that is present but cannot
    // restore is precisely the failure the owner hit (the backup looked complete and the settings were
    // gone). The whole thing runs in a transaction that is rolled back, so nothing it writes survives.
    $deleteStmt = null;
    $insertStmt = null;
    if (preg_match('/(DELETE FROM `tenant_module_settings`[^;]*;)/', $contents, $dm) === 1) {
        $deleteStmt = $dm[1];
    }
    if (preg_match('/(INSERT INTO `tenant_module_settings`[^;]*;)/s', $contents, $im) === 1) {
        $insertStmt = $im[1];
    }
    $h->test(
        'the dumped section yields a runnable DELETE and INSERT',
        $deleteStmt !== null && $insertStmt !== null,
        'delete=' . var_export($deleteStmt, true) . ' insert=' . (is_string($insertStmt) ? substr($insertStmt, 0, 80) : var_export($insertStmt, true))
    );

    $originalValue = null;
    $tamperedValue = null;
    $restoredValue = null;
    $applyError = '';
    if ($deleteStmt !== null && $insertStmt !== null) {
        $escalation::kernelEscalationEnter();
        try {
            $db = app()->db();
            $db->beginTransaction();
            try {
                $read = $db->prepare('SELECT setting_value FROM tenant_module_settings WHERE tenant_id = :tid AND module_id = :mid AND setting_key = :k');
                $read->execute([':tid' => $tenantId, ':mid' => $syntheticId, ':k' => 'zz_probe_text']);
                $originalValue = $read->fetchColumn();

                $db->exec("UPDATE tenant_module_settings SET setting_value = '\"tampered\"' WHERE tenant_id = " . $tenantId
                    . " AND module_id = '{$syntheticId}' AND setting_key = 'zz_probe_text'");
                $tamperedValue = 'read-back';
                $read->execute([':tid' => $tenantId, ':mid' => $syntheticId, ':k' => 'zz_probe_text']);
                $tamperedValue = $read->fetchColumn();

                $db->exec($deleteStmt);
                $db->exec($insertStmt);

                $read->execute([':tid' => $tenantId, ':mid' => $syntheticId, ':k' => 'zz_probe_text']);
                $restoredValue = $read->fetchColumn();
            } finally {
                $db->rollBack();
            }
        } catch (\Throwable $e) {
            $applyError = $e->getMessage();
        } finally {
            $escalation::kernelEscalationLeave();
        }
    }

    $h->test(
        'applying the dumped section RESTORES a tampered setting',
        $applyError === ''
            && $tamperedValue === '"tampered"'
            && $originalValue !== null
            && $restoredValue === $originalValue,
        'error=' . ($applyError !== '' ? substr($applyError, 0, 200) : '(none)')
            . ' original=' . var_export($originalValue, true)
            . ' tampered=' . var_export($tamperedValue, true)
            . ' restored=' . var_export($restoredValue, true)
    );
    $h->test(
        'and the restore proof left nothing behind (rolled back)',
        $applyError === '' && $readSettings($syntheticId) === $seeded,
        'rows_after=' . count($readSettings($syntheticId)) . ' expected=' . count($seeded)
    );

    // MUST REFUSE end to end: no tenant in the request context means the rows cannot be scoped, so the
    // section must be absent -- and the rest of the dump must still be complete and importable.
    unset($_SERVER['HTTP_HOST']);
    $noTenant = ModuleBackupService::generate($synthetic, 'dl_consignee', 'no-tenant-test', $options);
    $noTenantFile = $backupDir . '/' . $noTenant['file_name'];
    $generated[] = $noTenantFile;
    $noTenantContents = (string) @file_get_contents($noTenantFile);
    $h->test(
        'MUST REFUSE: without a tenant the section is omitted and the reason is reported',
        ($noTenant['settings']['included'] ?? true) === false
            && !str_contains($noTenantContents, '-- Module settings:')
            && !str_contains($noTenantContents, 'tenant_module_settings')
            && str_contains((string) ($noTenant['settings']['reason'] ?? ''), 'no tenant in the request context'),
        'settings=' . json_encode($noTenant['settings'] ?? null)
    );
    $h->test(
        'and that dump is still complete (a missing tenant must not break the backup)',
        $noTenant['total_rows'] > 0 && str_contains($noTenantContents, 'SET FOREIGN_KEY_CHECKS=1;'),
        'total_rows=' . ($noTenant['total_rows'] ?? '?')
    );
} finally {
    if ($host !== null) {
        $_SERVER['HTTP_HOST'] = $host;
    }
    foreach (array_unique($generated) as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    // ensureBackupDir() also writes a .htaccess into the directory it creates, so the directory is not
    // empty after the backups are removed.
    if (is_dir($backupDir)) {
        foreach (array_merge((array) glob($backupDir . '/*'), (array) glob($backupDir . '/.[!.]*')) as $leftover) {
            @unlink((string) $leftover);
        }
        @rmdir($backupDir);
    }
    $deleteSettings($syntheticId);
}

$h->test('end-to-end artifacts cleaned up', is_dir($backupDir) === false && $readSettings($syntheticId) === [], 'dir=' . $backupDir);

$h->done();
