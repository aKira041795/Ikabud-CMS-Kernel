<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/admin/bakeshop/history';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/bakeshop/helpers.php';
require_once __DIR__ . '/../modules/bakeshop/handlers.php';

$pass = 0;
$fail = 0;
$errors = [];

function btHistoryPage(string $label, bool $ok, string $detail = ''): void
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

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== BAKESHOP HISTORY PAGE TEST ===\n\n";

$db = app()->db();
$seed = strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
$search = 'history-page-' . strtolower($seed);
$auditId = 0;
$originalGet = $_GET;
$originalRequest = $_REQUEST;
$originalUri = $_SERVER['REQUEST_URI'] ?? '';

try {
    $stmt = $db->prepare(
        'INSERT INTO audit_logs (module, action, entity_type, entity_id, metadata_json, created_at)
         VALUES (:module, :action, :entity_type, :entity_id, :metadata_json, NOW())'
    );
    $stmt->execute([
        ':module' => 'bakeshop',
        ':action' => 'bakeshop.test.' . $search,
        ':entity_type' => 'module_settings',
        ':entity_id' => $seed,
        ':metadata_json' => json_encode(['seed' => $seed], JSON_UNESCAPED_SLASHES),
    ]);
    $auditId = (int)$db->lastInsertId();
    btHistoryPage('seeded audit row inserted', $auditId > 0, (string)$auditId);

    app()->setUser([
        'id' => 1,
        'username' => 'admin',
        'role' => 'admin',
        'source' => 'bakeshop',
    ]);

    $_GET = [
        'search' => $search,
        'limit' => '25',
        'offset' => '0',
    ];
    $_REQUEST = $_GET;
    $_SERVER['REQUEST_URI'] = '/admin/bakeshop/history?search=' . urlencode($search);

    ob_start();
    bakeshopPageHistory();
    $html = (string)ob_get_clean();

    btHistoryPage('history page renders heading', str_contains($html, 'Activity History'));
    btHistoryPage('history page renders seeded action', str_contains($html, 'bakeshop.test.' . $search), $html);
    btHistoryPage('history page renders module settings label', str_contains($html, 'Module Settings #' . $seed), $html);
    btHistoryPage('history page links module settings entries to settings screen', str_contains($html, '/admin/bakeshop/settings'), $html);
    btHistoryPage('user history entity url includes focus user id', bakeshopAuditHistoryEntityUrl('bakeshop_users', '42') === '/admin/bakeshop/users?focus_user_id=42', bakeshopAuditHistoryEntityUrl('bakeshop_users', '42') ?? '');
    btHistoryPage('branch history entity url includes focus params', bakeshopAuditHistoryEntityUrl('bakeshop_branches', '13') === '/admin/bakeshop/branches?focus_kind=branch&focus_id=13', bakeshopAuditHistoryEntityUrl('bakeshop_branches', '13') ?? '');
    btHistoryPage('history actor url includes focus user id', bakeshopAuditHistoryActorUrl(['actor_source' => 'bakeshop', 'actor_module_user_id' => 77]) === '/admin/bakeshop/users?focus_user_id=77', bakeshopAuditHistoryActorUrl(['actor_source' => 'bakeshop', 'actor_module_user_id' => 77]) ?? '');
} finally {
    $_GET = $originalGet;
    $_REQUEST = $originalRequest;
    $_SERVER['REQUEST_URI'] = $originalUri;

    if ($auditId > 0) {
        $db->prepare('DELETE FROM audit_logs WHERE id = ?')->execute([$auditId]);
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));
btHistoryPage('no app.log errors', $appLog === '' || !str_contains(strtolower($appLog), 'error'), $appLog);
btHistoryPage('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

exit($fail > 0 ? 1 : 0);