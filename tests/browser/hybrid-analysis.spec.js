/**
 * Hybrid Analysis — Unites ALL layers into a single test run.
 *
 * Runs:
 *   1. MODULE PAGE DISCOVERY — reads module.json, discovers all pages
 *   2. PROCESS COMPREHENSION — parses templates, understands fields/creatables/flows
 *   3. MODULE DIAGNOSTIC — visits every page, compares expected vs actual rendering
 *   4. BEHAVIOR FLOW — interacts with forms (fill, add items, submit) to catch UX issues
 *   5. EVIDENCE BRIDGE — feeds all findings into the PHP Comprehension Engine for Bayesian analysis
 *   6. COMPREHENSION ENGINE — runs the 6-layer hybrid analysis (Deterministic + Bayesian +
 *      Semantic + Temporal + Pattern + Cross-Module) on the evidence
 *
 * This is the full HYBRID approach: static analysis + dynamic checks + behavioral testing
 * → unified evidence → Bayesian causal analysis → fix recommendations.
 *
 * Usage:
 *   MODULE=project-audit-ledger npx playwright test tests/browser/hybrid-analysis.spec.js
 *
 * Environment:
 *   MODULE     - Module directory name (required)
 *   ADMIN_USER - Login username
 *   ADMIN_PASS - Login password
 *   HYBRID_GATE - Gate severity for failures: 'off' | 'critical' | 'major' (default: 'critical')
 */

// @ts-check
const MODULE = process.env.MODULE || '';
const workbenchFixture = MODULE === 'daily-ledger'
    ? require('./modules/daily-ledger/daily-ledger-adapter')
    : require('./WorkbenchFixture');
const { test, expect } = workbenchFixture;
const { ModulePageDiscovery } = require('./ModulePageDiscovery');
const { ProcessComprehension } = require('./ProcessComprehension');
const { ModuleDiagnostic } = require('./ModuleDiagnostic');
const { BehaviorFlow, BehaviorRegistry } = require('./BehaviorFlow');
const { EvidenceBridge } = require('./comprehension/EvidenceBridge');
const { AnalystReport } = require('./analyst/AnalystReport');
const { ScenarioGuidance } = require('./scenario/ScenarioGuidance');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { execFileSync } = require('child_process');

// Canonical run ID — set early as fallback. For full reliability,
// use the wrapper: node tests/browser/run-workbench.js --module=<id>
if (!process.env.WB_RUN_ID) {
    const stamp = new Date().toISOString().replace(/\D/g, '').slice(0, 14);
    process.env.WB_RUN_ID = `${stamp}-${crypto.randomUUID().slice(0, 8)}`;
}

const MODULE_PATH = process.env.MODULE_PATH || path.resolve(__dirname, '../../modules', MODULE);
const HYBRID_GATE = process.env.HYBRID_GATE || 'critical';

if (!MODULE) throw new Error('MODULE environment variable required.');

/**
 * Safely read a JSON file, returning fallback on any error.
 * @param {string} file
 * @param {any} fallback
 */
function readJsonIfExists(file, fallback = {}) {
    try {
        if (!fs.existsSync(file)) return fallback;
        return JSON.parse(fs.readFileSync(file, 'utf-8'));
    } catch (e) {
        return fallback;
    }
}

const manifest = JSON.parse(fs.readFileSync(path.join(MODULE_PATH, 'module.json'), 'utf-8'));

/**
 * Determine whether a gate level should fail the test.
 * @param {string} gate
 * @param {string} severity
 */
function isGateFailure(gate, severity) {
    if (gate === 'off') return false;
    if (gate === 'critical') return severity === 'critical';
    if (gate === 'major') return severity === 'critical' || severity === 'major';
    return severity === 'critical';
}

var FINGERPRINT_FILES_BY_MODULE = {
    'daily-ledger': [
        'modules/daily-ledger/module.json',
        'modules/daily-ledger/routes.php',
        'modules/daily-ledger/workbench-contract.json',
        'templates/modules/daily-ledger/layouts/app.disyl',
        'templates/modules/daily-ledger/cashier/ledger.disyl'
    ]
};

test.describe(`Hybrid Analysis: ${manifest.name || MODULE}`, () => {

    test('full hybrid analysis — static → dynamic → behavioral → Bayesian', async ({ page, integrity }) => {
        test.setTimeout(600000); // 10 min

        (FINGERPRINT_FILES_BY_MODULE[MODULE] || []).forEach(function (relativePath) {
            integrity.fingerprint(relativePath);
        });

        // ════════════════════════════════════════════════════════
        // LAYER 1: Process Comprehension (static template analysis)
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 1: Process Comprehension (static)');
        console.log('═══════════════════════════════════════════');

        const providerData = readJsonIfExists('/tmp/provider_data.json');
        const pc = new ProcessComprehension(MODULE_PATH, manifest, providerData);
        const processReport = pc.generateReport();

        console.log(`  Pages: ${processReport.pages}`);
        console.log(`  Fields: ${processReport.fields}`);
        console.log(`  Creatables: ${processReport.creatables}`);
        console.log(`  Data flows: ${processReport.dataFlows}`);

        // ════════════════════════════════════════════════════════
        // LAYER 2: Module Diagnostic (dynamic rendering checks)
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 2: Module Diagnostic (dynamic)');
        console.log('═══════════════════════════════════════════');

        const diagnostic = new ModuleDiagnostic(page, MODULE_PATH, manifest, providerData);
        const diagnosticResult = await diagnostic.runFullDiagnostic();
        console.log(`  Checks: ${diagnosticResult.passed} passed, ${diagnosticResult.failed} issues`);
        const scenario = ScenarioGuidance.load(process.env.WB_SCENARIO_FILE || '');
        const scenarioGuidance = ScenarioGuidance.evaluate(scenario, diagnosticResult);
        if (scenario) console.log(`  Human guidance: ${scenarioGuidance.summary.answered} evidence-linked, ${scenarioGuidance.summary.unresolved} for validation`);

        // ════════════════════════════════════════════════════════
        // LAYER 3: Behavioral Flow (runtime interaction)
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 3: Behavioral Flow (runtime)');
        console.log('═══════════════════════════════════════════');

        const BehaviorClass = BehaviorRegistry.forModule(MODULE);
        const flow = new BehaviorClass(page);
        const flowObservations = await flow.runDefaultScenario();
        const taskTelemetry = typeof flow.getTelemetry === 'function' ? flow.getTelemetry() : {};
        console.log(flow.generateReport());

        // Track created entities for evidence-based cleanup
        const createdEntities = typeof flow.getCreatedEntities === 'function'
            ? flow.getCreatedEntities()
            : [];
        if (createdEntities.length > 0) {
            console.log(`  📦 Created ${createdEntities.length} entities (tracked for cleanup)`);
        }

        // ════════════════════════════════════════════════════════
        // LAYER 4: Evidence Bridge → Comprehension Engine
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 4: Evidence → Bayesian Analysis');
        console.log('═══════════════════════════════════════════');

        const bridge = new EvidenceBridge(MODULE, undefined, {
            runId: process.env.WB_RUN_ID,
            module: MODULE,
        });
        bridge.addProcessData(processReport);
        bridge.addObservations(diagnosticResult.issues, `${MODULE}.diagnostic`);
        bridge.addBehavioralData(flowObservations);
        if (scenario) {
            bridge.meta.scenario_id = scenario.scenario_id;
            bridge.meta.scenario_run_file = process.env.WB_SCENARIO_RUN_FILE || null;
            bridge.meta.human_directions = scenario.directions;
            bridge.meta.human_questions = scenario.questions;
            bridge.meta.scenario_guidance = scenarioGuidance;
        }

        // Attach created entities to evidence meta for cleanup dispatch
        if (createdEntities.length > 0) {
            bridge.meta.created_entities = createdEntities;
        }

        const evidencePath = bridge.write();

        // Run the PHP Comprehension Engine with this evidence
        console.log('  Running Comprehension Engine...');
        let comprehensionReport = null;
        const engineFailures = [];
        try {
            const engineScript = path.resolve(__dirname, '../../kernel/Workbench/Comprehension/run.php');
            const output = execFileSync('php', [engineScript, MODULE, '--evidence=' + evidencePath], {
                encoding: 'utf-8',
                timeout: 30000,
            });
            console.log(output);

            // Read the comprehension report the engine wrote
            const reportDir = process.env.WB_RUN_ID
                ? path.resolve(__dirname, `../../test_results/ai/runs/${process.env.WB_RUN_ID}`)
                : path.resolve(__dirname, '../../test_results/ai');
            comprehensionReport = readJsonIfExists(path.join(reportDir, 'comprehension-report.json'), null);
        } catch (e) {
            const stderr = e.stderr || '';
            const stdout = e.stdout || '';
            console.log(`  ⚠ Engine output: ${stdout}`);
            if (stderr) console.log(`  ⚠ Engine errors: ${stderr}`);

            const isNoProvider = stderr.includes('No comprehension provider') || stdout.includes('No comprehension provider');
            if (isNoProvider) {
                console.log('  ℹ No comprehension provider registered — deterministic analyst layers remain active');
            } else {
                engineFailures.push({
                    module: MODULE,
                    detail: stderr || stdout || e.message || 'Engine crashed',
                });
            }
        }

        // ════════════════════════════════════════════════════════
        // RESULTS
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  HYBRID ANALYSIS SUMMARY');
        console.log('═══════════════════════════════════════════');
        console.log(`  Layer 1 (Static): ${processReport.pages} pages, ${processReport.fields} fields, ${processReport.creatables} creatables`);
        console.log(`  Layer 2 (Dynamic): ${diagnosticResult.passed} checks, ${diagnosticResult.failed} issues`);
        console.log(`  Layer 3 (Behavioral): ${flowObservations.length} observations`);
        console.log(`  Layer 4 (Bayesian): ${evidencePath}`);

        // Report all issues by severity
        const criticalIssues = diagnosticResult.issues.filter(i => i.severity === 'critical');
        const majorIssues = diagnosticResult.issues.filter(i => i.severity === 'major');

        const behavioralErrors = flowObservations.filter(
            o => o.severity === 'error' || o.severity === 'critical'
        );

        console.log(`  Critical: ${criticalIssues.length}, Major: ${majorIssues.length}, Info: ${diagnosticResult.issues.filter(i => i.severity === 'minor').length}`);
        console.log(`  Behavioral errors: ${behavioralErrors.length}`);

        const analystReport = AnalystReport.build({
            runId: process.env.WB_RUN_ID,
            module: MODULE,
            process: processReport,
            pages: diagnosticResult.pages || [],
            issues: [...diagnosticResult.issues, ...flowObservations],
            coverage: {
                static_pages: processReport.pages,
                runtime_pages: (diagnosticResult.pages || []).length,
                checks_passed: diagnosticResult.passed,
                raw_observations: diagnosticResult.issues.length + flowObservations.length,
            },
            task: taskTelemetry,
            scenario: scenario ? scenarioGuidance : null,
        });
        const analystPath = AnalystReport.write(
            analystReport,
            path.resolve(__dirname, '../../test_results/analyst')
        );
        console.log(`  Analyst report: ${analystPath}`);
        console.log(`  UX evolution: ${analystReport.ux_evolution.score}/100 (${analystReport.ux_evolution.comparison.status})`);
        const uxGateFailure = !analystReport.ux_evolution.gate.passed;

        // ── Gate checks ──
        // Static/dynamic gate
        const gateFailures = diagnosticResult.issues.filter(i => isGateFailure(HYBRID_GATE, i.severity));
        if (gateFailures.length > 0) {
            console.log(`  ❌ Gate (${HYBRID_GATE}): ${gateFailures.length} diagnostic issues`);
        }

        // Behavioral gate — separate from diagnostic gate
        const behavioralGateFailures = behavioralErrors.filter(i => isGateFailure(HYBRID_GATE, i.severity));
        if (behavioralGateFailures.length > 0) {
            console.log(`  ❌ Behavioral gate (${HYBRID_GATE}): ${behavioralGateFailures.length} behavioral errors`);
        }

        // Comprehension gate — parse engine diagnosis and gate on breakpoints
        const comprehensionFailures = [];
        if (comprehensionReport && comprehensionReport.analysis) {
            const analyses = Array.isArray(comprehensionReport.analysis)
                ? comprehensionReport.analysis
                : Object.values(comprehensionReport.analysis);
            for (const actionAnalysis of analyses) {
                if (actionAnalysis && actionAnalysis.breakpoint) {
                    // Use diagnosis severity if available; default to critical for any breakpoint
                    const severity = actionAnalysis.severity
                        || actionAnalysis.breakpoint_severity
                        || 'critical';
                    if (isGateFailure(HYBRID_GATE, severity)) {
                        comprehensionFailures.push({
                            action: actionAnalysis.action || 'unknown',
                            breakpoint: actionAnalysis.breakpoint,
                            likely_area: actionAnalysis.likely_area || 'unknown',
                            severity,
                        });
                    }
                }
            }
        }
        if (comprehensionFailures.length > 0) {
            console.log(`  ❌ Comprehension gate (${HYBRID_GATE}): ${comprehensionFailures.length} breakpoints`);
        }

        // Engine gate — modules with a registered provider must not crash
        if (engineFailures.length > 0) {
            console.log(`  ❌ Engine gate: ${engineFailures.length} engine failure(s) for module with expected provider`);
        }

        // Assert gates
        expect(gateFailures.length, `Diagnostic gate failures (severity≥${HYBRID_GATE}): ${gateFailures.map(i => i.component).join(', ')}`).toBe(0);
        expect(behavioralGateFailures.length, `Behavioral gate failures (severity≥${HYBRID_GATE}): ${behavioralGateFailures.map(i => i.message).join('; ')}`).toBe(0);
        expect(comprehensionFailures.length, `Comprehension breakpoints: ${comprehensionFailures.map(f => `${f.action}@${f.breakpoint} [${f.severity}]`).join(', ')}`).toBe(0);
        expect(engineFailures.length, `Engine failures: ${engineFailures.map(f => f.detail).join('; ')}`).toBe(0);
        expect(uxGateFailure, `UX evolution gate failed: score=${analystReport.ux_evolution.score}, status=${analystReport.ux_evolution.comparison.status}, regressions=${JSON.stringify(analystReport.ux_evolution.comparison.regressions)}`).toBe(false);
    });
});
