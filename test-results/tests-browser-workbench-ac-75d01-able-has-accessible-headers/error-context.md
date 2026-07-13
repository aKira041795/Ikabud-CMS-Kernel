# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/browser/workbench/accessibility.spec.js >> ARK Workbench accessibility >> project list table has accessible headers
- Location: tests/browser/workbench/accessibility.spec.js:114:5

# Error details

```
Error: expect(received).toBeGreaterThanOrEqual(expected)

Expected: >= 1
Received:    0
```

# Page snapshot

```yaml
- generic [active] [ref=e1]:
  - link "Skip to content" [ref=e2] [cursor=pointer]:
    - /url: "#wb-main"
  - complementary [ref=e3]:
    - generic [ref=e4]:
      - heading "Test" [level=1] [ref=e5]
      - paragraph [ref=e6]: Noah Omamalin
    - navigation "Main navigation" [ref=e7]:
      - generic [ref=e8]:
        - button "Overview" [expanded] [ref=e9] [cursor=pointer]:
          - generic [ref=e10]: Overview
          - generic [ref=e11]: −
        - generic [ref=e12]:
          - link "Dashboard" [ref=e13] [cursor=pointer]:
            - /url: /admin/project-audit-ledger
            - generic [ref=e14]: 📊
            - text: Dashboard
          - link "New Job Order" [ref=e15] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/projects/create
            - generic [ref=e16]: ➕
            - text: New Job Order
      - generic [ref=e17]:
        - button "Job Orders" [expanded] [ref=e18] [cursor=pointer]:
          - generic [ref=e19]: Job Orders
          - generic [ref=e20]: −
        - generic [ref=e21]:
          - link "All Job Orders" [ref=e22] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/projects
            - generic [ref=e23]: 📋
            - text: All Job Orders
          - link "Clients" [ref=e24] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/clients
            - generic [ref=e25]: 👤
            - text: Clients
          - link "Suppliers" [ref=e26] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/suppliers
            - generic [ref=e27]: 🏭
            - text: Suppliers
      - generic [ref=e28]:
        - button "Sales & Billing" [expanded] [ref=e29] [cursor=pointer]:
          - generic [ref=e30]: Sales & Billing
          - generic [ref=e31]: −
        - generic [ref=e32]:
          - link "Sales Invoices" [ref=e33] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/sales
            - generic [ref=e34]: 💰
            - text: Sales Invoices
          - link "Collections" [ref=e35] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/collections
            - generic [ref=e36]: 💵
            - text: Collections
          - link "Quotations" [ref=e37] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/quotations
            - generic [ref=e38]: 📝
            - text: Quotations
          - link "BOM" [ref=e39] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/bom
            - generic [ref=e40]: 📋
            - text: BOM
      - generic [ref=e41]:
        - button "Inventory & Procurement" [expanded] [ref=e42] [cursor=pointer]:
          - generic [ref=e43]: Inventory & Procurement
          - generic [ref=e44]: −
        - generic [ref=e45]:
          - link "Inventory" [ref=e46] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/inventory
            - generic [ref=e47]: 📦
            - text: Inventory
          - link "Stock Movements" [ref=e48] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/inventory/movements
            - generic [ref=e49]: 📤
            - text: Stock Movements
          - link "Purchases" [ref=e50] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/purchases
            - generic [ref=e51]: 🛒
            - text: Purchases
          - link "Issuances" [ref=e52] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/issuances
            - generic [ref=e53]: 📤
            - text: Issuances
          - link "Returns" [ref=e54] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/issuances/returns
            - generic [ref=e55]: ↩
            - text: Returns
          - link "Expenses" [ref=e56] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/expenses
            - generic [ref=e57]: 💳
            - text: Expenses
      - generic [ref=e58]:
        - button "Operations" [expanded] [ref=e59] [cursor=pointer]:
          - generic [ref=e60]: Operations
          - generic [ref=e61]: −
        - generic [ref=e62]:
          - link "Fabrication" [ref=e63] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/fabrication/allocations
            - generic [ref=e64]: 🔧
            - text: Fabrication
          - link "Mobilization" [ref=e65] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/mobilization
            - generic [ref=e66]: 🚛
            - text: Mobilization
          - link "Cash Advances" [ref=e67] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/cash-advances
            - generic [ref=e68]: 💵
            - text: Cash Advances
      - generic [ref=e69]:
        - button "Oversight" [expanded] [ref=e70] [cursor=pointer]:
          - generic [ref=e71]: Oversight
          - generic [ref=e72]: −
        - generic [ref=e73]:
          - link "Approvals" [ref=e74] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/approvals
            - generic [ref=e75]: ✅
            - text: Approvals
          - link "Reports" [ref=e76] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/reports
            - generic [ref=e77]: 📊
            - text: Reports
          - link "Audit Trail" [ref=e78] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/audit-trail
            - generic [ref=e79]: 🔍
            - text: Audit Trail
      - generic [ref=e80]:
        - button "Administration" [expanded] [ref=e81] [cursor=pointer]:
          - generic [ref=e82]: Administration
          - generic [ref=e83]: −
        - generic [ref=e84]:
          - link "Settings" [ref=e85] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/settings
            - generic [ref=e86]: ⚙
            - text: Settings
          - link "Users" [ref=e87] [cursor=pointer]:
            - /url: /admin/project-audit-ledger/users
            - generic [ref=e88]: 👥
            - text: Users
    - link "Sign Out" [ref=e90] [cursor=pointer]:
      - /url: /api/v1/project-audit-ledger/auth/logout
  - main [ref=e91]:
    - heading "Job Orders" [level=1] [ref=e92]
    - generic [ref=e93]:
      - generic [ref=e94]:
        - heading "Job Orders" [level=2] [ref=e95]
        - link "+ New Job Order" [ref=e96] [cursor=pointer]:
          - /url: /admin/project-audit-ledger/projects/create
      - generic [ref=e97]:
        - textbox "Search JOs..." [ref=e99]
        - table [ref=e100]:
          - rowgroup [ref=e101]:
            - row "Project id Title Client name Contract amount Status Start date Id Actions" [ref=e102]:
              - columnheader "Project id" [ref=e103]:
                - link "Project id" [ref=e104] [cursor=pointer]:
                  - /url: "?sort=project_id&dir=asc"
              - columnheader "Title" [ref=e105]:
                - link "Title" [ref=e106] [cursor=pointer]:
                  - /url: "?sort=title&dir=asc"
              - columnheader "Client name" [ref=e107]:
                - link "Client name" [ref=e108] [cursor=pointer]:
                  - /url: "?sort=client_name&dir=asc"
              - columnheader "Contract amount" [ref=e109]:
                - link "Contract amount" [ref=e110] [cursor=pointer]:
                  - /url: "?sort=contract_amount&dir=asc"
              - columnheader "Status" [ref=e111]:
                - link "Status" [ref=e112] [cursor=pointer]:
                  - /url: "?sort=status&dir=asc"
              - columnheader "Start date" [ref=e113]:
                - link "Start date" [ref=e114] [cursor=pointer]:
                  - /url: "?sort=start_date&dir=asc"
              - columnheader "Id" [ref=e115]:
                - link "Id" [ref=e116] [cursor=pointer]:
                  - /url: "?sort=id&dir=asc"
              - columnheader "Actions" [ref=e117]
          - rowgroup [ref=e118]:
            - row "P-20260711-b49f81 Tarp banner 4x8 feet Walk-in ₱3,680.00 Active Jul 11 2 View Edit" [ref=e119]:
              - cell "P-20260711-b49f81" [ref=e120]
              - cell "Tarp banner 4x8 feet" [ref=e121]
              - cell "Walk-in" [ref=e122]
              - cell "₱3,680.00" [ref=e123]
              - cell "Active" [ref=e124]:
                - generic [ref=e125]: Active
              - cell "Jul 11" [ref=e126]
              - cell "2" [ref=e127]
              - cell "View Edit" [ref=e128]:
                - generic [ref=e129]:
                  - link "View" [ref=e130] [cursor=pointer]:
                    - /url: /admin/project-audit-ledger/projects/2
                  - link "Edit" [ref=e131] [cursor=pointer]:
                    - /url: /admin/project-audit-ledger/projects/2/edit
            - row "P-20260622-832489 Lighted Sign EJ Agrivet Supplies ₱125,000.00 Active Jun 26 1 View Edit" [ref=e132]:
              - cell "P-20260622-832489" [ref=e133]
              - cell "Lighted Sign" [ref=e134]
              - cell "EJ Agrivet Supplies" [ref=e135]
              - cell "₱125,000.00" [ref=e136]
              - cell "Active" [ref=e137]:
                - generic [ref=e138]: Active
              - cell "Jun 26" [ref=e139]
              - cell "1" [ref=e140]
              - cell "View Edit" [ref=e141]:
                - generic [ref=e142]:
                  - link "View" [ref=e143] [cursor=pointer]:
                    - /url: /admin/project-audit-ledger/projects/1
                  - link "Edit" [ref=e144] [cursor=pointer]:
                    - /url: /admin/project-audit-ledger/projects/1/edit
  - status
```

# Test source

```ts
  21  | 
  22  |     test.beforeEach(async ({ page }) => {
  23  |         await page.goto(`${APP_URL}/project-audit-ledger/login`);
  24  |         await page.fill('input[name="username"]', 'paladmin');
  25  |         await page.fill('input[name="password"]', 'pAl123456');
  26  |         await page.click('button[type="submit"]');
  27  |         await page.waitForURL('**/admin/project-audit-ledger');
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
> 121 |         expect(count).toBeGreaterThanOrEqual(1);
      |                       ^ Error: expect(received).toBeGreaterThanOrEqual(expected)
  122 |     });
  123 | 
  124 |     // ── Focus management ──
  125 | 
  126 |     test('mobile menu button is focusable', async ({ page }) => {
  127 |         const menuBtn = page.locator('#wb-menu-btn');
  128 |         await expect(menuBtn).toBeVisible();
  129 |         await menuBtn.focus();
  130 |         await expect(menuBtn).toBeFocused();
  131 |     });
  132 | });
  133 | 
```