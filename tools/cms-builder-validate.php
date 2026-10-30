#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate a stored CMS page-builder document and print EVERY issue.
 *
 * Why this exists (measured 2026-10-30): the builder saves through the CMS API, which
 * answered `{"error":"validation_failed"}` with the reasons in a sibling `issues` array.
 * The panel showed the code, so an operator could not tell which node was rejected and
 * an engineer on a different machine could not reproduce it - the document lives in the
 * tenant's database, not in the repository.
 *
 * This reads that document where the data actually is and names the rule it breaks.
 *
 * Usage:
 *   php tools/cms-builder-validate.php --tenant=23 --content=23
 *   php tools/cms-builder-validate.php --tenant=23 --content=23 --status=published
 *
 * Exit codes: 0 = every checked document is valid; 1 = issues found; 2 = nothing to check.
 */

$basePath = dirname(__DIR__);
require_once $basePath . '/bootstrap.php';

$options = getopt('', ['tenant:', 'content:', 'status::', 'help']);
if (isset($options['help']) || !isset($options['tenant'], $options['content'])) {
    fwrite(STDERR, "Usage: php tools/cms-builder-validate.php --tenant=<id> --content=<id> [--status=draft|published|both]\n");
    exit(2);
}

$tenantId = (int)$options['tenant'];
$contentId = (int)$options['content'];
$status = (string)($options['status'] ?? 'both');
if (!in_array($status, ['draft', 'published', 'both'], true)) {
    fwrite(STDERR, "--status must be draft, published or both\n");
    exit(2);
}

require_once $basePath . '/src/helpers/module-manager.php';
require_once $basePath . '/modules/cms/helpers.php';

app()->tenant()->setTenantId($tenantId);
modulePushContext('cms');

echo "tenant={$tenantId} content={$contentId}\n";
if (function_exists('cmsActiveTheme')) {
    echo 'active theme: ' . cmsActiveTheme() . "\n";
}

$db = cmsDb();
$stmt = $db->prepare('SELECT id, type, status, title, slug FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1');
$stmt->execute([':id' => $contentId]);
$content = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$content) {
    fwrite(STDERR, "content {$contentId} not found in tenant {$tenantId}\n");
    exit(2);
}
echo 'content: ' . json_encode($content, JSON_UNESCAPED_SLASHES) . "\n";

$statuses = $status === 'both' ? ['draft', 'published'] : [$status];
$failures = 0;
$found = 0;

foreach ($statuses as $which) {
    try {
        $row = cmsBuilderLoadDocumentRow($contentId, $which);
    } catch (Throwable $e) {
        echo "{$which}: load failed: " . $e->getMessage() . "\n";
        $failures++;
        continue;
    }
    if (!$row || empty($row['document_json'])) {
        echo "{$which}: (no stored document)\n";
        continue;
    }

    $normalized = cmsBuilderNormalizeDocument((string)$row['document_json']);
    $validation = cmsBuilderValidateDocument($normalized);
    $ok = !empty($validation['ok']);
    $found++;

    echo "{$which}: " . ($ok ? 'VALID' : 'INVALID') . ' (' . count($validation['issues'] ?? []) . " issue(s))\n";
    if (!$ok) {
        $failures++;
        $failure = cmsBuilderValidationFailure($validation);
        echo '  message: ' . $failure['message'] . "\n";
        foreach (($validation['issues'] ?? []) as $issue) {
            echo '  - ' . (string)($issue['path'] ?? '?') . ': ' . (string)($issue['message'] ?? '') . "\n";
        }
    }
}

if ($found === 0) {
    echo "nothing to validate\n";
    exit(2);
}

exit($failures === 0 ? 0 : 1);
