# Instructions

- Following Playwright test failed.
- Explain why, be concise, respect Playwright best practices.
- Provide a snippet of code with the fix, if possible.

# Test info

- Name: tests/browser/workbench/accessibility.spec.js >> ARK Workbench accessibility >> form fields have associated labels
- Location: tests/browser/workbench/accessibility.spec.js:93:5

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
    - heading "New Job Order" [level=1] [ref=e92]
    - generic [ref=e93]:
      - generic [ref=e94]:
        - generic [ref=e95]:
          - generic [ref=e96]:
            - heading "Create Job Order" [level=2] [ref=e97]
            - generic [ref=e98]:
              - button "👤 Customer" [ref=e99]: 👤 Customer
              - button "📋 Details" [ref=e101]: 📋 Details
              - button "💰 Pricing" [ref=e103]: 💰 Pricing
              - button "🔧 Fab" [ref=e105]: 🔧 Fab
              - button "📦 Items" [ref=e107]: 📦 Items
              - button "🖼 Docs" [ref=e109]: 🖼 Docs
          - generic [ref=e112]:
            - generic [ref=e113]:
              - heading "👤 Customer Information" [level=3] [ref=e114]
              - generic [ref=e115]:
                - generic [ref=e116]:
                  - generic [ref=e117]: Customer's Name *
                  - combobox [ref=e118]:
                    - option "—" [selected]
                    - option "EJ Agrivet Supplies"
                    - option "Walk-in"
                    - 'option "✦ Other: type new..."'
                - generic [ref=e119]:
                  - generic [ref=e120]: Contact Person
                  - textbox "Company or contact person" [ref=e121]
                - generic [ref=e122]:
                  - generic [ref=e123]: "Contact #"
                  - textbox "Phone number" [ref=e124]
                - generic [ref=e125]:
                  - generic [ref=e126]: Client Email
                  - textbox "Email address" [ref=e127]
                - generic [ref=e128]:
                  - generic [ref=e129]: Address
                  - textbox [ref=e130]
            - generic [ref=e131]:
              - heading "📋 Job Details" [level=3] [ref=e132]
              - generic [ref=e133]:
                - generic [ref=e134]:
                  - generic [ref=e135]: Title / Job Description *
                  - textbox [ref=e136]
                - generic [ref=e137]:
                  - generic [ref=e138]: JO Number
                  - textbox "Auto-generated if empty" [ref=e139]
                - generic [ref=e140]:
                  - generic [ref=e141]: Date
                  - textbox [ref=e142]
                - generic [ref=e143]:
                  - generic [ref=e144]: Scope of Work
                  - combobox [ref=e145]:
                    - option "—" [selected]
                    - option "New"
                    - option "Refurbish"
                    - option "Warranty Claim"
                    - option "Labor Only"
                    - option "Print Only"
                - generic [ref=e146]:
                  - generic [ref=e147]: Project Type
                  - combobox [ref=e148]:
                    - option "—" [selected]
                    - option "Lighted Signs"
                    - option "Tarp printing"
                    - 'option "✦ Other: type new..."'
                - generic [ref=e149]:
                  - generic [ref=e150]: Prepared by
                  - textbox [disabled] [ref=e151]: Noah Omamalin
                - generic [ref=e152]:
                  - generic [ref=e153]:
                    - generic [ref=e154]: Status
                    - generic [ref=e155]: Draft
                  - generic [ref=e156]:
                    - generic [ref=e157]: Target End
                    - textbox [ref=e158]
                - generic [ref=e159]:
                  - generic [ref=e160]: Description
                  - textbox [ref=e161]
            - generic [ref=e162]:
              - heading "💰 Pricing & Charges" [level=3] [ref=e163]
              - generic [ref=e164]:
                - heading "📋 JO Type" [level=3] [ref=e166]
                - generic [ref=e167]:
                  - generic [ref=e168] [cursor=pointer]:
                    - radio "📋 Quotation Items" [checked] [disabled] [ref=e169]
                    - generic [ref=e170]: 📋 Quotation Items
                  - generic [ref=e171] [cursor=pointer]:
                    - radio "💰 Contracted Amount" [ref=e172]
                    - generic [ref=e173]: 💰 Contracted Amount
                - heading "💰 Charges" [level=3] [ref=e175]
                - generic [ref=e176]:
                  - generic [ref=e177]: Installation Charge
                  - spinbutton [ref=e178]: "0"
                - generic [ref=e179]:
                  - generic [ref=e180]: Mobilization Charge
                  - spinbutton [ref=e181]: "0"
                - generic [ref=e182]:
                  - generic [ref=e183]: Other Charges
                  - spinbutton [ref=e184]: "0"
                - generic [ref=e185]:
                  - generic [ref=e186]: Mode of Payment
                  - combobox [ref=e187]:
                    - option "—" [selected]
                    - option "Cash"
                    - option "Check"
                    - option "Bank Transfer"
                    - option "Gcash"
                - generic [ref=e188]:
                  - generic [ref=e189]: Down Payment
                  - spinbutton [ref=e190]
                - generic [ref=e191]:
                  - generic [ref=e192]: With Installation
                  - generic [ref=e193]:
                    - checkbox "Yes" [ref=e194]
                    - generic [ref=e195]: "Yes"
          - generic [ref=e196]:
            - heading "📦 Line Items" [level=3] [ref=e197]
            - table [ref=e199]:
              - rowgroup [ref=e200]:
                - row "# Material Particulars Width Height Unit QTY Price/Unit Price/SqFt Total" [ref=e201]:
                  - columnheader "#" [ref=e202]
                  - columnheader "Material" [ref=e203]
                  - columnheader "Particulars" [ref=e204]
                  - columnheader "Width" [ref=e205]
                  - columnheader "Height" [ref=e206]
                  - columnheader "Unit" [ref=e207]
                  - columnheader "QTY" [ref=e208]
                  - columnheader "Price/Unit" [ref=e209]
                  - columnheader "Price/SqFt" [ref=e210]
                  - columnheader "Total" [ref=e211]
                  - columnheader [ref=e212]
              - rowgroup
            - button "+ Add Item" [ref=e213]
            - generic [ref=e214]: "Subtotal: ₱0.00"
        - generic [ref=e215]:
          - heading "🖼 Mockup / Reference Image" [level=3] [ref=e216]
          - generic [ref=e218]:
            - button "Choose File" [ref=e219]
            - paragraph [ref=e220]: Upload a design mockup or reference image for this JO (max 2MB recommended)
      - generic [ref=e221]:
        - button "Save as Draft" [ref=e222]
        - button "Submit for Approval" [ref=e223]
        - link "Cancel" [ref=e224] [cursor=pointer]:
          - /url: /admin/project-audit-ledger/projects
    - generic [ref=e226]:
      - generic [ref=e227]:
        - heading "Order Summary" [level=4] [ref=e228]
        - generic [ref=e229]:
          - generic [ref=e230]:
            - generic [ref=e231]: Subtotal
            - generic [ref=e232]: ₱0.00
          - generic [ref=e233]:
            - generic [ref=e234]: Charges
            - generic [ref=e235]: ₱0.00
          - generic [ref=e236]:
            - generic [ref=e237]: Discount
            - generic [ref=e238]: —
          - generic [ref=e239]:
            - generic [ref=e240]: Total
            - generic [ref=e241]: ₱0.00
          - generic [ref=e242]:
            - generic [ref=e243]: Down Payment
            - generic [ref=e244]: —
          - generic [ref=e245]:
            - generic [ref=e246]: Expected Balance
            - generic [ref=e247]: ₱0.00
      - generic [ref=e248]: 💡 Use the step buttons above to navigate between sections.
  - status
```

# Test source

```ts
  7   |  *   - npm install @axe-core/playwright
  8   |  *
  9   |  * Run: npx playwright test tests/browser/workbench/accessibility.spec.js
  10  |  *
  11  |  * @see storage/application-profiles/ark-workbench/components/
  12  |  */
  13  | 
  14  | // @ts-check
  15  | const { test, expect } = require('@playwright/test');
  16  | const { AxeBuilder } = require('@axe-core/playwright');
  17  | 
  18  | const APP_URL = process.env.APP_URL || 'http://palsystem.test';
  19  | 
  20  | test.describe('ARK Workbench accessibility', () => {
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
> 107 |                 expect(labelCount).toBeGreaterThanOrEqual(1);
      |                                    ^ Error: expect(received).toBeGreaterThanOrEqual(expected)
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
  128 |         await expect(menuBtn).toBeVisible();
  129 |         await menuBtn.focus();
  130 |         await expect(menuBtn).toBeFocused();
  131 |     });
  132 | });
  133 | 
```