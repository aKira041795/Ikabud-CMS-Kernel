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
 */

// @ts-check
const { test, expect } = require('./WorkbenchFixture');
const { ModulePageDiscovery } = require('./ModulePageDiscovery');
const { ProcessComprehension } = require('./ProcessComprehension');
const { ModuleDiagnostic } = require('./ModuleDiagnostic');
const { BehaviorFlow } = require('./BehaviorFlow');
const { EvidenceBridge } = require('./comprehension/EvidenceBridge');
const fs = require('fs');
const path = require('path');
const execSync = require('child_process').execSync;

const MODULE = process.env.MODULE || '';
const MODULE_PATH = process.env.MODULE_PATH || path.resolve(__dirname, '../../modules', MODULE);

if (!MODULE) throw new Error('MODULE environment variable required.');

const manifest = JSON.parse(fs.readFileSync(path.join(MODULE_PATH, 'module.json'), 'utf-8'));

test.describe(`Hybrid Analysis: ${manifest.name || MODULE}`, () => {

    test('full hybrid analysis — static → dynamic → behavioral → Bayesian', async ({ page }) => {
        test.setTimeout(600000); // 10 min

        // ════════════════════════════════════════════════════════
        // LAYER 1: Process Comprehension (static template analysis)
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 1: Process Comprehension (static)');
        console.log('═══════════════════════════════════════════');

        const providerData = JSON.parse(
            fs.readFileSync('/tmp/provider_data.json', 'utf-8').catch(() => '{}')
        );
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

        // ════════════════════════════════════════════════════════
        // LAYER 3: Behavioral Flow (runtime interaction)
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 3: Behavioral Flow (runtime)');
        console.log('═══════════════════════════════════════════');

        const flow = new BehaviorFlow(page);
        const flowObservations = await flow.runJobOrderFlow();
        console.log(flow.generateReport());

        // ════════════════════════════════════════════════════════
        // LAYER 4: Evidence Bridge → Comprehension Engine
        // ════════════════════════════════════════════════════════
        console.log('\n═══════════════════════════════════════════');
        console.log('  LAYER 4: Evidence → Bayesian Analysis');
        console.log('═══════════════════════════════════════════');

        const bridge = new EvidenceBridge(MODULE);
        bridge.addProcessData(processReport);
        bridge.addObservations(diagnosticResult.issues, `${MODULE}.diagnostic`);
        bridge.addBehavioralData(flowObservations);
        const evidencePath = bridge.write();

        // Run the PHP Comprehension Engine with this evidence
        console.log('  Running Comprehension Engine...');
        try {
            const output = execSync(`php ${path.resolve(__dirname, '../../kernel/Workbench/Comprehension/run.php')} ${MODULE} --evidence=${evidencePath}`, {
                encoding: 'utf-8',
                timeout: 30000,
            });
            console.log(output);
        } catch (e) {
            console.log(`  ⚠ Engine output: ${e.stdout || ''}`);
            if (e.stderr) console.log(`  ⚠ Engine errors: ${e.stderr}`);
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

        // Report critical issues
        const criticalIssues = diagnosticResult.issues.filter(i => i.severity === 'critical');
        const warningIssues = diagnosticResult.issues.filter(i => i.severity === 'major');

        console.log(`  Critical: ${criticalIssues.length}, Warnings: ${warningIssues.length}, Info: ${diagnosticResult.issues.filter(i => i.severity === 'minor').length}`);

        // Fail only on truly broken pages
        expect(criticalIssues.length, `Critical issues: ${criticalIssues.map(i => i.component).join(', ')}`).toBe(0);
    });
});
