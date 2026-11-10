<?php

declare(strict_types=1);

/**
 * ACCEPTANCE ORACLE — concern #3, the missing half.
 *
 * Client, verbatim 2026-11-10:
 *   "... gusto ni sir naay correcting area, ug naay remark both branch about sa issue then
 *    makita pod na namo kauban sa variance."
 *   "... no need makausab ang branch but if ever naay correction sa mga delivery need lang
 *    naay correcting area both branch ug need naay remarks if unsay nahitabo, then kami ra
 *    diri sa office ang makausab."
 *
 * WHAT IS ALREADY TRUE (verified 2026-11-10, 231 assertions across six suites):
 *   - apiSaveDeliveryVarianceRemark exists and authorizes each SIDE independently: a user
 *     assigned to the sending branch may write only the sender remark, a user assigned to the
 *     receiving branch only the receiver remark;
 *   - dl_variance_flags carries sender_remark and receiver_remark, and the variance page
 *     renders both, with who wrote it, from which branch, and when;
 *   - the correction itself stays office-only (apiResolveDeliveryVariance is admin-only).
 *
 * WHAT IS MISSING, AND WHY THIS FILE FAILS TODAY: nothing calls the endpoint. The backend and
 * the read surface exist; the CAPTURE surface does not. So "both branches can remark" cannot
 * happen in practice and the office reads "No sender remark recorded." for ever.
 *
 * THIS ORACLE ASSERTS THE OUTCOME THE CLIENT ASKED FOR: a branch user, on the surface they
 * already use for that branch's variances, is offered an input for their OWN side and can send
 * it. It is deliberately blind to how the input is styled or wired - only that both sides are
 * offered and both post to the existing endpoint.
 *
 * Run: php tools/checks/dl-two-sided-remark-acceptance.php
 * Exit 0 = PASS (both sides capturable), exit 1 = FAIL.
 */

$root = dirname(__DIR__, 2);
$failures = [];
$passes = [];

$templates = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/templates/modules/daily-ledger'));
foreach ($it as $file) {
    if ($file->isFile() && str_ends_with($file->getFilename(), '.disyl')) {
        $templates[] = $file->getPathname();
    }
}

// AC1 - some branch-facing surface posts to the existing two-sided remark endpoint.
$posting = [];
foreach ($templates as $path) {
    $src = (string) file_get_contents($path);
    if (str_contains($src, 'variances/delivery-remark')) {
        $posting[] = $path;
    }
}
if ($posting === []) {
    $failures[] = 'No template posts to /api/v1/variances/delivery-remark, so no branch can record its side of a delivery discrepancy. The endpoint exists but is unreachable from the UI.';
} else {
    $passes[] = 'a surface posts to the delivery-remark endpoint: ' . count($posting) . ' template(s)';
}

// AC2 - BOTH sides are capturable. The client asked for remarks from BOTH branches, so a
// surface that only ever sends one side is not the outcome.
$sides = [];
foreach ($posting as $path) {
    $src = (string) file_get_contents($path);
    if (preg_match('/[\'"]sender[\'"]/', $src) === 1) { $sides['sender'] = true; }
    if (preg_match('/[\'"]receiver[\'"]/', $src) === 1) { $sides['receiver'] = true; }
}
if (count($sides) < 2) {
    $failures[] = 'Only ' . (count($sides) ?: 'no') . ' side(s) capturable by the UI. Concern #3 requires remarks from BOTH the sending and the receiving branch.';
} else {
    $passes[] = 'both sides capturable: sender and receiver';
}

// AC3 - the capture must not smuggle in correction power. The client was explicit that only
// the office may change the delivery, so a remark surface must never also call a
// correction/resolution endpoint for the same control.
foreach ($posting as $path) {
    $src = (string) file_get_contents($path);
    $remarkPos = strpos($src, 'variances/delivery-remark');
    $window = substr($src, max(0, $remarkPos - 3000), 6000);
    foreach (['resolve-delivery-variance', 'correct-consignee-discrepancy', 'variances/status'] as $forbidden) {
        if (str_contains($window, $forbidden)) {
            $failures[] = basename($path) . ': the remark control also posts to "' . $forbidden . '". A branch remark must record words, never change the discrepancy.';
        }
    }
}
if ($failures === []) {
    $passes[] = 'the remark surface grants no correction power';
}

// AC4 - the office must still be able to read both sides next to the variance.
$adminVariances = $root . '/templates/modules/daily-ledger/admin/variances.disyl';
if (is_file($adminVariances)) {
    $src = (string) file_get_contents($adminVariances);
    if (!str_contains($src, 'sender_remark') || !str_contains($src, 'receiver_remark')) {
        $failures[] = 'The variance page no longer renders sender_remark and receiver_remark together; the office must see both sides beside the variance.';
    } else {
        $passes[] = 'the office still sees both remarks beside the variance';
    }
} else {
    $failures[] = 'admin/variances.disyl is missing.';
}

echo "── concern #3 acceptance ──\n";
foreach ($passes as $p) {
    echo "  ✅ {$p}\n";
}
foreach ($failures as $f) {
    echo "  ❌ {$f}\n";
}
echo $failures === [] ? "\nRESULT: PASS\n" : "\nRESULT: FAIL (" . count($failures) . ")\n";
exit($failures === [] ? 0 : 1);
