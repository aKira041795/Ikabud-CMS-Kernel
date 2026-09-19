<?php
/**
 * DC Cafe — audit coverage.
 *
 * The trail is only worth having if it describes what changed. A handler that
 * writes to the database and records nothing leaves a gap that reads like a
 * complete record, which is worse than an obvious absence: nobody goes looking
 * for what they believe is already there.
 *
 * The first section is the important one. It is a structural check, not a list
 * of remembered cases: every state-mutating route must either write an audit row
 * or be on a short allowlist of routes that genuinely change nothing. That makes
 * this class of gap impossible to reintroduce quietly — a new handler has to
 * either audit or justify itself here.
 *
 * The rest exercises representative handlers end to end and checks the rows they
 * produce, so the coverage is real rather than textual.
 *
 * Fixtures are the suite's own: it creates what it edits and deletes it
 * afterwards, so branch data is never touched.
 */

declare(strict_types=1);

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('dc-cafe-audit-coverage', TestHarness::MODE_INTEGRATION, 'dccafe.test');
require_once __DIR__ . '/../../src/helpers/module-manager.php';
require_once __DIR__ . '/../../modules/dc-cafe/helpers.php';

foreach ([
    'modules/dc-cafe/routes.php',
    'modules/dc-cafe/helpers.php',
    'modules/dc-cafe/handlers.php',
    'modules/dc-cafe/handlers-orders.php',
    'modules/dc-cafe/handlers-inventory.php',
    'modules/dc-cafe/handlers-customers.php',
    'modules/dc-cafe/handlers-products.php',
    'modules/dc-cafe/handlers-backup.php',
] as $file) {
    $h->fingerprint($file);
}

$moduleDir = __DIR__ . '/../../modules/dc-cafe';
$routes = (string) file_get_contents($moduleDir . '/routes.php');
$helpers = (string) file_get_contents($moduleDir . '/helpers.php');

$db = app()->db();

/**
 * @return array{status:int,body:string,json:mixed}
 */
function dcCoverageRequest(string $method, string $uri, array $user, ?array $body = null): array
{
    $base = '/var/www/html/applicationostest';
    $encoded = $body !== null ? http_build_query($body) : '';
    $runnerPath = sys_get_temp_dir() . '/dccafe-cov-' . bin2hex(random_bytes(6)) . '.php';

    $script = "<?php\n"
        . "\$_SERVER['REQUEST_METHOD'] = " . var_export($method, true) . ";\n"
        . "\$_SERVER['REQUEST_URI'] = " . var_export($uri, true) . ";\n"
        . "\$_SERVER['HTTP_HOST'] = 'dccafe.test';\n"
        . "\$_SERVER['SERVER_NAME'] = 'dccafe.test';\n"
        . "\$_SERVER['HTTP_ACCEPT'] = 'application/json';\n"
        . "\$_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';\n"
        . "\$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';\n"
        . "\$_SERVER['REMOTE_ADDR'] = '127.0.0.1';\n"
        . "\$_GET = [];\n"
        . "parse_str((string) parse_url(" . var_export($uri, true) . ", PHP_URL_QUERY), \$_GET);\n"
        . "\$_POST = [];\n"
        . "if (" . var_export($encoded, true) . " !== '') { parse_str(" . var_export($encoded, true) . ", \$_POST); }\n"
        . "\$_REQUEST = array_merge(\$_GET, \$_POST);\n"
        . "require " . var_export($base . '/bootstrap.php', true) . ";\n"
        . "\$u = " . var_export($user, true) . ";\n"
        . "if (is_array(\$u)) { app()->setUser(\$u); }\n"
        . "register_shutdown_function(static function (): void {\n"
        . "    echo \"\\n__R__\\n\";\n"
        . "    echo json_encode(['status' => (int) (http_response_code() ?: 200)], JSON_UNESCAPED_SLASHES);\n"
        . "});\n"
        . "require " . var_export($base . '/public/index.php', true) . ";\n";

    file_put_contents($runnerPath, $script);
    $output = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($runnerPath) . ' 2>&1', $output, $exitCode);
    @unlink($runnerPath);

    $text = implode("\n", $output);
    $parts = explode("\n__R__\n", $text, 2);
    $meta = json_decode((string) ($parts[1] ?? ''), true);
    $bodyText = trim((string) ($parts[0] ?? ''));

    return [
        'status' => (int) (is_array($meta) ? ($meta['status'] ?? 0) : 0),
        'body' => $bodyText,
        'json' => json_decode($bodyText, true),
    ];
}

/**
 * Extract a function's body by brace matching, so a check cannot be fooled by a
 * call that happens to sit in the next function along.
 */
function dcCoverageHandlerBody(string $source, string $fn): ?string
{
    if (!preg_match('/\nfunction\s+' . preg_quote($fn, '/') . '\s*\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
        return null;
    }
    $start = strpos($source, '{', (int) $m[0][1]);
    if ($start === false) {
        return null;
    }
    $depth = 0;
    for ($i = $start, $len = strlen($source); $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $start, $i - $start + 1);
            }
        }
    }
    return null;
}

// ── 1. Every mutating route either audits or is a known no-op ──
$h->section('Every Mutating Route Describes Its Change');

// Routes whose name says POST but whose work is a lookup. They write nothing, so
// they have no change to describe — and the kernel fallback stays silent for them
// precisely because no write statement is issued.
$readShapedPosts = [
    '/dc-cafe/api/v1/products/stock'    => 'reads stock levels for the till',
    '/dc-cafe/api/v1/vouchers/validate' => 'prices a voucher code',
];

$source = '';
foreach (array_merge(glob($moduleDir . '/*.php') ?: [], glob($moduleDir . '/helpers/*.php') ?: []) as $file) {
    $source .= "\n" . file_get_contents($file);
}

$audited = [];
$silent = [];
$unknown = [];
foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
    if (!preg_match("/'" . $method . "'\s*=>\s*\[(.*?)\n    \]/s", $routes, $section)) {
        continue;
    }
    if (!preg_match_all("/'([^']+)'\s*=>\s*'dc-cafe:(\w+)'/", $section[1], $found, PREG_SET_ORDER)) {
        continue;
    }
    foreach ($found as $route) {
        [$path, $fn] = [$route[1], $route[2]];
        if (isset($readShapedPosts[$path])) {
            continue;
        }
        $body = dcCoverageHandlerBody($source, $fn);
        if ($body === null) {
            $unknown[] = "{$method} {$path} -> {$fn}";
            continue;
        }
        if (preg_match('/dc_auditLog\s*\(|dcAudit\w*\s*\(|->audit\s*\(/', $body)) {
            $audited[] = "{$method} {$path}";
        } else {
            $silent[] = "{$method} {$path} -> {$fn}";
        }
    }
}

$h->test('every mutating handler was found in the source', $unknown === [], implode(', ', $unknown));
$h->test(
    'no mutating route changes data without recording it',
    $silent === [],
    'silent: ' . implode(' | ', $silent)
);
$h->test(
    'and the coverage is broad, not a token one or two',
    count($audited) >= 45,
    count($audited) . ' mutating routes audit'
);
$h->test(
    'the only silent routes are the documented read-shaped POSTs',
    count($silent) === 0 && count($readShapedPosts) === 2
);

// ── 2. The rows actually appear ──
$h->section('Rows Actually Appear');

$suffix = 'cov_' . bin2hex(random_bytes(4));
$db->prepare(
    "INSERT INTO dc_users (username, password_hash, email, full_name, role, store_id, is_active)
     VALUES (?, ?, NULL, 'Coverage Editor', 'admin', 1, 1)"
)->execute([$suffix, password_hash('secret123', PASSWORD_BCRYPT)]);
$actorId = (int) $db->lastInsertId();
$actor = [
    'id' => $actorId, 'user_id' => $actorId, 'username' => $suffix,
    'name' => 'Coverage Editor', 'full_name' => 'Coverage Editor',
    'role' => 'admin', 'store_id' => 1, 'source' => 'dc-cafe',
];

$sinceStmt = $db->prepare('SELECT COALESCE(MAX(id), 0) FROM audit_logs');
$sinceStmt->execute();
$since = (int) $sinceStmt->fetchColumn();

$latestStmt = $db->prepare(
    "SELECT action, entity_type, entity_id, old_data, new_data, actor_module_user_id
     FROM audit_logs WHERE id > ? AND action = ? ORDER BY id DESC LIMIT 1"
);
$rowFor = static function (string $action) use ($latestStmt, $since): array {
    $latestStmt->execute([$since, $action]);
    return $latestStmt->fetch(PDO::FETCH_ASSOC) ?: [];
};

// Catalog: create a supplier, then edit it.
$created = dcCoverageRequest('POST', '/dc-cafe/api/v1/suppliers', $actor, [
    'name' => 'Coverage Supplier ' . $suffix,
    'phone' => '09170000000',
]);
$supplierId = (int) ($created['json']['supplier_id'] ?? 0);
$supplierRow = $rowFor('supplier.created');
$h->test('creating a supplier is recorded', $supplierRow !== [], json_encode($created['json']));
$h->test('naming the record and the actor',
    ((string) ($supplierRow['entity_id'] ?? '')) === (string) $supplierId
    && (int) ($supplierRow['actor_module_user_id'] ?? 0) === $actorId,
    json_encode($supplierRow));

// Suppliers, like the other catalog records, expose the update on POST as well as
// PUT. POST is used here because that is how the settings screen calls it.
$edited = dcCoverageRequest('POST', '/dc-cafe/api/v1/suppliers/' . $supplierId, $actor, [
    'name' => 'Coverage Supplier Renamed ' . $suffix,
]);
$editRow = $rowFor('supplier.updated');
$h->test('editing a supplier is recorded', $edited['status'] === 200 && $editRow !== [], $edited['body']);
$h->test('and says what changed',
    str_contains((string) ($editRow['new_data'] ?? ''), 'changes'),
    (string) ($editRow['new_data'] ?? ''));

// Inventory: receiving into a supplier-free fixture ingredient.
$db->prepare(
    "INSERT INTO dc_ingredients (name, unit, cost_per_unit, reorder_level, current_stock, is_active)
     VALUES (?, 'kg', 10.00, 0, 0, 1)"
)->execute(['Coverage Ingredient ' . $suffix]);
$ingredientId = (int) $db->lastInsertId();

$received = dcCoverageRequest('POST', '/dc-cafe/api/v1/inventory/receive/batch', $actor, [
    'items' => [['ingredient_id' => $ingredientId, 'quantity' => 5, 'cost_per_unit' => 12]],
]);
$receiveRow = $rowFor('stock.received');
$h->test('receiving a batch is recorded', $received['status'] === 200 && $receiveRow !== [], $received['body']);
$h->test('and distinguishes what was submitted from what was accepted',
    str_contains((string) ($receiveRow['new_data'] ?? ''), 'submitted'),
    (string) ($receiveRow['new_data'] ?? ''));

// Input::parse() reads a PUT/PATCH body from php://input and merges it over
// $_GET, which a CLI child cannot provide — so the values go in the query string,
// which the same merge picks up.
$adjusted = dcCoverageRequest(
    'PATCH',
    '/dc-cafe/api/v1/inventory/stock/' . $ingredientId . '?current_stock=2&reorder_level=1',
    $actor
);
$adjustRow = $rowFor('stock.adjusted');
$h->test('an inline stock edit is recorded', $adjusted['status'] === 200 && $adjustRow !== [], $adjusted['body']);
$h->test('with the movement it caused',
    str_contains((string) ($adjustRow['new_data'] ?? ''), 'delta'),
    (string) ($adjustRow['new_data'] ?? ''));

// Soft-serve: an option edit reuses the action the create already used.
$db->prepare("INSERT INTO dc_soft_serve_bases (name) VALUES (?)")
   ->execute(['COVERAGE BASE ' . strtoupper($suffix)]);
$baseId = (int) $db->lastInsertId();
$baseEdit = dcCoverageRequest('POST', '/dc-cafe/api/v1/soft-serve/bases/' . $baseId, $actor, [
    'name' => 'Coverage Base Renamed ' . $suffix,
]);
$baseRow = $rowFor('softserve.base_saved');
$h->test('editing a soft-serve option is recorded', $baseEdit['status'] === 200 && $baseRow !== [], $baseEdit['body']);
$h->test('and is distinguishable from creating one',
    str_contains((string) ($baseRow['new_data'] ?? ''), 'changes')
    && !str_contains((string) ($baseRow['new_data'] ?? ''), 'created'),
    (string) ($baseRow['new_data'] ?? ''));

// Password recovery: the request and the unknown-account attempt.
$resetRequest = dcCoverageRequest('POST', '/dc-cafe/api/v1/auth/forgot-password', $actor, [
    'identity' => $suffix,
]);
$requestRow = $rowFor('auth.password_reset_requested');
$h->test('a password reset request is recorded',
    $resetRequest['status'] === 200 && $requestRow !== [], $resetRequest['body']);
$h->test('naming the account it was issued for',
    ((string) ($requestRow['entity_id'] ?? '')) === (string) $actorId,
    json_encode($requestRow));

$unknownRequest = dcCoverageRequest('POST', '/dc-cafe/api/v1/auth/forgot-password', $actor, [
    'identity' => 'nobody_' . $suffix,
]);
$unknownRow = $rowFor('auth.password_reset_unknown');
$h->test('a request for an account that does not exist is recorded', $unknownRow !== []);
$h->test('while the response stays deliberately vague',
    str_contains((string) ($unknownRequest['body'] ?? ''), 'If the account exists'),
    $unknownRequest['body']);
$h->test('and the attempt is toned as a warning', dcAuditActionTone('auth.password_reset_unknown') === 'danger');

// A split handler file loads with helpers.php only, so this also proves the audit
// helper is reachable from there — the alternative is a fatal after the row is
// already written.
$customerPhone = '0999' . random_int(1000000, 9999999);
$customerCreated = dcCoverageRequest('POST', '/dc-cafe/api/v1/customers', $actor, [
    'name' => 'Coverage Customer ' . $suffix,
    'phone' => $customerPhone,
]);
$customerId = (int) ($customerCreated['json']['customer_id'] ?? 0);
$customerRow = $rowFor('customer.created');
$h->test('creating a customer is recorded from its split handler file',
    $customerCreated['status'] === 200 && $customerId > 0 && $customerRow !== [],
    $customerCreated['body']);

// A repeat phone returns the existing customer and changes nothing, so it is not
// recorded as a creation.
$sinceStmt->execute();
$beforeRepeat = (int) $sinceStmt->fetchColumn();
$repeat = dcCoverageRequest('POST', '/dc-cafe/api/v1/customers', $actor, [
    'name' => 'Coverage Customer Again',
    'phone' => $customerPhone,
]);
$repeatStmt = $db->prepare("SELECT COUNT(*) FROM audit_logs WHERE id > ? AND action = 'customer.created'");
$repeatStmt->execute([$beforeRepeat]);
$h->test('and a duplicate phone is not recorded as a new customer',
    ($repeat['json']['existing'] ?? null) === true
    && (int) $repeatStmt->fetchColumn() === 0,
    $repeat['body']);

// ── 3. Presentation ──
$h->section('Presentation');

$h->test('a created catalog record has a human label',
    dcAuditActionLabel('supplier.created') === 'Supplier added', dcAuditActionLabel('supplier.created'));
$h->test('an edit does too',
    dcAuditActionLabel('ingredient.updated') === 'Ingredient edited', dcAuditActionLabel('ingredient.updated'));
$h->test('a password reset is labelled',
    dcAuditActionLabel('auth.password_reset') === 'Password reset completed', dcAuditActionLabel('auth.password_reset'));
$h->test('the reset is toned as a credential change', dcAuditActionTone('auth.password_reset') === 'warn');
$newActions = [
    'customer.created', 'supplier.created', 'supplier.updated', 'ingredient.created',
    'ingredient.updated', 'payment_method.created', 'payment_method.updated', 'store.updated',
    'auth.password_reset_requested', 'auth.password_reset', 'auth.password_reset_unknown',
];
$declaredLabels = dcAuditActionLabels();
$h->test('every new action is declared with a human label',
    array_diff($newActions, array_keys($declaredLabels)) === [],
    'undeclared: ' . implode(', ', array_diff($newActions, array_keys($declaredLabels))));
$h->test('none of them falls back to a label generated from the key',
    array_filter($newActions, static fn(string $a): bool => !isset($declaredLabels[$a])) === []);
// The filter lists the actions actually present in the log, so this checks a row
// produced above turns up as a readable option rather than a raw key.
$h->test('the filter offers what occurred in the log, named',
    in_array('supplier.created', array_column(dcAuditActionOptions(), 'value'), true)
    && dcAuditActionLabel('supplier.created') === 'Supplier added');
$h->test('a created record renders its name',
    dcAuditDetail('supplier.created', ['name' => 'Acme']) === 'Added Acme');
$h->test('an edit renders its name and what changed',
    dcAuditDetail('ingredient.updated', ['name' => 'Flour', 'changes' => ['cost_per_unit']])
        === 'Flour — changed: cost_per_unit');
$h->test('a soft-serve create and edit read differently',
    dcAuditDetail('softserve.base_saved', ['created' => true, 'name' => 'VANILLA']) === 'Added VANILLA'
    && dcAuditDetail('softserve.base_saved', ['name' => 'VANILLA', 'changes' => ['name']]) === 'Updated VANILLA');
$h->test('a reset request names the account',
    dcAuditDetail('auth.password_reset_requested', ['username' => 'admin']) === 'Reset link issued for admin');
$h->test('the helper tolerates a missing payload rather than erroring',
    dcAuditDetail('supplier.updated', []) === '' && dcAuditDetail('softserve.base_saved', []) === 'Updated');

// ── Cleanup ──
try {
    $db->prepare('DELETE FROM dc_password_resets WHERE user_id = ?')->execute([$actorId]);
    $db->prepare('DELETE FROM dc_customers WHERE customer_id = ?')->execute([$customerId]);
    $db->prepare('DELETE FROM dc_inventory_movements WHERE ingredient_id = ?')->execute([$ingredientId]);
    $db->prepare('DELETE FROM dc_ingredients WHERE ingredient_id = ?')->execute([$ingredientId]);
    $db->prepare('DELETE FROM dc_suppliers WHERE supplier_id = ?')->execute([$supplierId]);
    $db->prepare('DELETE FROM dc_soft_serve_bases WHERE base_id = ?')->execute([$baseId]);
    // Everything this run wrote, including its audit rows, so the suite leaves the
    // trail as it found it and can be run again.
    $db->prepare('DELETE FROM audit_logs WHERE actor_module_user_id = ?')->execute([$actorId]);
    $db->prepare('DELETE FROM dc_users WHERE user_id = ?')->execute([$actorId]);
} catch (\Throwable $cleanupError) {
    echo "  ℹ cleanup skipped: " . $cleanupError->getMessage() . "\n";
}

$h->done();
