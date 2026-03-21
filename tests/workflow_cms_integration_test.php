<?php
/**
 * Workflow ↔ CMS Integration Test
 * Run: php tests/workflow_cms_integration_test.php
 */

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/cms/handlers.php';
require_once __DIR__ . '/../modules/workflow/helpers.php';

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

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

// Ensure workflow definition exists
workflowEnsureCmsContentWorkflow();

echo "\n=== CMS CONTENT CREATE ===\n";

$db = app()->db();
$title = 'WF Test ' . bin2hex(random_bytes(3));
$slug = strtolower(str_replace(' ', '-', $title));

// Create CMS content directly (simpler than calling handlers)
$stmt = $db->prepare(
    "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, comment_status, created_at)\n     VALUES (:uuid, :title, :slug, :body, :excerpt, 'post', 'draft', 1, 'open', NOW())"
);
$stmt->execute([
    ':uuid' => cmsUuid(),
    ':title' => $title,
    ':slug' => $slug,
    ':body' => '<p>Hello</p>',
    ':excerpt' => 'Excerpt',
]);
$contentId = (int)$db->lastInsertId();

t('content created', $contentId > 0);

echo "\n=== WORKFLOW STATE GET (capability) ===\n";

$res = app()->cap()->call('workflow.state.get@1', [
    'workflow_key' => 'cms.content',
    'module' => 'cms',
    'entity_type' => 'cms_content',
    'entity_id' => (string)$contentId,
], ['caller_module' => 'cms', 'caller_user' => ['id'=>1,'role'=>'superadmin','source'=>'cms']]);

t('state.get returns ok', !empty($res['ok']));
$wf = $res['workflow'] ?? [];
t('workflow state is draft', is_array($wf) && ($wf['state'] ?? '') === 'draft');
$actions = is_array($wf) ? ($wf['allowed_actions'] ?? []) : [];
t('allowed_actions has submit', is_array($actions) && count(array_filter($actions, fn($a) => is_array($a) && ($a['action'] ?? '') === 'submit')) >= 1);

echo "\n=== WORKFLOW TRANSITION submit ===\n";

$res2 = app()->cap()->call('workflow.transition@1', [
    'workflow_key' => 'cms.content',
    'module' => 'cms',
    'entity_type' => 'cms_content',
    'entity_id' => (string)$contentId,
    'action' => 'submit',
], ['caller_module' => 'cms', 'caller_user' => ['id'=>1,'role'=>'superadmin','source'=>'cms']]);

t('transition returns ok', !empty($res2['ok']));
t('transition to_state is review', ($res2['to_state'] ?? '') === 'review');

echo "\n=== WORKFLOW STATE GET after submit ===\n";
$res3 = app()->cap()->call('workflow.state.get@1', [
    'workflow_key' => 'cms.content',
    'module' => 'cms',
    'entity_type' => 'cms_content',
    'entity_id' => (string)$contentId,
], ['caller_module' => 'cms', 'caller_user' => ['id'=>1,'role'=>'superadmin','source'=>'cms']]);

t('state is review', (($res3['workflow']['state'] ?? '') === 'review'));

echo "\n=== CLEANUP ===\n";

$db->prepare("DELETE FROM cms_content WHERE id = :id")->execute([':id' => $contentId]);

t('content cleaned up', true);

echo "\n=== LOG CHECK ===\n";

$appLog = @file_get_contents(STORAGE_PATH . '/logs/app.log') ?: '';
$errLog = @file_get_contents(STORAGE_PATH . '/logs/error.log') ?: '';

$appErrors = array_filter(explode("\n", $appLog), function ($line) {
    if (!str_contains($line, '[error]')) {
        return false;
    }
    return !str_contains($line, 'Intentional test error');
});
t('No app.log errors', empty($appErrors), implode('; ', $appErrors));

$errLines = array_filter(explode("\n", $errLog), function ($l) {
    $l = trim($l);
    if ($l === '') return false;
    if (str_contains($l, 'Ikabud Cache:')) return false;
    return true;
});

t('No PHP errors in error.log', empty($errLines), implode('; ', array_slice($errLines, 0, 3)));

echo "\n══════════════════════════════════════════════════\n";
echo "  PASS: {$pass}  FAIL: {$fail}\n";
echo "══════════════════════════════════════════════════\n";

if (!empty($errors)) {
    echo "\nFailed tests:\n";
    foreach ($errors as $e) {
        echo "  - {$e}\n";
    }
}

exit($fail > 0 ? 1 : 0);
