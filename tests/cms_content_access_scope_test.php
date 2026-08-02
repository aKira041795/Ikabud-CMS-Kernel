<?php

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'cmsnew.test';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/api/v1/cms/content';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';

loadModuleRoutes(['GET' => [], 'POST' => [], 'PUT' => [], 'DELETE' => []]);

if (($argv[1] ?? '') === '--child') {
    $case = (string)($argv[2] ?? '');
    $payloadRaw = base64_decode((string)($argv[3] ?? ''), true);
    $payload = is_string($payloadRaw) && $payloadRaw !== ''
        ? json_decode($payloadRaw, true)
        : [];
    $payload = is_array($payload) ? $payload : [];

    app()->setUser(is_array($payload['user'] ?? null) ? $payload['user'] : []);
    app()->setActiveModule('cms');
    \Ikabud\Kernel\Database\KernelPDO::setActiveModule('cms');
    $_GET = [];
    $_POST = [];
    $_REQUEST = [];
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_SERVER['REQUEST_URI'] = '/api/v1/cms/content';

    ob_start();
    register_shutdown_function(static function (): void {
        $body = (string)ob_get_clean();
        $status = http_response_code();
        $decoded = json_decode($body, true);
        app()->clearActiveModule();
        \Ikabud\Kernel\Database\KernelPDO::setActiveModule(null);
        echo json_encode([
            'status' => is_int($status) ? $status : 200,
            'body' => $body,
            'json' => is_array($decoded) ? $decoded : null,
        ], JSON_UNESCAPED_SLASHES);
    });

    switch ($case) {
        case 'list':
            $_GET = [
                'type' => 'post',
                'status' => 'draft',
                'limit' => '50',
            ];
            $_REQUEST = $_GET;
            cmsApiContentList();
            break;

        case 'get':
            cmsApiContentGet(['id' => (int)($payload['content_id'] ?? 0)]);
            break;

        case 'workflow-state':
            cmsApiContentWorkflowState(['id' => (int)($payload['content_id'] ?? 0)]);
            break;

        case 'workflow-transition':
            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_POST = ['action' => 'submit'];
            $_REQUEST = $_POST;
            cmsApiContentWorkflowTransition(['id' => (int)($payload['content_id'] ?? 0)]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Unknown child case']);
            exit;
    }

    exit;
}

ob_start();

$pass = 0;
$fail = 0;
$errors = [];

function ccasert(string $label, bool $ok, string $detail = ''): void
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

function cmsContentAccessRunChild(string $case, array $payload): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(__FILE__)
        . ' --child ' . escapeshellarg($case)
        . ' ' . escapeshellarg(base64_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES)));

    $descriptorSpec = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, BASE_PATH);
    if (!is_resource($process)) {
        return ['status' => 500, 'body' => '', 'json' => null, 'stderr' => 'Failed to start child process'];
    }

    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    proc_close($process);

    $decoded = json_decode((string)$stdout, true);
    if (!is_array($decoded)) {
        return ['status' => 500, 'body' => (string)$stdout, 'json' => null, 'stderr' => (string)$stderr];
    }

    $decoded['stderr'] = (string)$stderr;
    return $decoded;
}

@file_put_contents(STORAGE_PATH . '/logs/app.log', '');
@file_put_contents(STORAGE_PATH . '/logs/error.log', '');

echo "\n=== CMS CONTENT ACCESS SCOPE TEST ===\n\n";

$db = app()->db();
$seed = bin2hex(random_bytes(4));

$contributorUser = [
    'id' => 0,
    'sub' => 'cms:0',
    'role' => 'contributor',
    'source' => 'cms',
    'email' => 'contributor-' . $seed . '@example.test',
];

$editorUser = [
    'id' => 0,
    'sub' => 'cms:0',
    'role' => 'editor',
    'source' => 'cms',
    'email' => 'editor-' . $seed . '@example.test',
];

$otherAuthorUser = [
    'id' => 0,
    'sub' => 'cms:0',
    'role' => 'author',
    'source' => 'cms',
    'email' => 'author-' . $seed . '@example.test',
];

$contentIds = [];
$userIds = [];

try {
    $userStmt = $db->prepare(
        'INSERT INTO cms_users (username, email, password_hash, display_name, role, is_active, created_at) '
        . 'VALUES (:username, :email, :password_hash, :display_name, :role, 1, NOW())'
    );

    $userFixtures = [
        ['user' => &$contributorUser, 'username' => 'contributor_' . $seed, 'display_name' => 'Contributor ' . $seed, 'role' => 'contributor'],
        ['user' => &$editorUser, 'username' => 'editor_' . $seed, 'display_name' => 'Editor ' . $seed, 'role' => 'editor'],
        ['user' => &$otherAuthorUser, 'username' => 'author_' . $seed, 'display_name' => 'Author ' . $seed, 'role' => 'author'],
    ];

    foreach ($userFixtures as $fixture) {
        $userStmt->execute([
            ':username' => $fixture['username'],
            ':email' => $fixture['user']['email'],
            ':password_hash' => password_hash('Password!123', PASSWORD_DEFAULT),
            ':display_name' => $fixture['display_name'],
            ':role' => $fixture['role'],
        ]);
        $fixture['user']['id'] = (int)$db->lastInsertId();
        $fixture['user']['sub'] = 'cms:' . $fixture['user']['id'];
        $userIds[] = $fixture['user']['id'];
    }

    $contentStmt = $db->prepare(
        'INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, created_at, updated_at) '
        . 'VALUES (:uuid, :title, :slug, :body, :excerpt, :type, :status, :author_id, NOW(), NOW())'
    );

    $contentFixtures = [
        [
            'title' => 'Contributor Draft ' . $seed,
            'slug' => 'contributor-draft-' . $seed,
            'author_id' => $contributorUser['id'],
        ],
        [
            'title' => 'Foreign Draft ' . $seed,
            'slug' => 'foreign-draft-' . $seed,
            'author_id' => $otherAuthorUser['id'],
        ],
    ];

    foreach ($contentFixtures as $fixture) {
        $contentStmt->execute([
            ':uuid' => cmsUuid(),
            ':title' => $fixture['title'],
            ':slug' => $fixture['slug'],
            ':body' => '<p>Test</p>',
            ':excerpt' => 'Scope fixture',
            ':type' => 'post',
            ':status' => 'draft',
            ':author_id' => $fixture['author_id'],
        ]);
        $contentIds[$fixture['slug']] = (int)$db->lastInsertId();
    }

    $contributorList = cmsContentAccessRunChild('list', ['user' => $contributorUser]);
    $contributorRows = is_array($contributorList['json']['data'] ?? null) ? $contributorList['json']['data'] : [];
    $contributorIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $contributorRows);

    ccasert('contributor list request succeeds', ($contributorList['status'] ?? 0) === 200 && (($contributorList['json']['ok'] ?? false) === true), json_encode($contributorList, JSON_UNESCAPED_SLASHES));
    ccasert('contributor list includes own draft only', in_array($contentIds['contributor-draft-' . $seed], $contributorIds, true), json_encode($contributorRows, JSON_UNESCAPED_SLASHES));
    ccasert('contributor list excludes foreign draft', !in_array($contentIds['foreign-draft-' . $seed], $contributorIds, true), json_encode($contributorRows, JSON_UNESCAPED_SLASHES));

    $editorList = cmsContentAccessRunChild('list', ['user' => $editorUser]);
    $editorRows = is_array($editorList['json']['data'] ?? null) ? $editorList['json']['data'] : [];
    $editorIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $editorRows);

    ccasert('editor list request succeeds', ($editorList['status'] ?? 0) === 200 && (($editorList['json']['ok'] ?? false) === true), json_encode($editorList, JSON_UNESCAPED_SLASHES));
    ccasert('editor list includes contributor draft', in_array($contentIds['contributor-draft-' . $seed], $editorIds, true), json_encode($editorRows, JSON_UNESCAPED_SLASHES));
    ccasert('editor list includes foreign draft', in_array($contentIds['foreign-draft-' . $seed], $editorIds, true), json_encode($editorRows, JSON_UNESCAPED_SLASHES));

    $ownGet = cmsContentAccessRunChild('get', [
        'user' => $contributorUser,
        'content_id' => $contentIds['contributor-draft-' . $seed],
    ]);
    ccasert('contributor can read own draft', ($ownGet['status'] ?? 0) === 200 && (($ownGet['json']['ok'] ?? false) === true), json_encode($ownGet, JSON_UNESCAPED_SLASHES));

    $foreignGet = cmsContentAccessRunChild('get', [
        'user' => $contributorUser,
        'content_id' => $contentIds['foreign-draft-' . $seed],
    ]);
    ccasert('contributor cannot read foreign draft', ($foreignGet['status'] ?? 0) === 403 && (($foreignGet['json']['error'] ?? '') === 'Permission denied'), json_encode($foreignGet, JSON_UNESCAPED_SLASHES));

    $foreignWorkflowState = cmsContentAccessRunChild('workflow-state', [
        'user' => $contributorUser,
        'content_id' => $contentIds['foreign-draft-' . $seed],
    ]);
    ccasert('contributor cannot view workflow for foreign draft', ($foreignWorkflowState['status'] ?? 0) === 403 && (($foreignWorkflowState['json']['error'] ?? '') === 'Permission denied'), json_encode($foreignWorkflowState, JSON_UNESCAPED_SLASHES));

    $foreignWorkflowTransition = cmsContentAccessRunChild('workflow-transition', [
        'user' => $contributorUser,
        'content_id' => $contentIds['foreign-draft-' . $seed],
    ]);
    ccasert('contributor cannot transition foreign draft workflow', ($foreignWorkflowTransition['status'] ?? 0) === 403 && (($foreignWorkflowTransition['json']['error'] ?? '') === 'Permission denied'), json_encode($foreignWorkflowTransition, JSON_UNESCAPED_SLASHES));

    $capOwnGet = app()->cap()->call('cms.content.get@1', [
        'id' => $contentIds['contributor-draft-' . $seed],
    ], [
        'caller_module' => 'workflow',
        'caller_user' => $contributorUser,
    ]);
    ccasert('capability get allows contributor own draft', (($capOwnGet['ok'] ?? false) === true) && ((int)($capOwnGet['data']['id'] ?? 0) === $contentIds['contributor-draft-' . $seed]), json_encode($capOwnGet, JSON_UNESCAPED_SLASHES));

    $capForeignGet = app()->cap()->call('cms.content.get@1', [
        'id' => $contentIds['foreign-draft-' . $seed],
    ], [
        'caller_module' => 'workflow',
        'caller_user' => $contributorUser,
    ]);
    ccasert('capability get denies contributor foreign draft', (($capForeignGet['ok'] ?? true) === false) && (($capForeignGet['error'] ?? '') === 'Permission denied'), json_encode($capForeignGet, JSON_UNESCAPED_SLASHES));

    $capContributorList = app()->cap()->call('cms.content.list@1', [
        'type' => 'post',
        'status' => 'draft',
        'limit' => 50,
    ], [
        'caller_module' => 'workflow',
        'caller_user' => $contributorUser,
    ]);
    $capContributorRows = is_array($capContributorList['data'] ?? null) ? $capContributorList['data'] : [];
    $capContributorIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $capContributorRows);
    ccasert('capability list allows contributor own draft', (($capContributorList['ok'] ?? false) === true) && in_array($contentIds['contributor-draft-' . $seed], $capContributorIds, true), json_encode($capContributorList, JSON_UNESCAPED_SLASHES));
    ccasert('capability list denies contributor foreign draft', !in_array($contentIds['foreign-draft-' . $seed], $capContributorIds, true), json_encode($capContributorList, JSON_UNESCAPED_SLASHES));

    $capEditorList = app()->cap()->call('cms.content.list@1', [
        'type' => 'post',
        'status' => 'draft',
        'limit' => 50,
    ], [
        'caller_module' => 'workflow',
        'caller_user' => $editorUser,
    ]);
    $capEditorRows = is_array($capEditorList['data'] ?? null) ? $capEditorList['data'] : [];
    $capEditorIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $capEditorRows);
    ccasert('capability list allows editor all drafts', (($capEditorList['ok'] ?? false) === true) && in_array($contentIds['contributor-draft-' . $seed], $capEditorIds, true) && in_array($contentIds['foreign-draft-' . $seed], $capEditorIds, true), json_encode($capEditorList, JSON_UNESCAPED_SLASHES));
} finally {
    if ($contentIds !== []) {
        $deleteContent = $db->prepare('DELETE FROM cms_content WHERE id = :id');
        foreach ($contentIds as $contentId) {
            $deleteContent->execute([':id' => $contentId]);
        }
    }

    if ($userIds !== []) {
        $deleteUsers = $db->prepare('DELETE FROM cms_users WHERE id = :id');
        foreach ($userIds as $userId) {
            $deleteUsers->execute([':id' => $userId]);
        }
    }
}

$appLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/app.log'));
$errorLog = trim((string)@file_get_contents(STORAGE_PATH . '/logs/error.log'));

$appLogLines = $appLog === '' ? [] : array_filter(explode("\n", $appLog), static function (string $line): bool {
    $normalized = strtolower($line);
    if (
        str_contains($normalized, 'aiss settings for tenant')
        || str_contains($normalized, '[info] capability.call')
    ) {
        return false;
    }

    return str_contains($normalized, '[warning]') || str_contains($normalized, '[error]') || str_contains($normalized, '[critical]');
});

ccasert('no app.log errors', $appLogLines === [], $appLog);
ccasert('no error.log errors', $errorLog === '', $errorLog);

echo "\n" . str_repeat('─', 50) . "\n";
echo "  Result: {$pass} passed, {$fail} failed\n";
if ($errors !== []) {
    echo "\n  Failures:\n";
    foreach ($errors as $error) {
        echo "    • {$error}\n";
    }
}
echo "\n";

echo (string)ob_get_clean();
exit($fail > 0 ? 1 : 0);
