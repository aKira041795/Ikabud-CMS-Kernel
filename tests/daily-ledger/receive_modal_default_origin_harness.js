'use strict';

/*
 * Behavioural harness for the receive-stock modal's paper-DR origin default.
 *
 * Background: routine receives submitted an empty origin_id, and originless
 * movements cannot be credited automatically (the ledger refuses to guess the
 * origin), so every receive without a hand-picked source parked as an
 * "origin unresolved" worklist item. The fix preselects the branch's assigned
 * supply line (server-built map, window.DL_DEFAULT_SOURCE_MAP) while keeping
 * blank expressible for genuinely external goods.
 *
 * This harness evaluates the REAL receiveModal() script from the DiSyL template
 * and reports what the form actually does:
 *
 *   - resetPaperForm() starts with the mapped source (not blank);
 *   - switching to branch clears the origin (a specific sender is chosen);
 *   - switching back to commissary restores the supply-line default;
 *   - switching type clears auto_dr (source changed, claim must be re-chosen);
 *   - a branch with no mapped source stays blank — no invented origin;
 *   - the submitted payload actually carries the defaulted origin;
 *   - an operator-cleared origin still submits as null (blank stays valid).
 *
 * Usage: node receive_modal_default_origin_harness.js <template-path>
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
const match = template.match(/<script>([\s\S]*?)<\/script>/);
if (!match) {
    process.stdout.write(JSON.stringify({ error: 'script not found' }) + '\n');
    process.exit(2);
}

const payloads = [];
const windowStub = {
    BRANCH_ID: '99572',
    SHIFT: 'AM',
    SHIFT_LOCKED: true,
    DL_CSRF: 'csrf',
    DL_TOKEN: 'token',
    // Server-built supply-line map (see dl_defaultSourceCommissaryMap):
    // branch 99572 is fed by commissary 99599.
    DL_DEFAULT_SOURCE_MAP: { '99572': 99599 },
    dlWriteTimeout: function (_url, options) {
        payloads.push(JSON.parse(options.body));
        return Promise.resolve({ json: function () { return Promise.resolve({ ok: true }); } });
    },
    refreshMainContent: function () {},
};
const sandbox = {
    window: windowStub,
    navigator: { onLine: true },
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
    LEDGER_DATE: '2031-05-17',
    showToast: function () {},
    fetch: function () {
        return Promise.resolve({ json: function () { return Promise.resolve({ ok: true }); } });
    },
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(match[1], sandbox, { filename: templatePath });

const makeSubmittable = function (component) {
    component.receivingShift = 'AM';
    component.paperForm.production_shift = 'AM';
    component.paperForm.dr_number = 'PAPER-1';
    component.paperForm.items = [{ product_id: '501', quantity: '3' }];
};

// 1) A mapped branch defaults to its supply line.
const mapped = sandbox.receiveModal();
mapped.resetPaperForm();
const defaultAfterReset = mapped.paperForm.origin_id;

// 2) Type switching: branch clears, commissary restores, auto_dr resets.
mapped.paperForm.auto_dr = true;
mapped.paperForm.origin_type = 'branch';
mapped.onOriginTypeChange();
const afterSwitchToBranch = mapped.paperForm.origin_id;
const autoDrClearedBySwitch = mapped.paperForm.auto_dr === false;
mapped.paperForm.origin_type = 'commissary';
mapped.onOriginTypeChange();
const afterSwitchBackToCommissary = mapped.paperForm.origin_id;

// 3) The submitted payload carries the defaulted origin.
makeSubmittable(mapped);
mapped.submitPaperDelivery();

// 4) A cleared origin still submits as null (blank remains expressible).
const cleared = sandbox.receiveModal();
cleared.resetPaperForm();
cleared.paperForm.origin_id = '';
makeSubmittable(cleared);
cleared.submitPaperDelivery();

// 5) A branch with no mapped supply line stays blank — nothing is invented.
windowStub.DL_DEFAULT_SOURCE_MAP = {};
const unmapped = sandbox.receiveModal();
unmapped.resetPaperForm();
const noMapDefault = unmapped.paperForm.origin_id;

setTimeout(function () {
    process.stdout.write(JSON.stringify({
        defaultAfterReset: defaultAfterReset,
        afterSwitchToBranch: afterSwitchToBranch,
        afterSwitchBackToCommissary: afterSwitchBackToCommissary,
        autoDrClearedBySwitch: autoDrClearedBySwitch,
        noMapDefault: noMapDefault,
        defaultedPayload: payloads[0] || null,
        clearedPayload: payloads[1] || null,
    }) + '\n');
}, 30);
