<?php
/**
 * Published builder documents must not ship depth-dependent relative links.
 *
 * Run: php tests/builder_relative_link_test.php
 */
declare(strict_types=1);

$_SERVER['HTTP_HOST'] = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? "\u{2713}" : "\u{2717}") . " {$label}" . (!$ok && $detail !== '' ? " -- {$detail}" : '') . "\n";
}

/** @return list<string> */
function relativeLinkFindings(mixed $value, string $path = '$'): array
{
    $findings = [];
    if (!is_array($value)) {
        return $findings;
    }
    foreach ($value as $key => $child) {
        $childPath = $path . '.' . (string) $key;
        if (is_array($child)) {
            array_push($findings, ...relativeLinkFindings($child, $childPath));
            continue;
        }
        if (!is_string($child)) {
            continue;
        }
        $linkKey = preg_match('/(?:href|url|link)$/i', (string) $key) === 1;
        $relativeValue = preg_match('~^\s*\.\.?/~', $child) === 1;
        $relativeHtmlHref = preg_match('~\bhref\s*=\s*(["\'])\s*\.\.?/~i', $child) === 1;
        if (($linkKey && $relativeValue) || $relativeHtmlHref) {
            $findings[] = $childPath;
        }
    }
    return $findings;
}

echo "\nBuilder relative-link publication guard\n\n";
$rows = cmsDb()->query(
    "SELECT id, content_id, document_json FROM cms_builder_documents WHERE status = 'published' ORDER BY id"
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
t('published builder documents exist', $rows !== []);

$allFindings = [];
foreach ($rows as $row) {
    $document = json_decode((string) $row['document_json'], true);
    if (!is_array($document)) {
        $allFindings[] = 'document ' . $row['id'] . ': invalid JSON';
        continue;
    }
    foreach (relativeLinkFindings($document) as $path) {
        $allFindings[] = 'document ' . $row['id'] . ': ' . $path;
    }
}
t('no published link-bearing prop contains ./ or ../', $allFindings === [], implode(', ', $allFindings));

$frontpage = cmsDb()->query(
    'SELECT id, document_json FROM cms_builder_documents WHERE id IN (1, 2) ORDER BY id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
t('both frontpage builder documents remain present', count($frontpage) === 2);
foreach ($frontpage as $row) {
    $json = (string) $row['document_json'];
    t('document ' . $row['id'] . ' uses the canonical contributing URL',
        str_contains($json, 'href=\\"/cms/blog/contributing\\"') || str_contains($json, 'href="/cms/blog/contributing"'));
    t('document ' . $row['id'] . ' preserves the link text', str_contains($json, 'Contributors/Testers'));
}

echo "\n  {$pass} passed, {$fail} failed\n\n";
exit($fail === 0 ? 0 : 1);
