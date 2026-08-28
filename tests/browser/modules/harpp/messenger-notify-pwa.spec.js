// @ts-check
/**
 * HARPP — PW-2 messenger / notifications / archive / PWA browser journey.
 *
 * Self-contained (like decision-inbox.spec.js): creates a disposable HARPP tenant
 * through harpp-browser-fixture.php, then verifies:
 *   - messenger: create conversation, send a message, list + read messages, render
 *   - archive: archive a conversation and observe it reflected
 *   - notifications/push status: unread-count + list + notifications page render
 *   - PWA: sw.js + manifest served; authenticated API responses are never cached
 *     by the service worker (contract rule)
 *   - mobile/PWA rendering: messenger renders on a phone viewport
 *   - workflow preflight (bridge/CLI tier): `harpp workflow validate` passes
 * Destroys the tenant and inspects both logs afterward.
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const MODULE_DIR = __dirname;
const ROOT = path.resolve(__dirname, '../../../..');
const STATE_FILE = path.join(MODULE_DIR, 'harpp-browser-fixture.json');
const FIXTURE = path.join(MODULE_DIR, 'harpp-browser-fixture.php');
const OWNER_PASSWORD = process.env.HARPP_BROWSER_OWNER_PASSWORD || 'HarppBrowser42!X';

function runFixture(mode) {
    execSync(`php ${JSON.stringify(FIXTURE)} ${mode}`, { cwd: ROOT, stdio: 'inherit', timeout: 120000 });
}

function readState() {
    return JSON.parse(fs.readFileSync(STATE_FILE, 'utf8'));
}

function clearLogs() {
    fs.writeFileSync(path.join(ROOT, 'storage/logs/app.log'), '');
    fs.writeFileSync(path.join(ROOT, 'storage/logs/error.log'), '');
}

function inspectLogs() {
    const appLog = fs.readFileSync(path.join(ROOT, 'storage/logs/app.log'), 'utf8');
    const errLog = fs.readFileSync(path.join(ROOT, 'storage/logs/error.log'), 'utf8');
    const issues = [];
    for (const line of errLog.split('\n')) {
        const trimmed = line.trim();
        if (trimmed === '') continue;
        if (trimmed.includes('PHP Deprecated') && (trimmed.includes('modules/ai/') || trimmed.includes('modules/anti-spam/'))) {
            continue;
        }
        issues.push('error.log finding: ' + trimmed);
    }
    for (const line of appLog.split('\n').filter((l) => l.trim() !== '')) {
        if (line.includes('[error]') || line.includes('[critical]') || line.includes('Unknown database') || line.includes('Access denied for user') || line.includes('SQLSTATE')) {
            issues.push('app.log finding: ' + line);
        }
    }
    return issues;
}

async function login(page, appUrl, email, password) {
    await page.goto(appUrl + '/harpp/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', email);
    await page.fill('input[name="password"]', password);
    await Promise.all([
        page.waitForURL('**/harpp', { timeout: 20000 }),
        page.click('button[type="submit"]'),
    ]);
}

async function csrfToken(page) {
    return page.evaluate(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    });
}

async function apiFetch(page, method, url, body, csrf) {
    return page.evaluate(async ({ method, url, body, csrf }) => {
        const headers = { Accept: 'application/json' };
        if (body !== undefined) headers['Content-Type'] = 'application/json';
        if (csrf) headers['X-CSRF-TOKEN'] = csrf;
        const response = await fetch(url, {
            method, credentials: 'same-origin', headers,
            body: body !== undefined ? JSON.stringify(body) : undefined,
        });
        let payload = null;
        try { payload = await response.json(); } catch (e) { payload = { raw: await response.text() }; }
        return { status: response.status, payload };
    }, { method, url, body, csrf });
}

async function serviceWorkerApiCacheUrls(page) {
    return page.evaluate(async () => {
        try {
            if (typeof caches === 'undefined') return [];
            const keys = await caches.keys();
            const urls = [];
            for (const key of keys) {
                const cache = await caches.open(key);
                const requests = await cache.keys();
                for (const req of requests) urls.push(typeof req === 'string' ? req : req.url);
            }
            return urls;
        } catch (e) {
            return ['__cache_check_error__'];
        }
    });
}

test.describe.serial('HARPP PW-2 messenger/notify/archive/PWA isolated browser journey', () => {
    let state;

    test.beforeAll(async () => {
        test.setTimeout(180000);
        runFixture('up');
        state = readState();
        clearLogs();
    });

    test.afterAll(async () => {
        test.setTimeout(180000);
        const issues = [];
        issues.push(...inspectLogs());
        runFixture('down');
        issues.push(...inspectLogs());
        if (issues.length) console.error('Post-test log findings:\n' + issues.join('\n---\n'));
        clearLogs();
        expect(issues, 'post-test logs must be clean').toEqual([]);
    });

    test('messenger: create conversation, send + read a message, archive', async ({ page }) => {
        test.setTimeout(180000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);
        const token = await csrfToken(page);

        const created = await apiFetch(page, 'POST', '/api/v1/harpp/conversations', { title: 'PW2 messenger conversation', harness_session_id: 'pw2-session-1' }, token);
        expect(created.status, 'create conversation must succeed').toBe(200);
        const convId = Number(created.payload && created.payload.data && created.payload.data.conversation_id);
        expect(convId).toBeGreaterThan(0);

        const sent = await apiFetch(page, 'POST', `/api/v1/harpp/conversations/${convId}/messages`, { body: 'hello from pw2', sender_type: 'user' }, token);
        expect(sent.status, 'send message must succeed').toBe(200);
        const msgId = Number(sent.payload && sent.payload.data && sent.payload.data.message_id);

        const list = await apiFetch(page, 'GET', '/api/v1/harpp/conversations');
        const rows = (list.payload && list.payload.data && list.payload.data.conversations) || [];
        const mine = rows.find((r) => Number(r.id) === convId);
        expect(mine, 'created conversation must appear in the list').toBeTruthy();
        expect(mine.title).toBe('PW2 messenger conversation');

        const messages = await apiFetch(page, 'GET', `/api/v1/harpp/conversations/${convId}/messages`);
        const bodies = (messages.payload && messages.payload.data && messages.payload.data.messages) || [];
        expect(bodies.some((m) => Number(m.id) === msgId && m.body === 'hello from pw2'), 'sent message must be readable').toBe(true);

        // Messenger page renders without error.
        await page.goto(appUrl + '/harpp');
        await page.waitForLoadState('networkidle');
        expect(await page.locator('body').isVisible(), 'messenger page must render').toBe(true);

        // Archive requires the conversation to be closed first.
        const closed = await apiFetch(page, 'POST', `/api/v1/harpp/conversations/${convId}/close`, {}, token);
        expect(closed.status, 'close conversation must succeed').toBe(200);
        const archived = await apiFetch(page, 'POST', `/api/v1/harpp/conversations/${convId}/archive`, { archived: true }, token);
        expect(archived.status, 'archive closed conversation must succeed').toBe(200);
    });

    test('notifications/push status: unread-count + list + page render', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);

        const unread = await apiFetch(page, 'GET', '/api/v1/harpp/notifications/unread-count');
        expect(unread.status, 'unread-count must be reachable').toBe(200);
        const count = Number((unread.payload && unread.payload.data && unread.payload.data.unread) ?? -1);
        expect(count, 'unread-count must be a non-negative integer').toBeGreaterThanOrEqual(0);

        const list = await apiFetch(page, 'GET', '/api/v1/harpp/notifications');
        expect(list.status, 'notifications list must be reachable').toBe(200);
        expect(Array.isArray(list.payload && list.payload.data && list.payload.data.notifications), 'notifications list must return an array').toBe(true);

        await page.goto(appUrl + '/harpp/notifications');
        await page.waitForLoadState('networkidle');
        expect(await page.locator('body').isVisible(), 'notifications page must render').toBe(true);
    });

    test('PWA: sw.js + manifest served; authenticated API responses never service-worker cached', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);

        const sw = await page.request.get(appUrl + '/harpp/sw.js');
        expect(sw.status(), 'sw.js must be served').toBe(200);
        const swType = (sw.headers()['content-type'] || '').toLowerCase();
        expect(swType.includes('javascript') || swType.includes('service-worker'), 'sw.js content-type must be a worker/js').toBe(true);

        const manifest = await page.request.get(appUrl + '/harpp/manifest.webmanifest');
        expect(manifest.status(), 'manifest must be served').toBe(200);
        const manifestText = await manifest.text();
        expect(() => JSON.parse(manifestText), 'manifest must be valid JSON').not.toThrow();

        // Load the app so the service worker is registered, then make an
        // authenticated API call, then assert no /api/v1/harpp/ URL is cached.
        await page.goto(appUrl + '/harpp');
        await page.waitForLoadState('networkidle');
        const token = await csrfToken(page);
        await apiFetch(page, 'GET', '/api/v1/harpp/notifications/unread-count', undefined, token);
        await page.evaluate(() => navigator.serviceWorker && navigator.serviceWorker.ready);
        await page.waitForTimeout(500);
        const cached = await serviceWorkerApiCacheUrls(page);
        const apiCached = cached.filter((u) => u.includes('/api/v1/harpp/'));
        expect(apiCached, 'authenticated HARPP API responses must not be service-worker cached').toEqual([]);
    });

    test('mobile/PWA rendering: messenger renders on a phone viewport', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await page.setViewportSize({ width: 390, height: 844 });
        const consoleErrors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);
        await page.goto(appUrl + '/harpp');
        await page.waitForLoadState('networkidle');
        expect(await page.locator('body').isVisible(), 'messenger must render on mobile viewport').toBe(true);
        expect(consoleErrors.filter((e) => !e.includes('Failed to load resource')), 'no unhandled page JS errors on mobile').toEqual([]);
    });

    test('runner fleet: owner-visible runner status page renders on a phone viewport', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await page.setViewportSize({ width: 390, height: 844 });
        const consoleErrors = [];
        page.on('console', (msg) => { if (msg.type() === 'error') consoleErrors.push(msg.text()); });
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);
        await page.goto(appUrl + '/harpp/runners');
        await page.waitForLoadState('networkidle');
        expect(await page.locator('body').isVisible(), 'runner fleet page must render on mobile viewport').toBe(true);
        const fleet = page.locator('#runner-fleet');
        expect(await fleet.count(), 'runner fleet container must be present').toBeGreaterThan(0);
        expect(consoleErrors.filter((e) => !e.includes('Failed to load resource')), 'no unhandled page JS errors on runner fleet').toEqual([]);
    });

    test('workflow preflight (bridge/CLI tier): harpp workflow validate passes', async () => {
        test.setTimeout(60000);
        const out = execSync(
            'python3 harpp workflow validate --manifest workflows/governed-loop.json',
            { cwd: path.join(ROOT, 'tools/harpp-bridge'), encoding: 'utf8', timeout: 30000 },
        );
        const parsed = JSON.parse(out);
        expect(parsed.valid, 'governed-loop manifest must preflight as valid').toBe(true);
    });
});
