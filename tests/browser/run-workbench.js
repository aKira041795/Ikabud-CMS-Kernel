#!/usr/bin/env node

/**
 * Workbench Hybrid Analysis Launcher.
 *
 * Sets WB_RUN_ID in the parent environment BEFORE Playwright starts,
 * guaranteeing that reporters, workers, EvidenceBridge, and the PHP
 * Comprehension Engine all share the SAME run ID.
 *
 * The run ID is a timestamp-prefixed, collision-resistant key:
 *   YYYYMMDDHHMMSS-<random8>
 *
 * Usage:
 *   node tests/browser/run-workbench.js --module=project-audit-ledger
 *   node tests/browser/run-workbench.js --module=guidance [--gate=major]
 *
 * All remaining args are forwarded to npx playwright test.
 *
 * Environment:
 *   MODULE        - Module directory name (required via --module or env)
 *   HYBRID_GATE   - Gate severity: off | critical | major (default: critical)
 *   ADMIN_USER    - Login username
 *   ADMIN_PASS    - Login password
 */

const { spawnSync } = require('child_process');
const path = require('path');
const crypto = require('crypto');

// ── Parse args ────────────────────────────────────────────────
const args = process.argv.slice(2);
let moduleId = process.env.MODULE || '';
let gate = process.env.HYBRID_GATE || 'critical';
let scenarioRef = process.env.WB_SCENARIO || '';
const passthrough = [];

for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--module' && i + 1 < args.length) {
        moduleId = args[++i];
    } else if (arg.startsWith('--module=')) {
        moduleId = arg.slice(9);
    } else if (arg === '--gate' && i + 1 < args.length) {
        gate = args[++i];
    } else if (arg.startsWith('--gate=')) {
        gate = arg.slice(7);
    } else if (arg === '--scenario' && i + 1 < args.length) {
        scenarioRef = args[++i];
    } else if (arg.startsWith('--scenario=')) {
        scenarioRef = arg.slice(11);
    } else {
        passthrough.push(arg);
    }
}

if (!moduleId) {
    console.error('Usage: node tests/browser/run-workbench.js --module=<module-id> [--gate=critical|major|off]');
    console.error('  or:  node tests/browser/run-workbench.js --module <module-id> --gate critical');
    process.exit(1);
}

// ── Generate canonical run ID ─────────────────────────────────
const stamp = new Date()
    .toISOString()
    .replace(/\D/g, '')
    .slice(0, 14);
const runId = `${stamp}-${crypto.randomUUID().slice(0, 8)}`;

// Set in parent env — Playwright workers AND reporter inherit this
process.env.WB_RUN_ID = runId;
process.env.MODULE = moduleId;
process.env.HYBRID_GATE = gate;

if (scenarioRef) {
    const scenarioRunner = path.resolve(__dirname, '../../kernel/Workbench/Scenario/run.php');
    const resolved = spawnSync('php', [scenarioRunner, 'resolve', `--module=${moduleId}`, `--scenario=${scenarioRef}`], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (resolved.status !== 0) {
        console.error(`Unable to resolve scenario '${scenarioRef}': ${resolved.stderr || 'unknown error'}`);
        process.exit(2);
    }
    const scenarioDir = path.resolve(__dirname, '../../test_results/scenarios', runId);
    require('fs').mkdirSync(scenarioDir, { recursive: true });
    const scenarioFile = path.join(scenarioDir, 'scenario.json');
    require('fs').writeFileSync(scenarioFile, resolved.stdout);
    process.env.WB_SCENARIO_FILE = scenarioFile;
    const prepared = spawnSync('php', [scenarioRunner, 'prepare', `--module=${moduleId}`, `--scenario=${scenarioRef}`, `--run-id=${runId}`], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (prepared.status !== 0 || !JSON.parse(prepared.stdout || '{}').ok) {
        console.error(`Scenario data preparation failed: ${prepared.stderr || prepared.stdout || 'unknown error'}`);
        process.exit(3);
    }
    process.env.WB_SCENARIO_RUN_FILE = path.join(scenarioDir, 'scenario-run.json');
}

console.log('');
console.log('═══════════════════════════════════════════');
console.log('  ARK Workbench — Hybrid Analysis');
console.log('═══════════════════════════════════════════');
console.log(`  Module:    ${moduleId}`);
console.log(`  Gate:      ${gate}`);
console.log(`  Run ID:    ${runId}`);
if (scenarioRef) console.log(`  Scenario:  ${scenarioRef}`);
console.log('───────────────────────────────────────────');
console.log('');

// ── Build Playwright command ──────────────────────────────────
const spec = path.resolve(__dirname, 'hybrid-analysis.spec.js');
const pwArgs = [
    'playwright',
    'test',
    spec,
    ...passthrough,
];

const result = spawnSync('npx', pwArgs, {
    cwd: path.resolve(__dirname, '../..'),
    stdio: 'inherit',
    env: process.env,
});

if (scenarioRef) {
    const scenarioRunner = path.resolve(__dirname, '../../kernel/Workbench/Scenario/run.php');
    const finalized = spawnSync('php', [scenarioRunner, 'finalize', `--run-id=${runId}`], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
    if (finalized.status !== 0 || !JSON.parse(finalized.stdout || '{}').ok) {
        console.error(`Scenario cleanup failed: ${finalized.stderr || finalized.stdout || 'unknown error'}`);
        process.exit(4);
    }
}

process.exit(result.status ?? 1);
