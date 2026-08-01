<?php
declare(strict_types=1);

/**
 * Academic Suite — Assessment schema migrations (011 / 014 / 015).
 *
 * Covers: tenant-owned tables exist, foreign keys, the 015 index swap that
 * allows snapshot regeneration, and idempotent re-execution of the additive
 * migration files against a live tenant database.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../modules/academic_similarity/helpers.php';
require_once __DIR__ . '/../modules/academic_thesis_evaluation/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];
function t(string $description, bool $condition, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($condition) { $pass++; echo "  \033[32m✓\033[0m {$description}\n"; }
    else { $fail++; $errors[] = $description . ($detail ? " — {$detail}" : ''); echo "  \033[31m✗\033[0m {$description}" . ($detail ? " — {$detail}" : '') . "\n"; }
}

file_put_contents(__DIR__ . '/../storage/logs/app.log', '');
file_put_contents(__DIR__ . '/../storage/logs/error.log', '');

$tenantId = 'aiss.test';
$resolved = (int)academic_similarity_resolve_tenant_id($tenantId);
if ($resolved <= 0) {
    $resolved = (int)$tenantId;
}
$pdo = app()->dbForTenant($resolved);
$dbName = (string)($pdo->query('SELECT DATABASE()')->fetchColumn());

echo "\n=== Academic Suite — Assessment Migrations ===\n";

// ── 1. Idempotent re-execution ────────────────────────────────────
$migrations = [
    __DIR__ . '/../modules/academic_similarity/migrations/011_academic_similarity_document_assessment.sql',
    __DIR__ . '/../modules/academic_thesis_evaluation/migrations/014_evidence_suggestion_reviews.sql',
    __DIR__ . '/../modules/academic_thesis_evaluation/migrations/015_aiss_snapshot_regeneration.sql',
];
foreach ($migrations as $file) {
    $sql = file_get_contents($file);
    try {
        $pdo->exec($sql);
        t('idempotent re-run: ' . basename($file), true);
    } catch (\Throwable $e) {
        t('idempotent re-run: ' . basename($file), false, $e->getMessage());
    }
}

// ── 2. Tables exist and are tenant-owned ──────────────────────────
$aissTables = [
    'ac_similarity_assessment_runs',
    'ac_similarity_document_sections',
    'ac_similarity_research_claims',
    'ac_similarity_assessment_evidence',
    'ac_similarity_reviewer_suggestions',
];
foreach ($aissTables as $table) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl"
    );
    $stmt->execute([':db' => $dbName, ':tbl' => $table]);
    t("AISS table {$table} exists", (int)$stmt->fetchColumn() === 1);
}

$ateTable = 'ate_evidence_suggestion_reviews';
$stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl");
$stmt->execute([':db' => $dbName, ':tbl' => $ateTable]);
t("ATE table {$ateTable} exists", (int)$stmt->fetchColumn() === 1);

// ── 3. Foreign keys on the suggestion reviews table ───────────────
$fk = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = :db AND TABLE_NAME = :tbl AND CONSTRAINT_NAME = :name"
);
$fk->execute([':db' => $dbName, ':tbl' => $ateTable, ':name' => 'fk_suggestion_snapshot']);
t('fk_suggestion_snapshot present (evidence snapshot FK)', (int)$fk->fetchColumn() === 1);
$fk2 = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.REFERENTIAL_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = :db AND TABLE_NAME = :tbl AND CONSTRAINT_NAME = :name"
);
$fk2->execute([':db' => $dbName, ':tbl' => $ateTable, ':name' => 'fk_suggestion_case']);
t('fk_suggestion_case present (evaluation case FK)', (int)$fk2->fetchColumn() === 1);

// ── 4. 015 index swap: regeneration allowed ───────────────────────
$idx = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :name"
);
$idx->execute([':db' => $dbName, ':tbl' => 'ate_aiss_evidence_snapshots', ':name' => 'idx_snapshot_manuscript_version']);
t('015 adds idx_snapshot_manuscript_version (non-unique)', (int)$idx->fetchColumn() >= 1);
$uq = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :name"
);
$uq->execute([':db' => $dbName, ':tbl' => 'ate_aiss_evidence_snapshots', ':name' => 'uq_snapshot_manuscript']);
t('015 drops blocking uq_snapshot_manuscript', (int)$uq->fetchColumn() === 0);

// ── 5. Unique constraints that protect integrity ──────────────────
$uqRun = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :name"
);
$uqRun->execute([':db' => $dbName, ':tbl' => 'ac_similarity_assessment_runs', ':name' => 'uq_assessment_idempotency']);
t('assessment run idempotency unique key present', (int)$uqRun->fetchColumn() >= 1);

$uqSug = $pdo->prepare(
    "SELECT COUNT(*) FROM information_schema.STATISTICS
     WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tbl AND INDEX_NAME = :name"
);
$uqSug->execute([':db' => $dbName, ':tbl' => 'ac_similarity_reviewer_suggestions', ':name' => 'uq_suggestion_key']);
t('suggestion unique key present', (int)$uqSug->fetchColumn() >= 1);

// ── Log check ─────────────────────────────────────────────────────
$appLog = (string)@file_get_contents(__DIR__ . '/../storage/logs/app.log');
$errLog = (string)@file_get_contents(__DIR__ . '/../storage/logs/error.log');
t('no critical errors in app.log', !str_contains($appLog, '[critical]'));
t('error.log clean', trim($errLog) === '');

echo "\n──────────────────────────────────────────────────\n";
echo '  PASS: ' . $pass . '  FAIL: ' . $fail . "\n";
if ($fail > 0) {
    exit(1);
}
