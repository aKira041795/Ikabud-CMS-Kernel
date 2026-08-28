// @ts-check
/**
 * HARPP — Decision inbox isolated browser journey.
 *
 * Self-contained: creates a disposable HARPP tenant + database through
 * harpp-browser-fixture.php, seeds a uniquely keyed ACKNOWLEDGED decision,
 * performs the full e2e journey, then destroys the tenant and inspects both
 * application logs. It never reads a pre-existing decision and never mutates a
 * shared/live tenant.
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
    execSync(`php ${JSON.stringify(FIXTURE)} ${mode}`, {
        cwd: ROOT,
        stdio: 'inherit',
        timeout: 120000,
    });
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
        // Unrelated pre-existing PHP 8.5 deprecations from other modules (ai/anti-spam)
        // are emitted by the kernel's own CLI/bootstrap path, not by HARPP routes.
        if (trimmed.includes('PHP Deprecated') && (trimmed.includes('modules/ai/') || trimmed.includes('modules/anti-spam/'))) {
            continue;
        }
        issues.push('error.log finding: ' + trimmed);
    }
    const appLines = appLog.split('\n').filter((line) => line.trim() !== '');
    for (const line of appLines) {
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

async function listDecisions(page, state) {
    const payload = await page.evaluate(async (state) => {
        const response = await fetch('/api/v1/harpp/decisions?state=' + encodeURIComponent(state), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, state);
    return (payload && payload.data && payload.data.decisions) || [];
}

async function getDecision(page, id) {
    const payload = await page.evaluate(async (id) => {
        const response = await fetch('/api/v1/harpp/decisions/' + id, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        return response.json();
    }, id);
    return payload && payload.data ? payload.data : null;
}

async function csrfToken(page) {
    return page.evaluate(() => {
        const meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') || '' : '';
    });
}

async function applyFetch(page, id, headers) {
    return page.evaluate(async ({ id, headers }) => {
        const response = await fetch('/api/v1/harpp/decisions/' + id + '/apply-and-close', {
            method: 'POST',
            credentials: 'same-origin',
            headers: Object.assign({ Accept: 'application/json', 'Content-Type': 'application/json' }, headers || {}),
            body: JSON.stringify({ apply_rationale: 'Browser journey apply', close_rationale: 'Browser journey close' }),
        });
        let body = null;
        try {
            body = await response.json();
        } catch (e) {
            body = { raw: await response.text() };
        }
        return { status: response.status, body };
    }, { id, headers });
}

test.describe.serial('HARPP decision inbox isolated browser journey', () => {
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
        if (issues.length) {
            console.error('Post-test log findings:\n' + issues.join('\n---\n'));
        }
        clearLogs();
        expect(issues, 'post-test logs must be clean').toEqual([]);
    });

    test('pending inbox, missing/invalid CSRF 419, form apply-and-close, durable CLOSED, idempotent retry', async ({ page }) => {
        test.setTimeout(180000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        const decisionId = Number(state.decision_id);

        const decisionRequests = [];
        page.on('request', (request) => {
            const url = request.url();
            if (url.includes('/api/v1/harpp/decisions')) {
                decisionRequests.push({ url, method: request.method() });
            }
        });

        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);

        // Default inbox: select starts at PENDING and the first load requests PENDING.
        await page.goto(appUrl + '/harpp/decisions');
        await page.waitForLoadState('networkidle');
        expect(await page.locator('select[name="state"]').inputValue(), 'inbox status select must default to PENDING').toBe('PENDING');

        const firstListRequest = decisionRequests.find((r) => r.method === 'GET' && r.url.includes('/api/v1/harpp/decisions') && !r.url.includes('/api/v1/harpp/decisions/'));
        expect(firstListRequest, 'first inbox load must request the decisions endpoint').toBeTruthy();
        expect(firstListRequest.url, 'first inbox load must request state=PENDING').toContain('state=PENDING');

        // The seeded ACKNOWLEDGED decision must not appear in the pending inbox.
        const pendingBefore = await listDecisions(page, 'PENDING');
        expect(pendingBefore.map((row) => Number(row.id)), 'seeded ACKNOWLEDGED decision must not appear in pending inbox').not.toContain(decisionId);

        // Detail page exposes Apply-and-close and drops the pre-decision shortcuts.
        await page.goto(appUrl + '/harpp/decisions/' + decisionId);
        await page.waitForLoadState('networkidle');
        await page.waitForSelector('#decision-apply-close', { state: 'attached', timeout: 10000 });
        await expect(page.locator('#decision-apply-close'), 'Apply and close form must be visible for ACKNOWLEDGED').toBeVisible({ timeout: 10000 });
        expect(await page.locator('#decision-decide-close').count(), 'Decide & close shortcut must not render').toBe(0);
        expect(await page.locator('#decision-close-plain').count(), 'Close without deciding shortcut must not render').toBe(0);

        const beforeCsrf = await getDecision(page, decisionId);
        expect(beforeCsrf.decision.lifecycle_state, 'fixture decision must be ACKNOWLEDGED').toBe('ACKNOWLEDGED');
        expect(Number(beforeCsrf.decision.version)).toBeGreaterThan(0);

        // Missing CSRF (no header): HTTP 419 with unchanged state/version/audit.
        const missingCsrf = await applyFetch(page, decisionId, {});
        expect(missingCsrf.status, 'missing CSRF must return HTTP 419').toBe(419);
        expect(missingCsrf.body && missingCsrf.body.ok, 'missing CSRF envelope must be a failure').toBe(false);
        const afterMissing = await getDecision(page, decisionId);
        expect(afterMissing.decision.lifecycle_state, 'missing CSRF must not change lifecycle state').toBe('ACKNOWLEDGED');
        expect(Number(afterMissing.decision.version), 'missing CSRF must not bump the version').toBe(Number(beforeCsrf.decision.version));
        expect(afterMissing.audit_trail.length, 'missing CSRF must not add audit transitions').toBe(beforeCsrf.audit_trail.length);

        // Invalid CSRF (bad header): HTTP 419 with unchanged state/version/audit.
        const invalidCsrf = await applyFetch(page, decisionId, { 'X-CSRF-TOKEN': 'invalid-csrf-token' });
        expect(invalidCsrf.status, 'invalid CSRF must return HTTP 419').toBe(419);
        expect(invalidCsrf.body && invalidCsrf.body.ok, 'invalid CSRF envelope must be a failure').toBe(false);
        const afterInvalid = await getDecision(page, decisionId);
        expect(afterInvalid.decision.lifecycle_state, 'invalid CSRF must not change lifecycle state').toBe('ACKNOWLEDGED');
        expect(Number(afterInvalid.decision.version), 'invalid CSRF must not bump the version').toBe(Number(beforeCsrf.decision.version));
        expect(afterInvalid.audit_trail.length, 'invalid CSRF must not add audit transitions').toBe(beforeCsrf.audit_trail.length);

        // Real form submission posts /apply-and-close and produces CLOSED.
        await page.fill('#decision-apply-close textarea[name="apply_rationale"]', 'Applied in browser journey');
        await page.fill('#decision-apply-close textarea[name="close_rationale"]', 'Closed in browser journey');
        const beforeSubmit = decisionRequests.length;
        await Promise.all([
            page.waitForURL('**/harpp/decisions', { timeout: 15000 }),
            page.click('#decision-apply-close button[type="submit"]'),
        ]);

        const applyRequests = decisionRequests
            .slice(beforeSubmit)
            .filter((r) => r.method === 'POST' && r.url.includes('/api/v1/harpp/decisions/' + decisionId + '/apply-and-close'));
        expect(applyRequests.length, 'form must submit exactly one apply-and-close request').toBe(1);

        const afterClose = await getDecision(page, decisionId);
        expect(afterClose.decision.lifecycle_state, 'form apply-and-close must produce CLOSED').toBe('CLOSED');

        // Closed decision is removed from the default pending inbox.
        const pendingAfter = await listDecisions(page, 'PENDING');
        expect(pendingAfter.map((row) => Number(row.id)), 'closed decision must be removed from pending inbox').not.toContain(decisionId);

        // Explicit CLOSED filter still returns it with both lifecycle transitions.
        const closedRows = await listDecisions(page, 'CLOSED');
        expect(closedRows.map((row) => Number(row.id)), 'closed decision must be retrievable via CLOSED filter').toContain(decisionId);
        const transitions = (afterClose.audit_trail || []).map((row) => `${row.from_state || 'START'}->${row.to_state}`);
        expect(transitions, 'audit must include ACKNOWLEDGED -> APPLIED').toContain('ACKNOWLEDGED->APPLIED');
        expect(transitions, 'audit must include APPLIED -> CLOSED').toContain('APPLIED->CLOSED');

        // Idempotent retry with a valid CSRF token: 200, still CLOSED, no duplicates.
        const token = await csrfToken(page);
        const retry = await applyFetch(page, decisionId, { 'X-CSRF-TOKEN': token });
        expect(retry.status, 'idempotent CLOSED retry must return HTTP 200').toBe(200);
        expect(retry.body && retry.body.ok, 'idempotent CLOSED retry must return ok').toBe(true);
        expect(retry.body && retry.body.data && retry.body.data.state, 'idempotent CLOSED retry must remain CLOSED').toBe('CLOSED');

        const afterRetry = await getDecision(page, decisionId);
        expect(afterRetry.audit_trail.length, 'idempotent retry must not add duplicate transitions').toBe(afterClose.audit_trail.length);
    });

    test('PENDING decision exposes a prominent direct status control (Decide/Close)', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);
        const token = await csrfToken(page);

        // Create a fresh PENDING decision via the API (owner-authenticated).
        const created = await page.evaluate(async (csrf) => {
            const response = await fetch('/api/v1/harpp/decisions', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({
                    title: 'PENDING direct test', body: 'body', requested_decision: 'pick',
                    decision_key: 'PENDING-DIRECT-' + Date.now(), priority: 'normal', source: 'harness',
                    workbench_state: 'ARCHITECTURE_DECISION_REQUIRED'
                }),
            });
            const body = await response.json();
            return { status: response.status, body };
        }, token);
        expect(created.status, 'create PENDING decision must succeed').toBe(200);
        const pendingId = Number(created.body && created.body.data && created.body.data.decision_id);
        expect(pendingId).toBeGreaterThan(0);

        await page.goto(appUrl + '/harpp/decisions/' + pendingId);
        await page.waitForLoadState('networkidle');
        await page.waitForSelector('#decision-advanced', { state: 'attached', timeout: 10000 });
        // The status control must be open (not collapsed) for a PENDING decision.
        expect(await page.locator('#decision-advanced').getAttribute('open'), 'status control must be open for PENDING').not.toBeNull();
        // DECIDED and CLOSED must be offered as direct targets for PENDING.
        const stateOptions = await page.locator('#decision-action select[name="state"] option').evaluateAll(
            (opts) => opts.filter((o) => !o.hidden).map((o) => o.value));
        expect(stateOptions, 'PENDING must offer DECIDED and CLOSED as direct targets').toEqual(expect.arrayContaining(['DECIDED', 'CLOSED']));

        // Move straight to CLOSED via the direct status control (no cycling).
        await page.selectOption('#decision-action select[name="state"]', 'CLOSED');
        await page.fill('#decision-action textarea[name="rationale"]', 'Direct close from PENDING in browser');
        await page.click('#decision-action button[type="submit"]');
        await expect.poll(async () => (await getDecision(page, pendingId)).decision.lifecycle_state, { timeout: 10000 })
            .toBe('CLOSED');
    });

    test('review artifacts: attach + download a generated file from the UI', async ({ page }) => {
        test.setTimeout(120000);
        const appUrl = process.env.APP_URL || ('http://' + state.domain);
        await login(page, appUrl, state.owner_email, OWNER_PASSWORD);
        const token = await csrfToken(page);

        // Create + close a decision (auto-builds the artifact bundle).
        const created = await page.evaluate(async (csrf) => {
            const response = await fetch('/api/v1/harpp/decisions', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ title: 'Artifact download test', body: 'body', requested_decision: 'pick',
                    decision_key: 'ART-DL-' + Date.now(), priority: 'normal', source: 'harness',
                    workbench_state: 'ARCHITECTURE_DECISION_REQUIRED' }),
            });
            const body = await response.json();
            return { status: response.status, body };
        }, token);
        expect(created.status, 'create decision must succeed').toBe(200);
        const decId = Number(created.body && created.body.data && created.body.data.decision_id);
        const closed = await page.evaluate(async ({ id, csrf }) => {
            const response = await fetch('/api/v1/harpp/decisions/' + id + '/transition', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
                body: JSON.stringify({ state: 'CLOSED', rationale: 'close for artifact test' }),
            });
            return { status: response.status, body: await response.json() };
        }, { id: decId, csrf: token });
        expect(closed.status, 'close decision must succeed').toBe(200);

        await page.goto(appUrl + '/harpp/decisions/' + decId);
        await page.waitForLoadState('networkidle');
        // Open the review-artifacts panel.
        await page.click('#artifact-open');
        await page.waitForSelector('#artifact-add-file', { state: 'visible', timeout: 10000 });

        // Attach a generated file through the UI.
        await page.fill('#artifact-add-file input[name="af_filename"]', 'report.txt');
        await page.fill('#artifact-add-file textarea[name="af_content"]', 'hello downloadable file');
        await page.click('#artifact-add-file-btn');
        await expect(page.locator('#artifact-list a.button', { hasText: 'Download file' }), 'a Download button must appear').toBeVisible({ timeout: 10000 });

        // The download endpoint streams the file content for the owner.
        const dl = await page.evaluate(async () => {
            const link = document.querySelector('#artifact-list a.button[href*="/download"]');
            const res = await fetch(link.getAttribute('href'), { credentials: 'same-origin' });
            return { status: res.status, text: await res.text() };
        });
        expect(dl.status, 'download endpoint must return 200').toBe(200);
        expect(dl.text, 'download must return the attached file content').toContain('hello downloadable file');
    });
});
