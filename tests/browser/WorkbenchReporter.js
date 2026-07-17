/**
 * WorkbenchReporter — Custom Playwright reporter for ARK Workbench tests.
 *
 * RECEIVES from tests:
 *   - Pass/fail results via onTestEnd (automatic)
 *   - Gaps and fingerprints via test.annotations (set by fixture)
 *
 * PRODUCES:
 *   - Per-suite JSON: test_results/browser/<suite>--<project>.json
 *   - Aggregate manifest: test_results/browser/manifest.json
 *   - Fingerprint baseline: test_results/browser/fingerprint-baseline.json
 *
 * ENV:
 *   WB_FINGERPRINT_MODE=check  — fail on mismatch (default, fails if no baseline)
 *   WB_FINGERPRINT_MODE=update — rewrite baseline
 *   WB_FINGERPRINT_MODE=off    — skip fingerprint check
 *
 * Usage: playwright.config.js
 *   reporter: [['list'], ['./tests/browser/WorkbenchReporter.js']]
 */

// @ts-check
var fs = require('fs');
var path = require('path');
var crypto = require('crypto');
var IssueCorrelator = require('./analyst/IssueCorrelator').IssueCorrelator;

var RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');
var FINGERPRINT_MODE = process.env.WB_FINGERPRINT_MODE || 'check';

function makeRunId() {
    var stamp = new Date()
        .toISOString()
        .replace(/\D/g, '')
        .slice(0, 14);
    return stamp + '-' + crypto.randomUUID().slice(0, 8);
}

function writeJsonAtomic(target, data) {
    var tmp = target + '.' + process.pid + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2));
    fs.renameSync(tmp, target);
}

class WorkbenchReporter {
    constructor() {
        this.suites = {};
        this.startTime = new Date().toISOString();
    }

    onTestEnd(test, result) {
        var suiteName = this._suiteName(test.location.file, test.parent.project().name);
        if (!this.suites[suiteName]) {
            this.suites[suiteName] = {
                results: [], gaps: [], fingerprints: {}, started: new Date().toISOString(),
            };
        }
        var suite = this.suites[suiteName];
        if (!suite.evidence) suite.evidence = [];
        if (!suite.issues) suite.issues = [];
        if (!suite.expectedHttp) suite.expectedHttp = [];

        // Record native status — preserve skipped, timedOut, interrupted
        suite.results.push({
            test: test.title,
            status: result.status, // 'passed' | 'failed' | 'skipped' | 'timedOut' | 'interrupted'
            duration: result.duration,
            detail: result.error ? (result.error.message || result.error.stack || '') : '',
        });

        // Collect gaps, fingerprints, issues, and evidence from test annotations
        // Set by test via: test.info().annotations.push({ type, description })
        if (test.annotations && test.annotations.length > 0) {
            for (var i = 0; i < test.annotations.length; i++) {
                var a = test.annotations[i];
                if (a.type === 'wb-gap') {
                    suite.gaps.push(a.description);
                } else if (a.type === 'wb-fingerprint') {
                    try {
                        var fp = JSON.parse(a.description);
                        suite.fingerprints[fp.file] = fp.hash;
                    } catch (e) { /* ignore parse errors */ }
                } else if (a.type === 'wb-issue') {
                    try {
                        var issue = JSON.parse(a.description);
                        issue.suite = suiteName;
                        issue.test = test.title;
                        suite.issues.push(issue);
                    } catch (e) { /* ignore parse errors */ }
                } else if (a.type === 'wb-evidence') {
                    suite.evidence = suite.evidence || [];
                    try {
                        suite.evidence.push(JSON.parse(a.description));
                    } catch (e) { /* ignore parse errors */ }
                } else if (a.type === 'wb-expected-http') {
                    suite.expectedHttp = suite.expectedHttp || [];
                    try {
                        suite.expectedHttp.push(JSON.parse(a.description));
                    } catch (e) { /* ignore parse errors */ }
                }
            }
        }
    }

    onEnd(result) {
        var finishedAt = new Date().toISOString();
        if (!fs.existsSync(RESULTS_DIR)) fs.mkdirSync(RESULTS_DIR, { recursive: true });

        var allFingerprints = {};
        var exitCode = 0;

        // Respect Playwright's own exit code
        if (result && result.status !== 'passed') {
            process.exitCode = 1;
        }

        // ── Run directory ────────────────────────────────
        var runId = process.env.WB_RUN_ID || makeRunId();
        var runDir = path.join(RESULTS_DIR, 'runs', runId);
        if (!fs.existsSync(runDir)) fs.mkdirSync(runDir, { recursive: true });

        // Helper: write to both run dir and legacy top-level (for backward compat)
        function writeRunResult(name, data) {
            writeJsonAtomic(path.join(runDir, name), data);
            writeJsonAtomic(path.join(RESULTS_DIR, name), data);
        }

        // Write per-suite JSON
        for (var suiteName in this.suites) {
            if (!this.suites.hasOwnProperty(suiteName)) continue;
            var suite = this.suites[suiteName];
            var passed = 0, failed = 0, skipped = 0, timedOut = 0, interrupted = 0;
            for (var j = 0; j < suite.results.length; j++) {
                var r = suite.results[j];
                if (r.status === 'passed') passed++;
                else if (r.status === 'failed') failed++;
                else if (r.status === 'skipped') skipped++;
                else if (r.status === 'timedOut') timedOut++;
                else if (r.status === 'interrupted') interrupted++;
            }

            var gapSet = {};
            for (var k = 0; k < suite.gaps.length; k++) gapSet[suite.gaps[k]] = true;
            var gapList = [];
            for (var g in gapSet) { if (gapSet.hasOwnProperty(g)) gapList.push(g); }
            gapList.sort();

            var data = {
                suite: suiteName,
                started: suite.started,
                finished: finishedAt,
                summary: {
                    passed: passed, failed: failed, skipped: skipped,
                    timedOut: timedOut, interrupted: interrupted,
                    total: suite.results.length,
                },
                source_fingerprints: Object.assign({}, suite.fingerprints),
                results: suite.results.slice(),
                gaps: gapList,
                issues: (suite.issues || []).slice(),
            };

            writeRunResult(suiteName + '.json', data);
            Object.assign(allFingerprints, suite.fingerprints);
            console.log('  📄 ' + path.join(runDir, suiteName + '.json'));
        }

        // Aggregate manifest
        var manifest = {
            run_id: runId,
            module: process.env.MODULE || '',
            gate: process.env.HYBRID_GATE || 'critical',
            commit: process.env.GIT_COMMIT || '',
            started: this.startTime,
            finished: finishedAt,
            suites: {},
            fingerprint_mode: FINGERPRINT_MODE,
        };
        for (var suiteName in this.suites) {
            if (!this.suites.hasOwnProperty(suiteName)) continue;
            var suite = this.suites[suiteName];
            var p = 0, f = 0, s = 0, t = 0, x = 0;
            for (var j = 0; j < suite.results.length; j++) {
                var r = suite.results[j];
                if (r.status === 'passed') p++;
                else if (r.status === 'failed') f++;
                else if (r.status === 'skipped') s++;
                else if (r.status === 'timedOut') t++;
                else if (r.status === 'interrupted') x++;
            }
            manifest.suites[suiteName] = {
                passed: p, failed: f, skipped: s,
                timed_out: t, interrupted: x, total: suite.results.length,
                gaps: suite.gaps.length,
                fingerprints: Object.keys(suite.fingerprints).length,
                finished: finishedAt,
            };
        }
        writeRunResult('manifest.json', manifest);
        console.log('  📄 test_results/browser/manifest.json');

        // ── Issue Report ───────────────────────────────────────
        var allIssues = [];
        for (var suiteName in this.suites) {
            if (!this.suites.hasOwnProperty(suiteName)) continue;
            var suite = this.suites[suiteName];
            if (suite.issues && suite.issues.length > 0) {
                allIssues = allIssues.concat(suite.issues);
            }
        }
        // Collect expected HTTP annotations from all suites
        var allExpectedHttp = [];
        for (var suiteName in this.suites) {
            if (!this.suites.hasOwnProperty(suiteName)) continue;
            var suite = this.suites[suiteName];
            if (suite.expectedHttp && suite.expectedHttp.length > 0) {
                allExpectedHttp = allExpectedHttp.concat(suite.expectedHttp);
            }
        }

        // Suppress expected HTTP issues (entity-not-found 404s for placeholder IDs)
        // Any issue with kind 'entity-not-found' is expected by design.
        // Expected HTTP annotations also suppress matching route/status issues.
        var filteredIssues = allIssues.filter(function (iss) {
            if (iss.kind === 'entity-not-found') return false;
            if (iss.kind === 'http-error' && iss.where && iss.detail) {
                for (var e = 0; e < allExpectedHttp.length; e++) {
                    var exp = allExpectedHttp[e];
                    if (iss.where.indexOf(exp.route) !== -1 && iss.detail.indexOf('HTTP ' + exp.status) !== -1) {
                        return false;
                    }
                }
            }
            return true;
        });
        var suppressedCount = allIssues.length - filteredIssues.length;
        var rawIssueCount = filteredIssues.length;
        filteredIssues = IssueCorrelator.correlate(filteredIssues);

        // Sort by severity: critical > major > minor > note
        var sevOrder = { critical: 0, major: 1, minor: 2, note: 3 };
        function severityRank(sev) {
            return Object.prototype.hasOwnProperty.call(sevOrder, sev)
                ? sevOrder[sev]
                : 99;
        }
        filteredIssues.sort(function (a, b) {
            return severityRank(a.severity) - severityRank(b.severity);
        });

        var issueReport = {
            generated: finishedAt,
            total_issues: filteredIssues.length,
            total_observations: rawIssueCount,
            total_correlated: rawIssueCount - filteredIssues.length,
            total_suppressed: suppressedCount,
            by_severity: {},
            by_kind: {},
            issues: filteredIssues,
        };
        for (var i = 0; i < filteredIssues.length; i++) {
            var iss = filteredIssues[i];
            issueReport.by_severity[iss.severity] = (issueReport.by_severity[iss.severity] || 0) + 1;
            issueReport.by_kind[iss.kind] = (issueReport.by_kind[iss.kind] || 0) + 1;
        }
        writeRunResult('issue-report.json', issueReport);

        // Human guidance is finalized against the correlated reporter issues.
        // Earlier diagnostic matches remain provisional and cannot overrule a
        // final HTTP or browser observation.
        try {
            var scenarioFile = process.env.WB_SCENARIO_FILE || '';
            if (scenarioFile && fs.existsSync(scenarioFile)) {
                var ScenarioGuidance = require('./scenario/ScenarioGuidance').ScenarioGuidance;
                var finalScenario = ScenarioGuidance.load(scenarioFile);
                var finalGuidance = ScenarioGuidance.evaluate(finalScenario, { pages: [], issues: filteredIssues }, 'final');
                writeRunResult('scenario-guidance.json', finalGuidance);
            }
        } catch (scenarioError) {
            console.warn('  ⚠ Scenario guidance finalization failed: ' + (scenarioError.message || scenarioError));
        }

        // ── Comprehension Auto-Launch ──────────────────────────
        // For every evidence annotation on failed tests, auto-launch Comprehension Engine.
        var comprehended = 0;
        for (var suiteName in this.suites) {
            if (!this.suites.hasOwnProperty(suiteName)) continue;
            var suite = this.suites[suiteName];
            // Compute summary from results (not stored on suite)
            var sPassed = 0, sFailed = 0, sTimedOut = 0, sInterrupted = 0;
            for (var ri = 0; ri < suite.results.length; ri++) {
                var st = suite.results[ri].status;
                if (st === 'failed') sFailed++;
                else if (st === 'timedOut') sTimedOut++;
                else if (st === 'interrupted') sInterrupted++;
                else if (st === 'passed') sPassed++;
            }
            if (sFailed === 0 && sTimedOut === 0 && sInterrupted === 0) continue;
            if (!suite.evidence || suite.evidence.length === 0) continue;

            for (var k = 0; k < suite.evidence.length; k++) {
                var ev = suite.evidence[k];
                if (!ev.file || !fs.existsSync(ev.file)) continue;

                var { execFileSync } = require('child_process');
                try {
                    var phpArgs = [
                        path.resolve(__dirname, '../../kernel/Workbench/Comprehension/run.php'),
                        ev.module || 'project-audit-ledger',
                        ev.action || '',
                        '--evidence=' + ev.file,
                        '--run-id=' + (ev.run_id || runId),
                    ];
                    if (ev.entity_id) phpArgs.push('--entity-id=' + ev.entity_id);
                    if (ev.tenant_id) phpArgs.push('--tenant=' + ev.tenant_id);

                    var out = execFileSync('php', phpArgs.filter(Boolean), { encoding: 'utf-8', timeout: 30000 });
                    comprehended++;
                    console.log('  🧠 Comprehension [' + ev.action + ']:\n' + out.trim().split('\n').slice(0, 8).join('\n'));
                } catch (e) {
                    console.log('  ⚠ Comprehension [' + (ev.action || '?') + ']: ' + (e.message || 'failed'));
                }
            }
        }
        if (comprehended > 0) {
            console.log('  🧠 Comprehension ran for ' + comprehended + ' action(s)');
        }

        // Assemble final evidence only after issue correlation and all
        // comprehension jobs, so AI never reasons over an interim report.
        try {
            var intelligenceRunner = path.resolve(__dirname, '../../kernel/Workbench/Intelligence/run.php');
            if (fs.existsSync(intelligenceRunner)) {
                var intelligenceOutput = require('child_process').execFileSync('php', [
                    intelligenceRunner, runId, process.env.MODULE || 'unknown'
                ], { encoding: 'utf8', timeout: 45000 });
                console.log('  🧠 Pattern intelligence: ' + intelligenceOutput.trim());
            }
        } catch (intelligenceError) {
            console.warn('  ⚠ Pattern intelligence unavailable; deterministic report retained: ' + (intelligenceError.message || intelligenceError));
        }

        // Console summary
        if (filteredIssues.length > 0) {
            console.log('');
            console.log('  📋 Issue Report — ' + filteredIssues.length + ' issues found');
            for (var kind in issueReport.by_kind) {
                if (!issueReport.by_kind.hasOwnProperty(kind)) continue;
                console.log('     ' + kind + ': ' + issueReport.by_kind[kind]);
            }
            for (var sev in issueReport.by_severity) {
                if (!issueReport.by_severity.hasOwnProperty(sev)) continue;
                console.log('     [' + sev + ']: ' + issueReport.by_severity[sev]);
            }
            console.log('  📄 test_results/browser/issue-report.json');
        } else {
            console.log('  ✅ No issues found');
        }

        // ── Quality Gate ──────────────────────────────────────
        // WB_ISSUE_GATE=off     — issues are informational only (default)
        // WB_ISSUE_GATE=critical — fail when ≥1 critical issue exists
        // WB_ISSUE_GATE=major    — fail when any critical or major issue exists
        var GATE = (process.env.WB_ISSUE_GATE || process.env.HYBRID_GATE || 'off').toLowerCase();
        if (GATE !== 'off') {
            var blockers = 0;
            for (var i = 0; i < filteredIssues.length; i++) {
                var s = filteredIssues[i].severity;
                if (s === 'critical' || (GATE === 'major' && s === 'major')) {
                    blockers++;
                }
            }
            if (blockers > 0) {
                console.error('  🚫 Quality gate [' + GATE + ']: ' + blockers + ' blocking issues');
                process.exitCode = 1;
            } else {
                console.log('  ✅ Quality gate [' + GATE + ']: passed');
            }
        }

        // Fingerprint baseline
        var baselineFile = path.join(RESULTS_DIR, 'fingerprint-baseline.json');

        if (FINGERPRINT_MODE === 'update') {
            writeJsonAtomic(baselineFile, allFingerprints);
            console.log('  📄 Fingerprint baseline updated: ' + baselineFile);
        } else if (FINGERPRINT_MODE === 'check') {
            if (!fs.existsSync(baselineFile)) {
                console.error('  ❌ No fingerprint baseline found at ' + baselineFile);
                console.error('  ❌ Run with WB_FINGERPRINT_MODE=update to create one.');
                console.error('  ❌ Fingerprint check mode requires a committed baseline.');
                process.exitCode = 1;
            } else {
                var baseline = JSON.parse(fs.readFileSync(baselineFile, 'utf-8'));
                var mismatches = 0;
                for (var fp in allFingerprints) {
                    if (!allFingerprints.hasOwnProperty(fp)) continue;
                    var expected = baseline[fp];
                    if (expected && expected !== allFingerprints[fp]) {
                        console.error('  ❌ FINGERPRINT MISMATCH: ' + fp);
                        console.error('     was: ' + expected);
                        console.error('     now: ' + allFingerprints[fp]);
                        mismatches++;
                    }
                }
                // New files not in baseline
                for (var fp in allFingerprints) {
                    if (!allFingerprints.hasOwnProperty(fp)) continue;
                    if (!baseline[fp]) {
                        console.warn('  ⚠ New fingerprint (not in baseline): ' + fp);
                    }
                }
                // Baseline files no longer tested
                for (var fp in baseline) {
                    if (!baseline.hasOwnProperty(fp)) continue;
                    if (!allFingerprints[fp]) {
                        console.warn('  ⚠ Baselines file no longer fingerprinted: ' + fp);
                    }
                }
                if (mismatches > 0) {
                    process.exitCode = 1;
                }
            }
        }
        // FINGERPRINT_MODE=off does nothing — pass through
    }

    _suiteName(filePath, projectName) {
        return filePath
            .replace(/^.*tests\/browser\//, '')
            .replace(/\.spec\.js$/, '')
            .replace(/[/\\]/g, '-') + '--' + projectName;
    }
}

module.exports = WorkbenchReporter;
