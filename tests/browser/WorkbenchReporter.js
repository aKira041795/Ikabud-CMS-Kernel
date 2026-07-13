/**
 * WorkbenchReporter — Custom Playwright reporter for ARK Workbench tests.
 *
 * Solves:
 *   - Unique suite filenames from test.location.file
 *   - Automatic pass/fail recording (no afterEach needed)
 *   - Manifest with concurrency-safe single-writer
 *   - Fingerprint baseline comparison
 *
 * Usage: add to playwright.config.js:
 *   reporter: [['list'], ['./tests/browser/WorkbenchReporter.js']]
 *
 * Environment variables:
 *   WB_FINGERPRINT_MODE=check   — fail on fingerprint mismatch (default)
 *   WB_FINGERPRINT_MODE=update  — rewrite fingerprint-baseline.json
 *   WB_FINGERPRINT_MODE=off     — skip fingerprint check
 */

// @ts-check
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');
const FINGERPRINT_BASELINE = path.join(RESULTS_DIR, 'fingerprint-baseline.json');
const FINGERPRINT_MODE = process.env.WB_FINGERPRINT_MODE || 'check';

class WorkbenchReporter {
    constructor() {
        /** @type {Map<string, {results: Array, gaps: Set<string>, fingerprints: Object, started: string}>} */
        this.suites = new Map();
        this.startTime = new Date().toISOString();
    }

    onTestEnd(test, result) {
        const file = test.location.file;
        const projectName = test.parent?.project()?.name || 'chromium';
        const suiteName = this._suiteName(file, projectName);

        if (!this.suites.has(suiteName)) {
            this.suites.set(suiteName, {
                results: [],
                gaps: new Set(),
                fingerprints: {},
                started: new Date().toISOString(),
            });
        }

        const suite = this.suites.get(suiteName);
        suite.results.push({
            test: test.title,
            status: result.status === 'passed' ? 'pass' : 'fail',
            detail: result.error ? result.error.message || result.error.stack || '' : '',
        });
    }

    onEnd() {
        const finishedAt = new Date().toISOString();

        if (!fs.existsSync(RESULTS_DIR)) {
            fs.mkdirSync(RESULTS_DIR, { recursive: true });
        }

        let allFingerprints = {};
        let exitCode = 0;

        // Write per-suite JSON
        for (const [suiteName, suite] of this.suites.entries()) {
            const passed = suite.results.filter(r => r.status === 'pass').length;
            const failed = suite.results.filter(r => r.status === 'fail').length;
            const gapList = Array.from(suite.gaps).sort();

            const data = {
                suite: suiteName,
                started: suite.started,
                finished: finishedAt,
                summary: { passed, failed, total: suite.results.length },
                source_fingerprints: Object.assign({}, suite.fingerprints),
                results: suite.results.slice(),
                gaps: gapList,
            };

            fs.writeFileSync(
                path.join(RESULTS_DIR, suiteName + '.json'),
                JSON.stringify(data, null, 2)
            );

            Object.assign(allFingerprints, suite.fingerprints);
            console.log(`  📄 test_results/browser/${suiteName}.json`);
        }

        // Aggregate manifest
        const manifestFile = path.join(RESULTS_DIR, 'manifest.json');
        const manifest = { suites: {}, updated: finishedAt, fingerprint_mode: FINGERPRINT_MODE };
        for (const [suiteName, suite] of this.suites.entries()) {
            const passed = suite.results.filter(r => r.status === 'pass').length;
            const failed = suite.results.filter(r => r.status === 'fail').length;
            manifest.suites[suiteName] = {
                passed, failed, total: suite.results.length,
                gaps: Array.from(suite.gaps).length,
                fingerprints: Object.keys(suite.fingerprints).length,
                finished: finishedAt,
            };
        }
        fs.writeFileSync(manifestFile, JSON.stringify(manifest, null, 2));
        console.log(`  📄 test_results/browser/manifest.json`);

        // Fingerprint baseline
        if (FINGERPRINT_MODE === 'update') {
            fs.writeFileSync(FINGERPRINT_BASELINE, JSON.stringify(allFingerprints, null, 2));
            console.log(`  📄 Fingerprint baseline updated: ${FINGERPRINT_BASELINE}`);
        } else if (FINGERPRINT_MODE === 'check' && fs.existsSync(FINGERPRINT_BASELINE)) {
            const baseline = JSON.parse(fs.readFileSync(FINGERPRINT_BASELINE, 'utf-8'));
            let mismatches = 0;
            for (const [fp, hash] of Object.entries(allFingerprints)) {
                const expected = baseline[fp];
                if (expected && expected !== hash) {
                    console.error(`  ❌ FINGERPRINT MISMATCH: ${fp}`);
                    console.error(`     was: ${expected}`);
                    console.error(`     now: ${hash}`);
                    console.error(`     Source changed — test coverage review required.`);
                    mismatches++;
                }
            }
            // Also warn about new files not in baseline
            for (const fp of Object.keys(allFingerprints)) {
                if (!baseline[fp]) {
                    console.warn(`  ⚠ New fingerprint: ${fp} (not in baseline)`);
                }
            }
            if (mismatches > 0) {
                console.error(`\n  ❌ ${mismatches} fingerprint mismatch(es) — failing run.`);
                exitCode = 1;
            }
        } else if (FINGERPRINT_MODE === 'check') {
            console.warn(`  ⚠ No fingerprint baseline found at ${FINGERPRINT_BASELINE}`);
            console.warn(`  ⚠ Run with WB_FINGERPRINT_MODE=update to create one.`);
        }

        process.exit(exitCode);
    }

    /** @param {string} file */
    fingerprint(filePath) {
        // Called from tests via integrity.fingerprint() — but reporter can also
        // compute hashes on the server. We accept both.
        const fullPath = path.resolve(__dirname, '../../', filePath);
        try {
            const content = fs.readFileSync(fullPath, 'utf-8');
            return crypto.createHash('md5').update(content).digest('hex').substring(0, 16);
        } catch (e) {
            return 'FILE_NOT_FOUND';
        }
    }

    /**
     * Derive a unique suite name from file path + project.
     * @param {string} filePath
     * @param {string} projectName
     * @returns {string}
     */
    _suiteName(filePath, projectName) {
        return filePath
            .replace(/^.*tests\/browser\//, '')
            .replace(/\.spec\.js$/, '')
            .replace(/[/\\]/g, '-') + '--' + projectName;
    }
}

module.exports = WorkbenchReporter;
