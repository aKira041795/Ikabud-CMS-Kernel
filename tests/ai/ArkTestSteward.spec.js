/**
 * ARK Test Steward — Unit Tests
 *
 * Validates the deterministic classifier against known failure patterns.
 * Run: node tests/ai/ArkTestSteward.spec.js
 */

// @ts-check
var fs = require('fs');
var path = require('path');
var { execSync } = require('child_process');

var STEWARD = path.resolve(__dirname, 'ArkTestSteward.js');
var FIXTURES = path.resolve(__dirname, 'fixtures');
var passed = 0, failed = 0, errors = [];

function test(name, fn) {
    try {
        fn();
        passed++;
        console.log('  ✅ ' + name);
    } catch (e) {
        failed++;
        errors.push({ name: name, error: e.message });
        console.log('  ❌ ' + name + ': ' + e.message);
    }
}

function assert(condition, msg) {
    if (!condition) throw new Error(msg || 'assertion failed');
}

// ── Run Steward on a fixture ───────────────────────────────────
function runSteward(fixtureName) {
    var fixturePath = path.join(FIXTURES, fixtureName);
    // Temporarily symlink fixture into test_results/browser
    var resultsDir = path.resolve(__dirname, '../../test_results/browser');
    if (!fs.existsSync(resultsDir)) fs.mkdirSync(resultsDir, { recursive: true });

    var manifest = JSON.parse(fs.readFileSync(fixturePath, 'utf-8'));
    // Write manifest and suite files
    var manifestPath = path.join(resultsDir, 'manifest.json');
    var suitePath = path.join(resultsDir, fixtureName.replace('.json', '') + '--chromium.json');

    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2));
    if (manifest.suites) {
        for (var s in manifest.suites) {
            if (!manifest.suites.hasOwnProperty(s)) continue;
            var suiteFile = path.join(resultsDir, s + '--chromium.json');
            fs.writeFileSync(suiteFile, JSON.stringify(manifest.suites[s], null, 2));
        }
    }

    // Run Steward
    var out = execSync('node ' + STEWARD + ' --module=project-audit-ledger --run=latest', {
        encoding: 'utf-8', timeout: 5000, cwd: path.resolve(__dirname, '../../'),
    });

    var diagnosis = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../../test_results/ai/steward-diagnosis.json'), 'utf-8'));
    return diagnosis;
}

// ── Tests ──────────────────────────────────────────────────────

console.log('ARK Test Steward — Unit Tests\n');

test('classifies DB seed failure as environment-issue', function () {
    var d = runSteward('seed-db-failure.json');
    assert(d.classification === 'environment-issue', 'Expected environment-issue, got ' + d.classification);
    assert(d.confidence >= 0.85, 'Expected confidence >= 0.85, got ' + d.confidence);
    assert(d.run_context.failed > 0, 'Expected failed > 0');
});

test('classifies missing entity as application-defect', function () {
    var d = runSteward('missing-entity.json');
    assert(d.classification === 'application-defect', 'Expected application-defect, got ' + d.classification);
});

test('classifies toBeVisible failure as locator-stale', function () {
    var d = runSteward('stale-locator.json');
    assert(d.classification === 'locator-stale', 'Expected locator-stale, got ' + d.classification);
});

test('healthy run has no failures', function () {
    var d = runSteward('successful-run.json');
    assert(d.classification === 'undetermined', 'Expected undetermined for healthy run');
    assert(d.run_context.failed === 0, 'Expected 0 failed');
    assert(d.run_context.passed > 0, 'Expected passed > 0');
});

test('clusters multiple failures as one root diagnosis', function () {
    var d = runSteward('multiple-consequential-failures.json');
    assert(d.evidence.some(function (e) { return e.includes('skipped'); }), 'Expected skip count in evidence');
});

// ── Summary ────────────────────────────────────────────────────

console.log('\n' + (passed + failed) + ' tests: ' + passed + ' passed, ' + failed + ' failed');
if (errors.length > 0) {
    console.log('\nErrors:');
    errors.forEach(function (e) { console.log('  - ' + e.name + ': ' + e.error); });
    process.exit(1);
}
