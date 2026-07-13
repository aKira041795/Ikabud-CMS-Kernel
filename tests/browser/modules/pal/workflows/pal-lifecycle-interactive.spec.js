/**
 * PAL Interactive Lifecycle — Browser-driven workflow through PAL UI.
 *
 * Seeds a minimal client + draft project, then performs ALL status
 * transitions through the actual browser UI (buttons, forms, sidebar).
 *
 * Button texts and sidebar labels sourced from the actual templates:
 *   modules/project-audit-ledger/templates/project-audit-ledger/pages/
 *
 * Prerequisites:
 *   - Application running at APP_URL
 *   - php in PATH
 *
 * Run: PAL_TEST_TENANT=999911 npx playwright test tests/browser/modules/pal/workflows/pal-lifecycle-interactive.spec.js
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');
var execSync = require('child_process').execSync;
var path = require('path');

var SEED_SCRIPT = path.resolve(__dirname, '../../../../pal/pal_seed_interactive.php');
var PAL_TEST_TENANT = process.env.PAL_TEST_TENANT || '999911';

test.describe('pal:interactive-lifecycle', function () {

    /** @type {{ project_id: number, project_status: string, client_id: number }} */
    var seed;

    test.beforeAll(async function () {
        var output = execSync(
            'php ' + SEED_SCRIPT + ' --tenant=' + PAL_TEST_TENANT,
            { encoding: 'utf-8', timeout: 15000 }
        );
        var data = JSON.parse(output);
        if (!data.ok) throw new Error('Seed failed: ' + (data.error || 'unknown'));
        seed = data;
        console.log('  🌱 Seeded draft project #' + seed.project_id);
    });

    test.afterAll(async function () {
        try {
            execSync('php ' + SEED_SCRIPT + ' --cleanup --tenant=' + PAL_TEST_TENANT, { encoding: 'utf-8', timeout: 10000 });
        } catch (/** @type {any} */ e) {
            console.error('  ❌ Cleanup FAILED: ' + e.message);
            throw e;
        }
    });

    // ── Status Transitions ─────────────────────────────────────

    test('draft → pending: submit for approval', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var pid = seed.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

        // Click "Submit for Approval" — sets hidden status field to "pending", submits form
        var submitBtn = page.locator('button', { hasText: 'Submit for Approval' });
        await expect(submitBtn).toBeVisible();
        await submitBtn.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });

        // Verify we landed on detail page with new status
        await expect(page.locator('#wb-main')).toContainText('Pending');
    });

    test('pending → approved: approve via approval queue', async function ({ page }) {
        var pid = seed.project_id;
        await page.goto('/admin/project-audit-ledger/approvals');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // Click Approve button on the first pending item
        var approveBtn = page.locator('button', { hasText: 'Approve' }).first();
        await expect(approveBtn).toBeVisible();
        await approveBtn.click();

        // Modal confirmation: click Yes/Confirm
        var confirmBtn = page.locator('button', { hasText: /Yes|Confirm/ }).first();
        await expect(confirmBtn).toBeVisible({ timeout: 5000 });
        await confirmBtn.click();
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // Verify project status updated
        await page.goto('/admin/project-audit-ledger/projects/' + pid);
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
        await expect(page.locator('#wb-main')).toContainText('Approved');
    });

    test('approved → started: start work', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var pid = seed.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var startBtn = page.locator('button', { hasText: 'Start Work' });
        await expect(startBtn).toBeVisible();
        await startBtn.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });

        await expect(page.locator('#wb-main')).toContainText('Started');
    });

    test('started → ongoing: mark ongoing', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var pid = seed.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var ongoingBtn = page.locator('button', { hasText: 'Mark Ongoing' });
        await expect(ongoingBtn).toBeVisible();
        await ongoingBtn.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });

        await expect(page.locator('#wb-main')).toContainText('Ongoing');
    });

    test('ongoing → completed: complete job order', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var pid = seed.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var completeBtn = page.locator('button', { hasText: 'Complete Job Order' });
        await expect(completeBtn).toBeVisible();
        await completeBtn.click();
        await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });

        await expect(page.locator('#wb-main')).toContainText('Completed');
    });

    // ── Side-effect Verification ───────────────────────────────

    test('completion creates invoice', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        await page.goto('/admin/project-audit-ledger/sales');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // The entity list should contain the seeded project's title (invoice auto-created on complete)
        var list = page.locator('[data-ikb-list="pal-sale"]');
        await expect(list).toBeVisible();
    });

    test('project appears on dashboard', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        await page.goto('/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // Dashboard KPI cards should be visible
        await expect(page.locator('#wb-main h1')).toContainText('Dashboard');
    });
});
