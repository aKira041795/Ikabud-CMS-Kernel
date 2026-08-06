<?php

declare(strict_types=1);

/**
 * Installer (public/lock.php) contract test.
 *
 * Validates the WordPress-style wizard surface and the domain-settable
 * behavior without running a destructive install:
 *   - installed guard returns 403 with "System already installed"
 *   - 4-step wizard markup is present (steps, progress, preflight)
 *   - user-settable site address (domain) field + APP_URL override wiring
 *   - advanced control-plane disclosure still contains the multi-tenant fields
 *   - smoke-test strings ("Database Host", "Admin Username") are preserved
 *   - installerNormalizeSiteUrl() (extracted from the shipped file) normalizes
 *     and rejects invalid site addresses
 */

require __DIR__ . '/../bootstrap.php';

$passed = 0;
$failed = 0;

$assert = static function (bool $condition, string $label) use (&$passed, &$failed): void {
    if ($condition) {
        $passed++;
        echo "PASS: {$label}\n";
        return;
    }
    $failed++;
    echo "FAIL: {$label}\n";
};

$lockFile = BASE_PATH . '/public/lock.php';
if (!is_file($lockFile)) {
    echo "FAIL: public/lock.php not found\n";
    exit(1);
}
$src = (string) file_get_contents($lockFile);

// ── Installed-guard contract (matches scripts/test-install-http.php) ────
$assert(
    str_contains($src, 'http_response_code(403)'),
    'installed guard returns HTTP 403'
);
$assert(
    str_contains($src, 'System already installed'),
    'installed guard body contains "System already installed"'
);

// ── 4-step wizard markup ─────────────────────────────────────────────────
foreach ([1, 2, 3, 4] as $step) {
    $assert(
        str_contains($src, 'data-step="' . $step . '"'),
        'wizard step ' . $step . ' present'
    );
}
$assert(str_contains($src, 'class="wizard-step"'), 'wizard-step sections present');
$assert(str_contains($src, 'wizGo('), 'wizard navigation JS present');
$assert(str_contains($src, 'installerRequirementsPreflight()'), 'requirements preflight invoked');
$assert(str_contains($src, 'classList.add(\'js\')'), 'progressive-enhancement JS gate present');
$assert(str_contains($src, 'data-errors='), 'server-error step fallback present');

// ── User-settable site address (domain) ──────────────────────────────────
$assert(str_contains($src, 'name="site_url"'), 'site address (domain) field present');
$assert(str_contains($src, 'function installerNormalizeSiteUrl'), 'site URL normalizer defined');
$assert(
    str_contains($src, 'installerEnvSanitizeValue($siteUrl)') && str_contains($src, '$siteUrl !== \'\''),
    'APP_URL override wiring uses user-supplied site address'
);

// ── Advanced control-plane disclosure preserved ──────────────────────────
$assert(str_contains($src, 'Advanced options'), 'advanced options disclosure present');
$assert(str_contains($src, 'name="app_multi_tenant_enabled"'), 'multi-tenant toggle preserved');
$assert(str_contains($src, 'name="control_db_enc_key"'), 'control-plane encryption key preserved');

// ── Admin + DB fields (WP parity) ────────────────────────────────────────
foreach (['db_name', 'db_user', 'db_pass', 'db_host', 'admin_username', 'admin_pass', 'admin_pass_confirm', 'admin_email'] as $field) {
    $assert(str_contains($src, 'name="' . $field . '"'), 'field "' . $field . '" present');
}
$assert(str_contains($src, 'strength-bar'), 'password strength meter present');

// ── Smoke-test strings preserved ─────────────────────────────────────────
$assert(str_contains($src, 'Database Host'), 'smoke string "Database Host" preserved');
$assert(str_contains($src, 'Admin Username'), 'smoke string "Admin Username" preserved');
$assert(str_contains($src, 'Install'), 'smoke string "Install" preserved');
$assert(str_contains($src, "'test_db'"), 'test_db AJAX endpoint preserved');

// ── installerNormalizeSiteUrl() unit behavior (extract from shipped file) ─
/**
 * Extract a top-level function body from lock.php by brace counting.
 */
$extractFn = static function (string $source, string $fname): ?string {
    $needle = 'function ' . $fname;
    $pos = strpos($source, $needle);
    if ($pos === false) {
        return null;
    }
    $open = strpos($source, '{', $pos);
    if ($open === false) {
        return null;
    }
    $depth = 0;
    $len = strlen($source);
    for ($i = $open; $i < $len; $i++) {
        if ($source[$i] === '{') {
            $depth++;
        } elseif ($source[$i] === '}') {
            $depth--;
            if ($depth === 0) {
                return substr($source, $pos, $i - $pos + 1);
            }
        }
    }
    return null;
};

$fSanitize = $extractFn($src, 'installerEnvSanitizeValue');
$fNormalize = $extractFn($src, 'installerNormalizeSiteUrl');
$normalizeLoaded = false;
if ($fSanitize !== null && $fNormalize !== null) {
    eval($fSanitize);
    eval($fNormalize);
    $normalizeLoaded = function_exists('installerNormalizeSiteUrl');
}
$assert($normalizeLoaded, 'installerNormalizeSiteUrl extracted and loadable');

if ($normalizeLoaded) {
    $assert(
        installerNormalizeSiteUrl('https://example.com', 'http', '') === 'https://example.com',
        'normalize keeps fully-qualified URL'
    );
    $assert(
        installerNormalizeSiteUrl('example.com', 'http', '') === 'http://example.com',
        'normalize prefixes scheme for bare domain'
    );
    $assert(
        installerNormalizeSiteUrl('https://example.com:8080', 'http', '') === 'https://example.com:8080',
        'normalize preserves explicit port'
    );
    $assert(
        installerNormalizeSiteUrl('https://example.com/subpath/', 'http', '') === 'https://example.com/subpath',
        'normalize trims trailing slash on path'
    );
    $assert(
        installerNormalizeSiteUrl('', 'http', '') === '',
        'normalize returns empty for blank input'
    );
    $assert(
        installerNormalizeSiteUrl('not a domain', 'http', '') === '',
        'normalize rejects invalid host'
    );
    $assert(
        installerNormalizeSiteUrl('https://exa mple.com', 'http', '') === '',
        'normalize rejects host with space'
    );
    $assert(
        installerNormalizeSiteUrl('javascript:alert(1)', 'http', '') === '',
        'normalize rejects non-http scheme'
    );
}

echo "\nSummary: {$passed} passed, {$failed} failed\n";
exit($failed === 0 ? 0 : 1);
