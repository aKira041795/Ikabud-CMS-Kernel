/**
 * Daily Ledger — Browser Test Adapter
 *
 * Configures the WorkbenchFixture for the daily-ledger module.
 * Credentials come from environment (never hardcoded):
 *   TEST_BASE_URL   — target site (e.g. https://baronledger.test)
 *   TEST_ADMIN_USER — admin login username
 *   TEST_ADMIN_PASS — admin login password
 */

// @ts-check
var { createWorkbenchTest } = require('../../WorkbenchFixture');

var appUrl = process.env.TEST_BASE_URL || 'http://baronledger.test';
var adminUser = process.env.TEST_ADMIN_USER || process.env.ADMIN_USER || 'Ledger-Admin';
var adminPass = process.env.TEST_ADMIN_PASS || process.env.ADMIN_PASS || 'ledger123';

process.env.APP_URL = appUrl;

var dl = createWorkbenchTest({
    appUrl: appUrl,
    loginPath: '/daily-ledger/login',
    landingPath: '/daily-ledger/admin/dashboard',
    adminUser: adminUser,
    adminPass: adminPass,
});

module.exports = { test: dl.test, expect: dl.expect };
