/**
 * Guidance Module Adapter — extends WorkbenchFixture for Guidance Monitoring.
 *
 * Usage (spec files):
 *   const { test, expect } = require('../GuidanceAdapter');
 *   test('dashboard loads', async ({ page, shell }) => {
 *       await shell.expectVisible();
 *   });
 */
const { createWorkbenchTest } = require('./WorkbenchFixture');

module.exports = createWorkbenchTest({
    appUrl: process.env.GUIDANCE_URL || process.env.APP_URL || 'http://palsystem.test',
    loginPath: '/guidance/login',
    landingPath: '/admin/guidance',
    adminUser: process.env.GUIDANCE_ADMIN_USER || process.env.ADMIN_USER || 'admin',
    adminPass: process.env.GUIDANCE_ADMIN_PASS || process.env.ADMIN_PASS || 'password',
});
