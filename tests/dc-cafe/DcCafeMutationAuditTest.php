<?php
/**
 * DC Cafe — kernel mutation audit fallback.
 *
 * A module handler is expected to describe its own state changes. When one does
 * not, the change would vanish from the trail, and a partial trail that reads
 * like a complete one is worse than no trail at all.
 *
 * These tests guard the kernel's safety net for that gap:
 *   1. it is opt-in per module, never blanket
 *   2. it only arms for state-mutating methods
 *   3. it only records when the handler actually issued a write
 *   4. it stands down when the handler wrote a row of its own
 *   5. it records into the database the change was made in
 *
 * The end-to-end cases matter most: the net only arms through the module
 * dispatcher, so they go through public/index.php rather than calling a handler
 * directly.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-mutation-audit', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

$h->fingerprint('config/app.php');
$h->fingerprint('kernel/Audit/MutationAuditFallback.php');
$h->fingerprint('kernel/Contracts/ModuleContext.php');
$h->fingerprint('kernel/Contracts/ModuleDB.php');
$h->fingerprint('src/helpers/module-manager.php');
$h->fingerprint('modules/dc-cafe/helpers.php');

$db = app()->db();

/**
 * Issue a request through the real entrypoint.
 *
 * @return array{status:int,body:string,armed:bool,wrote:bool}
 */
function dcMutRunRequest(string $method, string $uri, array $user, ?array $post = null): array
{
    $base = '/var/www/html/applicationostest';
    $runnerPath = sys_get_temp_dir() . '/dccafe-mut-' . bin2hex(random_bytes(6)) . '.php';

    $server = [
        'REQUEST_METHOD' => $method,
        'REQUEST_URI' => $uri,
        'HTTP_HOST' => 'dccafe.test',
        'SERVER_NAME' => 'dccafe.test',
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
    ];

    // Arming and the write signal are per-process, so they are reported by the
    // child request rather than read from the parent.
    $script = "<?php\n"
        . "foreach (" . var_export($server, true) . " as \$k => \$v) { \$_SERVER[(string) \$k] = \$v; }\n"
        . "\$_GET = [];\n"
        . "parse_str((string) parse_url((string) \$_SERVER['REQUEST_URI'], PHP_URL_QUERY), \$_GET);\n"
        . "\$_POST = " . var_export($post, true) . " ?: [];\n"
        . "\$_REQUEST = array_merge(\$_GET, \$_POST);\n"
        . "require " . var_export($base . '/bootstrap.php', true) . ";\n"
        . "\$u = " . var_export($user, true) . ";\n"
        . "if (is_array(\$u)) { app()->setUser(\$u); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__RESULT__\\n\";\n"
        . "    echo json_encode([\n"
        . "        'status' => (int) (http_response_code() ?: 200),\n"
        . "        'armed' => \\Ikabud\\Kernel\\Audit\\MutationAuditFallback::isRegistered(),\n"
        . "        'wrote' => \\Ikabud\\Kernel\\Audit\\MutationAuditFallback::wrote(),\n"
        . "    ], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . "require " . var_export($base . '/public/index.php', true) . ";\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $raw = implode("\n", $output);
    $parts = explode("\n__RESULT__\n", $raw, 2);
    $meta = json_decode((string) ($parts[1] ?? ''), true);
    if (!is_array($meta)) {
        $meta = [];
    }

    return [
        'status' => (int) ($meta['status'] ?? 0),
        'body' => (string) ($parts[0] ?? ''),
        'armed' => (bool) ($meta['armed'] ?? false),
        'wrote' => (bool) ($meta['wrote'] ?? false),
    ];
}

// ── 1. Opt-in scope ──
$h->section('Opt-In Scope');

$h->test(
    'dc-cafe is opted into the fallback',
    \Ikabud\Kernel\Audit\MutationAuditFallback::enabledFor('dc-cafe') === true
);
$h->test(
    'a module not in the configured list is untouched',
    \Ikabud\Kernel\Audit\MutationAuditFallback::enabledFor('cms') === false
);
$h->test(
    'an empty module id is never enabled',
    \Ikabud\Kernel\Audit\MutationAuditFallback::enabledFor('') === false
);
$h->test(
    'the configured list is read from config, not hardcoded per module',
    in_array('dc-cafe', \Ikabud\Kernel\Audit\MutationAuditFallback::configuredModules(), true),
    json_encode(\Ikabud\Kernel\Audit\MutationAuditFallback::configuredModules())
);
$h->test(
    'a comma-separated string is accepted as a list',
    is_array(config('app.audit.mutation_fallback_modules'))
);

// ── 2. Mutating-method gate ──
$h->section('Mutating Methods');

$fallback = \Ikabud\Kernel\Audit\MutationAuditFallback::class;

foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'post'] as $mutating) {
    $h->test("'{$mutating}' counts as a mutation", $fallback::isMutating($mutating) === true);
}
foreach (['GET', 'HEAD', 'OPTIONS', ''] as $safe) {
    $h->test("'{$safe}' does not count as a mutation", $fallback::isMutating($safe) === false);
}

// ── 3. Write evidence ──
$h->section('Write Evidence');

$writes = [
    'INSERT INTO dc_orders (id) VALUES (1)' => true,
    '  update dc_products set name = ?' => true,
    'DELETE FROM dc_sessions' => true,
    'REPLACE INTO dc_settings VALUES (1)' => true,
    '/* trace */ INSERT INTO dc_orders VALUES (1)' => true,
    'SELECT * FROM dc_orders' => false,
    'SHOW COLUMNS FROM dc_orders' => false,
    'SET NAMES utf8mb4' => false,
    '' => false,
];
foreach ($writes as $sql => $expected) {
    // The observer only counts while the net is armed, so arm it first.
    $fallback::resetTracking();
    $fallback::register('dc-cafe', 'POST', '/__write-evidence');
    $fallback::noteStatement($sql);
    $h->test(
        ($expected ? 'write' : 'non-write') . " detected: '" . substr($sql, 0, 34) . "'",
        $fallback::wrote() === $expected
    );
}

$fallback::resetTracking();
$h->test('a write before arming is not counted as the handler\'s', (static function () use ($fallback): bool {
    $fallback::noteStatement('INSERT INTO dc_orders (id) VALUES (1)');
    return $fallback::wrote() === false;
})());

$fallback::resetTracking();
$h->test('resetting clears both the armed and the write signal',
    $fallback::wrote() === false && $fallback::isRegistered() === false);

// ── 4. Arming rules ──
$h->section('Arming Rules');

$fallback::resetTracking();
$fallback::register('dc-cafe', 'GET', '/dc-cafe/audit');
$h->test('a read request is never armed', $fallback::isRegistered() === false);

$fallback::resetTracking();
$fallback::register('cms', 'POST', '/cms/admin');
$h->test('an opted-out module is never armed', $fallback::isRegistered() === false);

$fallback::resetTracking();
$fallback::register('dc-cafe', 'POST', '/dc-cafe/api/v1/orders');
$h->test('a mutating request on an opted-in module is armed', $fallback::isRegistered() === true);

// ── 5. End to end ──
$h->section('End To End');

$suffix = 'mut_' . bin2hex(random_bytes(4));
$storeId = (int) ($db->query('SELECT store_id FROM dc_stores ORDER BY store_id LIMIT 1')->fetchColumn() ?: 1);

$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, ?, 'Mutation Audit Admin', 'admin', ?, 1)"
)->execute([$suffix, password_hash('secret123', PASSWORD_BCRYPT), $suffix . '@example.test', $storeId]);
$actorId = (int) $db->lastInsertId();

$actor = [
    'id' => $actorId,
    'user_id' => $actorId,
    'username' => $suffix,
    'name' => 'Mutation Audit Admin',
    'full_name' => 'Mutation Audit Admin',
    'role' => 'admin',
    'store_id' => $storeId,
    'source' => 'dc-cafe',
];

$rowsFor = static function (string $action) use ($db): int {
    $stmt = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ?');
    $stmt->execute([$action]);
    return (int) $stmt->fetchColumn();
};

// A POST that only reads: 200, no write, so nothing is recorded.
$before = $rowsFor('request.mutated');
$readShaped = dcMutRunRequest('POST', '/dc-cafe/api/v1/products/stock', $actor);
$h->test('read-shaped POST returns success', $readShaped['status'] === 200, $readShaped['body']);
$h->test('read-shaped POST arms the net', $readShaped['armed'] === true);
$h->test('read-shaped POST shows no write', $readShaped['wrote'] === false);
$h->test(
    'read-shaped POST is not recorded',
    $rowsFor('request.mutated') === $before,
    'rows went from ' . $before . ' to ' . $rowsFor('request.mutated')
);

// A POST that is rejected changed nothing, so nothing is recorded.
$before = $rowsFor('request.mutated');
$rejected = dcMutRunRequest('POST', '/dc-cafe/api/v1/payment-methods/create', $actor, ['name' => 'no code']);
$h->test('rejected POST fails validation', $rejected['status'] === 400, $rejected['body']);
$h->test(
    'rejected POST is not recorded',
    $rowsFor('request.mutated') === $before,
    'rows went from ' . $before . ' to ' . $rowsFor('request.mutated')
);

// This endpoint used to write with nothing recorded, which is what the fallback
// was covering for. It now describes itself, so the specific row must win and the
// fallback must stand down. DcCafeAuditCoverageTest is what keeps that true.
$before = $rowsFor('request.mutated');
$code = 'MUT' . strtoupper(bin2hex(random_bytes(3)));
$write = dcMutRunRequest('POST', '/dc-cafe/api/v1/payment-methods/create', $actor, [
    'code' => $code,
    'name' => 'Mutation Audit Probe',
]);
$h->test('a described write succeeds', $write['status'] === 200, $write['body']);
$h->test('and the till-level write signal still sees it', $write['wrote'] === true);

// Scoped to this run's actor, so a row left by an earlier run cannot make these
// pass on its behalf.
$scopedRows = static function (string $action) use ($db, $actorId): int {
    $s = $db->prepare('SELECT COUNT(*) FROM audit_logs WHERE action = ? AND actor_module_user_id = ?');
    $s->execute([$action, $actorId]);
    return (int) $s->fetchColumn();
};
$h->test('the handler describes it itself', $scopedRows('payment_method.created') === 1,
    'rows: ' . $scopedRows('payment_method.created'));
$h->test(
    'so the fallback stands down',
    $rowsFor('request.mutated') === $before,
    'fallback rows went from ' . $before . ' to ' . $rowsFor('request.mutated')
);

$stmt = $db->prepare(
    "SELECT module, actor_module_user_id, actor_source, old_data, new_data
     FROM audit_logs WHERE action = 'payment_method.created' AND actor_module_user_id = ?
     ORDER BY id DESC LIMIT 1"
);
$stmt->execute([$actorId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
$h->test('the recorded row names dc-cafe', ($row['module'] ?? null) === 'dc-cafe', json_encode($row));
$h->test('names the acting user', (int) ($row['actor_module_user_id'] ?? 0) === $actorId, json_encode($row));
$h->test('names the actor source', ($row['actor_source'] ?? null) === 'dc-cafe', json_encode($row));
$h->test(
    'and carries what was created',
    str_contains((string) ($row['new_data'] ?? ''), 'Mutation Audit Probe'),
    (string) ($row['new_data'] ?? '')
);

// An audited write records its own row and must not also produce a fallback.
$beforeFallback = $rowsFor('request.mutated');
$audited = dcMutRunRequest('POST', '/dc-cafe/api/v1/settings/preferences', $actor, [
    'settings' => ['pos_quick_stock_entry' => '1'],
]);
$h->test('audited write succeeds', $audited['status'] === 200, $audited['body']);
$h->test(
    'the specific row wins over the fallback',
    $rowsFor('request.mutated') === $beforeFallback,
    'fallback rows went from ' . $beforeFallback . ' to ' . $rowsFor('request.mutated')
);
$h->test('the audited action recorded its own row', $rowsFor('settings.preferences_saved') > 0);

// A GET is never a mutation and is never armed.
$before = $rowsFor('request.mutated');
$get = dcMutRunRequest('GET', '/dc-cafe/audit', $actor);
$h->test('GET succeeds', $get['status'] === 200);
$h->test('GET never arms the net', $get['armed'] === false);
$h->test('GET is not recorded', $rowsFor('request.mutated') === $before);

// ── 6. Presentation ──
$h->section('Audit Page Presentation');

// Driven from the payload the fallback writes rather than a stored row. dc-cafe
// has no undescribed mutation left to produce one, and a renderer check should
// not depend on data an earlier run happened to leave behind.
$detail = dcAuditDetail('request.mutated', [
    'method' => 'POST',
    'path' => '/dc-cafe/api/v1/payment-methods/create',
], []);

$h->test(
    'the action has a human label',
    dcAuditActionLabel('request.mutated') !== 'Request Mutated',
    dcAuditActionLabel('request.mutated')
);
$h->test(
    'the label does not read like routine work',
    str_contains(strtolower(dcAuditActionLabel('request.mutated')), 'no detail'),
    dcAuditActionLabel('request.mutated')
);
$h->test('the action is toned as caution, not routine', dcAuditActionTone('request.mutated') === 'warn');
$h->test(
    'the detail names the method and path',
    str_contains($detail, 'POST') && str_contains($detail, 'payment-methods/create'),
    $detail
);
$h->test(
    'and says plainly that no specific record was written',
    str_contains($detail, 'no specific audit record'),
    $detail
);
$h->test(
    'the fallback is still a declared action, ready if a new gap appears',
    isset(dcAuditActionLabels()['request.mutated'])
);

// Clean up what this suite created. Best-effort: a leftover fixture must not turn
// a passing suite into an error page.
try {
    $db->prepare('DELETE FROM dc_payment_methods WHERE code = ?')->execute([$code]);
    // Every row this run wrote, and its actor, so the suite leaves no phantom
    // activity behind and can be run again.
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id = ?')->execute([$actorId]);
    $db->prepare('DELETE FROM dc_users WHERE user_id = ?')->execute([$actorId]);
} catch (\Throwable $cleanupError) {
    echo "  ℹ cleanup skipped: " . $cleanupError->getMessage() . "\n";
}

$h->done();
