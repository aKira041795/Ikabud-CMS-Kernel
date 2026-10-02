'use strict';

// Executes the real receiveModal() script and captures its outgoing request.
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
    dlWriteTimeout: function (_url, options) {
        payloads.push(JSON.parse(options.body));
        return Promise.resolve({ json: function () { return Promise.resolve({ ok: true, received_count: 1 }); } });
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
};
sandbox.globalThis = sandbox;
vm.createContext(sandbox);
vm.runInContext(match[1], sandbox, { filename: templatePath });

const group = {
    group_key: 'delivery:995701',
    ids: [],
    delivery_ids: [995701],
    production_shift: '',
    items: [
        { id: 101, product_id: 501, quantity: 10 },
        { id: 102, product_id: 502, quantity: 4 },
    ],
};

const untouched = sandbox.receiveModal();
const initialValues = group.items.map(function (item) { return untouched.receivedQty(group, item); });
const initiallyEnabled = untouched.canAcceptGroup(group) && !untouched.hasInvalidCorrection(group) && !untouched.busyKey;
group.production_shift = 'PM';
const enabledWithBoth = untouched.canAcceptGroup(group) && !untouched.hasInvalidCorrection(group);
untouched.acceptGroup(group);

const missingReceiving = sandbox.receiveModal();
missingReceiving.receivingShift = '';
const enabledWithoutReceiving = missingReceiving.canAcceptGroup(group);

const corrected = sandbox.receiveModal();
corrected.setReceivedQty(group, group.items[0], '8');
corrected.acceptGroup(group);

const restored = sandbox.receiveModal();
restored.setReceivedQty(group, group.items[0], '8');
restored.setReceivedQty(group, group.items[0], '10');
restored.acceptGroup(group);

const over = sandbox.receiveModal();
over.setReceivedQty(group, group.items[0], '13');
over.acceptGroup(group);

setTimeout(function () {
    process.stdout.write(JSON.stringify({
        initialValues: initialValues,
        initiallyEnabled: initiallyEnabled,
        enabledWithoutReceiving: enabledWithoutReceiving,
        enabledWithBoth: enabledWithBoth,
        boundReceivingLocked: template.includes('<template x-if="window.SHIFT_LOCKED">')
            && template.includes('Locked to <span class="mx-1" x-text="receivingShift"></span> Shift'),
        editableReceivingOnlyWhenUnlocked: template.includes('<template x-if="!window.SHIFT_LOCKED">')
            && template.includes('<select x-model="receivingShift"'),
        untouchedPayload: payloads[0] || null,
        correctedPayload: payloads[1] || null,
        restoredPayload: payloads[2] || null,
        overPayload: payloads[3] || null,
    }) + '\n');
}, 30);
