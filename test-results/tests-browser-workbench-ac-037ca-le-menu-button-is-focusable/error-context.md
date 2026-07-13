# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/browser/workbench/accessibility.spec.js >> ARK Workbench accessibility >> mobile menu button is focusable
- Location: tests/browser/workbench/accessibility.spec.js:126:5

# Error details

```
Error: expect(locator).toBeVisible() failed

Locator:  locator('#wb-menu-btn')
Expected: visible
Received: hidden
Timeout:  5000ms

Call log:
  - Expect "toBeVisible" with timeout 5000ms
  - waiting for locator('#wb-menu-btn')
    13 × locator resolved to <button id="wb-menu-btn" aria-expanded="false" aria-label="Open menu" class="md:hidden fixed top-3 left-3 z-50 p-2 bg-slate-900 text-white rounded-lg shadow-lg">…</button>
       - unexpected value "hidden"

```

```yaml
- link "Skip to content":
  - /url: "#wb-main"
- complementary:
  - heading "Test" [level=1]
  - paragraph: Noah Omamalin
  - navigation "Main navigation":
    - button "Overview" [expanded]
    - link "Dashboard":
      - /url: /admin/project-audit-ledger
    - link "New Job Order":
      - /url: /admin/project-audit-ledger/projects/create
    - button "Job Orders" [expanded]
    - link "All Job Orders":
      - /url: /admin/project-audit-ledger/projects
    - link "Clients":
      - /url: /admin/project-audit-ledger/clients
    - link "Suppliers":
      - /url: /admin/project-audit-ledger/suppliers
    - button "Sales & Billing" [expanded]
    - link "Sales Invoices":
      - /url: /admin/project-audit-ledger/sales
    - link "Collections":
      - /url: /admin/project-audit-ledger/collections
    - link "Quotations":
      - /url: /admin/project-audit-ledger/quotations
    - link "BOM":
      - /url: /admin/project-audit-ledger/bom
    - button "Inventory & Procurement" [expanded]
    - link "Inventory":
      - /url: /admin/project-audit-ledger/inventory
    - link "Stock Movements":
      - /url: /admin/project-audit-ledger/inventory/movements
    - link "Purchases":
      - /url: /admin/project-audit-ledger/purchases
    - link "Issuances":
      - /url: /admin/project-audit-ledger/issuances
    - link "Returns":
      - /url: /admin/project-audit-ledger/issuances/returns
    - link "Expenses":
      - /url: /admin/project-audit-ledger/expenses
    - button "Operations" [expanded]
    - link "Fabrication":
      - /url: /admin/project-audit-ledger/fabrication/allocations
    - link "Mobilization":
      - /url: /admin/project-audit-ledger/mobilization
    - link "Cash Advances":
      - /url: /admin/project-audit-ledger/cash-advances
    - button "Oversight" [expanded]
    - link "Approvals":
      - /url: /admin/project-audit-ledger/approvals
    - link "Reports":
      - /url: /admin/project-audit-ledger/reports
    - link "Audit Trail":
      - /url: /admin/project-audit-ledger/audit-trail
    - button "Administration" [expanded]
    - link "Settings":
      - /url: /admin/project-audit-ledger/settings
    - link "Users":
      - /url: /admin/project-audit-ledger/users
  - link "Sign Out":
    - /url: /api/v1/project-audit-ledger/auth/logout
- main:
  - heading "Dashboard" [level=1]
  - text: 📁 0 / 2 total Monthly Collections 💰 ₱1,500.00 This month's cash inflow Monthly Expenses 💳 ₱0.00 ₱0.00 ops + ₱0.00 fab Net Cash Flow 📈 +₱1,500.00 Positive cash flow Pending ⏳ 0 approvals 🔴 0 low stock
  - heading "📊 Financial Health" [level=2]
  - text: All-time totals
  - paragraph: Contract Value
  - paragraph: ₱128,680.00
  - paragraph: Expenses
  - paragraph: ₱1,500.00
  - paragraph: Fabrication
  - paragraph: ₱0.00
  - paragraph: Total Costs
  - paragraph: ₱1,500.00
  - paragraph: Collected
  - paragraph: ₱51,500.00
  - paragraph: Est. Profit
  - paragraph: ₱127,180.00
  - text: Expenses Fabrication Profit
  - heading "📋 Outstanding Receivables" [level=2]
  - link "View all →":
    - /url: /admin/project-audit-ledger/sales
  - text: ₱0.00 uncollected sales
  - paragraph
  - paragraph: — ·
  - paragraph: ₱8,508.80
  - paragraph: —
  - paragraph
  - paragraph: — ·
  - paragraph: ₱1,500.00
  - paragraph: —
  - paragraph
  - paragraph: — ·
  - paragraph: ₱50,000.00
  - paragraph: —
  - heading "🔴 Low Stock Alerts" [level=2]
  - link "View inventory →":
    - /url: /admin/project-audit-ledger/inventory
  - text: ✅ All stock levels are healthy
  - heading "📁 Recent Projects" [level=2]
  - link "All →":
    - /url: /admin/project-audit-ledger/projects
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:37
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:35
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:33
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:30
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:30
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:27
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:25
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:23
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:22
  - link:
    - /url: /admin/project-audit-ledger/projects/
  - paragraph: · 2026-07-13 10:29:20
  - heading "💳 Recent Expenses" [level=2]
  - link "All →":
    - /url: /admin/project-audit-ledger/expenses
  - paragraph: "#54684"
  - paragraph: — ·
  - paragraph: ₱1,500.00
  - text: approved
  - heading "⏳ Pending Approvals" [level=2]
  - link "All →":
    - /url: /admin/project-audit-ledger/approvals
  - text: ✅ All clear — no pending approvals
  - heading "📊 All Projects" [level=2]
  - link "Manage projects →":
    - /url: /admin/project-audit-ledger/projects
  - table:
    - rowgroup:
      - row "Project id Title Client name Contract amount Status Start date Id Actions":
        - columnheader "Project id"
        - columnheader "Title"
        - columnheader "Client name"
        - columnheader "Contract amount"
        - columnheader "Status"
        - columnheader "Start date"
        - columnheader "Id"
        - columnheader "Actions"
    - rowgroup:
      - row "P-20260711-b49f81 Tarp banner 4x8 feet Walk-in ₱3,680.00 Active Jul 11 2 View Edit":
        - cell "P-20260711-b49f81"
        - cell "Tarp banner 4x8 feet"
        - cell "Walk-in"
        - cell "₱3,680.00"
        - cell "Active"
        - cell "Jul 11"
        - cell "2"
        - cell "View Edit":
          - link "View":
            - /url: /admin/project-audit-ledger/projects/2
          - link "Edit":
            - /url: /admin/project-audit-ledger/projects/2/edit
      - row "P-20260622-832489 Lighted Sign EJ Agrivet Supplies ₱125,000.00 Active Jun 26 1 View Edit":
        - cell "P-20260622-832489"
        - cell "Lighted Sign"
        - cell "EJ Agrivet Supplies"
        - cell "₱125,000.00"
        - cell "Active"
        - cell "Jun 26"
        - cell "1"
        - cell "View Edit":
          - link "View":
            - /url: /admin/project-audit-ledger/projects/1
          - link "Edit":
            - /url: /admin/project-audit-ledger/projects/1/edit
- status
```

# Test source

```ts
  28  |         await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
  29  |     });
  30  | 
  31  |     // ── Dashboard ──
  32  | 
  33  |     test('dashboard page has no critical accessibility violations', async ({ page }) => {
  34  |         const results = await new AxeBuilder({ page })
  35  |             .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
  36  |             .analyze();
  37  | 
  38  |         expect(results.violations.filter(v => v.impact === 'critical').length).toBe(0);
  39  |     });
  40  | 
  41  |     test('dashboard has correct heading hierarchy', async ({ page }) => {
  42  |         const headings = await page.locator('h1, h2, h3').allTextContents();
  43  |         expect(headings.length).toBeGreaterThanOrEqual(1);
  44  |         // First heading should be the page title (h1)
  45  |         expect(headings[0].trim().length).toBeGreaterThan(0);
  46  |     });
  47  | 
  48  |     test('dashboard has skip-to-content link', async ({ page }) => {
  49  |         const skipLink = page.locator('.wb-skip-link');
  50  |         await expect(skipLink).toBeVisible();
  51  |         await expect(skipLink).toHaveAttribute('href', '#wb-main');
  52  |     });
  53  | 
  54  |     // ── Navigation ──
  55  | 
  56  |     test('sidebar navigation is a proper nav landmark', async ({ page }) => {
  57  |         const nav = page.locator('#wb-sidebar nav');
  58  |         await expect(nav).toHaveAttribute('aria-label', 'Main navigation');
  59  |     });
  60  | 
  61  |     test('sidebar section triggers have aria-expanded', async ({ page }) => {
  62  |         const triggers = page.locator('.wb-sidebar-section__trigger');
  63  |         const count = await triggers.count();
  64  |         for (let i = 0; i < Math.min(count, 5); i++) {
  65  |             await expect(triggers.nth(i)).toHaveAttribute('aria-expanded');
  66  |         }
  67  |     });
  68  | 
  69  |     // ── Images ──
  70  | 
  71  |     test('all images have alt text', async ({ page }) => {
  72  |         const images = page.locator('img');
  73  |         const count = await images.count();
  74  |         for (let i = 0; i < count; i++) {
  75  |             const alt = await images.nth(i).getAttribute('alt');
  76  |             expect(alt).not.toBeNull();
  77  |         }
  78  |     });
  79  | 
  80  |     // ── Color contrast ──
  81  | 
  82  |     test('status badges use semantic tone not just color', async ({ page }) => {
  83  |         const badges = page.locator('[data-wb-component="status-badge"]');
  84  |         const count = await badges.count();
  85  |         for (let i = 0; i < count; i++) {
  86  |             const text = await badges.nth(i).textContent();
  87  |             expect(text?.trim().length).toBeGreaterThan(0);
  88  |         }
  89  |     });
  90  | 
  91  |     // ── Forms ──
  92  | 
  93  |     test('form fields have associated labels', async ({ page }) => {
  94  |         // Navigate to project create form
  95  |         await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/create`);
  96  |         await page.waitForSelector('form', { timeout: 10000 });
  97  | 
  98  |         // Check for accessible form fields
  99  |         const inputs = page.locator('input:visible, select:visible, textarea:visible');
  100 |         const count = await inputs.count();
  101 |         for (let i = 0; i < Math.min(count, 5); i++) {
  102 |             const input = inputs.nth(i);
  103 |             const id = await input.getAttribute('id');
  104 |             if (id) {
  105 |                 const label = page.locator(`label[for="${id}"]`);
  106 |                 const labelCount = await label.count();
  107 |                 expect(labelCount).toBeGreaterThanOrEqual(1);
  108 |             }
  109 |         }
  110 |     });
  111 | 
  112 |     // ── Project List ──
  113 | 
  114 |     test('project list table has accessible headers', async ({ page }) => {
  115 |         // Navigate directly; auth cookie is preserved from beforeEach
  116 |         await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);
  117 |         await page.waitForSelector('[data-wb-component="entity-list"]', { timeout: 15000 });
  118 | 
  119 |         const headers = page.locator('th[scope="col"]');
  120 |         const count = await headers.count();
  121 |         expect(count).toBeGreaterThanOrEqual(1);
  122 |     });
  123 | 
  124 |     // ── Focus management ──
  125 | 
  126 |     test('mobile menu button is focusable', async ({ page }) => {
  127 |         const menuBtn = page.locator('#wb-menu-btn');
> 128 |         await expect(menuBtn).toBeVisible();
      |                               ^ Error: expect(locator).toBeVisible() failed
  129 |         await menuBtn.focus();
  130 |         await expect(menuBtn).toBeFocused();
  131 |     });
  132 | });
  133 | 
```