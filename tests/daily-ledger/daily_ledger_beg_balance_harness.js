'use strict';

/*
 * Behavioural harness: the production sheet's Book Balance
 * (= BEG + Produced − Dispatched − Wastage) moves when BEG is edited, so the
 * SUGGESTED actual count the operator reads (ACTUAL BAL field prefill + tooltip)
 * must move with it by the same delta — WITHOUT ever overwriting an entry the
 * operator made, and without touching a recorded count.
 *
 * This executes the REAL saveProductionBeg() from the DiSyL template in a VM
 * with a minimal DOM stub and reports what the fields hold after each save.
 * (Owner report: editing BEG on the Pagadian sheet did not update the balance.)
 *
 * Usage: node daily_ledger_beg_balance_harness.js <template-path>
 * Output: one JSON object on stdout.
 */

const fs = require('fs');
const vm = require('vm');

const templatePath = process.argv[2];
if (!templatePath || !fs.existsSync(templatePath)) {
    process.stdout.write(JSON.stringify({ error: 'template not found' }) + '\n');
    process.exit(2);
}
const template = fs.readFileSync(templatePath, 'utf8');
const rawBlock = [...template.matchAll(/<script>([\s\S]*?)<\/script>/g)].map(m => m[1])
    .find(b => b.includes('function saveProductionBeg'));
if (!rawBlock) {
    process.stdout.write(JSON.stringify({ error: 'saveProductionBeg not found in template' }) + '\n');
    process.exit(2);
}

// The template carries DiSyL placeholders that the real renderer substitutes
// before the browser sees the script. Substitute the same way so the extracted
// code runs: placeholders inside quotes keep their quotes (we insert only the
// rendered text), the bare branch id becomes its rendered number.
const block = rawBlock
    .replace(/\{base_url\}/g, 'http://localhost')
    .replace(/\{date\}/g, '2026-10-13')
    .replace(/\{shift\}/g, 'AM')
    .replace(/\{day_status\}/g, 'open')
    .replace(/\{if production_reference_only\}1\{else\}0\{\/if\}/g, '0')
    .replace(/\{sheet_source_branch_id\}/g, '21');

const els = {};
function makeEl() {
    const attrs = {};
    return {
        value: '', disabled: false, textContent: '', title: '', placeholder: '',
        classList: { add() {}, remove() {}, toggle() {}, contains() { return false; } },
        setAttribute(k, v) { attrs[k] = String(v); },
        getAttribute(k) { return Object.prototype.hasOwnProperty.call(attrs, k) ? attrs[k] : null; },
        addEventListener() {}, closest() { return null; },
        querySelector() { return null; }, querySelectorAll() { return []; },
        options: [], selectedIndex: -1
    };
}
function el(id) { if (!els[id]) els[id] = makeEl(); return els[id]; }

const pid = '22';
const rowStub = {
    querySelector(sel) {
        if (sel === '.production-variance') return el('variance-' + pid);
        if (sel === '.production-actual-input') return el('production-actual-' + pid);
        return null;
    }
};

const savedPayloads = [];
const toasts = [];
const sandbox = {
    window: {
        dlWriteTimeout(url, opts) {
            const payload = JSON.parse(opts.body);
            savedPayloads.push(payload);
            // The server persists exactly what was saved and reads the row back.
            return Promise.resolve({
                ok: true,
                json: () => Promise.resolve({ ok: true, row: { beg_qty: payload.beg_qty, calc_variance: null } })
            });
        }
    },
    navigator: { onLine: true },
    document: {
        getElementById: (id) =>
            (id === 'production-beg-' + pid || id === 'production-actual-' + pid || id === 'variance-' + pid)
                ? el(id) : null,
        querySelector: (sel) => sel.includes('data-product-id="' + pid + '"') ? rowStub : null,
        querySelectorAll: () => [],
        addEventListener() {}
    },
    localStorage: { getItem: () => null, setItem() {}, removeItem() {} },
    showToast(message) { toasts.push(message); },
    dlNewSubmissionId: () => 'sub-harness',
    Promise, Date, Number, String, parseInt, console
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(block, sandbox, { filename: templatePath });

const save = () => { sandbox.saveProductionBeg(pid); return new Promise(r => setTimeout(r, 40)); };
const snap = () => ({
    beg: el('production-beg-' + pid).value,
    begOriginal: el('production-beg-' + pid).getAttribute('data-original'),
    begRecorded: el('production-beg-' + pid).getAttribute('data-recorded'),
    actualValue: el('production-actual-' + pid).value,
    actualSuggestion: el('production-actual-' + pid).getAttribute('data-suggestion'),
    actualTitle: el('production-actual-' + pid).title
});

(async () => {
    // Scenario 1: uncounted row, field untouched (holds the old suggestion 5).
    // BEG 5 -> 8 must move the suggestion/value/tooltip to 8.
    el('production-beg-' + pid).value = '8';
    el('production-beg-' + pid).setAttribute('data-original', '5');
    el('production-actual-' + pid).value = '5';
    el('production-actual-' + pid).setAttribute('data-suggestion', '5');
    el('production-actual-' + pid).setAttribute('data-original', '');
    await save();
    const untouched = snap();

    // Scenario 2: the operator typed 99 into the field; BEG 8 -> 6.
    // The entry must survive; the suggestion must move 8 -> 6.
    el('production-beg-' + pid).value = '6';
    el('production-actual-' + pid).value = '99';
    await save();
    const operatorEntry = snap();

    // Scenario 3: a recorded count (12); BEG 6 -> 7.
    // The recorded value must survive; the suggestion must move 6 -> 7.
    el('production-beg-' + pid).value = '7';
    el('production-actual-' + pid).value = '12';
    el('production-actual-' + pid).setAttribute('data-original', '12');
    await save();
    const counted = snap();

    process.stdout.write(JSON.stringify({
        savedBegValues: savedPayloads.map(p => p.beg_qty),
        toasts,
        untouched,
        operatorEntry,
        counted
    }) + '\n');
})();
