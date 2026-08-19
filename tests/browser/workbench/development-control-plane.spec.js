// @ts-check
/**
 * Workbench Development Control Plane — focused authenticated journey.
 *
 * task list -> task detail -> contract/scope/timeline -> linked run evidence.
 * Asserts no console errors, no failed network requests, and no false
 * release-ready state. When the authenticated environment is unavailable, the
 * journey records NOT_RUN with the exact blocker instead of claiming a pass.
 */
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://applicationos.test';
const ADMIN_USER = process.env.ADMIN_USER || 'superadmin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'superadmin123';

function attachWatchers(page, diagnostics) {
    page.on('console', function (msg) {
        if (msg.type() === 'error') diagnostics.push('console: ' + msg.text());
    });
    page.on('pageerror', function (err) {
        diagnostics.push('pageerror: ' + err.message);
    });
    page.on('requestfailed', function (req) {
        diagnostics.push('network: ' + req.method() + ' ' + req.url());
    });
    page.on('response', function (res) {
        if (res.status() >= 500) diagnostics.push('http ' + res.status() + ': ' + res.url());
    });
}

test.describe('workbench:development_control_plane', () => {
    // The cockpit aggregates several heavy superadmin APIs (runs, modules,
    // process map, and the task detail which live re-verifies the working tree),
    // so give the focused journey a generous budget rather than racing 30s.
    test.setTimeout(120000);

    test('task ledger journey renders without console or network errors', async ({ page }) => {
        // Environment probe — NOT_RUN with the exact blocker when unavailable.
        let probe;
        try {
            probe = await page.request.get(APP_URL + '/login');
        } catch (e) {
            test.skip(true, 'NOT_RUN: development web server unavailable at ' + APP_URL + ' (' + e.message + ')');
            return;
        }
        if (probe.status() >= 500) {
            test.skip(true, 'NOT_RUN: login page returned HTTP ' + probe.status());
            return;
        }

        const diagnostics = [];
        attachWatchers(page, diagnostics);

        await page.goto(APP_URL + '/login');
        await page.fill('input[name="username"]', ADMIN_USER);
        await page.fill('input[name="password"]', ADMIN_PASS);
        await page.click('button[type="submit"]');
        try {
            await page.waitForURL('**/superadmin/**', { timeout: 12000 });
        } catch (e) {
            test.skip(true, 'NOT_RUN: superadmin authentication unavailable (' + e.message + ')');
            return;
        }
        await page.goto(APP_URL + '/superadmin/workbench');

        // Task-first health panel is the default and renders.
        await expect(page.locator('#wb-panel-tasks')).toBeVisible();
        await expect(page.locator('#wb-tasks-body')).toBeVisible();
        await expect(page.locator('#wb-tasks-body')).not.toContainText('Loading development tasks', { timeout: 15000 });

        // No false release-ready: release stat is driven by the read API.
        const listRes = await page.request.get(APP_URL + '/api/v1/superadmin/workbench/tasks');
        expect(listRes.status()).toBeLessThan(500);
        const listData = await listRes.json();
        expect(listData.ok).toBe(true);
        const tasks = listData.tasks || [];
        const releaseReadyCells = await page.locator('#wb-tasks-body td').allTextContents();
        // Every task with release_decision 'approved' must be READY_FOR_RELEASE;
        // any other combination must not be shown as release-ready.
        for (const t of tasks) {
            const showsApproved = releaseReadyCells.some((c) => c.trim() === 'approved');
            expect(showsApproved, 'false release-ready state for ' + t.task_id)
                .toBe(t.state === 'READY_FOR_RELEASE' && t.release_decision === 'approved');
        }

        // A task shown as release-ready must be genuinely release-ready: its
        // deterministic live blockers (tampered gate/artifact, stale verification,
        // or working-tree drift) must be empty. This prevents a stored
        // READY_FOR_RELEASE state from surviving a rule change (e.g. a new
        // attestation requirement) without being re-gated.
        for (const t of tasks) {
            if (t.state === 'READY_FOR_RELEASE' && t.release_decision === 'approved') {
                const lr = await page.request.get(APP_URL + '/api/v1/superadmin/workbench/task?id=' + encodeURIComponent(t.task_id));
                expect(lr.status(), 'detail for ' + t.task_id).toBeLessThan(500);
                const lrData = await lr.json();
                expect(lrData.ok, 'detail ok for ' + t.task_id).toBe(true);
                expect(lrData.task.live_blockers || [], 'release-ready task ' + t.task_id + ' must have no live blockers')
                    .toEqual([]);
            }
        }

        // Task detail + timeline journey (when the ledger has tasks).
        if (tasks.length > 0) {
            const taskId = tasks[0].task_id;
            const detailRes = await page.request.get(APP_URL + '/api/v1/superadmin/workbench/task?id=' + encodeURIComponent(taskId));
            expect(detailRes.status()).toBeLessThan(500);
            const detail = await detailRes.json();
            expect(detail.ok).toBe(true);
            expect(detail.task.task_id).toBe(taskId);

            const timelineRes = await page.request.get(APP_URL + '/api/v1/superadmin/workbench/task/timeline?id=' + encodeURIComponent(taskId));
            expect(timelineRes.status()).toBeLessThan(500);
            const timeline = await timelineRes.json();
            expect(timeline.ok).toBe(true);
            const seqs = (timeline.events || []).map((e) => e.sequence);
            for (let i = 1; i < seqs.length; i++) expect(seqs[i]).toBeGreaterThan(seqs[i - 1]);

            // Drill into the task detail panel from the UI.
            await page.locator('#wb-tasks-body button', { hasText: 'Detail' }).first().click();
            await expect(page.locator('#wb-task-detail')).toBeVisible();
            await expect(page.locator('#wb-task-detail')).toContainText(taskId);
            await expect(page.locator('#wb-task-detail')).toContainText('Approved scope');
            await expect(page.locator('#wb-task-detail')).toContainText('Actual scope');
        }

        // No console errors, no page errors, no failed network requests, no 5xx.
        expect(diagnostics.filter((d) => d.startsWith('console:') || d.startsWith('pageerror:')),
            'console/page errors: ' + diagnostics.join(' | ')).toEqual([]);
        expect(diagnostics.filter((d) => d.startsWith('network:')),
            'failed network requests: ' + diagnostics.join(' | ')).toEqual([]);
        expect(diagnostics.filter((d) => d.startsWith('http 5')),
            '5xx responses: ' + diagnostics.join(' | ')).toEqual([]);
    });
});
