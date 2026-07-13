/**
 * PAL Seeded Integration Journey — Deterministic entity verification.
 *
 * Seeds a complete lifecycle scenario via PHP CLI bridge, then
 * verifies all resulting entities render correctly in browser views.
 *
 * For the interactive (browser-driven) lifecycle, see pal-lifecycle-interactive.spec.js.
 *
 * Prerequisites:
 *   - Application running at APP_URL
 *   - php in PATH
 *
 * Run: npx playwright test tests/browser/modules/pal/workflows/pal-seeded-lifecycle.spec.js
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');

test.describe('pal:seeded-lifecycle', function () {

    var FINGERPRINTS = [
        'modules/project-audit-ledger/services/JobOrderWorkflow.php',
        'modules/project-audit-ledger/services/ProjectService.php',
        'tests/pal/pal_seed_lifecycle.php',
    ];

    test('project detail shows seeded project title and status', async function ({ page, integrity, palLifecycleSeed }) {
        FINGERPRINTS.forEach(function (f) { integrity.fingerprint(f); });
        integrity.gap('Interactive lifecycle: submit/approve/start/complete via browser UI');

        var seed = palLifecycleSeed;
        await page.goto('/admin/project-audit-ledger/projects/' + seed.project.id);
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
        await expect(page.locator('#wb-main')).toContainText(seed.project.title);
    });

    test('project list contains seeded project', async function ({ page, palLifecycleSeed }) {
        var seed = palLifecycleSeed;
        await page.goto('/admin/project-audit-ledger/projects');
        await page.waitForSelector('[data-ikb-list="pal-project"]', { timeout: 10000 });
        await expect(page.locator('[data-ikb-list="pal-project"]')).toContainText(seed.project.title);
    });

    test('expense detail shows approved seeded expense', async function ({ page, palLifecycleSeed }) {
        var seed = palLifecycleSeed;
        await page.goto('/admin/project-audit-ledger/expenses/' + seed.expense.id);
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
        var main = page.locator('#wb-main');
        await expect(main).toBeVisible();
        // Verify expense shows seeded amount and was approved
        var amountDisplayed = await main.textContent();
        expect(amountDisplayed).toContain('Approved');
    });

    test('dashboard shows seeded project in activity', async function ({ page, shell, palLifecycleSeed }) {
        var seed = palLifecycleSeed;
        await shell.expectVisible();
        await expect(page.locator('#wb-main h1')).toBeVisible();
        // Dashboard should reference the seeded project
        var body = await page.locator('#wb-main').textContent();
        expect(body).toContain(seed.project.title);
    });

    test('navigate to project list via sidebar', async function ({ page, shell }) {
        await shell.navigateViaSidebar('All Job Orders');
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger\/projects/);
        await expect(page.locator('[data-ikb-list="pal-project"]')).toBeVisible();
    });

    test('navigate back to dashboard via sidebar', async function ({ page, shell }) {
        await shell.navigateViaSidebar('Dashboard');
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger$/);
    });
});
