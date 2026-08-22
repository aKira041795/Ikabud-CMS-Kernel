<?php
/**
 * CMS Akira — Caller Policy (all four cms.content.*@1) contract test.
 *
 * Verifies that:
 *   - cms-akira-core is present in the allow_callers of all four
 *     cms.content.get/list/create/update@1 policies (additive, no wildcard).
 *   - Non-Akira callers that were already allowed remain allowed (no regression).
 *   - A caller NOT in the list is denied (negative), and a caller in the list
 *     is admitted (positive) — using the exact-match allow list.
 *
 * Run: php tests/cms_akira_caller_policy_all_four_test.php
 */

declare(strict_types=1);

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';

$pass = 0;
$fail = 0;
$errors = [];

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
    } else {
        $fail++;
        $errors[] = $label . ($detail ? ": {$detail}" : '');
        echo "  \u{2717} {$label}" . ($detail ? " — {$detail}" : '') . "\n";
    }
}

// ── Load CMS manifest ───────────────────────────────────────────────
$manifest = json_decode((string) file_get_contents(BASE_PATH . '/modules/cms/module.json'), true);
t('CMS manifest is valid JSON', is_array($manifest));

$policy = $manifest['capabilities']['policy']['capabilities'] ?? [];
t('CMS capability policy block present', is_array($policy) && $policy !== []);

$four = [
    'cms.content.get@1'    => ['media', 'search', 'workflow', 'cms-akira-core'],
    'cms.content.list@1'   => ['media', 'search', 'workflow', 'cms-akira-core'],
    'cms.content.create@1' => ['content-ingestion', 'media', 'search', 'users', 'workflow', 'cms-akira-core'],
    'cms.content.update@1' => ['content-ingestion', 'media', 'search', 'users', 'workflow', 'cms-akira-core'],
];

echo "\n── Caller policy: cms-akira-core admitted (positive) ──\n";
foreach ($four as $cap => $expected) {
    $rule = $policy[$cap] ?? null;
    t("{$cap} policy declared", is_array($rule) && is_array($rule['allow_callers'] ?? null));
    $list = is_array($rule['allow_callers'] ?? null) ? $rule['allow_callers'] : [];
    t("{$cap} allows cms-akira-core (exact, not wildcard)", in_array('cms-akira-core', $list, true), implode(',', $list));
    t("{$cap} has no cms-akira-* wildcard entry", !in_array('cms-akira-*', $list, true));
}

echo "\n── Caller policy: pre-existing callers preserved (no regression) ──\n";
$regression = [
    'cms.content.get@1'    => ['media', 'search', 'workflow'],
    'cms.content.list@1'   => ['media', 'search', 'workflow'],
    'cms.content.create@1' => ['content-ingestion', 'media', 'search', 'users', 'workflow'],
    'cms.content.update@1' => ['content-ingestion', 'media', 'search', 'users', 'workflow'],
];
foreach ($regression as $cap => $existing) {
    $list = is_array($policy[$cap]['allow_callers'] ?? null) ? $policy[$cap]['allow_callers'] : [];
    foreach ($existing as $caller) {
        t("{$cap} still allows {$caller}", in_array($caller, $list, true));
    }
}

echo "\n── Caller policy: foreign caller denied (negative) ──\n";
foreach (['cms-akira-media', 'cms-akira-theme', 'unknown-module'] as $foreign) {
    foreach (array_keys($four) as $cap) {
        $list = is_array($policy[$cap]['allow_callers'] ?? null) ? $policy[$cap]['allow_callers'] : [];
        t("{$cap} denies {$foreign} (not in allow list)", !in_array($foreign, $list, true));
    }
}

echo "\n── Suite membership: only cms-akira-core may call CMS content (per freeze) ──\n";
// The freeze recorded that ONLY cms-akira-core directly invokes the four
// cms.content.*@1 capabilities. Assert no other cms-akira-* module is in the lists.
foreach (array_keys($four) as $cap) {
    $list = is_array($policy[$cap]['allow_callers'] ?? null) ? $policy[$cap]['allow_callers'] : [];
    foreach ($list as $caller) {
        if (str_starts_with($caller, 'cms-akira-') && $caller !== 'cms-akira-core') {
            t("{$cap} has no unexpected extra Akira caller {$caller}", false);
            break;
        }
    }
}

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";
if ($fail > 0) {
    echo "Errors:\n  - " . implode("\n  - ", $errors) . "\n";
    exit(1);
}
exit(0);
