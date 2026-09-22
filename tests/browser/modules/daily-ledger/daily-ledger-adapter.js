/**
 * Daily Ledger — Browser Test Adapter
 *
 * Configures the WorkbenchFixture for the daily-ledger module.
 * Credentials come from environment (never hardcoded):
 *   TEST_BASE_URL   — target site (e.g. https://baronledger.test)
 *   TEST_ADMIN_USER — admin login username
 *   TEST_ADMIN_PASS — admin login password
 *   TEST_ADMIN_FULL_NAME — optional; overrides reading the real dl_users.full_name
 *   TEST_TENANT_ID  — tenant whose dl_users holds the login account (default 207)
 *
 * When TEST_ADMIN_USER / TEST_ADMIN_PASS are NOT set, a dedicated browser-test admin is
 * seeded on the target tenant so the suite is self-sufficient. The previous fallback
 * (Ledger-Admin / ledger123) silently stopped matching after a live data restore, and
 * every daily-ledger browser spec then failed at login - so nothing could be verified.
 * Seeding is REFUSED unless the target host is local/test; a test run must never mint an
 * admin account on a real site.
 */

// @ts-check
var path = require('path');
var { execFileSync } = require('child_process');
var { createWorkbenchTest } = require('../../WorkbenchFixture');

// tests/browser/modules/daily-ledger -> up 4 = repo root. Using 3 levels landed on
// "tests/" and produced the path tests/tests/daily-ledger/..., so the seed silently
// failed and every spec logged in with a stale password. Count the levels.
var repoRoot = path.join(__dirname, '..', '..', '..', '..');
var appUrl = process.env.TEST_BASE_URL || 'http://baronledger.test';
var tenantId = parseInt(process.env.TEST_TENANT_ID || '207', 10);

var adminUser = process.env.TEST_ADMIN_USER || process.env.ADMIN_USER || null;
var adminPass = process.env.TEST_ADMIN_PASS || process.env.ADMIN_PASS || null;

function isLocalTestHost(url) {
    try {
        var host = new URL(url).hostname;
        return host === 'localhost' || host === '127.0.0.1' || host === '::1' || /\.test$/.test(host) || /\.local$/.test(host);
    } catch (e) {
        return false;
    }
}

if (!adminUser || !adminPass) {
    adminUser = adminUser || 'Browser-QA';
    adminPass = adminPass || 'browser-fixture-pass';
}

var needsSeed = isLocalTestHost(appUrl);

process.env.APP_URL = appUrl;

/** Idempotently ensure the login account exists with the password this suite will type. */
function seedBrowserAccount() {
    if (!needsSeed) {
        // Refuse to mint an admin account on anything that is not a local/test host.
        throw new Error('refusing to seed a browser-test admin on non-test host: ' + appUrl);
    }
    execFileSync('php', [
        path.join(repoRoot, 'tests', 'daily-ledger', 'daily_ledger_browser_login_fixture.php'),
        'ensure', adminUser, String(tenantId), adminPass,
    ], { cwd: repoRoot, timeout: 30000, stdio: ['ignore', 'ignore', 'pipe'] });
}

var dl = createWorkbenchTest({
    appUrl: appUrl,
    loginPath: '/daily-ledger/login',
    landingPath: '/daily-ledger/admin/dashboard',
    adminUser: adminUser,
    adminPass: adminPass,
    // Runs once per worker before the first login, so the account always matches the
    // credentials this suite uses - whatever a data restore did to the real accounts.
    prepareLogin: seedBrowserAccount,
    // The login form's Full Name is PERSISTED to dl_users.full_name by the server, so the
    // fixture must send the account's real name: sending the username silently renames the
    // account on every test run. Read it via the browser login fixture.
    adminFullName: process.env.TEST_ADMIN_FULL_NAME || null,
    adminTenantId: tenantId,
});

module.exports = { test: dl.test, expect: dl.expect };
