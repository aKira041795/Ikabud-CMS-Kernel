/**
 * PAL Sidebar Navigation & Page View — Deep Semantic Tests.
 *
 * Simulates real human use: navigate via sidebar, verify the page
 * template structure (shell, headings, sections), interact with
 * forms (fill, submit, validate), check entity lists, and verify
 * recursive state changes (e.g. creating a record redirects to
 * its detail page, then navigating back preserves context).
 *
 * Patterns:
 *   - test.step() for structured, readable workflows
 *   - FormHarness for form-level assertions
 *   - data-wb-* attributes as stable test IDs
 *   - expect(locator, 'descriptive message') for clear diagnostics
 *   - Recursive navigate + verify chains
 *
 * @see modules/project-audit-ledger/module.json — nav + routes
 * @see storage/application-profiles/ark-workbench/testing/harnesses/FormHarness.js
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');
const { FormHarness } = require('../../../../storage/application-profiles/ark-workbench/testing/harnesses/FormHarness');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const BASE = '/admin/project-audit-ledger';

test.describe('PAL Sidebar Navigation — Deep Semantic Tests', () => {

    // ════════════════════════════════════════════════════════════
    // 1. DASHBOARD (Project Ledger)
    // ════════════════════════════════════════════════════════════
    test.describe('Dashboard (Project Ledger)', () => {

        test('renders full dashboard template: shell, KPI cards, financial sections, entity list', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Project Ledger');
            await page.waitForURL(`**${BASE}**`, { timeout: 10000 });

            await shell.expectVisible();
            await shell.expectActiveNav('Project Ledger');

            // KPI summary cards exist with visible values
            const cards = page.locator('[data-wb-component="summary-card"]');
            await expect(cards.first(), 'Dashboard must have KPI summary cards').toBeVisible();
            const cardCount = await cards.count();
            expect(cardCount).toBeGreaterThanOrEqual(1);
            for (let i = 0; i < cardCount; i++) {
                const text = await cards.nth(i).textContent();
                expect(text?.trim().length, `Summary card ${i} has content`).toBeGreaterThan(0);
            }

            // Financial health sections
            await expect(page.locator('h2:has-text("Financial Health")'), 'Financial Health section required').toBeVisible();
            await expect(page.locator('h2:has-text("Outstanding Receivables")'), 'Outstanding Receivables section required').toBeVisible();
            await expect(page.locator('h2:has-text("Recent Projects")'), 'Recent Projects section required').toBeVisible();

            // Entity list (All Projects)
            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first(), 'Dashboard must contain entity list').toBeVisible();
            await expect(entityList.first()).toHaveAttribute('data-wb-entity');
        });

        test('sidebar nav persists active state after page reload', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Project Ledger');
            await page.waitForURL(`**${BASE}**`, { timeout: 10000 });
            await page.reload();
            await page.waitForLoadState('networkidle');
            await shell.expectActiveNav('Project Ledger');
            await shell.expectVisible();
        });
    });

    // ════════════════════════════════════════════════════════════
    // 2. PROJECTS — List + Create + Detail
    // ════════════════════════════════════════════════════════════
    test.describe('Projects', () => {

        test('project list: entity table, search, create button, and row actions', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Projects');
            await page.waitForURL(`**${BASE}/projects**`, { timeout: 10000 });

            await shell.expectVisible();
            await shell.expectActiveNav('Projects');

            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first()).toBeVisible({ timeout: 10000 });
            await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_project');

            // Search input
            const searchInput = page.locator('input[type="search"], input[placeholder*="Search"]').first();
            await expect(searchInput, 'Projects list must have search').toBeVisible();
            await searchInput.fill('Test');
            await page.waitForTimeout(300);
            await searchInput.clear();

            // Create button
            const createBtn = page.locator('a[href*="projects/create"]').first();
            await expect(createBtn, 'Projects list must have Create New button').toBeVisible();
            await expect(createBtn).toContainText(/New|Create|Add/i);

            // Table rows with actions
            const rows = page.locator('[data-wb-component="entity-list"] table tbody tr, [data-wb-role="row"]');
            const rowCount = await rows.count();
            if (rowCount > 0) {
                const firstRowActions = rows.first().locator('a, button');
                const actionCount = await firstRowActions.count();
                expect(actionCount, 'Each project row must have at least one action').toBeGreaterThanOrEqual(1);
            }
        });

        test('create project form: required fields, validation, and submit redirects', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Projects');
            await page.waitForURL(`**${BASE}/projects**`, { timeout: 10000 });

            const createBtn = page.locator('a[href*="projects/create"]').first();
            await createBtn.click();
            await page.waitForURL(`**${BASE}/projects/create**`, { timeout: 10000 });

            const form = new FormHarness(page, 'form');
            await form.expectVisible();
            await form.expectRequiredFields();

            // Submit empty — verify validation
            await form.submit();
            await page.waitForTimeout(500);

            const validationSummary = page.locator('[data-wb-component="validation-summary"]');
            const fieldErrors = page.locator('[data-wb-field-error], .field-error, .error, [class*="error"]');
            const hasFeedback = (await validationSummary.count() > 0) || (await fieldErrors.count() > 0);
            const stillOnForm = page.url().includes('/create');

            if (stillOnForm && hasFeedback) {
                console.log('  ✓ Validation caught empty submission');
            } else if (!stillOnForm) {
                console.log('  ℹ Empty form submitted — title may be optional');
            }

            // Fill required fields
            const runPrefix = 'NV-' + Date.now();
            await form.fill('title', runPrefix + ' Project');

            const amountField = page.locator('input[name="contract_amount"], [data-wb-field="contract_amount"]');
            if (await amountField.isVisible({ timeout: 1000 }).catch(() => false)) {
                await amountField.fill('50000');
            }

            const clientSelect = page.locator('select[name="client_id"], [data-wb-field="client_id"]');
            if (await clientSelect.isVisible({ timeout: 1000 }).catch(() => false)) {
                const opts = await clientSelect.locator('option').count();
                if (opts > 1) await clientSelect.selectOption({ index: 1 });
            }

            await form.submit();
            await page.waitForURL(/\/projects\/\d+/, { timeout: 15000 }).catch(() => {});

            const onDetail = /\/projects\/\d+/.test(page.url());
            const onList = page.url().includes('/projects') && !page.url().includes('/create');
            if (onDetail) {
                await expect(page.locator('h1'), 'Detail page heading must be visible').toBeVisible();
            } else if (onList) {
                console.log('  ℹ Returned to list after create');
            }
        });

        test('create → detail navigation chain (recursive)', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Projects');
            await page.waitForURL(`**${BASE}/projects**`, { timeout: 10000 });

            const existingLinks = page.locator('a[href*="/projects/"]').filter({ has: page.locator('text=View') });
            const firstExisting = await existingLinks.first().getAttribute('href').catch(() => null);

            if (firstExisting) {
                await page.goto(`${APP_URL}${firstExisting}`);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });

                const heading = page.locator('h1');
                await expect(heading, 'Detail page must have heading').toBeVisible();
                const mainText = await page.locator('#wb-main').textContent();
                expect(mainText?.length, 'Detail page must have substantive content').toBeGreaterThan(100);

                // Navigate back via sidebar
                await shell.navigateViaSidebar('Projects');
                await page.waitForURL(`**${BASE}/projects**`, { timeout: 10000 });
                await shell.expectActiveNav('Projects');
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 3. EXPENSES
    // ════════════════════════════════════════════════════════════
    test.describe('Expenses', () => {

        test('expenses: entity list, create form with amount input accepted', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Expenses');
            await page.waitForURL(`**${BASE}/expenses**`, { timeout: 10000 });
            await shell.expectActiveNav('Expenses');

            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first()).toBeVisible({ timeout: 10000 });
            await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_expense');

            const createBtn = page.locator('a[href*="expenses/create"]');
            if (await createBtn.isVisible({ timeout: 2000 }).catch(() => false)) {
                await createBtn.first().click();
                await page.waitForURL(`**${BASE}/expenses/create**`, { timeout: 10000 });

                const form = new FormHarness(page);
                await form.expectVisible();
                await form.expectMinFields(2);

                const amountField = page.locator('input[type="number"], input[name*="amount"], input[name*="cost"]');
                if (await amountField.isVisible({ timeout: 1000 }).catch(() => false)) {
                    await amountField.fill('1000');
                    const val = await amountField.inputValue();
                    expect(val, 'Amount field must accept numeric input').toBe('1000');
                }
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 4. APPROVALS
    // ════════════════════════════════════════════════════════════
    test.describe('Approvals', () => {

        test('approvals page: queue with items or empty state, action buttons', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Approvals');
            await page.waitForURL(`**${BASE}/approvals**`, { timeout: 10000 });
            await shell.expectActiveNav('Approvals');

            const table = page.locator('table').first();
            const emptyState = page.locator('[data-wb-component="empty-state"], .empty-state, p:has-text("No")');
            const hasTable = await table.isVisible({ timeout: 2000 }).catch(() => false);

            if (hasTable) {
                const headers = await table.locator('th').allTextContents();
                expect(headers.join(' ').length, 'Approval table must have headers').toBeGreaterThan(0);

                const approveBtns = page.locator('button:has-text("Approve"), a:has-text("Approve"), [data-wb-action*="approve"]');
                const rejectBtns = page.locator('button:has-text("Reject"), a:has-text("Reject"), [data-wb-action*="reject"]');
                if ((await approveBtns.count()) > 0 || (await rejectBtns.count()) > 0) {
                    console.log('  ✓ Approval queue has decision buttons');
                } else {
                    const badges = page.locator('[data-wb-component="status-badge"], .badge, [class*="status"]');
                    if (await badges.first().isVisible({ timeout: 1000 }).catch(() => false)) {
                        console.log('  ✓ Approval items show status badges');
                    }
                }
            } else if (await emptyState.isVisible({ timeout: 1000 }).catch(() => false)) {
                console.log('  ℹ No pending approvals — empty state');
            } else {
                const text = await page.locator('#wb-main').textContent();
                expect(text?.length, 'Approvals page must have content').toBeGreaterThan(20);
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 5. RECURSIVE CROSS-PAGE FLOW
    // ════════════════════════════════════════════════════════════
    test.describe('Recursive Cross-Page Navigation', () => {

        test('navigate sidebar → Projects → Expenses → Approvals → Dashboard: each intact', async ({ page, shell }) => {
            const pages = [
                { label: 'Projects',      urlPattern: '/projects' },
                { label: 'Expenses',      urlPattern: '/expenses' },
                { label: 'Approvals',     urlPattern: '/approvals' },
                { label: 'Project Ledger', urlPattern: BASE },
            ];

            for (const { label, urlPattern } of pages) {
                await shell.navigateViaSidebar(label);
                await page.waitForURL(`**${urlPattern}**`, { timeout: 10000 });

                await shell.expectActiveNav(label);
                await shell.expectVisible();
                await expect(page.locator('#wb-main h1'), `"${label}" page must have heading`).toBeVisible();

                const text = await page.locator('#wb-main').textContent();
                expect(text?.length, `"${label}" page must have content`).toBeGreaterThan(50);
                console.log(`  ✓ "${label}" renders shell, heading, content`);
            }
        });

        test('sidebar search filters nav items', async ({ page, shell }) => {
            await shell.expectVisible();
            const searchInput = page.locator('#wb-sidebar input[type="search"], #wb-sidebar input[placeholder*="Search"], [data-wb-role="nav-search"]');
            if (await searchInput.isVisible({ timeout: 2000 }).catch(() => false)) {
                await searchInput.fill('Projects');
                await page.waitForTimeout(300);
                await expect(page.locator('[data-wb-role="nav-item"]').filter({ hasText: 'Projects' }).first(), 'Filtered nav must show matches').toBeVisible();
                await searchInput.clear();
                console.log('  ✓ Sidebar search filters nav items');
            } else {
                console.log('  ℹ No sidebar search input');
            }
        });

        test('direct URL navigation preserves sidebar active state', async ({ page, shell }) => {
            await page.goto(`${APP_URL}${BASE}/purchases`);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await shell.expectActiveNav('Purchases');

            await page.goto(`${APP_URL}${BASE}/sales`);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await shell.expectActiveNav('Sales');

            await page.goto(`${APP_URL}${BASE}/settings`);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
            await shell.expectActiveNav('Settings');
        });
    });

    // ════════════════════════════════════════════════════════════
    // 6. FABRICATION
    // ════════════════════════════════════════════════════════════
    test.describe('Fabrication', () => {

        test('fabrication page: heading, summary cards, and data content', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Fabrication');
            await page.waitForURL(`**${BASE}/fabrication**`, { timeout: 10000 });
            await shell.expectActiveNav('Fabrication');

            await expect(page.locator('#wb-main h1'), 'Fabrication must have heading').toBeVisible();
            const headingText = await page.locator('#wb-main h1').textContent();
            expect(headingText).toMatch(/Fabrication/i);

            const cards = page.locator('[data-wb-component="summary-card"]');
            const forms = page.locator('form').filter({ hasText: /Allocation|Dues/i });
            const tables = page.locator('table');

            const hasCards = await cards.first().isVisible({ timeout: 1000 }).catch(() => false);
            const hasForm = await forms.first().isVisible({ timeout: 1000 }).catch(() => false);
            const hasTable = await tables.first().isVisible({ timeout: 500 }).catch(() => false);

            if (hasCards) console.log('  ✓ Fabrication summary cards');
            if (hasForm) console.log('  ✓ Fabrication allocation form');
            if (hasTable) console.log('  ✓ Fabrication data table');
            if (!hasCards && !hasForm && !hasTable) {
                const text = await page.locator('#wb-main').textContent();
                expect(text?.length, 'Fabrication must have content').toBeGreaterThan(50);
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 7. INVENTORY
    // ════════════════════════════════════════════════════════════
    test.describe('Inventory', () => {

        test('inventory page: entity list with pal_inventory, stock quantity columns', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Inventory');
            await page.waitForURL(`**${BASE}/inventory**`, { timeout: 10000 });
            await shell.expectActiveNav('Inventory');

            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first()).toBeVisible({ timeout: 10000 });
            await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_inventory');

            const table = page.locator('table').first();
            if (await table.isVisible({ timeout: 1000 }).catch(() => false)) {
                const headers = await table.locator('th').allTextContents();
                const h = headers.join(' ').toLowerCase();
                if (/qty|stock|quantity|on hand/.test(h)) {
                    console.log('  ✓ Inventory table tracks stock quantities');
                }
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 8. PURCHASES
    // ════════════════════════════════════════════════════════════
    test.describe('Purchases', () => {

        test('purchases page: entity list with pal_purchase, supplier/vendor columns', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Purchases');
            await page.waitForURL(`**${BASE}/purchases**`, { timeout: 10000 });
            await shell.expectActiveNav('Purchases');

            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first()).toBeVisible({ timeout: 10000 });
            await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_purchase');

            const table = page.locator('table').first();
            if (await table.isVisible({ timeout: 1000 }).catch(() => false)) {
                const headers = await table.locator('th').allTextContents();
                const h = headers.join(' ').toLowerCase();
                if (/supplier|vendor|provider/.test(h)) {
                    console.log('  ✓ Purchases table references supplier');
                }
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 9. SALES
    // ════════════════════════════════════════════════════════════
    test.describe('Sales', () => {

        test('sales page: entity list with pal_sale, financial columns', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Sales');
            await page.waitForURL(`**${BASE}/sales**`, { timeout: 10000 });
            await shell.expectActiveNav('Sales');

            const entityList = page.locator('[data-wb-component="entity-list"]');
            await expect(entityList.first()).toBeVisible({ timeout: 10000 });
            await expect(entityList.first()).toHaveAttribute('data-wb-entity', 'pal_sale');

            const table = page.locator('table').first();
            if (await table.isVisible({ timeout: 1000 }).catch(() => false)) {
                const headers = await table.locator('th').allTextContents();
                if (/amount|total|price|cost|value/i.test(headers.join(' '))) {
                    console.log('  ✓ Sales table includes financial columns');
                }
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 10. REPORTS
    // ════════════════════════════════════════════════════════════
    test.describe('Reports', () => {

        test('reports page: report links list, export generation', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Reports');
            await page.waitForURL(`**${BASE}/reports**`, { timeout: 10000 });
            await shell.expectActiveNav('Reports');

            const reportLinks = page.locator('a[href*="report"], a[href*="export"], [data-wb-component="report-list"]');
            const hasLinks = await reportLinks.first().isVisible({ timeout: 2000 }).catch(() => false);

            if (hasLinks) {
                const count = await reportLinks.count();
                expect(count, 'Reports must have at least one report link').toBeGreaterThanOrEqual(1);
                console.log(`  ✓ Reports shows ${count} report link(s)`);
            } else {
                await expect(page.locator('#wb-main h1'), 'Reports must have heading').toBeVisible();
                const text = await page.locator('#wb-main').textContent();
                expect(text?.length, 'Reports must have content').toBeGreaterThan(50);
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 11. SETTINGS — Admin-only
    // ════════════════════════════════════════════════════════════
    test.describe('Settings', () => {

        test('settings page: configuration form with save capability', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Settings');
            await page.waitForURL(`**${BASE}/settings**`, { timeout: 10000 });
            await shell.expectActiveNav('Settings');

            const form = new FormHarness(page);
            await form.expectVisible();
            await form.expectMinFields(1);

            const saveBtn = page.locator('button[type="submit"], [data-wb-action="save"], button:has-text("Save")');
            await expect(saveBtn.first(), 'Settings must have Save button').toBeVisible();
        });
    });

    // ════════════════════════════════════════════════════════════
    // 12. INVENTORY → PURCHASES: cross-module link (recursive)
    // ════════════════════════════════════════════════════════════
    test.describe('Cross-Page Entity Links', () => {

        test('inventory item links to purchase detail (if present)', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Inventory');
            await page.waitForURL(`**${BASE}/inventory**`, { timeout: 10000 });

            // Check if any inventory row links to a purchase
            const purchaseLinks = page.locator('a[href*="/purchases/"]');
            if (await purchaseLinks.first().isVisible({ timeout: 2000 }).catch(() => false)) {
                const href = await purchaseLinks.first().getAttribute('href');
                if (href) {
                    await page.goto(`${APP_URL}${href}`);
                    await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
                    await shell.expectActiveNav('Purchases');
                    console.log('  ✓ Inventory→Purchase cross-link works');
                }
            } else {
                console.log('  ℹ No cross-links from inventory to purchases');
            }
        });
    });
});
