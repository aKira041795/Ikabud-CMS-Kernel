// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

test.describe('Daily Ledger PWA offline pilot', () => {
    // The whole suite stays at 4 tests = 4 logins so it fits under the
    // application auth rate limiter (5 attempts per 5-minute window).
    test.describe.configure({ timeout: 90000 });

    test('is installable and reloads the cached ledger offline', async ({ page, context, shell, integrity }) => {
        integrity.fingerprint('public/daily-ledger/manifest.webmanifest');
        integrity.fingerprint('public/daily-ledger/sw.js');
        integrity.fingerprint('templates/modules/daily-ledger/layouts/app.disyl');

        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', BASE + '/manifest.webmanifest');
        var secureContext = await page.evaluate(() => window.isSecureContext && 'serviceWorker' in navigator);
        test.skip(!secureContext, 'Service-worker offline reload requires an HTTPS or localhost test origin.');
        var pinProvisioned = await page.evaluate(async () => {
            window.dlOfflineAuthClear();
            return window.dlOfflineAuthSetPin('2468');
        });
        expect(pinProvisioned).toBe(true);
        await page.evaluate(() => navigator.serviceWorker.ready);
        // A first registration is not guaranteed to control the page that created it.
        // Reload once online so the activated worker owns the ledger navigation.
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 });
        var cachedCredentialState = await page.evaluate(async () => {
            var cache = await caches.open('daily-ledger-pwa-v5');
            var cached = await cache.match('/daily-ledger/ledger');
            var html = cached ? await cached.text() : '';
            return {
                hasCachedPage: html.length > 0,
                hasBearer: Boolean(window.DL_TOKEN) && html.indexOf(window.DL_TOKEN) !== -1,
                hasCsrf: Boolean(window.DL_CSRF) && html.indexOf(window.DL_CSRF) !== -1
            };
        });
        expect(cachedCredentialState.hasCachedPage).toBe(true);
        expect(cachedCredentialState.hasBearer).toBe(false);
        expect(cachedCredentialState.hasCsrf).toBe(false);

        await context.setOffline(true);
        await page.reload({ waitUntil: 'domcontentloaded' });
        await expect(page.locator('#offline-lock')).toBeVisible();
        await page.locator('#offline-pin-input').fill('2468');
        await page.locator('#offline-unlock-btn').click();
        await expect(page.locator('#offline-lock')).toBeHidden();
        await expect(page.locator('#connectivity-banner-text')).toContainText('Offline');
        await expect(page.locator('h2')).toContainText('Sales Report');
        await expect(page.locator('.ledger-input').first()).toBeVisible();
        await context.setOffline(false);
        await page.evaluate(() => window.dlOfflineAuthClear());
    });

    test('product reference is scope-isolated and populates a rowless cashier modal', async ({ page, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

        var result = await page.evaluate(async () => {
            var captured = await window.dlCaptureProductReference();
            var expected = await window.dlReadProductReference();
            var originalBranch = window.BRANCH_ID;
            window.BRANCH_ID = String(originalBranch) + '-different';
            var crossBranch = await window.dlReadProductReference();
            window.BRANCH_ID = originalBranch;

            document.querySelectorAll('tr[data-product-id]').forEach(function (row) { row.remove(); });
            var modal = window.withdrawalModal();
            modal.loadProducts();
            for (var i = 0; i < 50 && modal.products.length === 0; i++) {
                await new Promise(function (resolve) { setTimeout(resolve, 10); });
            }
            return {
                captured: captured,
                expected: expected,
                crossBranch: crossBranch,
                modalProducts: modal.products
            };
        });

        expect(result.captured).toBe(true);
        expect(result.expected.length).toBeGreaterThan(0);
        expect(result.crossBranch).toEqual([]);
        expect(result.modalProducts).toEqual(result.expected);
    });

    test('offline queues drain on reconnect, online-only actions stay blocked, stale retries never overwrite newer edits, and production output queues offline', async ({ page, context, shell }) => {
        // 1) Manual field-save queue drains on reconnect and never shows All saved while pending.
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        await context.setOffline(true);

        await page.evaluate(() => {
            var key = ['daily-ledger:pending-saves', window.DL_TENANT_SCOPE || 'tenant', window.DL_USER_ID || 'anonymous', window.BRANCH_ID || 'branch', window.LEDGER_DATE || 'date', window.SHIFT || 'AM'].join(':');
            localStorage.setItem(key, JSON.stringify([{
                schema_version: 2,
                module: 'daily-ledger',
                tenant_scope: String(window.DL_TENANT_SCOPE || 'tenant'),
                actor_id: String(window.DL_USER_ID || 'anonymous'),
                branch_id: String(window.BRANCH_ID || ''),
                date: String(window.LEDGER_DATE || ''),
                shift: String(window.SHIFT || 'AM'),
                product_id: '1', field: 'beg_bal', value: 7, created_at: new Date().toISOString()
            }]));
            window.dispatchEvent(new Event('offline'));
        });
        await expect(page.locator('#global-status')).not.toHaveText('All saved');
        await expect(page.locator('#connectivity-banner-text')).toContainText('1 pending');

        await page.route('**/daily-ledger/api/v1/cashier/ledger/day-status', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }));
        await page.route('**/daily-ledger/api/v1/cashier/ledger/save', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' }));
        await page.route('**/daily-ledger/api/v1/cashier/ledger/withdrawals', route => route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true,"totals":[]}' }));
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.locator('#global-status')).toHaveText('All saved');
        await expect(page.locator('#connectivity-banner-text')).toContainText('Online');

        // 2) Queued cashier operation (withdrawal) replays on reconnect.
        await context.setOffline(true);
        await page.evaluate(() => {
            var key = ['daily-ledger:pending-ops', window.DL_TENANT_SCOPE || 'tenant', window.DL_USER_ID || 'anonymous', window.BRANCH_ID || 'branch', window.LEDGER_DATE || 'date', window.SHIFT || 'AM'].join(':');
            localStorage.setItem(key, JSON.stringify([{
                schema_version: 1,
                module: 'daily-ledger',
                tenant_scope: String(window.DL_TENANT_SCOPE || 'tenant'),
                actor_id: String(window.DL_USER_ID || 'anonymous'),
                branch_id: String(window.BRANCH_ID || ''),
                date: String(window.LEDGER_DATE || ''),
                shift: String(window.SHIFT || 'AM'),
                op: 'withdrawal',
                idempotency_key: 'test-op-key-1',
                payload: {
                    date: String(window.LEDGER_DATE || ''),
                    branch_id: String(window.BRANCH_ID || ''),
                    header: { withdrawal_type: 'charge', reason_code: 'manual_adjustment', custom_reason: '' },
                    lines: [{ product_id: 1, quantity: 2 }]
                },
                created_at: new Date().toISOString()
            }]));
            window.dispatchEvent(new Event('offline'));
        });
        await expect(page.locator('#connectivity-banner-text')).toContainText('1 pending');
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.locator('#global-status')).toHaveText('All saved');
        await expect(page.locator('#connectivity-banner-text')).toContainText('Online');

        // 3) Online-only actions stay blocked while offline.
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await page.locator('[data-online-action="Day close"]').click();
        await expect(page.locator('#online-action-blocked')).toBeVisible();
        await expect(page.locator('#online-action-blocked')).toContainText('requires cloud connectivity');
        await context.setOffline(false);

        // 4) A stale retry completion never removes a newer same-field edit.
        var remaining = await page.evaluate(async () => {
            var key = ['daily-ledger:pending-saves', window.DL_TENANT_SCOPE || 'tenant', window.DL_USER_ID || 'anonymous', window.BRANCH_ID || 'branch', window.LEDGER_DATE || 'date', window.SHIFT || 'AM'].join(':');
            var oldEntry = {
                schema_version: 2, module: 'daily-ledger', tenant_scope: String(window.DL_TENANT_SCOPE || 'tenant'),
                actor_id: String(window.DL_USER_ID || 'anonymous'), branch_id: String(window.BRANCH_ID || ''),
                date: String(window.LEDGER_DATE || ''), shift: String(window.SHIFT || 'AM'),
                product_id: '1', field: 'beg_bal', value: 7, created_at: '2026-08-14T00:00:00.000Z'
            };
            localStorage.setItem(key, JSON.stringify([oldEntry]));
            var originalFetch = window.fetch;
            var release;
            window.fetch = function (url) {
                if (String(url).indexOf('/ledger/save') !== -1) {
                    return new Promise(function (resolve) { release = function () { resolve(new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } })); }; });
                }
                return originalFetch.apply(window, arguments);
            };
            cloudOnline = true;
            var drain = retryPending();
            while (!release) await new Promise(resolve => setTimeout(resolve, 0));
            var newer = Object.assign({}, oldEntry, { value: 9, created_at: '2026-08-14T00:00:01.000Z' });
            localStorage.setItem(key, JSON.stringify([newer]));
            release();
            await drain;
            window.fetch = originalFetch;
            return JSON.parse(localStorage.getItem(key) || '[]');
        });
        expect(remaining).toHaveLength(1);
        expect(remaining[0].value).toBe(9);

        // 5) Production output queues offline with an idempotency key.
        await page.goto(APP_URL + BASE + '/admin/production-output');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        await context.setOffline(true);
        if (await page.locator('#submit-output').count() > 0) {
            await page.locator('#output-branch-id').evaluate(select => {
                var option = Array.from(select.options).find(item => item.value);
                if (option) select.value = option.value;
            });
            await page.locator('.output-qty-input').first().fill('3');
            await page.locator('#submit-output').click();
        } else {
            await page.evaluate(() => {
                queueOutputBatch({
                    idempotency_key: 'production-output-contract-test',
                    created_at: new Date().toISOString(),
                    operations: [{
                        type: 'output', destination_branch_id: 1, product_id: 1,
                        ledger_date: '2026-08-14', quantity: 3, flow_mode: 'production',
                        reason: 'offline contract test', client_op_id: 'output-contract-test-1'
                    }]
                });
                renderOutputOfflineStatus();
            });
        }
        var queued = await page.evaluate(() => {
            var key = ['daily-ledger:pending-production-output', String(window.DL_TENANT_SCOPE || 'tenant'), String(window.DL_USER_ID || 'anonymous')].join(':');
            return JSON.parse(localStorage.getItem(key) || '[]');
        });
        expect(queued).toHaveLength(1);
        expect(queued[0].idempotency_key).toContain('production-output');
        await expect(page.locator('#production-offline-status')).toContainText('1 pending batch');
        await context.setOffline(false);
    });

    test('offline PIN verifies, throttles failures, and unlocks the offline shell', async ({ page, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        var secureContext = await page.evaluate(() => window.isSecureContext && Boolean(window.crypto && window.crypto.subtle));
        test.skip(!secureContext, 'PBKDF2 offline PIN requires an HTTPS or localhost test origin.');

        var result = await page.evaluate(async () => {
            window.dlOfflineAuthClear();
            var set = await window.dlOfflineAuthSetPin('2468');
            var wrong = await window.dlOfflineAuthVerify('1357');
            var attemptsKey = ['daily-ledger:offline-pin-attempts', window.DL_TENANT_SCOPE, window.DL_USER_ID].join(':');
            var attemptCount = JSON.parse(localStorage.getItem(attemptsKey) || '{}').count;
            var correct = await window.dlOfflineAuthVerify('2468');
            for (var i = 0; i < 5; i++) await window.dlOfflineAuthVerify('0000');
            return { set: set, wrong: wrong, attemptCount: attemptCount, correct: correct, locked: window.dlOfflineAuthLockState().locked };
        });
        expect(result).toEqual({ set: true, wrong: false, attemptCount: 1, correct: true, locked: true });

        await page.evaluate(() => {
            var key = ['daily-ledger:offline-pin-attempts', window.DL_TENANT_SCOPE, window.DL_USER_ID].join(':');
            localStorage.removeItem(key);
            window.DL_OFFLINE_SHELL = true;
            window.dlMaybeLockOffline();
        });
        await expect(page.locator('#offline-lock')).toBeVisible();
        await page.locator('#offline-pin-input').fill('2468');
        await page.locator('#offline-unlock-btn').click();
        await expect(page.locator('#offline-lock')).toBeHidden();
        await page.evaluate(() => window.dlOfflineAuthClear());
    });

});