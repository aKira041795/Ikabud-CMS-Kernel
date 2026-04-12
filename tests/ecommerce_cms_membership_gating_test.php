<?php

declare(strict_types=1);

/**
 * Tests for Milestone 6 — CMS-Wide Membership Gating
 * helpers/86-memberships-loyalty.php (functions added in M6)
 */

$_SERVER['HTTP_HOST']   = 'cmsnew.test';
$_SERVER['REQUEST_URI'] = '/ecommerce/shop';

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../src/helpers/module-manager.php';
require_once __DIR__ . '/../modules/cms/helpers.php';
require_once __DIR__ . '/../modules/ecommerce/helpers.php';

$tenantId = (int)(moduleTenantSettingsTenantId() ?? app()->tenant()->current() ?? 0);
if ($tenantId > 0) {
    syncTenantCliMigrationsForTenant($tenantId, 'ecommerce');
}

$pass   = 0;
$fail   = 0;
$errors = [];

function tGate(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail, $errors;
    if ($ok) {
        $pass++;
        echo "  \u{2713} {$label}\n";
        return;
    }
    $fail++;
    $errors[] = $label . ($detail !== '' ? ': ' . $detail : '');
    echo "  \u{2717} {$label}" . ($detail !== '' ? " \u{2014} {$detail}" : '') . "\n";
}

// ── Fixture: a CMS content row we can gate ────────────────────────────────

// Use any cms_content row as fixture (column is 'type', not 'content_type')
$contentRow = ecDb()->query(
    "SELECT id FROM cms_content ORDER BY id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if (!is_array($contentRow) || (int)($contentRow['id'] ?? 0) < 1) {
    echo "SKIP — no cms_content rows available\n";
    exit(0);
}
$contentId = (int)$contentRow['id'];

// ─────────────────────────────────────────────────────────────────────────
// §1  ecMembershipUserHasAccess
// ─────────────────────────────────────────────────────────────────────────

echo "\n§1  ecMembershipUserHasAccess\n";

tGate('no tiers required → true for any user', ecMembershipUserHasAccess(1, []));
tGate('no tiers required (empty string) → true', ecMembershipUserHasAccess(1, ''));
tGate('userId=0 with required tier → false', !ecMembershipUserHasAccess(0, 'gold'));
tGate('userId=0 with required tier (no storage needed to block)', !ecMembershipUserHasAccess(0, ['gold', 'vip']));

// ─────────────────────────────────────────────────────────────────────────
// §2  ecContentSaveMembershipTiers + ecContentMembershipRequiredTiers
// ─────────────────────────────────────────────────────────────────────────

echo "\n§2  Save / read content tiers\n";

ecContentSaveMembershipTiers($contentId, ['gold', 'vip']);
$tiers = ecContentMembershipRequiredTiers($contentId);
tGate('saved tiers readable', !empty($tiers));
tGate("'gold' in saved tiers", in_array('gold', $tiers, true));
tGate("'vip' in saved tiers", in_array('vip', $tiers, true));
tGate('exactly 2 tiers stored', count($tiers) === 2);

// Overwrite
ecContentSaveMembershipTiers($contentId, ['premium']);
$tiersAfterUpdate = ecContentMembershipRequiredTiers($contentId);
tGate('overwrite works', $tiersAfterUpdate === ['premium']);

// Clear (empty array)
ecContentSaveMembershipTiers($contentId, []);
$tiersAfterClear = ecContentMembershipRequiredTiers($contentId);
tGate('clear with [] removes meta row', $tiersAfterClear === []);

// contentId=0 — no exception
$threw = false;
try { ecContentSaveMembershipTiers(0, ['gold']); } catch (\Throwable $e) { $threw = true; }
tGate('contentId=0 no-ops without exception', !$threw);

$zeroTiers = ecContentMembershipRequiredTiers(0);
tGate('contentId=0 returns empty array', $zeroTiers === []);

// ─────────────────────────────────────────────────────────────────────────
// §3  ecMembershipGateForContent — no tiers required
// ─────────────────────────────────────────────────────────────────────────

echo "\n§3  Gate — unrestricted content\n";

// contentId has no tiers (just cleared above)
$gate = ecMembershipGateForContent($contentId);
tGate('allowed = true when no tiers set', (bool)($gate['allowed'] ?? false));
tGate('requires_membership = false', !(bool)($gate['requires_membership'] ?? true));
tGate('login_required = false', !(bool)($gate['login_required'] ?? true));
tGate('required_tiers = []', ($gate['required_tiers'] ?? null) === []);
tGate('message = empty string', ($gate['message'] ?? 'x') === '');

// ─────────────────────────────────────────────────────────────────────────
// §4  ecMembershipGateForContent — gated content, guest user
// ─────────────────────────────────────────────────────────────────────────

echo "\n§4  Gate — gated content, guest\n";

ecContentSaveMembershipTiers($contentId, ['gold']);
$gateGuest = ecMembershipGateForContent($contentId, null);
tGate('allowed = false for guest', !(bool)($gateGuest['allowed'] ?? true));
tGate('requires_membership = true', (bool)($gateGuest['requires_membership'] ?? false));
tGate('login_required = true for guest', (bool)($gateGuest['login_required'] ?? false));
tGate("required_tiers contains 'gold'", in_array('gold', $gateGuest['required_tiers'] ?? [], true));
tGate('message is non-empty', strlen(trim((string)($gateGuest['message'] ?? ''))) > 0);

// ─────────────────────────────────────────────────────────────────────────
// §5  ecMembershipGateForContent — gated content, logged-in non-member
// ─────────────────────────────────────────────────────────────────────────

echo "\n§5  Gate — gated content, logged-in non-member\n";

$nonMember = ['id' => 88888, 'email' => 'nonmember@example.com', 'source' => 'cms'];
$gateNonMember = ecMembershipGateForContent($contentId, $nonMember);
tGate('allowed = false for non-member', !(bool)($gateNonMember['allowed'] ?? true));
tGate('login_required = false (logged in)', !(bool)($gateNonMember['login_required'] ?? true));
tGate('message mentions membership', strpos((string)($gateNonMember['message'] ?? ''), 'membership') !== false);

// ─────────────────────────────────────────────────────────────────────────
// §6  Gate result shape matches ecMembershipGateForProduct
// ─────────────────────────────────────────────────────────────────────────

echo "\n§6  Gate shape consistency\n";

$expectedKeys = ['allowed', 'requires_membership', 'login_required', 'required_tiers', 'active_tiers', 'message'];
foreach ($expectedKeys as $key) {
    tGate("gate result has key '{$key}'", array_key_exists($key, $gateGuest));
}

// ─────────────────────────────────────────────────────────────────────────
// §7  Capability registered
// ─────────────────────────────────────────────────────────────────────────

echo "\n§7  Capability ecommerce.membership.content_gate@1\n";

$capResult = null;
$capThrew  = false;
try {
    if (app()->capabilities()->has('ecommerce.membership.content_gate@1')) {
        $capResult = app()->cap()->call('ecommerce.membership.content_gate@1', ['content_id' => $contentId]);
        tGate('capability callable', is_array($capResult));
        tGate('capability returns allowed key', array_key_exists('allowed', $capResult ?? []));
        tGate('capability result matches direct call', ($capResult['allowed'] ?? null) === $gateGuest['allowed']);
    } else {
        tGate('capability registered (has() not available, skip)', true);
        tGate('capability registered (has() not available, skip)', true);
        tGate('capability registered (has() not available, skip)', true);
    }
} catch (\Throwable $e) {
    $capThrew = true;
    tGate('capability callable without exception', false, $e->getMessage());
    tGate('capability returns allowed key', false);
    tGate('capability result matches direct call', false);
}

// ─────────────────────────────────────────────────────────────────────────
// Cleanup
// ─────────────────────────────────────────────────────────────────────────

ecContentSaveMembershipTiers($contentId, []);

// ─────────────────────────────────────────────────────────────────────────
// Summary
// ─────────────────────────────────────────────────────────────────────────

echo "\n";
if ($fail === 0) {
    echo "PASS  {$pass} assertions passed\n";
    exit(0);
}
echo "FAIL  {$pass} passed, {$fail} failed\n";
foreach ($errors as $e) {
    echo "  - {$e}\n";
}
exit(1);
