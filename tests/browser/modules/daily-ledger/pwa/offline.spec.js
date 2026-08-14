// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';
var BASE = '/daily-ledger';

test.describe('Daily Ledger PWA offline pilot', () => {
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
        await page.evaluate(() => navigator.serviceWorker.ready);
        // A first registration is not guaranteed to control the page that created it.
        // Reload once online so the activated worker owns the ledger navigation.
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForFunction(() => navigator.serviceWorker && navigator.serviceWorker.controller, null, { timeout: 15000 });
        var cachedCredentialState = await page.evaluate(async () => {
            var cache = await caches.open('daily-ledger-pwa-v3');
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
        await expect(page.locator('#connectivity-banner-text')).toContainText('Offline');
        await expect(page.locator('h2')).toContainText('Sales Report');
        await expect(page.locator('.ledger-input').first()).toBeVisible();
        await context.setOffline(false);
    });

    test('pending queue auto-retries on reconnect and never reports all saved while pending', async ({ page, context, shell }) => {
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
        await context.setOffline(false);
        await page.evaluate(() => window.dispatchEvent(new Event('online')));
        await expect(page.locator('#global-status')).toHaveText('All saved');
        await expect(page.locator('#connectivity-banner-text')).toContainText('Online');
    });

    test('required-online actions show a red blocked message offline', async ({ page, context, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();
        await context.setOffline(true);
        await page.evaluate(() => window.dispatchEvent(new Event('offline')));
        await page.locator('[data-online-action="Day close"]').click();
        await expect(page.locator('#online-action-blocked')).toBeVisible();
        await expect(page.locator('#online-action-blocked')).toContainText('requires cloud connectivity');
    });

    test('an older retry completion cannot delete a newer same-field edit', async ({ page, shell }) => {
        await page.goto(APP_URL + BASE + '/ledger');
        await page.waitForLoadState('networkidle');
        await shell.expectVisible();

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
            window.fetch = function(url) {
                if (String(url).indexOf('/ledger/save') !== -1) {
                    return new Promise(function(resolve) { release = function() { resolve(new Response('{"ok":true}', { status: 200, headers: { 'Content-Type': 'application/json' } })); }; });
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
    });

    test('production output queues offline with an idempotency key', async ({ page, context, shell }) => {
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
});
