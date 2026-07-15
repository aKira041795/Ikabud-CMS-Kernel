/**
 * PAL JO Form Semantic Test — Line items & button flow validation.
 * Tests the JO form's line items table, save-as-draft vs update
 * button logic, and data persistence through the browser UI.
 */
var { test, expect } = require('../../../WorkbenchFixture');
var { WorkbenchObserver } = require('../../../WorkbenchObserver');
var execSync = require('child_process').execSync;
var path = require('path');

var RUN_ID = Date.now();
var PREFIX = 'FORM-' + RUN_ID;
var PROJECT_TITLE = PREFIX + '-Project';
var SEED = path.resolve(__dirname, '../../../../pal/pal_seed_interactive.php');
var PAL_TEST_TENANT = process.env.PAL_TEST_TENANT || '502';
var GAPS = [];

async function goTo(page, url) {
    await page.goto(url);
    await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });
    await page.waitForTimeout(400);
}

async function getManifest(page, urlPath) {
    var sep = urlPath.indexOf('?') === -1 ? '?' : '&';
    await page.goto(urlPath + sep + 'disyl_nocache=1&wb_inspect=1');
    await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });
    await page.waitForFunction(function () { return window.__wbManifest && window.__wbManifest.actions; }, {}, { timeout: 5000 });
    return await page.evaluate(function () { return window.__wbManifest; });
}

test.afterAll(async function () {
    try {
        execSync('php ' + SEED + ' --cleanup --tenant=' + PAL_TEST_TENANT, { encoding: 'utf-8', timeout: 10000 });
    } catch (e) { /* ignore */ }
    if (GAPS.length > 0) {
        console.log('\n  ===== GAPS DISCOVERED =====');
        GAPS.forEach(function (g, i) { console.log('  [' + (i + 1) + '] ' + g); });
    }
});

test.describe('pal:jo-form-semantic', function () {
    test('JO form: line items & button flow', async function ({ page, integrity }) {
        test.setTimeout(180000);
        var base = '/admin/project-audit-ledger';
        var projectId = null;

        // Step 1: Seed
        await test.step('seed', function () {
            var out = execSync('php ' + SEED + ' --tenant=' + PAL_TEST_TENANT, { encoding: 'utf-8', timeout: 15000 });
            var seed = JSON.parse(out);
            if (!seed.ok) throw new Error('Seed failed');
            projectId = seed.project_id;
            console.log('  Seeded project #' + projectId);
        });

        // Step 2: Load edit form
        await test.step('load edit form', async function () {
            await goTo(page, base + '/projects/' + projectId + '/edit');
            var titleInput = page.locator('input[name="title"]');
            await expect(titleInput).toBeVisible({ timeout: 5000 });
            console.log('  Title field visible');
        });

        // Step 3: Check manifest for buttons
        await test.step('manifest buttons', async function () {
            var m = await getManifest(page, base + '/projects/' + projectId + '/edit');
            var keys = m.actions.map(function (a) { return a.key; });
            if (keys.indexOf('save-as-draft') < 0) GAPS.push('save-as-draft missing for draft project');
            if (keys.indexOf('submit-for-approval') < 0) GAPS.push('submit-for-approval missing for draft project');
            console.log('  Actions: ' + JSON.stringify(keys));
        });

        // Step 4: Check line items
        await test.step('line items count', async function () {
            var rows = page.locator('#items-body tr.item-row');
            var count = await rows.count();
            if (count === 0) GAPS.push('No item rows rendered for seeded project');
            console.log('  Existing items: ' + count);
        });

        // Step 5: Add 3 items
        await test.step('add 3 items', async function () {
            var addBtn = page.locator('button:has-text("Add Item")');
            await expect(addBtn).toBeVisible({ timeout: 3000 });
            for (var i = 0; i < 3; i++) { await addBtn.click(); await page.waitForTimeout(300); }
            var rows = page.locator('#items-body tr.item-row');
            var count = await rows.count();
            console.log('  After add: ' + count + ' rows');
            // Check numbering
            var nums = [];
            for (var r = 0; r < count; r++) {
                var n = await rows.nth(r).locator('.item-num').textContent();
                nums.push(parseInt(n.trim()));
            }
            var expected = [];
            for (var e = 1; e <= count; e++) expected.push(e);
            if (JSON.stringify(nums) !== JSON.stringify(expected)) {
                GAPS.push('Item numbering not sequential: ' + JSON.stringify(nums));
            }
            console.log('  Numbers: ' + nums.join(','));
        });

        // Step 6: Fill items
        await test.step('fill items', async function () {
            var rows = page.locator('#items-body tr.item-row');
            var count = await rows.count();
            for (var i = Math.max(0, count - 3); i < count; i++) {
                var row = rows.nth(i);
                await row.locator('input[name$="[particulars]"]').fill(PREFIX + '-Item-' + (i + 1));
                await row.locator('input[name$="[quantity]"]').fill(String(2 + i));
                await row.locator('input[name$="[price_per_unit]"]').fill(String(100 * (i + 1)));
            }
            console.log('  Filled last 3 items');
        });

        // Step 7: Remove item & check renumbering
        await test.step('remove item & renumber', async function () {
            var rows = page.locator('#items-body tr.item-row');
            var before = await rows.count();
            if (before < 2) { GAPS.push('Need >=2 items for removal test'); return; }
            var removeBtns = rows.nth(1).locator('button');
            var btnCount = await removeBtns.count();
            // The remove button contains the unicode X character (U+2715)
            // Try first by content, fall back to last button in row
            var removeBtn = removeBtns.last();
            if (await removeBtns.first().isVisible()) removeBtn = removeBtns.first();
            await removeBtn.click();
            await page.waitForTimeout(300);
            var afterRows = page.locator('#items-body tr.item-row');
            var after = await afterRows.count();
            expect(after).toBe(before - 1);
            var nums = [];
            for (var r = 0; r < after; r++) {
                var n = await afterRows.nth(r).locator('.item-num').textContent();
                nums.push(parseInt(n.trim()));
            }
            var expected = [];
            for (var e = 1; e <= after; e++) expected.push(e);
            if (JSON.stringify(nums) !== JSON.stringify(expected)) {
                GAPS.push('Renumbering failed after removal: ' + JSON.stringify(nums));
            }
            console.log('  Removed: ' + before + ' -> ' + after + ', renumbered=' + (JSON.stringify(nums) === JSON.stringify(expected)));
        });

        // Step 8: Save as Draft via API
        await test.step('save as draft API', async function () {
            var result = await page.evaluate(async function () {
                var csrf = document.querySelector('input[name="_token"]');
                var token = csrf ? csrf.value : '';
                var statusInput = document.querySelector('input[name="status"]');
                var pidInput = document.querySelector('input[name="_project_numeric_id"]');
                var form = document.querySelector('form');
                if (!form) return { ok: false, error: 'no form' };
                if (statusInput) statusInput.value = 'draft';
                var fd = new FormData(form);
                if (token) fd.append('_token', token);
                var r = await fetch('/api/v1/project-audit-ledger/projects/' + (pidInput ? pidInput.value : ''),
                    { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                var d = await r.json();
                return { ok: d.ok, status: r.status };
            });
            if (result.ok) console.log('  Save-as-draft API OK');
            else { GAPS.push('Save-as-draft API failed: ' + JSON.stringify(result)); console.log('  Save-as-draft FAILED'); }
        });

        // Step 9: Verify persistence
        await test.step('verify persistence', async function () {
            var m = await getManifest(page, base + '/projects/' + projectId);
            console.log('  Detail status: ' + m.status);
            if (m.status !== 'draft') GAPS.push('Status should be draft but got "' + m.status + '"');
            var body = await page.locator('#wb-main').textContent();
            if (body.indexOf(PREFIX + '-Item') < 0) GAPS.push('Item data not found on detail page after save');
            else console.log('  Items found on detail page');
        });

        // Step 10: Edit form buttons after save
        await test.step('buttons after draft save', async function () {
            var m = await getManifest(page, base + '/projects/' + projectId + '/edit');
            var keys = m.actions.map(function (a) { return a.key; });
            if (keys.indexOf('save-as-draft') < 0) GAPS.push('save-as-draft missing after draft save');
            if (keys.indexOf('submit-for-approval') < 0) GAPS.push('submit-for-approval missing after draft save');
            console.log('  Post-draft actions: ' + JSON.stringify(keys));
        });

        // Step 11: Submit for approval
        await test.step('submit for approval API', async function () {
            var result = await page.evaluate(async function (opts) {
                var csrf = document.querySelector('input[name="_token"]');
                var token = csrf ? csrf.value : '';
                var pidInput = document.querySelector('input[name="_project_numeric_id"]');
                var fd = new URLSearchParams();
                fd.append('status', 'pending');
                if (token) fd.append('_token', token);
                var r = await fetch('/api/v1/project-audit-ledger/projects/' + (pidInput ? pidInput.value : opts.projectId) + '/status',
                    { method: 'POST', body: fd });
                var d = await r.json();
                return { ok: d.ok };
            }, { projectId: projectId });
            if (result.ok) console.log('  Submit approval OK');
            else { GAPS.push('Submit approval failed'); console.log('  Submit approval FAILED'); }
        });

        // Step 12: Verify pending status
        await test.step('verify pending', async function () {
            var m = await getManifest(page, base + '/projects/' + projectId);
            if (m.status !== 'pending') GAPS.push('Expected pending but got "' + m.status + '"');
            console.log('  Status after submit: ' + m.status);
        });

        // Step 13: Check buttons in pending state
        await test.step('buttons in pending state', async function () {
            var m = await getManifest(page, base + '/projects/' + projectId + '/edit');
            var keys = m.actions.map(function (a) { return a.key; });
            if (keys.indexOf('save-as-draft') >= 0) GAPS.push('OBSERVATION: save-as-draft visible in pending state (confusing?)');
            if (keys.indexOf('submit-for-approval') >= 0) GAPS.push('OBSERVATION: submit-for-approval visible in pending state (confusing?)');
            console.log('  Pending actions: ' + JSON.stringify(keys));
        });

        // Step 14: Update draft title
        await test.step('update draft title', async function () {
            await goTo(page, base + '/projects/' + projectId + '/edit?disyl_nocache=1');
            await page.locator('input[name="title"]').fill(PROJECT_TITLE + '-v2');
            var result = await page.evaluate(async function () {
                var csrf = document.querySelector('input[name="_token"]');
                var token = csrf ? csrf.value : '';
                var pidInput = document.querySelector('input[name="_project_numeric_id"]');
                var statusInput = document.querySelector('input[name="status"]');
                var form = document.querySelector('form');
                if (!form) return { ok: false };
                if (statusInput) statusInput.value = 'draft';
                var fd = new FormData(form);
                if (token) fd.append('_token', token);
                var r = await fetch('/api/v1/project-audit-ledger/projects/' + (pidInput ? pidInput.value : ''),
                    { method: 'POST', body: fd, headers: { 'Accept': 'application/json' } });
                var d = await r.json();
                return { ok: d.ok };
            });
            if (!result.ok) GAPS.push('Draft update API failed');
            else console.log('  Draft update OK');
            await goTo(page, base + '/projects/' + projectId);
            var body = await page.locator('#wb-main').textContent();
            if (body.indexOf('-v2') < 0) GAPS.push('Updated title not reflected on detail page');
            else console.log('  Title update verified');
        });

        // Step 15: Report all gaps
        await test.step('report gaps', function () {
            if (GAPS.length > 0) {
                console.log('\n  ~~~~~~~~~~~~ GAPS DISCOVERED ~~~~~~~~~~~~');
                GAPS.forEach(function (g, i) { console.log('  [' + (i + 1) + '] ' + g); integrity.gap(g); });
            } else {
                console.log('\n  No gaps discovered');
            }
        });
    });
});
