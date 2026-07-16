const assert = require('assert');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { IssueCorrelator } = require('./IssueCorrelator');
const { RuntimeResolver } = require('./RuntimeResolver');
const { AnalystReport } = require('./AnalystReport');
const { UxEvolution } = require('./UxEvolution');

function fakePage(hrefs = []) {
    return {
        locator() { return { evaluateAll: async () => hrefs }; },
        goto: async () => {},
    };
}

(async function run() {
    const correlated = IssueCorrelator.correlate([
        { kind: 'console-error', severity: 'major', url: 'http://app.test/orders/12', detail: 'Failed to load resource: 404' },
        { kind: 'http-error', severity: 'minor', where: 'GET http://app.test/orders/12', detail: 'HTTP 404 Not Found' },
    ]);
    assert.strictEqual(correlated.length, 1, 'console and HTTP evidence must correlate');
    assert.deepStrictEqual(correlated[0].evidence_kinds, ['console-error', 'http-error']);
    assert.strictEqual(correlated[0].occurrences, 2);
    const navigation = IssueCorrelator.normalize({ kind: 'navigation', detail: 'Redirected to http://app.test/orders/578' });
    assert.strictEqual(navigation.status, null, 'entity IDs must not be interpreted as HTTP status codes');

    const resolver = new RuntimeResolver(fakePage(['/admin/acme/orders/create', '/admin/acme/orders/91', '/admin/acme/orders/91/edit']), { nav: [] });
    await resolver.observeCurrentPage();
    assert.match(resolver.resolve('/admin/acme/orders/{id}'), /orders\/91\?/);
    assert.match(resolver.resolve('/admin/acme/orders/{id}/edit'), /orders\/91\/edit\?/);
    assert.strictEqual(resolver.resolve('/admin/acme/orders/{id}/approve'), null);
    assert.strictEqual(resolver.classifyUnresolved('/orders/{id}').classification, 'unmet-prerequisite');

    const ux = UxEvolution.score({
        pages: [{ metrics: { h1_count: 1, unnamed_controls: 0, heading_jumps: 0, duplicate_ids: 0 }, terminology: { actions: [{ key: 'save', label: 'Save' }] }, keyboard: { invisible_focus: 0, unique_reached: 4, tabs: 4 }, responsive: [{ horizontal_overflow: false, visible_primary_actions: 1, total_primary_actions: 1 }] }],
        task: { interactions: 3, successful_steps: 3 },
    });
    assert.strictEqual(ux.score, 100);
    assert.strictEqual(UxEvolution.compare(ux, { score: 95, penalties: {} }).status, 'improved');

    const report = AnalystReport.build({
        runId: 'unit', module: 'acme', process: { pages: 2, dataFlows: 3 },
        coverage: { runtime_pages: 1 }, pages: [],
        issues: [{ kind: 'http-error', severity: 'major', where: 'GET /api/orders', detail: 'HTTP 500' }],
    });
    assert.strictEqual(report.schema, 'ark.system-analyst-report.v1');
    assert.ok(typeof report.ux_evolution.score === 'number');
    assert.ok(report.role_indexes.backend.length > 0);
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ark-analyst-'));
    assert.ok(fs.existsSync(AnalystReport.write(report, dir)));

    console.log('system-analyst.unit: all assertions passed');
})().catch(error => { console.error(error); process.exit(1); });
