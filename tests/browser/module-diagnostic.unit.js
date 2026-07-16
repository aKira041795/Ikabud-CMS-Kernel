const assert = require('assert');
const fs = require('fs');
const path = require('path');
const { ModuleDiagnostic } = require('./ModuleDiagnostic');
const { ProcessComprehension } = require('./ProcessComprehension');

function fakePage(hrefs, statuses) {
    return {
        locator() {
            return {
                evaluateAll: async () => hrefs,
            };
        },
        context() {
            return {
                request: {
                    get: async url => {
                        const pathname = new URL(url).pathname;
                        const result = statuses[pathname] || { status: 200, location: '' };
                        return {
                            status: () => result.status,
                            headers: () => ({ location: result.location || '' }),
                        };
                    },
                },
            };
        },
    };
}

(async () => {
    const manifest = { id: 'sample', name: 'Sample', nav: [] };
    const diagnostic = new ModuleDiagnostic(
        fakePage(
            ['/admin/sample', '/admin/sample/broken'],
            {'/admin/sample/broken': {status: 404}}
        ),
        __dirname,
        manifest
    );
    await diagnostic._auditRuntimeNavigation('http://sample.test');
    assert.strictEqual(diagnostic.issues.length, 1);
    assert.strictEqual(diagnostic.issues[0].kind, 'broken-navigation');
    assert.strictEqual(diagnostic.issues[0].severity, 'critical');
    assert.strictEqual(diagnostic.issues[0].status, 404);

    const healthy = new ModuleDiagnostic(
        fakePage(
            [
                '/admin/sample',
                '/admin/sample/download/report.csv',
                '/admin/sample/logout',
                'https://example.com/help',
                '#details',
            ],
            {
                '/': {status: 404},
                '/admin/sample/download/report.csv': {status: 404},
                '/admin/sample/logout': {status: 404},
            }
        ),
        __dirname,
        manifest
    );
    await healthy._auditRuntimeNavigation('http://sample.test');
    assert.deepStrictEqual(healthy.issues, []);

    const palPath = path.resolve(__dirname, '../../modules/project-audit-ledger');
    const palManifest = JSON.parse(fs.readFileSync(path.join(palPath, 'module.json'), 'utf8'));
    const palDiagnostic = new ModuleDiagnostic(fakePage([], {}), palPath, palManifest);
    const navMap = new Map();
    const visit = entry => {
        if (entry && entry.url) navMap.set(entry.url, entry.label || '');
        for (const child of (entry && entry.children) || []) visit(child);
    };
    for (const entry of palManifest.nav || []) visit(entry);
    const templates = new ProcessComprehension(palPath, palManifest).analyzeTemplates();
    const uncovered = templates
        .map(template => template.page)
        .filter(pageName => !palDiagnostic._findPageUrl(pageName, navMap));
    assert.deepStrictEqual(uncovered, [], `PAL templates missing page-route coverage: ${uncovered.join(', ')}`);

    console.log('module diagnostic runtime navigation: PASS');
})().catch(error => {
    console.error(error);
    process.exit(1);
});
