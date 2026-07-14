/**
 * Module Diagnostic Test — Compares expected template structure against
 * live browser rendering. Detects breakages, pinpoints failing components,
 * and generates structured fix reports.
 *
 * For every element the system knows SHOULD exist (from ProcessComprehension
 * template analysis), this test verifies it actually renders in the browser.
 * When something is missing or broken, it traces through the component chain:
 * template → handler → capability → service → database — to identify root cause.
 *
 * Usage:
 *   MODULE=project-audit-ledger npx playwright test tests/browser/module-diagnostic.spec.js
 *
 * Output: Structured fix report in test_results/diagnostic/
 *
 * Environment:
 *   MODULE          - Module directory name under modules/ (required)
 *   ADMIN_USER      - Login username
 *   ADMIN_PASS      - Login password
 *   APP_URL         - App base URL
 */

// @ts-check
const { test, expect } = require('./WorkbenchFixture');
const { ModuleDiagnostic } = require('./ModuleDiagnostic');
const fs = require('fs');
const path = require('path');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const MODULE = process.env.MODULE || '';
const MODULE_PATH = process.env.MODULE_PATH || path.resolve(__dirname, '../../modules', MODULE);

if (!MODULE) {
    throw new Error('MODULE environment variable required. Usage: MODULE=project-audit-ledger npx playwright test ...');
}

const manifest = JSON.parse(fs.readFileSync(path.join(MODULE_PATH, 'module.json'), 'utf-8'));

// Load provider data if available
let providerData = { entities: [], actions: [] };
try {
    providerData = JSON.parse(fs.readFileSync('/tmp/provider_data.json', 'utf-8'));
} catch (e) {
    console.log('  ℹ No provider data at /tmp/provider_data.json — run: php -r "..." first');
}

test.describe(`Module Diagnostic: ${manifest.name || MODULE}`, () => {

    test('full structural diagnostic — compares expected vs actual for all pages', async ({ page }) => {
        test.setTimeout(300000); // 5 min for full diagnostic

        const diagnostic = new ModuleDiagnostic(page, MODULE_PATH, manifest, providerData);
        const result = await diagnostic.runFullDiagnostic();

        // Print the report
        console.log(result.report);

        // Save report to file
        const reportDir = path.resolve(__dirname, '../../test_results/diagnostic');
        fs.mkdirSync(reportDir, { recursive: true });
        const reportFile = path.join(reportDir, `${MODULE}-diagnostic.json`);
        fs.writeFileSync(reportFile, JSON.stringify({
            module: manifest.name || MODULE,
            timestamp: new Date().toISOString(),
            passed: result.passed,
            failed: result.failed,
            issues: result.issues,
            report: result.report,
        }, null, 2));

        console.log(`\n  📄 Full report: ${reportFile}`);

        // Fails only on critical issues (breakages that actually break the UX)
        const criticalIssues = result.issues.filter(i => i.severity === 'critical');
        const majorIssues = result.issues.filter(i => i.severity === 'major');

        if (criticalIssues.length > 0) {
            console.log(`\n  🔴 ${criticalIssues.length} critical issue(s) — page-breaking problems detected`);
        }
        if (majorIssues.length > 0) {
            console.log(`\n  🟠 ${majorIssues.length} major issue(s) — important problems detected`);
        }

        // Assert: no critical issues (pages that don't load or have PHP errors)
        expect(criticalIssues.length, `Critical issues: ${criticalIssues.map(i => i.component).join(', ')}`).toBe(0);
    });
});
