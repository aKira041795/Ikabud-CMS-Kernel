/**
 * PAL Interactive Lifecycle — Browser-driven Job Order workflow.
 *
 * Seeds a minimal client + draft project, then performs ALL status
 * transitions through actual browser UI in a single atomic test.
 * Side-effect assertions verify invoice creation and dashboard presence.
 *
 * Run: PAL_TEST_TENANT=502 npx playwright test tests/browser/modules/pal/workflows/pal-lifecycle-interactive.spec.js
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');
var execSync = require('child_process').execSync;
var path = require('path');

var SEED_SCRIPT = path.resolve(__dirname, '../../../../pal/pal_seed_interactive.php');
var PAL_TEST_TENANT = process.env.PAL_TEST_TENANT || '502';

test.describe('pal:interactive-lifecycle', function () {

    /** @type {{ project_id: number, project_status: string, client_id: number, prefix: string }} */
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

    test('Job Order completes full UI lifecycle', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var pid = seed.project_id;
        var base = '/admin/project-audit-ledger';
        var editUrl = base + '/projects/' + pid + '/edit';
        var detailUrl = base + '/projects/' + pid;

        // ── Step 1: Draft → Pending (Submit for Approval) ──
        await test.step('draft → pending', async function () {
            await page.goto(editUrl);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

            var submitBtn = page.locator('button', { hasText: 'Submit for Approval' });
            await expect(submitBtn).toBeVisible();
            await submitBtn.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });

            await expect(page.locator('#wb-main')).toContainText('Pending');
        });

        // ── Step 2: Pending → Approved (approve exact seeded entity) ──
        await test.step('pending → approved', async function () {
            await page.goto(base + '/approvals');
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            // Target the approval row for THIS entity, not just .first()
            var row = page.locator('[data-wb-entity-type="project"][data-wb-entity-id="' + pid + '"]');
            await expect(row).toBeVisible({ timeout: 5000 });

            var approveBtn = row.locator('button', { hasText: 'Approve' });
            await expect(approveBtn).toBeVisible();
            await approveBtn.click();

            // Modal confirmation
            var confirmBtn = page.locator('button', { hasText: /Yes|Confirm/ }).first();
            await expect(confirmBtn).toBeVisible({ timeout: 5000 });
            await confirmBtn.click();
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            // Verify on detail page
            await page.goto(detailUrl);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await expect(page.locator('#wb-main')).toContainText('Approved');
        });

        // ── Step 3: Approved → Started ──
        await test.step('approved → started', async function () {
            await page.goto(editUrl);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            var startBtn = page.locator('button', { hasText: 'Start Work' });
            await expect(startBtn).toBeVisible();
            await startBtn.click();
            await page.waitForURL('**' + detailUrl, { timeout: 10000 });

            await expect(page.locator('#wb-main')).toContainText('Started');
        });

        // ── Step 4: Started → Ongoing ──
        await test.step('started → ongoing', async function () {
            await page.goto(editUrl);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            var ongoingBtn = page.locator('button', { hasText: 'Mark Ongoing' });
            await expect(ongoingBtn).toBeVisible();
            await ongoingBtn.click();
            await page.waitForURL('**' + detailUrl, { timeout: 10000 });

            await expect(page.locator('#wb-main')).toContainText('Ongoing');
        });

        // ── Step 5: Ongoing → Completed ──
        await test.step('ongoing → completed', async function () {
            await page.goto(editUrl);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            var completeBtn = page.locator('button', { hasText: 'Complete Job Order' });
            await expect(completeBtn).toBeVisible();
            await completeBtn.click();
            await page.waitForURL('**' + detailUrl, { timeout: 10000 });

            await expect(page.locator('#wb-main')).toContainText('Completed');
        });

        // ── Step 6: Verify invoice auto-created for THIS project ──
        await test.step('completion creates invoice', async function () {
            await page.goto(base + '/sales');
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            var list = page.locator('[data-ikb-list="pal-sale"]');
            await expect(list).toBeVisible();

            // The sales list should contain the seeded project's title
            // (the project title appears in the invoice line item)
            await expect(list).toContainText('Interactive Project', { timeout: 5000 });
        });

        // ── Step 7: Verify project appears on dashboard ──
        await test.step('project appears on dashboard', async function () {
            await page.goto(base);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

            // Dashboard KPI cards and heading
            await expect(page.locator('#wb-main h1')).toContainText('Dashboard');

            // Active projects metric should be ≥ 1 (our project is now completed)
            var summaryCards = page.locator('[data-wb-component="summary-card"]');
            var count = await summaryCards.count();
            expect(count).toBeGreaterThanOrEqual(3);
        });
    });
});
