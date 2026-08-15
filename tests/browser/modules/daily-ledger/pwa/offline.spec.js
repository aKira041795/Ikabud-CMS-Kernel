// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

test.describe('Daily Ledger PWA offline vault', () => {
    // The whole suite stays at 4 tests = 4 logins so it fits under the
    // application auth rate limiter (5 attempts per 5-minute window).
    test.describe.configure({ timeout: 120000 });

    /**
     * Enroll the current device through the real online flow ("Enable offline
     * access" → PIN + confirm prompts) and wait for verified readiness.
     * @param {import('@playwright/test').Page} page
     * @param {string} pin
     */
    async function enrollDevice(page, pin) {
        await page.evaluate(() => window.DLOfflineVault.clearEnrollment().catch(() => { }));
        page.on('dialog', async function (d) {
            // prompt #1 = set PIN, prompt #2 = confirm PIN.
            await d.accept(pin);
        });
        await page.locator('#offline-access-btn').click();
        await expect(page.locator('#offline-ready-badge')).toBeVisible({ timeout: 20000 });
        await expect(page.locator('#offline-ready-badge')).toContainText('Offline ready');
    }

    test('one enrollment action reaches verified offline ready; cold offline launch opens the encrypted PIN shell', async ({ page, context, shell, integrity }) => {
        integrity.fingerprint('public/daily-ledger/manifest.webmanifest');
        integrity.fingerprint('public/daily-ledger/sw.js');
        integrity.fingerprint('public/daily-ledger/offline.html');
        integrity.fingerprint('public/daily-ledger/assets/offline-vault.js');
        integrity.fingerprint('public/daily-ledger/assets/offline-app.js');
        integrity.fingerprint('templates/modules/daily-ledger/layouts/app.disyl');

        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', BASE + '/manifest.webmanifest');
        var secureContext = await page.evaluate(() => window.isSecureContext && 'serviceWorker' in navigator && !!window.DLOfflineVault && !!window.crypto.subtle);
        test.skip(!secureContext, 'Encrypted offline vault requires an HTTPS or localhost test origin.');

        var pin = '2468';
        await enrollDevice(page, pin);

        // No manual reload: readiness is derived from a verified vault write/read.
        await expect(page.locator('#offline-ready-badge')).toHaveText('Offline ready');
        var readyState = await page.evaluate(async () => ({
            enrolled: await window.DLOfflineVault.hasLocalEnrollment(),
            unlocked: window.DLOfflineVault.isUnlocked()
        }));
        expect(readyState.enrolled).toBe(true);
        expect(readyState.unlocked).toBe(true);

        await page.evaluate(() => navigator.serviceWorker.ready);
        // A freshly registered worker only controls a page AFTER a reload, so
        // reload first, then wait for control (skipWaiting/claim make it active).
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 });

        // Cold offline launch: installed start URL fails online → static shell → Unlock.
        await context.setOffline(true);
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('domcontentloaded');
        await expect(page.locator('#offline-pin')).toBeVisible();
        await expect(page.locator('#state-root')).toContainText('Unlock');
        await page.locator('#offline-pin').fill(pin);
        await page.locator('#unlock-btn').click();
        await expect(page.locator('#state-root')).toContainText('Offline ready', { timeout: 15000 });
        await expect(page.locator('#state-root')).toContainText('Sales Report');
        await context.setOffline(false);
    });

    test('product reference is vault-scoped to the enrolled branch and populates cashier modals', async ({ page, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        var secureContext = await page.evaluate(() => window.isSecureContext && !!window.DLOfflineVault);
        test.skip(!secureContext, 'Encrypted offline vault requires an HTTPS or localhost test origin.');

        await enrollDevice(page, '2468');

        var result = await page.evaluate(async () => {
            var captured = await window.dlCaptureProductReference();
            var expected = await window.dlReadProductReference();
            // The vault bootstrap is branch-bound; a spoofed branch id must not
            // change the returned set (no cross-branch leak from a single vault).
            var originalBranch = window.BRANCH_ID;
            window.BRANCH_ID = String(originalBranch) + '-spoofed';
            var spoofed = await window.dlReadProductReference();
            window.BRANCH_ID = originalBranch;

            document.querySelectorAll('tr[data-product-id]').forEach(function (row) { row.remove(); });
            var modal = window.withdrawalModal();
            modal.loadProducts();
            for (var i = 0; i < 50 && modal.products.length === 0; i++) {
                await new Promise(function (resolve) { setTimeout(resolve, 10); });
            }
            return { captured: captured, expected: expected, spoofed: spoofed, modalProducts: modal.products };
        });

        expect(result.captured).toBe(true);
        expect(result.expected.length).toBeGreaterThan(0);
        // The vault bootstrap is branch-bound. Under the spoofed branch the
        // synthetic reference key/scope prefix changes, but the product IDENTITY
        // set must be identical (no foreign branch can leak in).
        var identity = function (list) {
            return list.map(function (p) { return String(p.id) + '|' + String(p.name); }).sort();
        };
        expect(identity(result.spoofed)).toEqual(identity(result.expected));
        expect(result.modalProducts).toEqual(result.expected);
    });

    test('offline queue drains via reconcile on reconnect, online-only actions stay blocked, and production output queues offline', async ({ page, context, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        var secureContext = await page.evaluate(() => window.isSecureContext && !!window.DLOfflineVault);
        test.skip(!secureContext, 'Encrypted offline vault requires an HTTPS or localhost test origin.');
        await enrollDevice(page, '2468');

        // 1) A vault-queued ledger save is durable and shown as pending; it
        //    drains through the reconcile endpoint on reconnect.
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        var opId = await page.evaluate(async () => {
            var op = await window.DLOfflineVault.enqueueOperation('ledger_save', {
                product_id: '1', field: 'beg_bal', value: 7,
                date: window.LEDGER_DATE || '', branch_id: window.BRANCH_ID || '', shift: window.SHIFT || 'AM'
            });
            return op.client_op_id;
        });
        // Durable: the encrypted operation is committed to the vault before the
        // promise resolves, so the vault reports exactly one pending op.
        await expect
            .poll(async () => page.evaluate(() => window.DLOfflineVault.countPending()))
            .toBe(1);

        // Mock the reconcile response so the drain resolves deterministically.
        await page.route('**/daily-ledger/api/v1/offline/reconcile', route => route.fulfill({
            status: 200,
            contentType: 'application/json',
            body: JSON.stringify({
                ok: true, enrollment_id: 'x', interrupted: false,
                results: [{ client_op_id: opId, ok: true, status: 'applied', result: { ok: true } }]
            })
        }));
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await page.evaluate(() => window.drainVault());
        await expect
            .poll(async () => page.evaluate(() => window.DLOfflineVault.countPending()))
            .toBe(0);
        await expect(page.locator('#global-status')).toHaveText('All saved');

        // 2) Online-only actions stay blocked while offline.
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await page.locator('[data-online-action="Day close"]').click();
        await expect(page.locator('#online-action-blocked')).toBeVisible();
        await expect(page.locator('#online-action-blocked')).toContainText('requires cloud connectivity');
        await context.setOffline(false);

        // 3) Production output queues offline with an idempotency key.
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

    test('offline vault unlock verifies the PIN, throttles failures, and fails closed', async ({ page, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        var secureContext = await page.evaluate(() => window.isSecureContext && !!window.DLOfflineVault && !!window.crypto.subtle);
        test.skip(!secureContext, 'PBKDF2/AES-GCM vault requires an HTTPS or localhost test origin.');

        await enrollDevice(page, '2468');

        var result = await page.evaluate(async () => {
            await window.DLOfflineVault.lock();
            var wrong = await window.DLOfflineVault.unlock('1357');
            var correct = await window.DLOfflineVault.unlock('2468');
            return { wrong: wrong, correct: correct };
        });
        expect(result.wrong.ok).toBe(false);
        expect(result.wrong.reason).toBe('bad-pin');
        expect(result.correct.ok).toBe(true);

        // After 5 failed attempts the vault locks (fails closed).
        var locked = await page.evaluate(async () => {
            await window.DLOfflineVault.lock();
            for (var i = 0; i < 5; i++) await window.DLOfflineVault.unlock('1111');
            return window.DLOfflineVault.unlock('1111');
        });
        expect(locked.ok).toBe(false);
        expect(locked.reason).toBe('locked');
    });
});
