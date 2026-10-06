// @ts-check
/**
 * CHAIR BASELINE — daily-ledger products/branches admin surfaces, BEFORE the per-branch visibility UI.
 *
 * Purpose right now is to freeze a PRE-CHANGE snapshot with the owner's own credentials so that, when the
 * picker (Slice B) lands, any change in these surfaces is attributable rather than assumed. It records
 * evidence and only asserts things that must be true both before and after.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test \
 *         tests/browser/daily-ledger-branch-product-visibility.spec.js --reporter=line --retries=0
 *
 * Read-only: it only loads pages.
 */
const { test, expect } = require('@playwright/test');

test.setTimeout(180000);

const ADMIN = { username: 'shiela_baina', fullName: 'shiela_baina', password: 'shielab123' };

async function login(page) {
    await page.goto('/daily-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN.username);
    await page.fill('input[name="full_name"]', ADMIN.fullName);
    await page.fill('input[name="password"]', ADMIN.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 30000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForSelector('#wb-sidebar', { timeout: 60000 });
}

// ─────────────────────────────────────────────────────────────────────────────
// Add-product modal DOM contract.
//
// The add modal's JavaScript looks elements up BY id / name / class. A relayout
// that renames or drops one silently breaks CREATING PRODUCTS, so we DERIVE the
// contract from the shipped JS itself (never a hand-copied list) and then check
// it against the rendered markup.
// ─────────────────────────────────────────────────────────────────────────────
const fs = require('fs');
const path = require('path');

const PRODUCTS_TEMPLATE = path.join(__dirname, '../../templates/modules/daily-ledger/admin/products.disyl');

function extractFunctionBody(source, name) {
    const sig = 'function ' + name + '(';
    const start = source.indexOf(sig);
    if (start === -1) { throw new Error('add-modal JS function not found: ' + name); }
    const open = source.indexOf('{', start);
    let depth = 0;
    for (let i = open; i < source.length; i++) {
        const ch = source[i];
        if (ch === '{') { depth++; }
        else if (ch === '}') {
            depth--;
            if (depth === 0) { return source.slice(open, i + 1); }
        }
    }
    throw new Error('unbalanced braces in ' + name);
}

function isWhitespaceChar(code) {
    return code === 0x20 || code === 0x09 || code === 0x0a || code === 0x0d;
}

// Return the first string-literal argument of every `callName(` call in `js`.
function stringArgs(js, callName) {
    const out = [];
    const marker = callName + '(';
    let idx = js.indexOf(marker);
    while (idx !== -1) {
        let i = idx + marker.length;
        while (i < js.length && isWhitespaceChar(js.charCodeAt(i))) { i++; }
        const quote = js[i];
        if (quote === "'" || quote === '"') {
            const end = js.indexOf(quote, i + 1);
            if (end !== -1) { out.push(js.slice(i + 1, end)); }
        }
        idx = js.indexOf(marker, idx + marker.length);
    }
    return out;
}

function isIdentifierStart(ch) { return !!ch && /[A-Za-z_]/.test(ch); }
function isIdentifierChar(ch) { return !!ch && /[A-Za-z0-9_-]/.test(ch); }

function splitWhitespace(text) {
    const out = [];
    let current = '';
    for (let i = 0; i < text.length; i++) {
        if (isWhitespaceChar(text.charCodeAt(i))) {
            if (current) { out.push(current); current = ''; }
        } else {
            current += text[i];
        }
    }
    if (current) { out.push(current); }
    return out;
}

// Derive the id / name / class contract from the add-modal JavaScript itself.
function deriveAddModalContract(source) {
    const js = ['openAddProduct', 'toggleAddBranchPicker', 'submitAddProduct']
        .map((fn) => extractFunctionBody(source, fn))
        .join(';');

    const ids = new Set();
    const names = new Set();
    const classes = new Set();

    stringArgs(js, 'getElementById').forEach((id) => ids.add(id));
    stringArgs(js, 'closeModal').forEach((id) => ids.add(id));
    stringArgs(js, 'getElementsByName').forEach((n) => names.add(n));
    stringArgs(js, 'selectedAssignmentMode').forEach((n) => names.add(n));
    stringArgs(js, 'collectCheckedBranches').forEach((sel) => {
        if (sel.charAt(0) === '.') { classes.add(sel.slice(1)); }
    });

    stringArgs(js, 'querySelector').concat(stringArgs(js, 'querySelectorAll')).forEach((sel) => {
        ['"', "'"].forEach((q) => {
            let i = sel.indexOf('name=' + q);
            while (i !== -1) {
                const end = sel.indexOf(q, i + 6);
                if (end === -1) { break; }
                names.add(sel.slice(i + 6, end));
                i = sel.indexOf('name=' + q, end + 1);
            }
        });
        for (let i = 0; i < sel.length; i++) {
            const ch = sel[i];
            if ((ch === '.' || ch === '#') && isIdentifierStart(sel[i + 1])) {
                let j = i + 1;
                while (j < sel.length && isIdentifierChar(sel[j])) { j++; }
                if (ch === '.') { classes.add(sel.slice(i + 1, j)); } else { ids.add(sel.slice(i + 1, j)); }
            }
        }
    });

    // Delegated overlay/Escape close reads the shared modal-overlay class.
    if (source.indexOf("contains('modal-overlay')") !== -1 || source.indexOf('contains("modal-overlay")') !== -1) {
        classes.add('modal-overlay');
    }

    return { ids: [...ids].sort(), names: [...names].sort(), classes: [...classes].sort() };
}

function addModalContractIssues(addModalMarkup, contract) {
    const issues = [];
    const hasAttr = (attr, value) =>
        addModalMarkup.indexOf(attr + '="' + value + '"') !== -1 ||
        addModalMarkup.indexOf(attr + "='" + value + "'") !== -1;
    contract.ids.forEach((id) => { if (!hasAttr('id', id)) { issues.push('missing id="' + id + '"'); } });
    contract.names.forEach((name) => { if (!hasAttr('name', name)) { issues.push('missing name="' + name + '"'); } });

    const classTokens = new Set();
    const classRe = /class=["']([^"']*)["']/g;
    let cm;
    while ((cm = classRe.exec(addModalMarkup))) {
        splitWhitespace(cm[1]).forEach((token) => classTokens.add(token));
    }
    contract.classes.forEach((cls) => { if (!classTokens.has(cls)) { issues.push('missing class "' + cls + '"'); } });
    return issues;
}

test('baseline: products admin page renders for the owner account', async ({ page }) => {
    await login(page);

    const resp = await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    console.log('products HTTP:', resp && resp.status());

    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const rows = await page.locator('table tbody tr').count();
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');

    console.log('--- PRODUCTS BASELINE ---------------------------------------');
    console.log('tbody rows        :', rows);
    console.log('has branch count  :', /\b\d+\s+branches\b/.test(body));
    console.log('has Add/New button:', /add product|new product/i.test(body));
    console.log('mentions Inactive :', /inactive/i.test(body));

    await page.screenshot({ path: '/tmp/chair-baseline-products.png', fullPage: false });

    // Must hold before AND after the change.
    expect(rows, 'the products page must list products').toBeGreaterThan(0);
});

test('baseline: branches admin page renders and lists the commissary', async ({ page }) => {
    await login(page);

    const resp = await page.goto('/daily-ledger/admin/branches', { waitUntil: 'domcontentloaded' });
    console.log('branches HTTP:', resp && resp.status());
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const rows = await page.locator('table tbody tr').count();
    const body = (await page.locator('body').innerText()).replace(/\s+/g, ' ');
    const commissaryMentioned = /commis/i.test(body);

    console.log('--- BRANCHES BASELINE --------------------------------------');
    console.log('tbody rows        :', rows);
    console.log('mentions commissary:', commissaryMentioned);
    console.log('has branch count  :', /\b\d+\s+products?\b/.test(body));

    await page.screenshot({ path: '/tmp/chair-baseline-branches.png', fullPage: false });

    expect(rows, 'the branches page must list branches').toBeGreaterThan(0);
});

// ─────────────────────────────────────────────────────────────────────────────
// Slice B picker — READ-ONLY. These tests load the tab, exercise the client-side
// search filter, and inspect the deep link. They NEVER click save and never
// change a checkbox against the live tenant.
// ─────────────────────────────────────────────────────────────────────────────
test('picker: products view exposes Products + Show in Branches tabs', async ({ page }) => {
    await login(page);
    const resp = await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    console.log('products (tabs) HTTP:', resp && resp.status());
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    await expect(page.locator('[data-products-tabs]')).toBeVisible();
    await expect(page.locator('[data-tab-products]')).toBeVisible();
    await expect(page.locator('[data-tab-assignment]')).toBeVisible();
    // With no tab parameter the product list is the only table shown.
    await expect(page.locator('#picker-table')).toHaveCount(0);
});

test('picker: assignment tab renders branch selector, search and checklist', async ({ page }) => {
    await login(page);
    const resp = await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    console.log('assignment tab HTTP:', resp && resp.status());
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    await expect(page.locator('[data-tab-assignment]')).toBeVisible();
    const branch = page.locator('[data-picker-branch]');
    await expect(branch).toBeVisible();
    const branchOptions = await branch.locator('option').count();

    await expect(page.locator('[data-picker-search]')).toBeVisible();
    const checks = page.locator('[data-product-check]');
    const total = await checks.count();

    console.log('--- PICKER ASSIGNMENT TAB -----------------------------------');
    console.log('branch options    :', branchOptions);
    console.log('product checkboxes:', total);

    expect(branchOptions, 'the assignment tab must offer at least one branch').toBeGreaterThan(0);
    expect(total, 'the assignment tab must list products with checkboxes').toBeGreaterThan(0);
    await page.screenshot({ path: '/tmp/chair-slice-b-assignment-tab.png', fullPage: false });

    // The branch being edited is always visible on screen.
    await expect(page.locator('[data-picker-branch-name]')).toBeVisible();
});

test('picker: client-side search filters the checklist', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    const total = await page.locator('[data-product-check]').count();
    const firstSku = (await page.locator('#picker-table tbody tr.picker-row').first().locator('.picker-sku').innerText()).trim();
    const token = firstSku;

    await page.fill('[data-picker-search]', token);
    await page.waitForTimeout(200);
    const visible = await page.locator('#picker-table tbody tr.picker-row:not(.is-hidden)').count();

    console.log('--- PICKER SEARCH FILTER -----------------------------------');
    console.log('filter token      :', token);
    console.log('visible / total   :', visible, '/', total);

    expect(visible, 'the filter must keep at least the matching product').toBeGreaterThan(0);
    expect(visible, 'the filter must hide non-matching products').toBeLessThan(total);
    // Read-only: the search box does not save anything.
});

test('picker: switching branches stays on the same assignment tab (read-only)', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#picker-table tbody tr.picker-row', { timeout: 120000 });

    const branch = page.locator('[data-picker-branch]');
    const optionCount = await branch.locator('option').count();
    if (optionCount < 2) {
        test.skip(true, 'need at least two branches to switch');
        return;
    }
    const first = (await page.locator('[data-picker-branch-name]').innerText()).trim();
    const secondValue = await branch.locator('option').nth(1).getAttribute('value');
    await branch.selectOption(secondValue);
    await page.waitForFunction(
        (previous) => {
            const el = document.querySelector('[data-picker-branch-name]');
            return !!el && el.textContent.trim() !== previous;
        },
        first,
        { timeout: 120000 }
    );
    const second = (await page.locator('[data-picker-branch-name]').innerText()).trim();
    console.log('--- PICKER BRANCH SWITCH -----------------------------------');
    console.log('from / to         :', first, '/', second);
    console.log('url               :', page.url());

    expect(second).not.toBe(first);
    expect(page.url()).toContain('tab=assignment');
    expect(page.url()).toContain('branch_id=' + secondValue);
    // Read-only: no checkbox was changed and no save was clicked.
});

test('picker: branches page links to the SAME assignment tab preselecting the branch', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/branches', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    const link = page.locator('a[data-branch-products]').first();
    await expect(link).toBeVisible();
    const href = await link.getAttribute('href');
    console.log('branch products deep link:', href);

    expect(href).toContain('/admin/products?tab=assignment&branch_id=');
});

/**
 * CHAIR VISUAL/TRUTHFULNESS CHECK.
 *
 * Measured invariant (tenant 207): 182 products x 19 branches = 3458 active pairs, 0 hidden. So for branch 8
 * every one of its 182 products is assigned and every checkbox MUST render checked. This asserts the UI
 * reflects REAL assignments rather than merely rendering.
 */
test('picker: checkboxes reflect the real assignments for the branch', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products?tab=assignment&branch_id=8', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('input[data-product-check]', { timeout: 60000 });

    const boxes = page.locator('input[data-product-check]');
    const total = await boxes.count();
    let checked = 0;
    for (let i = 0; i < total; i++) {
        if (await boxes.nth(i).isChecked()) { checked++; }
    }

    // Is the on-screen "ticked" counter correct on FIRST render, before any interaction?
    const counter = (await page.locator('#picker-checked-count').innerText()).trim();

    console.log('--- PICKER TRUTHFULNESS -------------------------------------');
    console.log('checkboxes        :', total);
    console.log('checked           :', checked);
    console.log('counter on load   :', JSON.stringify(counter), '(should equal checked, not 0)');
    console.log('branch selector   :', await page.locator('select').first().inputValue().catch(() => 'n/a'));

    await page.screenshot({ path: '/tmp/chair-assignment-tab.png', fullPage: false });

    // Dump the REAL rendered markup so a design review can work from the shipped artefact, not a description.
    try {
        require('fs').writeFileSync('/tmp/chair-assignment-tab.html', await page.content());
        console.log('rendered HTML     : /tmp/chair-assignment-tab.html');
    } catch (e) {
        console.log('rendered HTML     : could not dump ->', String(e).slice(0, 80));
    }

    expect(total, 'the branch must list its 182 assigned products').toBe(182);
    expect(checked, 'all 182 are assigned to every branch (0 hidden pairs) so all must be checked').toBe(182);
    // The counter must be truthful on first paint; a stale "0" would mislead the admin.
    expect(counter, 'the ticked counter must be correct before any interaction').toBe(String(checked));
});

// ─────────────────────────────────────────────────────────────────────────────
// Add product modal — READ-ONLY. It is only ever OPENED; "Add Product" is never
// clicked because that endpoint writes a REAL product to the live tenant.
// ─────────────────────────────────────────────────────────────────────────────
test('add product modal: DOM contract derived from the JS is present in the rendered markup', async ({ page }) => {
    const contract = deriveAddModalContract(fs.readFileSync(PRODUCTS_TEMPLATE, 'utf8'));
    console.log('--- ADD MODAL JS-DERIVED DOM CONTRACT ----------------------');
    console.log('ids     :', contract.ids.join(', '));
    console.log('names   :', contract.names.join(', '));
    console.log('classes :', contract.classes.join(', '));

    await login(page);
    await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('#add-modal', { state: 'attached', timeout: 60000 });

    const html = await page.content();
    const start = html.indexOf('id="add-modal"');
    const end = html.indexOf('id="edit-modal"');
    expect(start, 'the add modal must render').toBeGreaterThan(-1);
    const addMarkup = html.slice(start, end > start ? end : undefined);

    const issues = addModalContractIssues(addMarkup, contract);
    console.log('contract check :', issues.length === 0 ? 'PASS (all present)' : 'FAIL ' + JSON.stringify(issues));
    expect(issues, 'add-modal JS reads elements that are missing from the rendered add-modal markup').toEqual([]);
});

test('add product modal: horizontal grid — two columns desktop, one mobile (read-only)', async ({ page }) => {
    await login(page);
    await page.goto('/daily-ledger/admin/products', { waitUntil: 'domcontentloaded' });
    await page.waitForSelector('table tbody tr', { timeout: 60000 });

    // Open the modal only. NEVER click "Add Product".
    await page.locator('button:has-text("+ Add")').first().click();
    await expect(page.locator('#add-modal')).toHaveClass(/show/);
    await expect(page.locator('#add-modal .product-modal__grid')).toBeVisible();

    // Focus behaviour preserved: openAddProduct() focuses #add-name.
    expect(await page.evaluate(() => document.activeElement && document.activeElement.id)).toBe('add-name');

    // Same structure as the edit modal: three titled sections in the grid.
    const sectionTitles = await page.locator('#add-modal .product-modal__section-title').evaluateAll((els) => els.map((el) => el.textContent.trim()));
    console.log('add modal sections          :', JSON.stringify(sectionTitles));
    expect(await page.locator('#add-modal .product-modal__section').count()).toBe(3);
    expect(sectionTitles).toContain('Catalog');
    expect(sectionTitles).toContain('Production Profile');
    expect(sectionTitles).toContain('Show in branches');

    // The nine submitted fields submitAddProduct() reads are all present.
    const fields = page.locator('#add-modal input:not([type="radio"]):not([type="checkbox"]), #add-modal select');
    const fieldCount = await fields.count();
    console.log('add modal submitted fields  :', fieldCount);
    expect(fieldCount, 'the add form must still submit exactly its nine fields').toBe(9);

    // Assignment defaults preserved.
    expect(await page.locator('#add-modal input[name="add-assignment-mode"]').count()).toBe(2);
    expect(await page.locator('#add-modal input[name="add-assignment-mode"][value="all_active"]').isChecked()).toBe(true);
    await expect(page.locator('#add-modal #add-branch-picker')).toBeHidden();

    // Branch checklist is present, scrollable, and NOT squeezed into a column.
    const branchCount = await page.locator('#add-modal .add-branch-check').count();
    console.log('add modal branch checkboxes :', branchCount);
    expect(branchCount).toBeGreaterThan(0);
    const overflowY = await page.locator('#add-modal #add-branch-picker').evaluate((el) => getComputedStyle(el).overflowY);
    expect(['auto', 'scroll']).toContain(overflowY);

    await expect.poll(async () => page.locator('#add-modal [data-add-assignment]').evaluate((el) => {
        const s = getComputedStyle(el);
        return s.gridColumnStart + '/' + s.gridColumnEnd;
    }), { message: 'the Show in branches section must span the full grid width', timeout: 10000 }).toBe('1/-1');

    const gridBox = await page.locator('#add-modal .product-modal__grid').boundingBox();
    const assignBox = await page.locator('#add-modal [data-add-assignment]').boundingBox();
    console.log('grid width / assignment width:', Math.round(gridBox.width), '/', Math.round(assignBox.width));
    expect(assignBox.width).toBeGreaterThan(gridBox.width * 0.95);

    // The inline onchange handler still reveals the picker.
    await page.locator('#add-modal input[name="add-assignment-mode"][value="specific"]').check();
    await expect(page.locator('#add-modal #add-branch-picker')).toBeVisible();
    await page.locator('#add-modal input[name="add-assignment-mode"][value="all_active"]').check();
    await expect(page.locator('#add-modal #add-branch-picker')).toBeHidden();

    const columnsAtViewport = async (width, height) => {
        await page.setViewportSize({ width, height });
        return page.locator('#add-modal .product-modal__grid').evaluate((el) =>
            getComputedStyle(el).gridTemplateColumns.trim().split(' ').filter(Boolean).length
        );
    };
    const desktopColumns = await columnsAtViewport(1280, 900);
    const mobileColumns = await columnsAtViewport(375, 800);
    console.log('grid columns desktop/mobile :', desktopColumns, '/', mobileColumns);
    expect(desktopColumns, 'desktop must lay the add modal out horizontally (2 columns)').toBe(2);
    expect(mobileColumns, 'the existing breakpoint must collapse the grid to 1 column').toBe(1);

    await page.screenshot({ path: '/tmp/add-product-modal-horizontal.png', fullPage: false });
});
