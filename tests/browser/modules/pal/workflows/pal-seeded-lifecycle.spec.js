/**
 * PAL Seeded Lifecycle — intended deterministic end-to-end journey.
 *
 * CURRENT STATUS: gap — requires API seeding bridge.
 * When the seeding API is available, this test will:
 *   1. POST /api/v1/project-audit-ledger/projects to create unique data
 *   2. Navigate through the full lifecycle in browser
 *   3. Verify invoice creation, collection, and dashboard aggregates
 *
 * Until then, this test documents the gap and navigates existing data.
 */

// @ts-check
var { test, expect } = require('../../../WorkbenchFixture');

test.describe('pal:seeded-lifecycle', function() {

    test.beforeAll(async function({ integrity }) {
        integrity.fingerprint('modules/project-audit-ledger/services/JobOrderWorkflow.php');
        integrity.gap('Deterministic API seeding — POST /api/v1/.../projects');
        integrity.gap('Invoice creation verification after completion');
        integrity.gap('Dashboard aggregate count verification');
        integrity.gap('Collection recording and approval workflow');
    });

    test('dashboard loads', async function({ page, shell }) {
        await shell.expectVisible();
    });

    test('project list renders', async function({ page }) {
        await page.goto('/admin/project-audit-ledger/projects');
        await expect(page.locator('[data-ikb-list="pal-project"]')).toBeVisible();
    });
});
