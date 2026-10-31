// @ts-check
const { test, expect } = require('@playwright/test');

/**
 * Live verification of the CMS extension surfaces on the cmsnew.test tenant.
 *
 * This host runs the multi-tenant install, which makes it the one place two
 * things can be checked together that unit tests cannot:
 *   - the template variables the module screen depends on are actually passed
 *     (a missing one renders `const CMS_MULTI_TENANT = ;` and the whole Alpine
 *     component dies, leaving a page that looks fine but does nothing);
 *   - the tenant-scoped Uninstall action is NOT offered here, because uninstall
 *     removes shared files and module tables that this tenant does not own.
 *
 * The "Check for Updates" click is the end-to-end path: route -> CSRF -> handler
 * -> feed helper -> cached state. It must answer, and it must answer without a
 * server error, even when no installed module declares a feed.
 */

const CMS_URL = 'http://cmsnew.test';

async function login(page) {
    await page.goto(`${CMS_URL}/cms/login`);
    await page.getByText('Username or Email', { exact: true }).locator('..').getByRole('textbox').fill('cmsuitest');
    await page.getByText('Password', { exact: true }).locator('..').locator('input').fill('CmsTest123!');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(`${CMS_URL}/cms/admin`);
}

test('module screen renders, its update surfaces are wired, and uninstall is withheld on a shared install', async ({ page }) => {
    test.setTimeout(90000);

    const pageErrors = [];
    page.on('pageerror', (error) => pageErrors.push(String(error)));

    await login(page);

    const response = await page.goto(`${CMS_URL}/cms/admin/modules`);
    expect(response && response.status(), 'module screen must not 500').toBeLessThan(500);

    await expect(page.getByRole('heading', { name: 'CMS Modules' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Check for Updates' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Upload ZIP' })).toBeVisible();

    // The multi-tenant flag must reach the component. If the handler stopped
    // passing multi_tenant_json this assertion fails here rather than in
    // production as "the buttons do nothing".
    const payload = await page.content();
    expect(payload).toContain('const CMS_MULTI_TENANT = true');

    // Either the installed table or the explicit empty state must be present.
    const tableOrEmpty = page.locator('table').or(page.getByText('No CMS extension modules installed yet.'));
    await expect(tableOrEmpty.first()).toBeVisible();

    // Upload panel must toggle open (proves the component evaluated).
    await page.getByRole('button', { name: 'Upload ZIP' }).click();
    await expect(page.getByText('Upload New Module ZIP')).toBeVisible();

    // Multi-tenant: uninstall must never be offered, because it deletes shared
    // files and module-owned tables this tenant does not alone own.
    await expect(page.getByRole('button', { name: 'Uninstall…' })).toHaveCount(0);

    // End-to-end update check: wraps route + CSRF + handler + feed helper.
    // Assert the ANSWER, not the container it lands in - the first version of
    // this spec scoped to #app, which this layout does not have, and reported a
    // failure for a banner that had in fact rendered.
    await page.getByRole('button', { name: 'Check for Updates' }).click();
    await expect(page.getByText(/up to date|update\(s\) available|No installed extension declares an update feed\.|Could not check:|Update check failed/i))
        .toBeVisible({ timeout: 30000 });

    expect(pageErrors, 'no uncaught page errors').toEqual([]);
});
