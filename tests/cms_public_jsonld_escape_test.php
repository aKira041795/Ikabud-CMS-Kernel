<?php
/**
 * CMS public JSON-LD must not be breakable by content fields.
 *
 * Why this guard exists: cmsStructuredDataJsonLd() encodes the content title
 * into a <script type="application/ld+json"> block. JSON_UNESCAPED_SLASHES
 * leaves "</script>" intact, so a title containing a closing script tag ends
 * the JSON-LD element and any following markup is parsed as HTML. The CMS CSP
 * permits inline script, so the payload executes.
 *
 * The test parses the emitted block as JSON and proves the hostile title
 * round-trips. It deliberately does not compare raw source: the fix is allowed
 * to change the encoding (\u003C etc.) as long as the decoded value is intact.
 *
 * Run: php tests/cms_public_jsonld_escape_test.php
 */
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  PASS {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ": {$detail}" : '');
    echo "  FAIL {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * Extract and decode the first ld+json payload. At HEAD the hostile title
 * closes the script early, so the captured fragment is invalid JSON.
 */
function ldJsonPayload(string $html): ?array
{
    if (!preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m)) {
        return null;
    }
    $decoded = json_decode($m[1], true);
    return is_array($decoded) ? $decoded : null;
}

file_put_contents(STORAGE_PATH . '/logs/app.log', '');
file_put_contents(STORAGE_PATH . '/logs/error.log', '');

$hostile = '</script><script>alert(1)</script>';
$html = cmsStructuredDataJsonLd([
    'type' => 'post',
    'slug' => 'xss-probe',
    'title' => $hostile,
]);
$payload = ldJsonPayload($html);

t('hostile title still produces parseable JSON-LD', $payload !== null, substr($html, 0, 240));
t('headline round-trips unchanged', ($payload['headline'] ?? null) === $hostile);
t('no executable script survives in the output', !str_contains($html, '<script>alert(1)</script>'));
t('exactly one ld+json element is emitted', substr_count($html, '</script>') === 1,
    'closing script tags: ' . substr_count($html, '</script>'));

$special = 'Tom & "Jerry\'s" <b>';
$specialPayload = ldJsonPayload(cmsStructuredDataJsonLd([
    'type' => 'post',
    'slug' => 'special-chars',
    'title' => $special,
]));
t('ampersands, quotes and angle brackets round-trip', ($specialPayload['headline'] ?? null) === $special);

$invalid = cmsStructuredDataJsonLd([
    'type' => 'post',
    'slug' => 'invalid-utf8',
    'title' => "bad\xC3",
]);
$invalidPayload = ldJsonPayload($invalid);
t('invalid UTF-8 never emits a partial script',
    $invalid === '' || ($invalidPayload !== null && is_string($invalidPayload['headline'] ?? null)));

$errorLog = (string)@file_get_contents(STORAGE_PATH . '/logs/error.log');
t('no PHP fatal error logged', !str_contains($errorLog, 'Fatal error'), trim(substr($errorLog, 0, 300)));

echo "\n" . ($fail === 0 ? 'OK' : 'FAILED') . ": {$pass} passed, {$fail} failed\n";
if ($fail !== 0) {
    foreach ($errors as $error) {
        echo "  - {$error}\n";
    }
    exit(1);
}
exit(0);
