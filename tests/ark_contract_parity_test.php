<?php
declare(strict_types=1);

/**
 * ARK contract parity + safety test (P1/P2, 2026-08-22).
 *
 *   - slots.json is the single source of truth; theme.manifest.json
 *     supported_slots must match it exactly (16).
 *   - The README slot table must match slots.json (accepts + multiplicity).
 *   - No inline on* handlers in ARK .disyl templates (safety-policy csp_note).
 *   - public.disyl slot count comment is correct (16).
 *
 * Unit-style: file reads only, no bootstrap.
 */

$basePath = dirname(__DIR__);
define('ARK', $basePath . '/storage/cms-themes/ark');

$pass = 0;
$fail = 0;

function t(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;

    if ($ok) {
        $pass++;
        echo "  ✓ {$label}\n";
        return;
    }

    $fail++;
    echo "  ✗ {$label}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
}

echo "── ARK Contract Parity & Safety Test (P1/P2) ──\n\n";

$slots = json_decode((string)file_get_contents(ARK . '/slots.json'), true);
$manifest = json_decode((string)file_get_contents(ARK . '/theme.manifest.json'), true);
$readme = (string)file_get_contents(ARK . '/docs/README.md');

$slotKeys = array_keys($slots);
$manifestSlots = $manifest['supported_slots'] ?? [];

t("slots.json has 16 slots", count($slotKeys) === 16, 'count=' . count($slotKeys));
t(
    "manifest supported_slots matches slots.json set",
    array_values($manifestSlots) === $slotKeys,
    'manifest-only: ' . implode(',', array_diff($manifestSlots, $slotKeys))
    . ' slots-only: ' . implode(',', array_diff($slotKeys, $manifestSlots))
);

// Parse README slot table rows: | `id` | purpose | accepts | multiple | ...
$readmeRows = [];
if (preg_match_all(
    '/^\| `([a-z.]+)` \| [^|]+ \| ([a-z_, ]+) \| (yes|no) \|/m',
    $readme,
    $m,
    PREG_SET_ORDER
)) {
    foreach ($m as $row) {
        $readmeRows[$row[1]] = ['accepts' => $row[2], 'multiple' => $row[3]];
    }
}
t("README slot table lists all 16 slots", count($readmeRows) === 16, 'count=' . count($readmeRows));

$parityOk = true;
foreach ($slotKeys as $id) {
    $acc = implode(', ', $slots[$id]['accepts']);
    $multi = $slots[$id]['multiple'] ? 'yes' : 'no';
    if (
        !isset($readmeRows[$id])
        || $readmeRows[$id]['accepts'] !== $acc
        || $readmeRows[$id]['multiple'] !== $multi
    ) {
        $parityOk = false;
        echo "    (drift: {$id} slots.json={$acc}/{$multi} vs readme="
            . ($readmeRows[$id]['accepts'] ?? 'MISSING') . '/'
            . ($readmeRows[$id]['multiple'] ?? '') . ")\n";
    }
}
t("README slot table matches slots.json (accepts + multiplicity)", $parityOk);

// No inline on* handlers in any ARK .disyl template.
$files = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator(ARK, FilesystemIterator::SKIP_DOTS)
);
$inlineFound = [];
foreach ($files as $file) {
    if ($file->getExtension() !== 'disyl') {
        continue;
    }
    $content = (string)file_get_contents($file->getPathname());
    if (preg_match('/\son[a-z]+=/i', $content, $mm)) {
        $inlineFound[] = $file->getFilename() . ': ' . $mm[0];
    }
}
t("no inline on* handlers in ARK .disyl templates", empty($inlineFound), implode('; ', array_slice($inlineFound, 0, 5)));

// archive.disyl: no-JS category filter present.
$archive = (string)file_get_contents(ARK . '/public/archive.disyl');
t("archive.disyl has no onchange", !str_contains($archive, 'onchange'));
t(
    "archive.disyl uses no-JS category links",
    str_contains($archive, 'aria-labelledby="ark-category-label"')
    && str_contains($archive, 'class="ark-button"')
);

// public.disyl slot count comment.
$layout = (string)file_get_contents(ARK . '/layouts/public.disyl');
t("public.disyl slot count comment = 16", str_contains($layout, '16 governed {ikb_slot} markers'));

echo "\n── Results: {$pass} passed, {$fail} failed ──\n";

exit($fail > 0 ? 1 : 0);
