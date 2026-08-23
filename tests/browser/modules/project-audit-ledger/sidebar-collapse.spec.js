// @ts-check
var base = require('../../WorkbenchFixture');
var adapted = base.createWorkbenchTest({
    appUrl: process.env.APP_URL || 'http://palsystem.test',
    loginPath: process.env.LOGIN_PATH || '/project-audit-ledger/login',
    landingPath: process.env.LANDING_PATH || '/admin/project-audit-ledger',
    adminUser: process.env.ADMIN_USER || 'admin',
    adminPass: process.env.ADMIN_PASS || 'pal1234',
});
var test = adapted.test;
var expect = adapted.expect;

test.describe('project-audit-ledger:sidebar-collapse', function () {
    test.use({ viewport: { width: 1280, height: 900 } });

    test('admin sidebar collapses and expands', async function ({ page }) {
        var sidebar = page.locator('#wb-sidebar');
        var toggle = page.locator('[data-wb-sidebar-toggle]');
        var firstNavLabel = page.locator('#wb-sidebar .wb-nav-item__label').first();

        await expect(sidebar).not.toHaveClass(/wb-app-shell__sidebar--collapsed/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(firstNavLabel).toBeVisible();

        await toggle.click();

        await expect(sidebar).toHaveClass(/wb-app-shell__sidebar--collapsed/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'false');
        await expect(firstNavLabel).toBeHidden();

        await toggle.click();

        await expect(sidebar).not.toHaveClass(/wb-app-shell__sidebar--collapsed/);
        await expect(toggle).toHaveAttribute('aria-expanded', 'true');
        await expect(firstNavLabel).toBeVisible();

        await toggle.click();
        await page.locator('#wb-sidebar .wb-nav-item[aria-label="All Job Orders"]').click();
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger\/projects/);
        await expect(page.locator('#wb-main')).toContainText('Job Orders');
    });
});
