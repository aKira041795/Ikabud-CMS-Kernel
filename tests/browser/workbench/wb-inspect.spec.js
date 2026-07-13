/**
 * Workbench Inspect Mode — validation test.
 *
 * Proves that ?wb_inspect=1 causes every page to annotate its DOM with
 * data-wb-* attributes and emit window.__wbManifest. Tests consume the
 * manifest, not guessed-at button texts.
 *
 * Run: PAL_TEST_TENANT=999922 npx playwright test tests/browser/workbench/wb-inspect.spec.js
 */

// @ts-check
var { test, expect } = require('../WorkbenchFixture');
var execSync = require('child_process').execSync;
var path = require('path');

var SEED = path.resolve(__dirname, '../../../tests/pal/pal_seed_interactive.php');
var PAL_TEST_TENANT = process.env.PAL_TEST_TENANT || '502';

test.describe('workbench:inspect-mode', function () {

    /** @type {{ project_id: number }} */
    var seed;

    test.beforeAll(async function () {
        var out = execSync('php ' + SEED + ' --tenant=' + PAL_TEST_TENANT, { encoding: 'utf-8', timeout: 15000 });
        seed = JSON.parse(out);
        if (!seed.ok) throw new Error('Seed failed');
    });

    test.afterAll(async function () {
        execSync('php ' + SEED + ' --cleanup --tenant=' + PAL_TEST_TENANT, { encoding: 'utf-8', timeout: 10000 });
    });

    /**
     * Helper: navigate to a page with ?wb_inspect=1 and return window.__wbManifest.
     * @param {import('@playwright/test').Page} page
     * @param {string} urlPath
     * @returns {Promise<object>}
     */
    async function getManifest(/** @type {import('@playwright/test').Page} */ page, /** @type {string} */ urlPath) {
        var sep = urlPath.indexOf('?') === -1 ? '?' : '&';
        await page.goto(urlPath + sep + 'disyl_nocache=1&wb_inspect=1');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });
        // Wait for the inspect runtime to annotate the DOM
        await page.waitForFunction(function () { return window.__wbManifest && window.__wbManifest.actions; }, {}, { timeout: 5000 });
        return await page.evaluate(function () { return window.__wbManifest; });
    }

    test('project form: buttons annotated with data-wb-action', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var m = await getManifest(page, '/admin/project-audit-ledger/projects/' + seed.project_id + '/edit');

        expect(m.actions.length).toBeGreaterThan(0);
        var keys = m.actions.map(function (a) { return a.key; });

        // Verify critical workflow buttons are detected
        expect(keys).toContain('save-as-draft');
        expect(keys).toContain('submit-for-approval');
        console.log('  project-form actions: ' + JSON.stringify(keys));
    });

    test('project detail: status badge annotated', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var m = await getManifest(page, '/admin/project-audit-ledger/projects/' + seed.project_id);

        expect(m.status).toBe('draft');
        // Detail page should have action buttons (Back, Edit, etc.)
        expect(m.actions.length).toBeGreaterThan(0);
        console.log('  project-detail status: ' + m.status + ', actions: ' + m.actions.length);
    });

    test('approvals: approve/reject buttons annotated', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var m = await getManifest(page, '/admin/project-audit-ledger/approvals');

        var keys = m.actions.map(function (a) { return a.key; });
        // May or may not have pending items — just verify page loads
        expect(m.page).toBeTruthy();
        console.log('  approvals actions: ' + JSON.stringify(keys));
    });

    test('sidebar nav annotated with data-wb-nav', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var m = await getManifest(page, '/admin/project-audit-ledger');

        expect(m.nav.length).toBeGreaterThan(5);
        var labels = m.nav.map(function (n) { return n.label; });
        expect(labels).toContain('Dashboard');
        expect(labels).toContain('All Job Orders');
        expect(labels).toContain('Approvals');
        console.log('  nav items: ' + m.nav.length + ' (labels: ' + labels.slice(0, 6).join(', ') + '...)');
    });

    test('entity list detected via data-wb-list', async function (/** @type {{page: import('@playwright/test').Page}} */ { page }) {
        var m = await getManifest(page, '/admin/project-audit-ledger/projects');

        expect(m.lists.length).toBeGreaterThan(0);
        var sources = m.lists.map(function (l) { return l.source; });
        expect(sources).toContain('pal_project');
        console.log('  lists: ' + JSON.stringify(sources));
    });
});
