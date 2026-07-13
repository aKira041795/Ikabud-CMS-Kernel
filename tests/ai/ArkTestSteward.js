/**
 * ARK Test Steward 0.2 — Failure Analyst + AI fallback
 *
 * Reads Workbench test artifacts and produces structured failure diagnoses.
 * Does NOT modify code. Provides evidence for developer or AI triage.
 *
 * Usage:
 *   node tests/ai/ArkTestSteward.js --module=project-audit-ledger [--run=latest] [--mode=triage]
 *
 * Output: test_results/ai/steward-diagnosis.json
 */

// @ts-nocheck
var fs = require('fs');
var path = require('path');

var RESULTS_DIR = path.resolve(__dirname, '../../test_results/browser');
var AI_DIR = path.resolve(__dirname, '../../test_results/ai');
var BROWSER_TEST_DIR = path.resolve(__dirname, '../../tests/browser');
var MODULE = argVal('--module') || 'project-audit-ledger';
var RUN_SELECTOR = argVal('--run') || 'latest';
var MODE = argVal('--mode') || 'triage';

// Load AI config
var CONFIG = readJson(path.resolve(__dirname, 'config.json')) || {};
var AI_ENABLED = CONFIG.ai?.enabled && process.env.ARK_AI_API_KEY;
var AI_API_KEY = process.env.ARK_AI_API_KEY || '';

function argVal(flag) {
    var idx = process.argv.indexOf(flag);
    return idx >= 0 ? process.argv[idx + 1] : null;
}

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
    var allFailures = [];
    var allSkipped = [];
    for (var i = 0; i < suiteFiles.length; i++) {
        var suite = readJson(path.join(RESULTS_DIR, suiteFiles[i]));
        if (suite) {
            suites[suiteFiles[i]] = suite;
            if (suite.results) {
                for (var j = 0; j < suite.results.length; j++) {
                    var r = suite.results[j];
                    if (r.status === 'failed' || r.status === 'timedOut') {
                        allFailures.push({
                            suite: suiteFiles[i],
                            suiteName: suite.suite || suiteFiles[i],
                            test: r.test,
                            status: r.status,
                            detail: r.detail || '',
                        });
                    } else if (r.status === 'skipped') {
                        allSkipped.push({ suite: suiteFiles[i], test: r.test });
                    }
                }
            }
        }
    }

    // ── Failure clustering ──────────────────────────────────
    var rootFailures = allFailures.slice();
    var consequentialSkips = allSkipped.slice();

    // Resolve exact test source files from suite names
    for (var m = 0; m < rootFailures.length; m++) {
        rootFailures[m].sourceFile = findTestFile(rootFailures[m].suiteName);
    }

    // Read module contracts
    var contracts = collectModuleContracts(MODULE);

    return {
        manifest: manifest,
        issueReport: issueReport,
        suites: suites,
        rootFailures: rootFailures,
        consequentialSkips: consequentialSkips,
        allFailures: allFailures,
        contracts: contracts,
        timestamp: new Date().toISOString(),
    };
}

/**
 * Resolve a suite name to its exact test source file.
 * Suite names follow pattern: '<path>--<project>'
 * e.g. 'workbench-wb-inspect--chromium' → tests/browser/workbench/wb-inspect.spec.js
 */
function findTestFile(suiteName) {
    if (!suiteName) return null;
    var fileHint = suiteName.replace(/--.*/, '').replace(/-/g, '/');
    var direct = path.resolve(BROWSER_TEST_DIR, fileHint + '.spec.js');
    if (fs.existsSync(direct)) return direct;
    if (!fs.existsSync(BROWSER_TEST_DIR)) return null;
    try {
        var entries = fs.readdirSync(BROWSER_TEST_DIR, { recursive: true });
        var short = fileHint.split('/').pop();
        for (var i = 0; i < entries.length; i++) {
            if (entries[i].endsWith('.spec.js') && entries[i].includes(short)) {
                return path.resolve(BROWSER_TEST_DIR, entries[i]);
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
    // ── Always calculate run summary ──────────────────────
    var summary = summarizeManifest(evidence.manifest);
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
            total_tests: summary.total,
            passed: summary.passed,
            failed: summary.failed,
            skipped: summary.skipped,
            timedOut: summary.timedOut,
            issues: evidence.issueReport ? evidence.issueReport.total_issues : 0,
        },
    };

    var rootFailures = evidence.rootFailures || [];
    var consequentialSkips = evidence.consequentialSkips || [];

    if (rootFailures.length === 0) {
        diagnosis.summary = summary.total + ' tests, ' + summary.passed + ' passed, ' + summary.failed + ' failed, ' + summary.skipped + ' skipped.';
        return diagnosis;
    }

    // ── Analyze primary (root) failure ────────────────────
    var primary = rootFailures[0];
    diagnosis.failed_test = {
        file: primary.sourceFile || primary.suiteName || primary.suite,
        test: primary.test,
        error: (primary.detail || '').substring(0, 300),
    };

    // Cluster info
    if (rootFailures.length > 1) {
        diagnosis.evidence.push(rootFailures.length + ' root failures detected');
    }
    if (consequentialSkips.length > 0) {
        diagnosis.evidence.push(consequentialSkips.length + ' tests skipped (likely consequential)');
    }

    var detail = primary.detail || '';
    // Strip ANSI escape codes from error text for cleaner matching
    var cleanDetail = detail.replace(/\u001b\[\d+m/g, '').replace(/\u001b\[\d+;\d+m/g, '');

    // ── Pattern-based classification ──

    // Seed/DB command failures
    if (cleanDetail.includes('Command failed') || cleanDetail.includes('SQLSTATE') || cleanDetail.includes('prepare()') || cleanDetail.includes('on null')) {
        diagnosis.classification = 'environment-issue';
        diagnosis.confidence = 0.9;
        diagnosis.summary = 'Seed script or database command failed — tenant DB may not be configured.';
        diagnosis.evidence.push('Seed script exited with error — database connection or tenant configuration issue');
        diagnosis.evidence.push('The test tenant ID may not have a DB connection in kernel_tenant_db_connections');
        if (consequentialSkips.length > 0) {
            diagnosis.evidence.push(consequentialSkips.length + ' subsequent tests skipped (consequential)');
        }
        diagnosis.recommended_action = 'Verify test tenant has a database connection. Set PAL_TEST_TENANT=502 for local dev.';
        return diagnosis;
    }

    // Timeout → timing or missing element
    if (cleanDetail.includes('Timeout') || cleanDetail.includes('timeout') || cleanDetail.includes('timedOut')) {
        if (cleanDetail.includes('waitForSelector') || cleanDetail.includes('waitForURL')) {
            if (cleanDetail.includes('data-wb-component')) {
                diagnosis.classification = 'environment-issue';
                diagnosis.confidence = 0.85;
                diagnosis.summary = 'App shell did not render — server may not be running or login failed.';
                diagnosis.evidence.push('waitForSelector timed out waiting for app-shell');
                diagnosis.evidence.push('Check that the application server is running');
                diagnosis.recommended_action = 'Verify app server is running and ADMIN_USER/ADMIN_PASS env vars are set.';
                return diagnosis;
            }
            if (cleanDetail.includes('data-wb-entity') || cleanDetail.includes('data-ikb')) {
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
    if (cleanDetail.includes('expect') || cleanDetail.includes('Expected')) {
        if (cleanDetail.includes('toContainText') || cleanDetail.includes('toHaveText')) {
            diagnosis.classification = 'application-defect';
            diagnosis.confidence = 0.75;
            diagnosis.summary = 'Expected text not found on page — status or label mismatch.';
            diagnosis.evidence.push('Text assertion failed — expected content not rendered');
            diagnosis.evidence.push('May indicate workflow transition did not apply or wrong status displayed');
            diagnosis.recommended_action = 'Check the entity status in the database, then verify the detail page renders it correctly.';
            return diagnosis;
        }
        if (cleanDetail.includes('toBeVisible')) {
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
    if (cleanDetail.includes('net::') || cleanDetail.includes('NS_ERROR')) {
        diagnosis.classification = 'environment-issue';
        diagnosis.confidence = 0.9;
        diagnosis.summary = 'Network request failed — server or network issue.';
        diagnosis.evidence.push('Network-level failure detected');
        diagnosis.recommended_action = 'Check server connectivity and CORS configuration.';
        return diagnosis;
    }

    // 404 / 500 from the app
    if (cleanDetail.includes('404') || cleanDetail.includes('500')) {
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
    var suite = failure.suiteName || failure.suite || '';
    var sourceFile = failure.sourceFile || '';

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
        if (suiteMap.hasOwnProperty(key) && (sourceFile.includes(key) || suite.includes(key))) {
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

// ── Manifest summary ──────────────────────────────────────────

function summarizeManifest(manifest) {
    var summary = { total: 0, passed: 0, failed: 0, skipped: 0, timedOut: 0 };
    if (!manifest || !manifest.suites) return summary;
    for (var s in manifest.suites) {
        if (!manifest.suites.hasOwnProperty(s)) continue;
        var suite = manifest.suites[s];
        summary.total += suite.total || 0;
        summary.passed += suite.passed || 0;
        summary.failed += suite.failed || 0;
        summary.skipped += suite.skipped || 0;
    }
    return summary;
}

// ── AI Fallback (Stage B) ─────────────────────────────────────

/**
 * Build a redacted evidence bundle for AI analysis.
 * Strips sensitive data, truncates long fields.
 */
function buildAiBundle(evidence, diagnosis) {
    var redact = CONFIG.redaction || {};
    var maxBytes = redact.max_evidence_bytes || 4096;

    var bundle = {
        preliminary_diagnosis: {
            classification: diagnosis.classification,
            confidence: diagnosis.confidence,
            summary: diagnosis.summary,
        },
        failed_test: diagnosis.failed_test || {},
        run_context: diagnosis.run_context || {},
        module: MODULE,
        workflow: evidence.contracts?.workflow || null,
        capabilities: evidence.contracts?.capabilities || null,
        issues: evidence.issueReport ? {
            total: evidence.issueReport.total_issues,
            by_kind: evidence.issueReport.by_kind,
            by_severity: evidence.issueReport.by_severity,
        } : null,
        root_failures: (evidence.rootFailures || []).map(function (f) {
            return {
                test: f.test,
                status: f.status,
                detail: redactText(f.detail || '', redact),
            };
        }),
        consequential_skips: (evidence.consequentialSkips || []).length,
    };

    // Truncate to max bytes
    var json = JSON.stringify(bundle);
    if (json.length > maxBytes) {
        bundle.root_failures = bundle.root_failures.slice(0, 3);
        for (var i = 0; i < bundle.root_failures.length; i++) {
            bundle.root_failures[i].detail = bundle.root_failures[i].detail.substring(0, 150);
        }
        json = JSON.stringify(bundle);
        if (json.length > maxBytes) {
            bundle.root_failures = bundle.root_failures.slice(0, 1);
            json = JSON.stringify(bundle);
        }
    }

    return bundle;
}

function redactText(text, redact) {
    var patterns = redact.strip_patterns || ['password', 'token', 'secret', 'cookie'];
    var out = text.substring(0, redact.max_stack_chars || 200);
    for (var i = 0; i < patterns.length; i++) {
        var re = new RegExp(patterns[i] + '[=:]["\']?[^\\s,;"\'}]+', 'gi');
        out = out.replace(re, patterns[i] + '=[REDACTED]');
    }
    return out;
}

/**
 * Call AI provider for diagnosis when deterministic classifier is uncertain.
 * Returns null if AI is disabled, fails, or returns invalid JSON.
 */
async function aiDiagnose(evidence, diagnosis) {
    if (!AI_ENABLED) return null;

    var cfg = CONFIG.ai;
    var bundle = buildAiBundle(evidence, diagnosis);
    var prompt = readTextFile(path.resolve(__dirname, 'prompts/triage.md')) || '';

    console.log('  🤖 AI fallback: ' + cfg.provider + '/' + cfg.model);

    var data, content;
    try {
        var res = await globalThis.fetch(cfg.endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Authorization': 'Bearer ' + AI_API_KEY,
            },
            body: JSON.stringify({
                model: cfg.model,
                messages: [
                    { role: 'system', content: prompt },
                    { role: 'user', content: JSON.stringify(bundle, null, 2) },
                ],
                max_tokens: cfg.max_tokens || 1000,
                temperature: cfg.temperature || 0.1,
                ...(cfg.reasoning_effort ? { reasoning_effort: cfg.reasoning_effort } : {}),
                stream: false,
            }),
            signal: AbortSignal.timeout(cfg.timeout_ms || 30000),
        });

        if (!res.ok) {
            console.warn('  ⚠ AI API returned ' + res.status);
            return null;
        }

        data = await res.json();
        content = data?.choices?.[0]?.message?.content || '';
        // DeepSeek V4 Pro may return reasoning_content separately
        if (!content && data?.choices?.[0]?.message?.reasoning_content) {
            content = data.choices[0].message.reasoning_content;
        }
        // Extract JSON from markdown code block or raw content
        var jsonMatch = content.match(/```(?:json)?\s*([\s\S]*?)```/);
        var jsonStr = jsonMatch ? jsonMatch[1].trim() : content.trim();
        // Remove any leading/trailing non-JSON text
        var braceStart = jsonStr.indexOf('{');
        var braceEnd = jsonStr.lastIndexOf('}');
        if (braceStart >= 0 && braceEnd > braceStart) {
            jsonStr = jsonStr.substring(braceStart, braceEnd + 1);
        }
        var aiResult = JSON.parse(jsonStr);
        console.log('  ✅ AI: ' + aiResult.classification + ' (' + Math.round((aiResult.confidence || 0) * 100) + '%)');
        return aiResult;
    } catch (e) {
        console.warn('  ⚠ AI fallback failed: ' + (e.message || 'unknown'));
        // Log a snippet of the raw response for debugging
        if (data && content) {
            console.warn('  ⚠ Raw response (first 500 chars): ' + content.substring(0, 500));
            console.warn('  ⚠ JSON attempted: ' + jsonStr.substring(0, 500));
        }
        return null;
    }
}

function readTextFile(filePath) {
    try { return fs.readFileSync(filePath, 'utf-8'); }
    catch (e) { return null; }
}

function validateAgainstSchema(result, schema) {
    if (!schema || !schema.required) return true;
    for (var i = 0; i < schema.required.length; i++) {
        if (!(schema.required[i] in result)) return false;
    }
    if (schema.properties?.classification?.enum) {
        if (schema.properties.classification.enum.indexOf(result.classification) === -1) return false;
    }
    return true;
}

// ── Main ───────────────────────────────────────────────────────

async function main() {
    console.log('🔍 ARK Test Steward 0.2 — Failure Analyst');
    if (AI_ENABLED) console.log('   AI: ' + CONFIG.ai.provider + '/' + CONFIG.ai.model + ' (fallback enabled)');
    console.log('   Module: ' + MODULE);
    console.log('');

    var evidence = collectEvidence();
    var diagnosis = diagnose(evidence);

    // AI fallback for low-confidence or undetermined classifications
    if (AI_ENABLED && (diagnosis.classification === 'undetermined' || diagnosis.confidence < 0.75)) {
        var aiResult = await aiDiagnose(evidence, diagnosis);
        if (aiResult) {
            // Merge: AI provides classification + evidence, deterministic provides run_context + contracts
            diagnosis.classification = aiResult.classification || diagnosis.classification;
            diagnosis.confidence = aiResult.confidence || diagnosis.confidence;
            diagnosis.summary = aiResult.summary || diagnosis.summary;
            if (aiResult.evidence) diagnosis.evidence = aiResult.evidence;
            if (aiResult.suspected_files) diagnosis.suspected_files = aiResult.suspected_files;
            if (aiResult.recommended_action) diagnosis.recommended_action = aiResult.recommended_action;
            diagnosis.ai_assisted = true;
        }
    }

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
