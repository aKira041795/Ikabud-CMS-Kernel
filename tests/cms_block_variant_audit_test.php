<?php

declare(strict_types=1);

/**
 * CMS — a declared block variant must have a template behind it.
 *
 * Repository rule (harvested into harpp-memory 2026-11-17): "A missing variant template file is a
 * hard error, not a silent fallback." `cmsResolveBlockTemplate()` throws at RENDER time, so a theme
 * that selects a variant it does not ship turns a customizer choice into a public 500 on the next
 * page view, discovered by a customer rather than by the person who made the choice.
 *
 * `cmsAuditBlockVariants()` answers the same question before publication. This test pins two things:
 *
 *   1. The repository is clean TODAY — every variant declared in `cmsThemeBlockVariantOptions()`
 *      resolves to a module or active-theme template. If someone adds `'minimal'` to a block without
 *      shipping `minimal.block.disyl`, this fails at the moment of the mistake.
 *   2. The audit never lies in the other direction: anything it reports must genuinely have neither
 *      file, so a false alarm cannot send someone hunting for a template that already exists.
 */

$_SERVER['HTTP_HOST'] = 'applicationos.test';
$_SERVER['REQUEST_URI'] = '/cms/admin/theme-customizer';

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
        echo "  ✓ {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "\n=== CMS: DECLARED BLOCK VARIANTS HAVE TEMPLATES ===\n\n";

t('the audit function exists', function_exists('cmsAuditBlockVariants'));

$missing = cmsAuditBlockVariants();

t(
    'every declared block variant resolves to a template',
    $missing === [],
    'these would throw at render time: ' . json_encode($missing)
);

// The audit must not report a variant whose file actually exists — a false alarm is as costly as a
// miss, because it teaches the reader to ignore the check.
$falseAlarms = [];
foreach ($missing as $entry) {
    $modulePath = TEMPLATES_PATH . '/' . (string)($entry['module'] ?? '');
    $themePath = (string)($entry['theme'] ?? '');
    if (is_file($modulePath)) {
        $falseAlarms[] = $entry;
        continue;
    }
    if ($themePath !== '' && is_file(TEMPLATES_PATH . '/' . $themePath)) {
        $falseAlarms[] = $entry;
    }
}
t('no entry is reported when its template exists', $falseAlarms === [], json_encode($falseAlarms));

// The declared set is the source of truth. Every non-empty declaration must be accounted for by
// exactly one of: a module template, a theme template, or an audit entry.
$accounted = 0;
$declared = 0;
foreach (cmsThemeBlockVariantOptions() as $block => $variants) {
    foreach ((array)$variants as $variant) {
        $variant = trim((string)$variant);
        if ($variant === '') {
            continue;
        }
        $declared++;
        $moduleExists = is_file(TEMPLATES_PATH . '/modules/cms/public/blocks/' . $block . '.' . $variant . '.block.disyl');
        $reported = false;
        foreach ($missing as $entry) {
            if (($entry['block'] ?? '') === $block && ($entry['variant'] ?? '') === $variant) {
                $reported = true;
                break;
            }
        }
        if ($moduleExists || $reported) {
            $accounted++;
        }
    }
}
t(
    'every declared variant is either backed by a template or reported missing',
    $accounted === $declared,
    "accounted {$accounted} of {$declared} declared variant(s)"
);

echo "\n" . str_repeat('─', 38) . "\n";
echo "  RESULTS\n";
echo "  {$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    foreach ($errors as $error) {
        echo "   - {$error}\n";
    }
}
echo str_repeat('─', 38) . "\n";
exit($fail > 0 ? 1 : 0);
