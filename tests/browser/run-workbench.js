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

console.log('');
console.log('═══════════════════════════════════════════');
console.log('  ARK Workbench — Hybrid Analysis');
console.log('═══════════════════════════════════════════');
console.log(`  Module:    ${moduleId}`);
console.log(`  Gate:      ${gate}`);
console.log(`  Run ID:    ${runId}`);
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

process.exit(result.status ?? 1);
