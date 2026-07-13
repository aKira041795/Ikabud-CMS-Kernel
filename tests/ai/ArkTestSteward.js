/**
 * ARK Test Steward 0.1 — Failure Analyst
 *
 * Reads Workbench test artifacts and produces structured failure diagnoses.
 * Does NOT modify code. Provides evidence for developer or AI triage.
 *
 * Usage:
 *   node tests/ai/ArkTestSteward.js --module=project-audit-ledger [--run=latest] [--mode=triage]
 *
 * Output: test_results/ai/steward-diagnosis.json
 */

// @ts-check
var fs = require('fs');
var path = require('path');

var RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');
var AI_DIR = path.resolve(__dirname, '../../test_results/ai');
var MODULE = process.argv.includes('--module')
    ? process.argv[process.argv.indexOf('--module') + 1]
    : 'project-audit-ledger';

// ── Evidence Collector ─────────────────────────────────────────

function readJson(filePath) {
    try { return JSON.parse(fs.readFileSync(filePath, 'utf-8')); }
    catch (e) { return null; }
}

function collectEvidence() {
    var manifest = readJson(path.join(RESULTS_DIR, 'manifest.json'));
    var issueReport = readJson(path.join(RESULTS_DIR, 'issue-report.json'));
    var suiteFiles = [];
    try {
        suiteFiles = fs.readdirSync(RESULTS_DIR).filter(function (f) {
            return f.endsWith('.json') && f !== 'manifest.json' && f !== 'issue-report.json' && f !== 'fingerprint-baseline.json';
        });
    } catch (e) { /* no results dir */ }

    // Collect all suite results
    var suites = {};
    var failures = [];
    for (var i = 0; i < suiteFiles.length; i++) {
        var suite = readJson(path.join(RESULTS_DIR, suiteFiles[i]));
        if (suite) {
            suites[suiteFiles[i]] = suite;
            if (suite.results) {
                for (var j = 0; j < suite.results.length; j++) {
                    var r = suite.results[j];
                    if (r.status === 'failed' || r.status === 'timedOut') {
                        failures.push({
                            suite: suiteFiles[i],
                            test: r.test,
                            status: r.status,
                            detail: r.detail || '',
                            file: suite.suite ? suite.suite.replace(/--.*/, '') : suiteFiles[i],
                        });
                    }
                }
            }
        }
    }

    // Try to locate failing test files
    var testFiles = [];
    for (var k = 0; k < failures.length; k++) {
        var f = failures[k];
        var testDir = path.resolve(__dirname, '../../tests/browser');
        var possible = findTestFile(testDir, f.file || f.suite);
        if (possible) testFiles.push(possible);
    }

    // Read module contracts
    var contracts = collectModuleContracts(MODULE);

    return {
        manifest: manifest,
        issueReport: issueReport,
        suites: suites,
        failures: failures,
        testFiles: testFiles,
        contracts: contracts,
        timestamp: new Date().toISOString(),
    };
}

function findTestFile(dir, pattern) {
    if (!fs.existsSync(dir)) return null;
    try {
        var entries = fs.readdirSync(dir, { recursive: true });
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].endsWith('.spec.js') && entries[i].includes('pal')) {
                return path.resolve(dir, entries[i]);
            }
        }
    } catch (e) { /* skip */ }
    return null;
}

function collectModuleContracts(moduleId) {
    var contracts = {};
    var modDir = path.resolve(__dirname, '../../modules', moduleId);

    // Routes
    var routesFile = path.join(modDir, 'routes.php');
    if (fs.existsSync(routesFile)) {
        try {
            var routesContent = fs.readFileSync(routesFile, 'utf-8');
            contracts.routes = extractRoutes(routesContent);
        } catch (e) { contracts.routes = { error: e.message }; }
    }

    // Handlers
    var handlersFile = path.join(modDir, 'handlers');
    if (fs.existsSync(handlersFile)) {
        contracts.handlers_dir = handlersFile;
    }

    // Services (workflow)
    var svcDir = path.join(modDir, 'services');
    if (fs.existsSync(svcDir)) {
        try {
            var svcFiles = fs.readdirSync(svcDir).filter(function (f) { return f.endsWith('.php'); });
            contracts.services = svcFiles;
            // Look for workflow transitions
            for (var i = 0; i < svcFiles.length; i++) {
                var content = fs.readFileSync(path.join(svcDir, svcFiles[i]), 'utf-8');
                var transitions = extractTransitions(content);
                if (transitions.length > 0) {
                    contracts.workflow = { file: 'services/' + svcFiles[i], transitions: transitions };
                }
            }
        } catch (e) { /* skip */ }
    }

    // Capabilities
    var modJson = path.join(modDir, 'module.json');
    if (fs.existsSync(modJson)) {
        try {
            var manifest = JSON.parse(fs.readFileSync(modJson, 'utf-8'));
            contracts.capabilities = {
                exposes: Object.keys(manifest.capabilities?.exposes || {}),
                depends: Object.keys(manifest.capabilities?.depends || {}),
            };
        } catch (e) { /* skip */ }
    }

    // Templates (for page family detection)
    var tplDir = path.join(modDir, 'templates', moduleId, 'pages');
    if (fs.existsSync(tplDir)) {
        try {
            contracts.pages = fs.readdirSync(tplDir).filter(function (f) { return f.endsWith('.disyl'); });
        } catch (e) { /* skip */ }
    }

    return contracts;
}

function extractRoutes(content) {
    var routes = { GET: [], POST: [] };
    // Match route definitions: '/path' => 'module:handler'
    var getMatch = content.match(/'GET'\s*=>\s*\[([\s\S]*?)\]/);
    var postMatch = content.match(/'POST'\s*=>\s*\[([\s\S]*?)\]/);
    if (getMatch) {
        var getRoutes = getMatch[1].match(/'([^']+)'\s*=>\s*'([^']+)'/g) || [];
        routes.GET = getRoutes.map(function (r) {
            var m = r.match(/'([^']+)'\s*=>\s*'([^']+)'/);
            return m ? { path: m[1], handler: m[2] } : null;
        }).filter(Boolean);
    }
    if (postMatch) {
        var postRoutes = postMatch[1].match(/'([^']+)'\s*=>\s*'([^']+)'/g) || [];
        routes.POST = postRoutes.map(function (r) {
            var m = r.match(/'([^']+)'\s*=>\s*'([^']+)'/);
            return m ? { path: m[1], handler: m[2] } : null;
        }).filter(Boolean);
    }
    return routes;
}

function extractTransitions(content) {
    var transitions = [];
    // Look for TRANSITIONS constant with arrow notation
    var match = content.match(/TRANSITIONS\s*=\s*\[([\s\S]*?)\];/);
    if (match) {
        var lines = match[1].split('\n');
        for (var i = 0; i < lines.length; i++) {
            var arrow = lines[i].match(/'(\w+)'\s*=>\s*'(\w+)'/);
            if (arrow) transitions.push({ from: arrow[1], to: arrow[2] });
        }
    }
    return transitions;
}

// ── Diagnosis Engine ───────────────────────────────────────────

function diagnose(evidence) {
    var diagnosis = {
        classification: 'undetermined',
        confidence: 0.5,
        summary: 'No failures to analyze.',
        evidence: [],
        suspected_files: [],
        recommended_action: 'No action needed — all tests passed.',
        safe_to_auto_heal: false,
        module: MODULE,
        run_context: {
            total_tests: 0,
            passed: 0,
            failed: 0,
            issues: evidence.issueReport ? evidence.issueReport.total_issues : 0,
        },
    };

    if (!evidence.failures || evidence.failures.length === 0) {
        if (evidence.manifest) {
            var total = 0, passed = 0;
            for (var s in evidence.manifest.suites) {
                if (!evidence.manifest.suites.hasOwnProperty(s)) continue;
                total += evidence.manifest.suites[s].total || 0;
                passed += evidence.manifest.suites[s].passed || 0;
            }
            diagnosis.run_context.total_tests = total;
            diagnosis.run_context.passed = passed;
            diagnosis.run_context.failed = total - passed;
            diagnosis.summary = total + ' tests, ' + passed + ' passed, ' + (total - passed) + ' failed.';
        }
        return diagnosis;
    }

    // Analyze each failure
    var primary = evidence.failures[0];
    diagnosis.failed_test = {
        file: primary.file || primary.suite,
        test: primary.test,
        error: primary.detail ? primary.detail.substring(0, 200) : 'No error detail',
    };

    var detail = primary.detail || '';
    var testContent = '';
    for (var t = 0; t < (evidence.testFiles || []).length; t++) {
        try {
            testContent = fs.readFileSync(evidence.testFiles[t], 'utf-8');
            break;
        } catch (e) { /* skip */ }
    }

    // ── Pattern-based classification ──

    // Timeout → timing or missing element
    if (detail.includes('Timeout') || detail.includes('timeout')) {
        if (detail.includes('waitForSelector') || detail.includes('waitForURL')) {
            if (detail.includes('data-wb-component')) {
                diagnosis.classification = 'environment-issue';
                diagnosis.confidence = 0.85;
                diagnosis.summary = 'App shell did not render — server may not be running or login failed.';
                diagnosis.evidence.push('waitForSelector timed out waiting for app-shell');
                diagnosis.evidence.push('Check that the application server is running');
                diagnosis.recommended_action = 'Verify app server is running and ADMIN_USER/ADMIN_PASS env vars are set.';
                return diagnosis;
            }
            if (detail.includes('data-wb-entity') || detail.includes('data-ikb')) {
                diagnosis.classification = 'application-defect';
                diagnosis.confidence = 0.8;
                diagnosis.summary = 'Expected entity or list not rendered on the page.';
                diagnosis.evidence.push('Entity selector timed out — element not found in DOM');
                diagnosis.evidence.push('Check that the entity exists and the current user can access it');
                diagnosis.recommended_action = 'Inspect the page with ?wb_inspect=1 to verify available actions and lists.';
                return diagnosis;
            }
            diagnosis.classification = 'timing-race';
            diagnosis.confidence = 0.6;
            diagnosis.summary = 'Element may exist but test waited too briefly.';
        }
    }

    // Assertion failure → wrong text, missing element
    if (detail.includes('expect') || detail.includes('Expected')) {
        if (detail.includes('toContainText') || detail.includes('toHaveText')) {
            diagnosis.classification = 'application-defect';
            diagnosis.confidence = 0.75;
            diagnosis.summary = 'Expected text not found on page — status or label mismatch.';
            diagnosis.evidence.push('Text assertion failed — expected content not rendered');
            diagnosis.evidence.push('May indicate workflow transition did not apply or wrong status displayed');
            diagnosis.recommended_action = 'Check the entity status in the database, then verify the detail page renders it correctly.';
            return diagnosis;
        }
        if (detail.includes('toBeVisible')) {
            diagnosis.classification = 'locator-stale';
            diagnosis.confidence = 0.7;
            diagnosis.summary = 'Expected element to be visible but it was not found. Selector may be stale.';
            diagnosis.evidence.push('toBeVisible assertion failed');
            diagnosis.evidence.push('Button/link locator may have changed — check with ?wb_inspect=1');
            diagnosis.recommended_action = 'Run the page with ?wb_inspect=1 and update data-wb-action selectors.';
            return diagnosis;
        }
    }

    // Network or HTTP errors
    if (detail.includes('net::') || detail.includes('NS_ERROR')) {
        diagnosis.classification = 'environment-issue';
        diagnosis.confidence = 0.9;
        diagnosis.summary = 'Network request failed — server or network issue.';
        diagnosis.evidence.push('Network-level failure detected');
        diagnosis.recommended_action = 'Check server connectivity and CORS configuration.';
        return diagnosis;
    }

    // Seed/DB command failures
    if (detail.includes('Command failed') || detail.includes('SQLSTATE') || detail.includes('prepare()')) {
        diagnosis.classification = 'environment-issue';
        diagnosis.confidence = 0.9;
        diagnosis.summary = 'Seed script or database command failed — tenant DB may not be configured.';
        diagnosis.evidence.push('Seed script exited with error — database connection or tenant configuration issue');
        diagnosis.evidence.push('The test tenant ID may not have a DB connection in kernel_tenant_db_connections');
        diagnosis.recommended_action = 'Verify the test tenant has a database connection, or set PAL_TEST_TENANT to a valid tenant.';
        return diagnosis;
    }

    // 404 / 500 from the app
    if (detail.includes('404') || detail.includes('500')) {
        diagnosis.classification = 'application-defect';
        diagnosis.confidence = 0.8;
        diagnosis.summary = 'HTTP error response from application.';
        diagnosis.evidence.push('Server returned error status');
        diagnosis.suspected_files = findSuspectedFiles(primary, evidence.contracts);
        diagnosis.recommended_action = 'Check app.log and error.log for the request.';
        return diagnosis;
    }

    // Could not extract useful pattern — generic analysis
    diagnosis.classification = 'undetermined';
    diagnosis.confidence = 0.3;
    diagnosis.summary = 'Could not classify the failure from error text alone. Manual triage needed.';
    diagnosis.evidence.push('Error: ' + primary.detail.substring(0, 300));
    diagnosis.recommended_action = 'Review the Playwright trace and error context file.';

    // Add contract evidence
    if (evidence.contracts && evidence.contracts.routes) {
        diagnosis.evidence.push('Module has ' + (evidence.contracts.routes.GET || []).length + ' GET routes, ' + (evidence.contracts.routes.POST || []).length + ' POST routes');
    }
    if (evidence.contracts && evidence.contracts.workflow) {
        diagnosis.evidence.push('Workflow has ' + evidence.contracts.workflow.transitions.length + ' defined transitions');
    }
    if (evidence.contracts && evidence.contracts.capabilities) {
        diagnosis.evidence.push('Module exposes ' + evidence.contracts.capabilities.exposes.length + ' capabilities');
    }

    return diagnosis;
}

function findSuspectedFiles(failure, contracts) {
    var files = [];
    var testFile = failure.file || '';
    var suite = failure.suite || '';

    // Map test suites to likely handler files
    var suiteMap = {
        'project': 'modules/' + MODULE + '/handlers/30-projects.php',
        'expense': 'modules/' + MODULE + '/handlers/25-expenses.php',
        'approval': 'modules/' + MODULE + '/handlers/55-approvals.php',
        'client': 'modules/' + MODULE + '/handlers/20-clients.php',
        'fabrication': 'modules/' + MODULE + '/handlers/45-fabrication.php',
        'sale': 'modules/' + MODULE + '/handlers/50-sales.php',
        'dashboard': 'modules/' + MODULE + '/handlers/15-projects.php',
    };

    for (var key in suiteMap) {
        if (suiteMap.hasOwnProperty(key) && (testFile.includes(key) || suite.includes(key))) {
            var handler = suiteMap[key];
            if (fs.existsSync(path.resolve(__dirname, '../../', handler))) {
                files.push(handler);
            }
            // Also add the template
            var tpl = handler.replace('handlers/', 'templates/' + MODULE + '/pages/').replace('.php', '.disyl');
            if (fs.existsSync(path.resolve(__dirname, '../../', tpl))) {
                files.push(tpl);
            }
            break;
        }
    }

    return files.length > 0 ? files : ['Unable to determine — check test file for related routes'];
}

// ── Main ───────────────────────────────────────────────────────

function main() {
    console.log('🔍 ARK Test Steward 0.1 — Failure Analyst');
    console.log('   Module: ' + MODULE);
    console.log('');

    var evidence = collectEvidence();
    var diagnosis = diagnose(evidence);

    // Output
    if (!fs.existsSync(AI_DIR)) fs.mkdirSync(AI_DIR, { recursive: true });

    var outputPath = path.join(AI_DIR, 'steward-diagnosis.json');
    fs.writeFileSync(outputPath, JSON.stringify(diagnosis, null, 2));
    console.log('  📄 ' + outputPath);

    // Console summary
    console.log('');
    console.log('  Classification: ' + diagnosis.classification);
    console.log('  Confidence:     ' + Math.round(diagnosis.confidence * 100) + '%');
    console.log('  Summary:        ' + diagnosis.summary);
    if (diagnosis.evidence.length > 0) {
        console.log('  Evidence:');
        for (var i = 0; i < diagnosis.evidence.length; i++) {
            console.log('    • ' + diagnosis.evidence[i]);
        }
    }
    if (diagnosis.suspected_files.length > 0) {
        console.log('  Suspected files:');
        for (var j = 0; j < diagnosis.suspected_files.length; j++) {
            console.log('    • ' + diagnosis.suspected_files[j]);
        }
    }
    console.log('  Recommended:    ' + diagnosis.recommended_action);
    console.log('  Auto-heal safe: ' + (diagnosis.safe_to_auto_heal ? 'yes' : 'no'));
}

main();
