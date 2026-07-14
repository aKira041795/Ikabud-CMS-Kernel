/**
 * PAL Sidebar Navigation and Page View tests.
 *
 * Validates every PAL sidebar link loads its expected page and renders
 * relevant content. Uses shell.navigateViaSidebar() for consistent
 * sidebar interaction.
 *
 * @see modules/project-audit-ledger/module.json — nav section
 */

// @ts-check
const { test, expect } = require('../../../WorkbenchFixture');

const SIDEBAR_LINKS = [
  { label: 'Project Ledger', url: '/admin/project-audit-ledger', title: /Dashboard|Project Ledger/i },
  { label: 'Projects',       url: '/admin/project-audit-ledger/projects',  title: /Job Orders|Projects/i },
  { label: 'Expenses',       url: '/admin/project-audit-ledger/expenses',  title: /Expenses/i },
  { label: 'Inventory',      url: '/admin/project-audit-ledger/inventory', title: /Inventory/i },
  { label: 'Purchases',      url: '/admin/project-audit-ledger/purchases', title: /Purchases/i },
  { label: 'Fabrication',    url: '/admin/project-audit-ledger/fabrication', title: /Fabrication/i },
  { label: 'Sales',          url: '/admin/project-audit-ledger/sales',     title: /Sales/i },
  { label: 'Approvals',      url: '/admin/project-audit-ledger/approvals', title: /Approvals/i },
  { label: 'Reports',        url: '/admin/project-audit-ledger/reports',   title: /Reports/i },
  { label: 'Settings',       url: '/admin/project-audit-ledger/settings',  title: /Settings/i },
];

test.describe('PAL Sidebar Navigation and Page Views', () => {

    SIDEBAR_LINKS.forEach(({ label, url, title }) => {
        test(`sidebar "${label}" link navigates to page with expected title`, async ({ page, shell }) => {
            await shell.navigateViaSidebar(label);
            await page.waitForURL(`**${url}**`, { timeout: 10000 });

            // Verify app shell is still rendered
            await shell.expectVisible();

            // Verify correct nav item is active
            await shell.expectActiveNav(label);

            // Page heading matches expected title pattern
            const heading = page.locator('#wb-main h1');
            await expect(heading).toContainText(title);

            // Page has meaningful content
            const main = page.locator('#wb-main');
            const text = await main.textContent() || '';
            expect(text.length).toBeGreaterThan(50);
        });
    });

    // ── Entity list pages: verify data-wb-component is present ──
    test.describe('entity list pages', () => {
        const entityListPages = [
            { label: 'Projects',   entity: 'pal_project' },
            { label: 'Expenses',   entity: 'pal_expense' },
            { label: 'Purchases',  entity: 'pal_purchase' },
            { label: 'Sales',      entity: 'pal_sale' },
            { label: 'Inventory',  entity: 'pal_inventory' },
        ];

        entityListPages.forEach(({ label, entity }) => {
            test(`"${label}" page contains entity-list for ${entity}`, async ({ page, shell }) => {
                await shell.navigateViaSidebar(label);
                await page.waitForURL(`**/admin/project-audit-ledger/**`, { timeout: 10000 });

                const entityList = page.locator('[data-wb-component="entity-list"]');
                await expect(entityList.first()).toBeVisible({ timeout: 10000 });

                // Check for the correct entity type
                await expect(entityList.first()).toHaveAttribute('data-wb-entity', entity);
            });
        });
    });

    // ── Action pages: verify forms / detail pages have CTA buttons ──
    test.describe('page actions', () => {

        test('Projects page has Create New button', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Projects');
            await page.waitForURL('**/admin/project-audit-ledger/projects**', { timeout: 10000 });
            const createBtn = page.locator('a[href*="projects/create"]').first();
            await expect(createBtn).toBeVisible();
        });

        test('Approvals page shows approval queue', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Approvals');
            await page.waitForURL('**/admin/project-audit-ledger/approvals**', { timeout: 10000 });
            // Approval items or empty state
            const approvalSection = page.locator('[data-wb-component="approval-list"], table, .empty-state').first();
            await expect(approvalSection).toBeVisible();
        });

        test('Dashboard sidebar remains active on page reload', async ({ page, shell }) => {
            await shell.navigateViaSidebar('Project Ledger');
            await page.waitForURL('**/admin/project-audit-ledger**', { timeout: 10000 });
            await page.reload();
            await page.waitForLoadState('networkidle');
            await shell.expectActiveNav('Project Ledger');
        });
    });
});
