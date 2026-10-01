'use strict';

/*
 * Behavioural harness for the receive-stock modal's auto-DR offline guard.
 *
 * The old guard test was `strpos($tpl, 'autoDrBlockedOffline') !== false`. A
 * string check cannot tell whether the behaviour is still there, so the guard
 * was deleted and the test stayed green for months. This harness evaluates the
 * REAL receiveModal() script from the DiSyL template and reports what
 * submitPaperDelivery() actually does, so the guard can be asserted on
 * behaviour instead.
 *
 * Usage: node receive_modal_offline_guard_harness.js <template> <mode>
 * Modes:
 *   auto-dr-offline           offline + auto-DR   -> must refuse, never write
 *   auto-dr-online            online  + auto-DR   -> may write (server mints DR)
 *   paper-offline             offline + paper DR  -> may attempt; still never queues
 *   auto-dr-server-fail-online  server 5xx + auto-DR -> reported, never queued
 *   auto-dr-netfail-offline   transport drop + auto-DR -> refused by the guard
 *
 * stdout is one JSON object:
 *   { mode, errorMsg, writeCalled, enqueueCalled, paperSaving, blocked }
 */

const fs = require('fs');
const vm = require('vm');

const tplPath = process.argv[2];
const mode = process.argv[3] || 'auto-dr-offline';

if (!tplPath || !fs.existsSync(tplPath)) {
    process.stdout.write(JSON.stringify({ error: 'template not found', tplPath: tplPath }) + '\n');
    process.exit(2);
}

const tpl = fs.readFileSync(tplPath, 'utf8');
const match = tpl.match(/<script>([\s\S]*?)<\/script>/);
if (!match) {
    process.stdout.write(JSON.stringify({ error: 'no <script> block found' }) + '\n');
    process.exit(2);
}

const calls = { write: 0, enqueue: 0 };

function writeResult() {
    if (mode === 'auto-dr-server-fail-online') {
        // Resolved HTTP response carrying a retryable server failure.
        return Promise.resolve({ json: function () { return Promise.resolve({ ok: false, error: 'temporary error' }); } });
    }
    if (mode === 'auto-dr-netfail-offline') {
        const err = new Error('network down');
        return Promise.reject(err);
    }
    // Healthy write.
    return Promise.resolve({ json: function () { return Promise.resolve({ ok: true }); } });
}

const windowStub = {
    BRANCH_ID: '1',
    SHIFT: 'AM',
    DL_CSRF: 'csrf',
    DL_TOKEN: 'token',
    generateOperationId: function () { return 'op-test-1'; },
    shouldQueueServerFailure: function () { return mode === 'auto-dr-server-fail-online'; },
    dlWriteTimeout: function () { calls.write++; return writeResult(); },
    dlWriteTimeoutMessage: function (label) { return 'timeout: ' + label; },
    refreshMainContent: function () {},
    enqueueOperation: function () { calls.enqueue++; return {}; },
    showToast: function () {},
};

const sandbox = {
    window: windowStub,
    navigator: { onLine: mode !== 'auto-dr-offline' && mode !== 'auto-dr-netfail-offline' },
    document: {
        getElementById: function () { return null; },
        querySelectorAll: function () { return []; },
    },
    console: console,
    Promise: Promise,
    Date: Date,
    parseInt: parseInt,
    Number: Number,
    String: String,
    BASE: 'http://localhost',
    LEDGER_DATE: '2026-10-01',
    showToast: function () {},
};
sandbox.globalThis = sandbox;

vm.createContext(sandbox);
vm.runInContext(match[1], sandbox, { filename: tplPath });

if (typeof sandbox.receiveModal !== 'function') {
    process.stdout.write(JSON.stringify({ error: 'receiveModal() not defined by template' }) + '\n');
    process.exit(2);
}

const modal = sandbox.receiveModal();
modal.paperForm.origin_type = 'commissary';
modal.paperForm.delivery_date = '2026-10-01';
modal.paperForm.receive_date = '2026-10-01';
modal.paperForm.auto_dr = !mode.startsWith('paper');
modal.paperForm.dr_number = mode.startsWith('paper') ? 'DR-TEST-1' : '';
modal.paperForm.items = [{ product_id: '5', quantity: '3' }];

modal.submitPaperDelivery();

setTimeout(function () {
    process.stdout.write(JSON.stringify({
        mode: mode,
        errorMsg: modal.errorMsg,
        writeCalled: calls.write > 0,
        enqueueCalled: calls.enqueue > 0,
        paperSaving: modal.paperSaving,
        blocked: calls.write === 0 && modal.errorMsg !== ''
            && modal.errorMsg.indexOf('requires connectivity') !== -1,
    }) + '\n');
}, 40);
