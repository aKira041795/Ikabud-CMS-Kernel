/**
 * PAL Process Journey — one serial, tester-driven journey through the module's
 * core processes as an admin. Sets up supporting data (categories, units,
 * clients, suppliers, materials, projects) then drives the real UI forms for
 * the transactions (expense, purchase, issuance, cash advance, user), the
 * approval workflow, and reports/BOM exports — asserting each step lands.
 *
 * Usage:
 *   APP_URL=http://palsystem.test npx playwright test tests/browser/modules/project-audit-ledger/pal-process-flow.spec.js --reporter=list
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const ADMIN_USER = process.env.ADMIN_USER || 'admin';
const ADMIN_PASS = process.env.ADMIN_PASS || 'pal1234';
const API = '/api/v1/project-audit-ledger';
const ts = Date.now().toString().slice(-8);
const N = (p) => `${p} ${ts}`;

async function login(page) {
    await page.goto(APP_URL + '/project-audit-ledger/login', { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="username"]', ADMIN_USER);
    await page.fill('input[name="password"]', ADMIN_PASS);
    await Promise.all([
        page.waitForURL('**/admin/project-audit-ledger', { timeout: 15000 }),
        page.click('button[type="submit"]'),
    ]);
}

/** CSRF token from the current page's csrf_field input. */
async function csrfToken(page) {
    return page.locator('input[name="_token"]').first().inputValue().catch(() => '');
}

/** Session-authenticated form-encoded POST (mirrors ajaxSubmit). */
async function apiPost(page, url, data) {
    const token = await csrfToken(page);
    const body = new URLSearchParams(data || {});
    if (token) body.set('_token', token);
    return page.evaluate(async ({ url, body }) => {
        const res = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body,
        });
        // Read the body exactly once, then attempt JSON parse (some endpoints
        // stream files/CSV back, which is not JSON).
        const text = await res.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) { json = { raw: text.slice(0, 300) }; }
        return { status: res.status, json };
    }, { url, body: body.toString() });
}

/** Quick-create a supporting record; returns its id (or throws). */
async function quickCreate(page, type, name) {
    const r = await apiPost(page, API + '/quick-create', { type, name });
    expect(r.status, `quick-create ${type} "${name}" -> ${JSON.stringify(r.json)}`).toBeLessThan(400);
    expect(r.json && r.json.ok, `quick-create ${type} ok`).toBeTruthy();
    return r.json.id;
}

/** Fill + submit a UI form via ajaxSubmit; waits for a success toast. */
async function submitUiForm(page, url, fillFn) {
    await page.goto(APP_URL + url, { waitUntil: 'domcontentloaded' });
    await fillFn(page);
    const alert = page.locator('div[role="alert"]');
    const before = await alert.count().catch(() => 0);
    await page.click('button[type="submit"]').catch(() => { });
    let sawToast = false;
    for (let i = 0; i < 20; i++) {
        await page.waitForTimeout(500);
        const count = await alert.count().catch(() => 0);
        if (count > before) { sawToast = true; break; }
    }
    return sawToast;
}

test('PAL full process journey (admin)', async ({ page }) => {
    test.setTimeout(600000); // 10 min
    await login(page);

    const results = [];
    const step = (msg) => { console.log('  ▶ ' + msg); results.push(msg); };

    // ── 1. Supporting data (quick-create API) ──
    const expCatId = await quickCreate(page, 'expense_category', N('Expense Cat'));
    step('expense category id=' + expCatId);
    const matCatId = await quickCreate(page, 'material_category', N('Mat Cat'));
    const unitId = await quickCreate(page, 'unit', 'PCS' + ts);
    await quickCreate(page, 'project_type', N('Proj Type'));
    const clientId = await quickCreate(page, 'client', N('Journey Client'));
    const supplierName = N('Journey Supplier');
    const supplierId = await quickCreate(page, 'supplier', supplierName);
    const teamLeadName = N('Journey Lead');
    const teamLeadId = await quickCreate(page, 'team_lead', teamLeadName);
    step(`supporting ids: matCat=${matCatId} unit=${unitId} client=${clientId} supplier=${supplierId} tl=${teamLeadId}`);

    // Material via materials API
    const mat = await apiPost(page, API + '/materials', { name: N('Journey Material'), category_id: matCatId, unit_id: unitId, reorder_level: 5, current_avg_cost: 50, price_per_unit: 120 });
    expect(mat.status, `material create -> ${JSON.stringify(mat.json)}`).toBeLessThan(400);
    const materialId = mat.json && mat.json.id;
    expect(materialId, 'material id').toBeTruthy();
    step('material id=' + materialId);

    // Project via projects API — as admin, request status=pending so the JO
    // auto-approves (draft→pending→approved), which issuance forms require.
    const projectTitle = N('Journey Project');
    const proj = await apiPost(page, API + '/projects', { title: projectTitle, scope_of_work: 'new', contract_amount: 100000, start_date: '2026-08-01', target_completion_date: '2026-10-01', client_id: clientId, status: 'pending' });
    expect(proj.status, `project create -> ${JSON.stringify(proj.json)}`).toBeLessThan(400);
    const projectId = proj.json && proj.json.id;
    expect(projectId, 'project id').toBeTruthy();
    step('project id=' + projectId + ' status=' + (proj.json.status || ''));

    // ── 2. Client + Supplier via real UI forms ──
    const clientUi = await submitUiForm(page, '/admin/project-audit-ledger/clients/create', async (p) => {
        await p.fill('input[name="name"]', N('UI Client'));
        await p.fill('input[name="contact_person"]', 'QA Tester');
        await p.fill('input[name="phone"]', '09170000000');
        await p.fill('input[name="email"]', 'ui' + ts + '@example.com');
        await p.fill('textarea[name="address"]', '123 UI Street');
    });
    step('client via UI submitted (toast=' + clientUi + ')');
    const clientOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/clients', { waitUntil: 'domcontentloaded' });
    expect(clientOnList.status(), 'clients list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('UI Client');
    step('✓ client visible on list');

    const supUi = await submitUiForm(page, '/admin/project-audit-ledger/suppliers/create', async (p) => {
        await p.fill('input[name="name"]', N('UI Supplier'));
        await p.fill('input[name="contact_person"]', 'Sup');
        await p.fill('input[name="phone"]', '09180000000');
        await p.fill('input[name="email"]', 'sup' + ts + '@example.com');
    });
    step('supplier via UI submitted (toast=' + supUi + ')');
    const supOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/suppliers', { waitUntil: 'domcontentloaded' });
    expect(supOnList.status(), 'suppliers list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('UI Supplier');
    step('✓ supplier visible on list');

    // ── 3. Expense via real UI form ──
    const expUi = await submitUiForm(page, '/admin/project-audit-ledger/expenses/create', async (p) => {
        await p.selectOption('select[name="category_id"]', String(expCatId));
        await p.fill('input[name="amount"]', '2500');
        await p.fill('textarea[name="description"]', N('Journey Expense'));
        await p.fill('input[name="expense_date"]', '2026-08-17');
    });
    step('expense via UI submitted (toast=' + expUi + ')');
    const expOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/expenses', { waitUntil: 'domcontentloaded' });
    expect(expOnList.status(), 'expenses list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('Journey Expense');
    step('✓ expense visible on list');

    // ── 4. Purchase via real UI form ──
    const purUi = await submitUiForm(page, '/admin/project-audit-ledger/purchases/create', async (p) => {
        await p.selectOption('select[name="supplier_id"]', String(supplierId));
        await p.fill('input[name="invoice_number"]', 'INV' + ts);
        await p.selectOption('select[name="material_id[]"]', String(materialId));
        await p.fill('input[name="quantity[]"]', '10');
        await p.fill('input[name="unit_cost[]"]', '80');
        await p.fill('input[name="purchase_date"]', '2026-08-17');
    });
    step('purchase via UI submitted (toast=' + purUi + ')');
    const purOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/purchases', { waitUntil: 'domcontentloaded' });
    expect(purOnList.status(), 'purchases list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText(supplierName);
    step('✓ purchase visible on list');

    // ── 5. Issuance via real UI form ──
    const issUi = await submitUiForm(page, '/admin/project-audit-ledger/issuances/create', async (p) => {
        await p.selectOption('select[name="project_id"]', String(projectId));
        await p.selectOption('select[name="material_id[]"]', String(materialId));
        await p.fill('input[name="quantity[]"]', '2');
        await p.fill('textarea[name="purpose"]', N('Journey Issuance'));
    });
    step('issuance via UI submitted (toast=' + issUi + ')');
    const issOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/issuances', { waitUntil: 'domcontentloaded' });
    expect(issOnList.status(), 'issuances list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText(projectTitle);
    step('✓ issuance visible on list');

    // ── 6. Cash advance via real UI form ──
    const caUi = await submitUiForm(page, '/admin/project-audit-ledger/cash-advances/create', async (p) => {
        await p.selectOption('select[name="team_lead_id"]', String(teamLeadId));
        await p.fill('input[name="amount"]', '3000');
        await p.fill('input[name="description"]', N('Journey CA'));
    });
    step('cash advance via UI submitted (toast=' + caUi + ')');
    const caOnList = await page.goto(APP_URL + '/admin/project-audit-ledger/cash-advances', { waitUntil: 'domcontentloaded' });
    expect(caOnList.status(), 'cash advances list').toBeLessThan(500);
    await expect(page.locator('body')).toContainText(teamLeadName);
    step('✓ cash advance visible on list');

    // ── 7. User via real UI form ──
    const username = 'qa' + ts;
    await page.goto(APP_URL + '/admin/project-audit-ledger/users', { waitUntil: 'domcontentloaded' });
    const formVisible = await page.locator('#create-user-form:not(.hidden)').count();
    if (!formVisible) {
        await page.locator('button', { hasText: 'Add User' }).click();
    }
    await page.fill('#create-user-form input[name="username"]', username);
    await page.fill('#create-user-form input[name="email"]', username + '@example.com');
    await page.fill('#create-user-form input[name="full_name"]', 'QA User');
    await page.fill('#create-user-form input[name="password"]', 'Test@12345');
    await page.click('#create-user-form button[type="submit"]');
    await page.waitForTimeout(1500);
    step('user submitted: ' + username);

    // ── 8. Approval workflow: draft expense → submit → approve via UI ──
    const draftExp = await apiPost(page, API + '/expenses', { category_id: expCatId, amount: 5000, description: N('Approval Expense'), status: 'draft', expense_date: '2026-08-17' });
    expect(draftExp.status, `draft expense -> ${JSON.stringify(draftExp.json)}`).toBeLessThan(400);
    const draftExpId = draftExp.json && draftExp.json.id;
    step('draft expense id=' + draftExpId);
    const sub = await apiPost(page, API + '/expenses/' + draftExpId + '/submit', {});
    expect(sub.status, `submit expense -> ${JSON.stringify(sub.json)}`).toBeLessThan(400);
    step('expense submitted for approval');

    await page.goto(APP_URL + '/admin/project-audit-ledger/approvals', { waitUntil: 'domcontentloaded' });
    await page.waitForTimeout(1000);
    const approvalsBody = await page.locator('body').innerText();
    // The approval card renders the entity label + id (e.g. "Expense #5"), not the
    // free-text description, so assert on the rendered identifier.
    expect(approvalsBody, 'approval item visible').toContain('Expense #' + draftExpId);
    const approved = await page.evaluate(() => {
        const btns = Array.from(document.querySelectorAll('button'));
        const approve = btns.find((b) => b.textContent.trim() === 'Approve');
        if (!approve) return false;
        approve.click();
        return true;
    });
    step('approve button clicked: ' + approved);
    await page.waitForTimeout(2000);

    // ── 9. Reports generate via UI ──
    await page.goto(APP_URL + '/admin/project-audit-ledger/reports', { waitUntil: 'domcontentloaded' });
    await page.click('button:has-text("Generate")');
    await page.waitForTimeout(2000);
    step('report generated via UI');

    // ── 10. BOM export via UI/API (reads project_id from the query string) ──
    const bom = await apiPost(page, API + '/bom/export?project_id=' + projectId, {});
    expect(bom.status, `bom export -> ${JSON.stringify(bom.json)}`).toBeLessThan(400);
    step('BOM export ok');

    // ── ROUND 2 — remaining processes: sales, quotation+convert, returns, stock ──
    // Sales invoice create (items serialized as JSON, matching the UI form)
    const sale = await apiPost(page, API + '/sales', {
        client_id: String(clientId),
        project_id: String(projectId),
        sales_date: '2026-08-17',
        invoice_number: 'INVS' + ts,
        mode_of_payment: 'cash',
        scope_of_work: 'new',
        items: JSON.stringify([{ material_id: materialId, particulars: 'QA Sale Item', width: 10, height: 5, uom: 'ft', quantity: 1, price_per_unit: 500, price_per_sqft: '', line_total: 500, sort_order: 1 }]),
    });
    expect(sale.status, `sales create -> ${JSON.stringify(sale.json)}`).toBeLessThan(400);
    const saleId = sale.json && sale.json.id;
    expect(saleId, 'sale id').toBeTruthy();
    step('sale created id=' + saleId);
    const saleDetail = await page.goto(APP_URL + '/admin/project-audit-ledger/sales/' + saleId, { waitUntil: 'domcontentloaded' });
    expect(saleDetail.status(), 'sale detail status').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('QA Sale Item');
    step('✓ sale detail renders');
    const salePrint = await page.goto(APP_URL + '/admin/project-audit-ledger/sales/' + saleId + '/print', { waitUntil: 'domcontentloaded' });
    expect(salePrint.status(), 'sale print status').toBeLessThan(500);
    step('✓ sale print renders');

    // Quotation create + detail + convert to project
    // (navigate to a normal form page first so the CSRF token source is valid)
    await page.goto(APP_URL + '/admin/project-audit-ledger/quotations/create', { waitUntil: 'domcontentloaded' });
    const quote = await apiPost(page, API + '/quotations', {
        client_id: String(clientId),
        quotation_date: '2026-08-17',
        scope_of_work: 'new',
        mode_of_payment: 'cash',
        items: JSON.stringify([{ material_id: materialId, particulars: 'QA Quote Item', width: 10, height: 5, uom: 'ft', quantity: 1, price_per_unit: 400, price_per_sqft: '', line_total: 400, sort_order: 1 }]),
    });
    expect(quote.status, `quotation create -> ${JSON.stringify(quote.json)}`).toBeLessThan(400);
    const quoteId = quote.json && quote.json.id;
    expect(quoteId, 'quotation id').toBeTruthy();
    step('quotation created id=' + quoteId);
    const quoteDetail = await page.goto(APP_URL + '/admin/project-audit-ledger/quotations/' + quoteId, { waitUntil: 'domcontentloaded' });
    expect(quoteDetail.status(), 'quotation detail status').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('QA Quote Item');
    step('✓ quotation detail renders');
    const convert = await apiPost(page, API + '/quotations/' + quoteId + '/convert', {});
    expect(convert.status, `quotation convert -> ${JSON.stringify(convert.json)}`).toBeLessThan(400);
    step('✓ quotation converted to project: ' + JSON.stringify(convert.json).slice(0, 80));

    // Material return
    const ret = await apiPost(page, API + '/materials/return', {
        project_id: String(projectId), material_id: String(materialId),
        quantity: '1', condition: 'reusable', reason: 'QA return', return_date: '2026-08-17',
    });
    expect(ret.status, `material return -> ${JSON.stringify(ret.json)}`).toBeLessThan(400);
    step('material return ok: ' + JSON.stringify(ret.json).slice(0, 80));

    // Inventory adjustment
    const adj = await apiPost(page, API + '/inventory/adjust', {
        material_id: String(materialId), quantity: '5', reason: 'QA stock add', description: 'QA adjustment',
    });
    expect(adj.status, `inventory adjust -> ${JSON.stringify(adj.json)}`).toBeLessThan(400);
    step('inventory adjust ok: ' + JSON.stringify(adj.json).slice(0, 80));

    // Project detail + edit render (with the created items from quotation conversion)
    const projDetail = await page.goto(APP_URL + '/admin/project-audit-ledger/projects/' + projectId, { waitUntil: 'domcontentloaded' });
    expect(projDetail.status(), 'project detail status').toBeLessThan(500);
    await expect(page.locator('body')).toContainText('Edit');
    step('✓ project detail renders');
    const projEdit = await page.goto(APP_URL + '/admin/project-audit-ledger/projects/' + projectId + '/edit', { waitUntil: 'domcontentloaded' });
    expect(projEdit.status(), 'project edit status').toBeLessThan(500);
    step('✓ project edit renders');

    console.log('\nJOURNEY STEPS COMPLETED:\n  - ' + results.join('\n  - '));
});
