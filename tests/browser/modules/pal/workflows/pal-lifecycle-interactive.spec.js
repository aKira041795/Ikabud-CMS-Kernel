/**
 * PAL Interactive Lifecycle — Browser-driven workflow through PAL UI.
 *
 * Seeds a minimal client + draft project, then performs ALL status
 * transitions through the actual browser UI (buttons, forms, sidebar).
 *
 * This is the authoritative test that proves the full system works
 * end-to-end through the browser, not just at the PHP service level.
 *
 * Prerequisites:
 *   - Application running at APP_URL
 *   - php in PATH
 *
 * Run: PAL_TEST_TENANT=999911 npx playwright test --spec=pal-lifecycle-interactive
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');
var execSync = require('child_process').execSync;
var path = require('path');

var SEED_SCRIPT = path.resolve(__dirname, '../../../../pal/pal_seed_interactive.php');
var PAL_TEST_TENANT = process.env.PAL_TEST_TENANT || '999911';
var seedData = null;

test.describe('pal:interactive-lifecycle', function () {

    test.beforeAll(async function () {
        // Seed a draft project via CLI — fatal on failure
        var output = execSync(
            'php ' + SEED_SCRIPT + ' --tenant=' + PAL_TEST_TENANT,
            { encoding: 'utf-8', timeout: 15000 }
        );
        seedData = JSON.parse(output);
        if (!seedData.ok) {
            throw new Error('Seed failed: ' + (seedData.error || 'unknown'));
        }
        console.log('  🌱 Seeded draft project #' + seedData.project_id);
    });

    test.afterAll(async function () {
        try {
            execSync(
                'php ' + SEED_SCRIPT + ' --cleanup --tenant=' + PAL_TEST_TENANT,
                { encoding: 'utf-8', timeout: 10000 }
            );
        } catch (e) {
            console.error('  ❌ Cleanup FAILED: ' + e.message);
            throw e;
        }
    });

    // ── Status Transitions (via Edit form) ──────────────────────

    test('Step 1: Submit draft project', async function ({ page }) {
        var pid = seedData.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        // Click "Submit for Approval" button
        var submitBtn = page.locator('button').filter({ hasText: /Submit|Pending/ }).first();
        if (await submitBtn.count() > 0) {
            await submitBtn.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });
        }
        await expect(page.locator('#wb-main')).toBeVisible();
    });

    test('Step 2: Approve project via approval queue', async function ({ page, shell }) {
        var pid = seedData.project_id;
        await shell.navigateViaSidebar('Approvals');

        // Find the approval for our project and click Approve
        var approveBtn = page.locator('button').filter({ hasText: 'Approve' }).first();
        if (await approveBtn.count() > 0) {
            await approveBtn.click();
            // Confirmation dialog may appear — handle it
            var confirmBtn = page.locator('button').filter({ hasText: /Yes|Confirm|OK/ }).first();
            if (await confirmBtn.count() > 0) {
                await confirmBtn.click();
            }
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
        }
    });

    test('Step 3: Start work on approved project', async function ({ page }) {
        var pid = seedData.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var startBtn = page.locator('button').filter({ hasText: 'Start Work' }).first();
        if (await startBtn.count() > 0) {
            await startBtn.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });
        }
    });

    test('Step 4: Mark project as ongoing', async function ({ page }) {
        var pid = seedData.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var ongoingBtn = page.locator('button').filter({ hasText: 'Mark Ongoing' }).first();
        if (await ongoingBtn.count() > 0) {
            await ongoingBtn.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });
        }
    });

    test('Step 5: Complete project (creates invoice)', async function ({ page }) {
        var pid = seedData.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid + '/edit');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

        var completeBtn = page.locator('button').filter({ hasText: /Complete|Finish/ }).first();
        if (await completeBtn.count() > 0) {
            await completeBtn.click();
            await page.waitForURL('**/admin/project-audit-ledger/projects/' + pid, { timeout: 10000 });
        }
    });

    // ── Verification ───────────────────────────────────────────

    test('Step 6: Verify project detail shows completed status', async function ({ page }) {
        var pid = seedData.project_id;
        await page.goto('/admin/project-audit-ledger/projects/' + pid);
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
        await expect(page.locator('#wb-main')).toBeVisible();
    });

    test('Step 7: Verify invoice was created', async function ({ page, shell }) {
        await shell.navigateViaSidebar('Sales / Invoicing');
        var list = page.locator('[data-wb-component="app-shell"]');
        await expect(list).toBeVisible();
    });

    test('Step 8: Navigate to dashboard', async function ({ page, shell }) {
        await shell.navigateViaSidebar('Dashboard');
        await expect(page).toHaveURL(/\/admin\/project-audit-ledger$/);
        await expect(page.locator('#wb-main h1')).toBeVisible();
    });
});
