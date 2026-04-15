<?php
/**
 * R31 — Full Workflow Lifecycle Test
 *
 * Verifies workflow: define→create instance→state query→transition→reject→rollback.
 * Requires DB connection.
 *
 * Run: php tests/workflow_lifecycle_test.php
 */

declare(strict_types=1);

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
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  ✗ {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

echo "=== Workflow Lifecycle ===\n";

$workflow = app()->workflow();
$testKey = 'test.lifecycle.' . getmypid();
$module = '_test';
$entityType = 'test_entity';
$entityId = 'entity_' . getmypid();

// ── Ensure workflow tables exist ──
try {
    app()->db()->exec(
        "CREATE TABLE IF NOT EXISTS workflow_definitions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workflow_key VARCHAR(128) NOT NULL,
            module VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            initial_state VARCHAR(64) NOT NULL DEFAULT 'draft',
            states_json JSON,
            transitions_json JSON,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_wf_def (workflow_key, module, entity_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    app()->db()->exec(
        "CREATE TABLE IF NOT EXISTS workflow_instances (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workflow_key VARCHAR(128) NOT NULL,
            module VARCHAR(64) NOT NULL,
            entity_type VARCHAR(64) NOT NULL,
            entity_id VARCHAR(128) NOT NULL,
            state VARCHAR(64) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT NULL,
            UNIQUE KEY uq_wf_inst (workflow_key, module, entity_type, entity_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
    app()->db()->exec(
        "CREATE TABLE IF NOT EXISTS workflow_transition_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            instance_id INT UNSIGNED NOT NULL,
            action VARCHAR(64) NOT NULL,
            from_state VARCHAR(64) NOT NULL,
            to_state VARCHAR(64) NOT NULL,
            actor_user_id INT UNSIGNED DEFAULT NULL,
            meta_json JSON,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );
} catch (\Throwable $e) {
    echo "SKIP: Cannot create workflow tables: {$e->getMessage()}\n";
    exit(0);
}

// ── Step 1: Define workflow ──
try {
    $workflow->ensureDefinition($testKey, $module, $entityType, 'draft', [
        ['key' => 'draft', 'label' => 'Draft'],
        ['key' => 'review', 'label' => 'In Review'],
        ['key' => 'approved', 'label' => 'Approved'],
        ['key' => 'published', 'label' => 'Published'],
    ], [
        ['from' => 'draft', 'action' => 'submit', 'to' => 'review', 'roles' => ['author', 'editor', 'superadmin']],
        ['from' => 'review', 'action' => 'approve', 'to' => 'approved', 'roles' => ['editor', 'superadmin']],
        ['from' => 'approved', 'action' => 'publish', 'to' => 'published', 'roles' => ['editor', 'superadmin']],
        ['from' => 'review', 'action' => 'reject', 'to' => 'draft', 'roles' => ['editor', 'superadmin']],
    ]);

    $def = $workflow->getDefinition($testKey, $module, $entityType);
    t('definition created', $def !== null && ($def['workflow_key'] ?? '') === $testKey);
    t('initial state is draft', ($def['initial_state'] ?? '') === 'draft');
} catch (\Throwable $e) {
    t('definition created', false, $e->getMessage());
}

// ── Step 2: Get or create instance ──
try {
    $inst = $workflow->getOrCreateInstance($testKey, $module, $entityType, $entityId, 'draft');
    t('instance created', $inst !== null);
    t('instance starts in draft', ($inst['state'] ?? '') === 'draft');
} catch (\Throwable $e) {
    t('instance created', false, $e->getMessage());
}

// ── Step 3: Query state ──
try {
    $state = $workflow->stateGet([
        'workflow_key' => $testKey,
        'module' => $module,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
    ]);
    t('stateGet returns ok', ($state['ok'] ?? false) === true);
    t('stateGet shows draft state', ($state['workflow']['state'] ?? '') === 'draft');
} catch (\Throwable $e) {
    t('stateGet returns ok', false, $e->getMessage());
}

// ── Step 4: Transition draft → review ──
try {
    $result = $workflow->transition([
        'workflow_key' => $testKey,
        'module' => $module,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => 'submit',
    ]);
    t('submit transition succeeds', ($result['ok'] ?? false) === true);
    t('submit goes draft→review', ($result['from_state'] ?? '') === 'draft' && ($result['to_state'] ?? '') === 'review');
} catch (\Throwable $e) {
    t('submit transition succeeds', false, $e->getMessage());
}

// ── Step 5: Reject review → draft ──
try {
    $reject = $workflow->transition([
        'workflow_key' => $testKey,
        'module' => $module,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => 'reject',
    ]);
    t('reject transition succeeds', ($reject['ok'] ?? false) === true);
    t('reject goes review→draft', ($reject['from_state'] ?? '') === 'review' && ($reject['to_state'] ?? '') === 'draft');
} catch (\Throwable $e) {
    t('reject transition succeeds', false, $e->getMessage());
}

// ── Step 6: Invalid transition from current state ──
try {
    $invalid = $workflow->transition([
        'workflow_key' => $testKey,
        'module' => $module,
        'entity_type' => $entityType,
        'entity_id' => $entityId,
        'action' => 'publish', // Can't publish from draft
    ]);
    t('invalid transition rejected', ($invalid['ok'] ?? true) === false);
} catch (\Throwable $e) {
    t('invalid transition rejected', true);
}

// ── Step 7: Full path: draft → review → approved → published ──
try {
    $workflow->transition(['workflow_key' => $testKey, 'module' => $module, 'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'submit']);
    $workflow->transition(['workflow_key' => $testKey, 'module' => $module, 'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'approve']);
    $pub = $workflow->transition(['workflow_key' => $testKey, 'module' => $module, 'entity_type' => $entityType, 'entity_id' => $entityId, 'action' => 'publish']);
    t('full lifecycle reaches published', ($pub['ok'] ?? false) === true && ($pub['to_state'] ?? '') === 'published');
} catch (\Throwable $e) {
    t('full lifecycle reaches published', false, $e->getMessage());
}

// ── Cleanup ──
try {
    app()->db()->prepare("DELETE FROM workflow_instances WHERE workflow_key = ? AND module = ?")->execute([$testKey, $module]);
    app()->db()->prepare("DELETE FROM workflow_definitions WHERE workflow_key = ? AND module = ?")->execute([$testKey, $module]);
} catch (\Throwable $ignored) {}

// ── Summary ──
echo "\n{$pass} passed, {$fail} failed\n";
if (!empty($errors)) {
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}
exit($fail > 0 ? 1 : 0);
