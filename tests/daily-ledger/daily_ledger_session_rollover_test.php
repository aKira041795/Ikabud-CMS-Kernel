#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Daily Ledger — session rollover (client concern 2026-11-13)
 *
 * "Naa time ma auto log out sya bisan ok ra among connection" — the access token
 * lives for JWT_EXPIRATION (4h by default) and the web client never stored the
 * refresh token login returned, so the session simply ENDED mid-shift.
 *
 * These assertions prove the renewal actually happens from the HttpOnly refresh
 * cookie, and — just as important — that it does NOT happen for a revoked,
 * unverifiable, or absent refresh token.
 */

$base = dirname(__DIR__, 2);

// Cookie writers bail out when headers were already sent. Output buffering keeps
// headers_sent() false in CLI so the renewal path is exercised for real.
ob_start();
require_once $base . '/src/helpers/cli-bootstrap.php';

$_SERVER['HTTP_HOST'] = 'baronledger.test';
$_SERVER['SERVER_NAME'] = 'baronledger.test';
$_SERVER['REQUEST_URI'] = '/daily-ledger/ledger';
unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION']);

$app = kernelCliBootstrap($base);
require_once $base . '/src/helpers/module-manager.php';
require_once $base . '/modules/daily-ledger/handlers.php';

$cashier = [
    'sub' => 'cashier:7',
    'id' => 7,
    'username' => 'rollover-cashier',
    'name' => 'Rollover Cashier',
    'role' => 'cashier',
    'source' => 'daily-ledger',
];

// ── 1. Expired access token + valid refresh cookie → renewed ────────────
$tokens = dl_generateAuthTokens($cashier);
$_COOKIE = [
    dlCookieName() => 'stale.access.token',
    dlRefreshCookieName() => $tokens['refresh_token'],
];
$renewedUser = dlUserFromRequest();
$mintedToken = (string)($_COOKIE[dlCookieName()] ?? '');
$mintedClaims = $mintedToken !== '' ? app()->jwt()->verify($mintedToken) : null;

// ── 2. Must-refuse directions ───────────────────────────────────────────
$revoked = dl_generateAuthTokens($cashier);
dl_revokeRefreshToken($revoked['refresh_token']);
$_COOKIE = [
    dlCookieName() => 'stale.access.token',
    dlRefreshCookieName() => $revoked['refresh_token'],
];
$revokedUser = dlUserFromRequest();

$_COOKIE = [
    dlCookieName() => 'stale.access.token',
    dlRefreshCookieName() => 'not-a-jwt',
];
$garbageUser = dlUserFromRequest();

$_COOKIE = [dlCookieName() => 'stale.access.token'];
$noCookieUser = dlUserFromRequest();

// ── 3. Source contracts for the UI half of the fix ──────────────────────
$layoutSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/layouts/app.disyl');
$modalSrc = (string)file_get_contents($base . '/templates/modules/daily-ledger/cashier/modal_patch.disyl');
$handlersSrc = (string)file_get_contents($base . '/modules/daily-ledger/handlers.php');
ob_end_clean();

require_once __DIR__ . '/../harness/TestHarness.php';

$h = new TestHarness('daily-ledger-session-rollover', TestHarness::MODE_PURE, 'baronledger.test');
$h->allowLogLines('daily-ledger session auto-refreshed');
$h->fingerprint('modules/daily-ledger/handlers.php');
$h->fingerprint('templates/modules/daily-ledger/layouts/app.disyl');
$h->fingerprint('templates/modules/daily-ledger/cashier/modal_patch.disyl');

$h->section('Expired access token is renewed, not ended');
$h->test(
    'a stale access token is replaced from the refresh cookie',
    is_array($renewedUser) && ($renewedUser['sub'] ?? '') === 'cashier:7',
    'dlUserFromRequest() returned ' . (is_array($renewedUser) ? ($renewedUser['sub'] ?? 'no sub') : 'null')
);
$h->test(
    'the renewed access token is written back to the module cookie',
    $mintedToken !== '' && $mintedToken !== 'stale.access.token'
);
$h->test(
    'the renewed access token verifies and keeps the role',
    is_array($mintedClaims) && ($mintedClaims['role'] ?? '') === 'cashier'
        && ($mintedClaims['source'] ?? '') === 'daily-ledger'
);

$h->section('Renewal refuses what it must refuse');
$h->test('a revoked refresh token does not renew the session', $revokedUser === null);
$h->test('an unverifiable refresh token does not renew the session', $garbageUser === null);
$h->test('with no refresh cookie the session still ends', $noCookieUser === null);

$h->section('Login / logout / refresh wiring');
$h->test('refresh cookie keeps the long-lived token out of JS reach',
    str_contains($handlersSrc, "'httponly' => true") && str_contains($handlersSrc, "return 'daily_ledger_refresh';"));
$h->test('refresh cookie path follows the install subpath (shared hosting)',
    dlRefreshCookiePath() === '/daily-ledger');
$_SERVER['IKABUD_BASE_PATH'] = '/kernelappos';
$subpathCookie = dlRefreshCookiePath();
unset($_SERVER['IKABUD_BASE_PATH']);
$h->test('a subpath install scopes the refresh cookie correctly',
    $subpathCookie === '/kernelappos/daily-ledger', 'got ' . $subpathCookie);
$h->test('login arms the refresh cookie',
    preg_match("/dlSetAuthCookie\\(\\\$tokens\\['token'\\][^)]*\\).*?dlSetRefreshCookie\\(/s", $handlersSrc) === 1);
$h->test('logout revokes and clears the refresh cookie',
    preg_match('/function dailyLedgerLogout\(\): void\s*\{.*?dl_revokeRefreshToken.*?dlClearRefreshCookie/s', $handlersSrc) === 1);
$h->test('the refresh endpoint accepts the cookie instead of a JS-held token',
    str_contains($handlersSrc, 'kernelCookie(dlRefreshCookieName()'));

$h->section('Row-point persistence (client concern 2)');
$h->test('the shell captures and restores the scroll position', str_contains($layoutSrc, 'dlCaptureScroll')
    && str_contains($layoutSrc, 'dlRestoreScroll')
    && str_contains($layoutSrc, "document.getElementById('main-content')"));
$h->test('a same-view refresh restores, a different view does not',
    str_contains($layoutSrc, 'var sameView') && str_contains($layoutSrc, 'window.dlRestoreScroll(scrollKey)'));
$h->test('the stock-adjustment modal anchors on the row it was opened from',
    str_contains($modalSrc, 'DL_SCROLL_ANCHOR_PRODUCT')
    && str_contains($modalSrc, 'detail.product_id'));
$h->test('Ctrl+S and Cmd+S save the modal while it is open',
    str_contains($modalSrc, '@keydown.window.ctrl.s=')
    && str_contains($modalSrc, '@keydown.window.meta.s=')
    && str_contains($modalSrc, '$event.preventDefault(), saveChanges()'));
$h->test('the save controls stay reachable while the modal body scrolls',
    str_contains($modalSrc, 'min-h-0 flex-1 overflow-y-auto')
    && str_contains($modalSrc, 'shrink-0 border-t'));
$h->test('a second save cannot start while one is in flight',
    str_contains($modalSrc, 'if (this.saving) return;'));

$h->done();
