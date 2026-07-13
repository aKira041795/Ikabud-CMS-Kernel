# ARK Workbench — UI Testing Guide

**Comprehensive guide for writing browser and integration tests using the
ARK Workbench test infrastructure.**

**Status**: Draft — v0.1.0
**Date**: 2026-07-13

---

## Table of Contents

1. [Architecture Overview](#1-architecture-overview)
2. [data-wb-* Test Attributes](#2-data-wb--test-attributes)
3. [WorkbenchTestHarness (PHP)](#3-workbenchtestharness-php)
4. [Browser Tests (Playwright)](#4-browser-tests-playwright)
5. [Module UI Fixtures](#5-module-ui-fixtures)
6. [Testing Layers](#6-testing-layers)
7. [PAL Reference Implementation](#7-pal-reference-implementation)
8. [Adding Tests to a New Module](#8-adding-tests-to-a-new-module)
9. [Troubleshooting](#9-troubleshooting)
10. [Appendix: Component Attribute Reference](#10-appendix-component-attribute-reference)

---

## 1. Architecture Overview

ARK Workbench provides the **UI conformance and testing vocabulary** for
Kernel OS operational modules. Its role:

```
Module domain services     → prove business correctness
Kernel contracts           → prove capability & authorization correctness
DiSyL                      → proves valid declarative rendering
ARK Workbench              → proves presentation & interaction conformance
Browser workflow tests     → prove the assembled user journey
```

### 1.1 The Testing Stack

| Layer | Tool | Location | Runs |
|---|---|---|---|
| Component conformance | Playwright | `tests/browser/workbench/` | CI or local |
| Module rendering | PHPUnit / custom PHP | `tests/php/workbench/` | `composer test` |
| Browser interaction | Playwright | `tests/browser/modules/<module>/` | CI or local |
| Context & workflow | Playwright | `tests/browser/modules/<module>/` | CI or local |
| Accessibility | Playwright + axe-core | `tests/browser/workbench/accessibility.spec.js` | CI or local |

### 1.2 Key Principles

1. **Tests use semantic selectors, not CSS classes.** `data-wb-*` attributes
   are the stable testing contract. CSS classes can change; `data-wb-*` is
   frozen per component version.

2. **Scenarios are deterministic and idempotent.** Each test scenario seeds a
   known state and asserts known outcomes. Running the same scenario twice
   produces the same result.

3. **Workbench tests the presentation layer only.** Business logic correctness
   belongs in module-level domain tests.

4. **Playwright tests prefer `getByRole` and `getByText`** for interaction,
   and `data-wb-*` attributes for domain-context assertions.

---

## 2. data-wb-* Test Attributes

Every ARK Workbench component exposes standard data attributes for testing
and observability. These are **not style hooks** — they are frozen testing
contracts.

### 2.1 Attribute Catalog

| Attribute | Applies To | Purpose | Example Value |
|---|---|---|---|
| `data-wb-component` | All components | Component type identifier | `"app-shell"`, `"responsive-table"` |
| `data-wb-page-family` | `<main>` element | Current page type | `"detail-workspace"`, `"operational-list"` |
| `data-wb-entity` | Tables, detail views | Entity type name | `"pal.job-order"`, `"pal.expense"` |
| `data-wb-entity-id` | Rows, detail views | Entity instance identifier | `"123"`, `"JO-000456"` |
| `data-wb-state` | Containers | Data state | `"populated"`, `"empty"`, `"loading"` |
| `data-wb-row-state` | Table rows | Row workflow state | `"ongoing"`, `"completed"` |
| `data-wb-action` | Buttons, links | Action identifier | `"approve"`, `"edit"`, `"delete"` |
| `data-wb-method` | Action buttons | HTTP method | `"POST"`, `"GET"` |
| `data-wb-tone` | Badges, cards | Semantic tone | `"success"`, `"warning"`, `"danger"` |
| `data-wb-size` | Empty states | Display size | `"sm"`, `"lg"` |
| `data-wb-error-for` | Error links | Linked field ID | `"title"`, `"client_name"` |
| `data-wb-error-count` | Validation summary | Number of errors | `"3"` |
| `data-wb-variant` | Dialogs, timelines | Component variant | `"default"`, `"alert"`, `"compact"` |
| `data-wb-dialog-variant` | Dialogs | Dialog type | `"default"`, `"alert"`, `"confirm"` |
| `data-wb-has-errors` | Form sections | Error presence | `"true"`, `"false"` |
| `data-wb-subject` | Approval panels | Approval subject | `"Expense #123"` |
| `data-wb-href` | Clickable rows | Navigation target | `/admin/.../projects/123` |
| `data-wb-close-on-backdrop` | Dialogs | Backdrop behavior | `"true"`, `"false"` |

### 2.2 Attribute Usage Patterns

**Locating a component:**
```js
await page.locator('[data-wb-component="responsive-table"]').waitFor();
```

**Finding a specific entity:**
```js
const row = page.locator(
  '[data-wb-entity="pal.job-order"][data-wb-entity-id="123"]'
);
```

**Invoking an action:**
```js
await page.locator('[data-wb-action="approve"]').click();
```

**Asserting state:**
```js
await expect(row).toHaveAttribute('data-wb-row-state', 'ongoing');
```

**Asserting an action is NOT present (authorization test):**
```js
await expect(
  page.locator('[data-wb-action="delete"]')
).not.toBeVisible();
```

---

## 3. WorkbenchTestHarness (PHP)

The `WorkbenchTestHarness` class (`kernel/Testing/WorkbenchTestHarness.php`)
provides a reusable PHP test utility for integration-style tests.

### 3.1 Basic Usage

```php
use Ikabud\Kernel\Testing\WorkbenchTestHarness;

$harness = new WorkbenchTestHarness('http://palsystem.test');

$harness
    ->loginAs('admin', 'pAl123456')
    ->openPage('/admin/project-audit-ledger')
    ->assertComponent('app-shell')
    ->assertComponent('summary-card')
    ->assertComponent('responsive-table')
    ->assertSee('Dashboard')
    ->assertStatus(200);
```

### 3.2 Entity and Action Assertions

```php
$harness
    ->openPage('/admin/project-audit-ledger/projects')
    ->assertEntityPresent('pal_project', '1')
    ->assertActionPresent('edit')
    ->assertActionPresent('view');
```

### 3.3 Page Family Assertions

```php
$harness
    ->openPage('/admin/project-audit-ledger')
    ->assertPageFamily('dashboard');
```

### 3.4 Scenario Fixtures

```php
// Load a named scenario
$scenario = WorkbenchTestHarness::scenario('basic');
// Seeds: $scenario['entities']
// Expected: $scenario['expected']
```

### 3.5 Full Integration Test Example

```php
function testProjectDetailShowsCorrectStatus(): void
{
    $harness = new WorkbenchTestHarness();
    $harness->loginAs('admin', 'pAl123456');

    // Navigate to project detail
    $harness->openPage('/admin/project-audit-ledger/projects/1');
    $harness->assertStatus(200);
    $harness->assertComponent('detail-header');
    $harness->assertSee('Project');
    $harness->assertEntityPresent('pal_project', '1');
}
```

---

## 4. Browser Tests (Playwright)

### 4.1 Setup

Install Playwright in the repo root:

```bash
npm init playwright@latest
# Follow prompts to install browsers
```

### 4.2 Running Tests

```bash
# Run all workbench component tests
npx playwright test tests/browser/workbench/

# Run PAL workflow tests
npx playwright test tests/browser/modules/pal/

# Run a specific test file
npx playwright test tests/browser/workbench/app-shell.spec.js
```

### 4.3 Test Pattern

Every browser test follows this structure:

```js
test.describe('feature name', () => {

    // Authenticate before each test
    test.beforeEach(async ({ page }) => {
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector(
            '[data-wb-component="app-shell"]',
            { timeout: 10000 }
        );
    });

    test('description of what it tests', async ({ page }) => {
        // Arrange — navigate to the page
        await page.goto(`${APP_URL}/admin/project-audit-ledger/projects`);

        // Act — interact with elements
        await page.locator('[data-wb-action="view"]').first().click();

        // Assert — verify expected state
        await expect(
            page.locator('[data-wb-component="detail-header"]')
        ).toBeVisible();
    });
});
```

### 4.4 Selector Priorities

| Priority | Selector Type | Example |
|---|---|---|
| 1 (best) | `getByRole` + name | `page.getByRole('button', { name: 'Approve' })` |
| 2 | `data-wb-*` attribute | `[data-wb-action="approve"]` |
| 3 | Visible text | `page.getByText('Dashboard')` |
| 4 | CSS class | `.wb-nav-item.is-active` |

### 4.5 Playwright Config

Create `playwright.config.js` in the repo root:

```js
// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
    testDir: './tests/browser',
    timeout: 30000,
    use: {
        baseURL: process.env.APP_URL || 'http://palsystem.test',
        viewport: { width: 1280, height: 720 },
    },
});
```

---

## 5. Module UI Fixtures

Modules provide named test scenarios via `PalUiFixtures` (or equivalent).

### 5.1 Scenario Contract

Each scenario returns:

```php
[
    'name'     => 'basic',                         // Scenario identifier
    'entities' => [                                 // Seed data
        'project'  => ['id' => 1, 'title' => '...'],
        'expense'  => ['id' => 1, 'amount' => 5000],
    ],
    'expected' => [                                 // Expected outcomes
        'project_count'           => 1,
        'project_status'          => 'ongoing',
        'contract_amount'         => '₱500,000.00',
    ],
]
```

### 5.2 Available PAL Scenarios

| Scenario | Description | Entities |
|---|---|---|
| `empty` | No data | None |
| `basic` | Single project + client + expense | 1 project, 1 client, 1 expense |
| `pending-approval` | Expense awaiting approval | 1 project, 1 pending expense |
| `validation-failure` | Incomplete form submission | None |
| `permission-denied` | Role-based restrictions | 1 draft project |
| `workflow-conflict` | Already-approved expense | 1 project, 1 approved expense |
| `large-dataset` | 50 projects for pagination | 50 projects |
| `mobile-team-lead` | Team lead view data | 1 project |

### 5.3 Creating Fixtures for a New Module

```php
final class MyModuleUiFixtures
{
    public static function basic(): array
    {
        return [
            'name' => 'basic',
            'entities' => [
                'record' => [
                    'id' => 1,
                    'name' => 'Test Record',
                ],
            ],
            'expected' => [
                'record_count' => 1,
                'status' => 'active',
            ],
        ];
    }
}
```

Place in `modules/<module-id>/testing/MyModuleUiFixtures.php`.

---

## 6. Testing Layers

### Layer 1: Workbench Component Conformance

These tests run once for the shared profile. Every module benefits.

**File:** `tests/browser/workbench/`
- `app-shell.spec.js` — Sidebar, navigation, mobile drawer, user display
- `responsive-table.spec.js` — Columns, rows, data-label, entity attributes
- `component-conformance.spec.js` — Badges, cards, dialogs, forms, accessibility

### Layer 2: Module Rendering Contracts (PHP)

These test that module data is correctly passed to Workbench components.

**Location:** `tests/php/workbench/` or `tests/<module>/`

```php
// Example: Status tone mapping
$status = new PalStatusPresenter();
$this->assertEquals('success', $status->tone('approved'));
$this->assertEquals('warning', $status->tone('pending'));
$this->assertEquals('danger',  $status->tone('rejected'));
```

### Layer 3: Browser Interaction Tests (Playwright)

These test the rendered application.

**Location:** `tests/browser/modules/<module>/`

```js
test('approve action is visible to admin', async ({ page }) => {
    await page.goto(`${APP_URL}/admin/project-audit-ledger/expenses/1`);
    await expect(
        page.locator('[data-wb-action="approve"]')
    ).toBeVisible();
});
```

### Layer 4: Context and Workflow Tests (Playwright)

These test relationships across screens.

```js
test('approving expense updates project total', async ({ page }) => {
    // Navigate to expense
    await page.goto(`${APP_URL}/admin/project-audit-ledger/expenses/2`);
    await page.locator('[data-wb-action="approve"]').click();

    // Check project detail reflects the change
    await page.goto(`${APP_URL}/admin/project-audit-ledger/projects/2`);
    const total = await page.locator(
        '[data-wb-component="summary-card"] .wb-summary-card__value'
    ).first().textContent();
    expect(total).toContain('₱');
});
```

---

## 7. PAL Reference Implementation

PAL is the reference implementation for ARK Workbench testing.

### 7.1 Test Files

| File | Type | What it covers |
|---|---|---|
| `tests/browser/workbench/app-shell.spec.js` | Browser | Shell, nav, drawer, mobile |
| `tests/browser/workbench/responsive-table.spec.js` | Browser | Table columns, rows, data-label |
| `tests/browser/workbench/component-conformance.spec.js` | Browser | All component data-wb-* attrs, a11y |
| `tests/browser/modules/pal/pal-workflow.spec.js` | Browser | Dashboard, project list/detail, forms, approvals, cross-page nav |
| `tests/php/workbench/PalMoneyPresenterTest.php` | PHPUnit | Money formatting, minor units |
| `tests/php/workbench/PalStatusPresenterTest.php` | PHPUnit | Status → tone mapping |
| `tests/pal_integration_harness.php` | Custom PHP | View models, template files, handlers |
| `tests/pal_service_integration_test.php` | Custom PHP | DB queries, services, routes |
| `modules/project-audit-ledger/testing/PalUiFixtures.php` | PHP | Test scenario fixtures |

### 7.2 UI Contract (Proposed)

Each module should publish a UI contract describing page relationships:

```
modules/<module-id>/ui-contract.json
```

See the user's design for the full schema. Key properties:
- `pages` — route, entity, family, parent
- `relationships` — from/to/preserve mappings
- `actions` — available actions per status

---

## 8. Adding Tests to a New Module

### 8.1 Prerequisites

Your module must use ARK Workbench components:

- `workbench:app_shell` for shell layout
- `workbench:page_header` for page headers
- `workbench:responsive_table` for lists
- etc.

### 8.2 Steps

1. **Create `testing/UiFixtures.php`** with named scenarios
2. **Create `tests/browser/modules/<module>/`** with Playwright specs
3. **Add PHP tests** for status mapping, money formatting, view models
4. **Add browser tests** following the PAL pattern:
   - Login → navigate via sidebar
   - Verify component presence with `data-wb-*` selectors
   - Test CRUD workflows
   - Test role-based action visibility

### 8.3 Checklist

- [ ] All pages use `data-wb-component="app-shell"` on `<main>`
- [ ] Tables use `data-wb-component="responsive-table"` on container
- [ ] Table rows have `data-wb-entity-id` and `data-wb-href`
- [ ] Actions have `data-wb-action` and `data-wb-method`
- [ ] Status badges have `data-wb-tone`
- [ ] Validation summaries have `data-wb-error-count`
- [ ] Error links have `data-wb-error-for`
- [ ] Login flow is testable (form inputs have stable `name` attributes)
- [ ] Navigation is testable (sidebar items have stable text)

---

## 9. Troubleshooting

### Tests fail with "element not found"

1. Check the page rendered without PHP errors:
   ```bash
   tail -50 storage/logs/app.log
   tail -50 storage/logs/error.log
   ```
2. Verify `data-wb-*` attributes are present in the HTML source
3. Increase Playwright timeout: `{ timeout: 15000 }`
4. Try `page.waitForTimeout(2000)` after navigation

### Tests pass locally but fail in CI

1. Check viewport size — use `{ viewport: { width: 1280, height: 720 } }`
2. Ensure test database has the expected seed data
3. Check base URL configuration — use `APP_URL` env variable

### `data-wb-*` attribute missing

1. Verify the component file includes the attribute in its `.disyl` template
2. Clear compiled cache: `rm -rf storage/cache/compiled/*`
3. Add `?disyl_nocache=1` to URL to force recompilation

### PHP harness returns 302 instead of 200

1. Check credentials
2. Verify session cookie is being preserved between requests
3. Check if the page requires a CSRF token

---

## 10. Appendix: Component Attribute Reference

### `workbench:app_shell`

```
<main data-wb-component="app-shell" data-wb-page-family="...">
```

Selectors:
- `[data-wb-component="app-shell"]` — main content area
- `#wb-sidebar` — sidebar
- `.wb-nav-item` — navigation items
- `.wb-nav-item.is-active` — active navigation item
- `#wb-menu-btn` — mobile menu toggle
- `#wb-overlay` — mobile drawer overlay
- `.wb-bottom-nav` — mobile bottom navigation
- `.wb-sidebar-section` — collapsible nav section
- `.wb-sidebar-section--collapsed` — collapsed section
- `.wb-sidebar-section__trigger` — section toggle button

### `workbench:responsive_table`

```
<div data-wb-component="responsive-table">
    <table data-wb-entity="..." data-wb-state="...">
        <tr data-wb-entity-id="..." data-wb-row-state="..." data-wb-href="...">
```

Selectors:
- `[data-wb-component="responsive-table"]` — table wrapper
- `th[scope="col"]` — column headers
- `td[data-label]` — data cells with mobile label
- `tr[data-wb-entity-id]` — entity rows
- `tr[data-wb-href]` — clickable rows

### `workbench:page_header`

```
<div data-wb-component="page-header">
    <button data-wb-action="..." data-wb-method="POST">
```

Selectors:
- `[data-wb-component="page-header"]` — header container
- `[data-wb-action]` — action buttons/links

### `workbench:status_badge`

```
<span data-wb-component="status-badge" data-wb-tone="success">
```

### `workbench:summary_card`

```
<div data-wb-component="summary-card" data-wb-tone="neutral">
    <p class="wb-summary-card__label">...</p>
    <p class="wb-summary-card__value">...</p>
```

### `workbench:dialog`

```
<div data-wb-component="dialog" data-wb-dialog-variant="alert"
     role="dialog" aria-modal="true">
```

### `workbench:validation_summary`

```
<div data-wb-component="validation-summary" data-wb-error-count="3">
    <a href="#field_id" data-wb-error-for="field_id">
```

### `workbench:empty_state`

```
<div data-wb-component="empty-state" data-wb-size="lg">
```

### `workbench:form_section`

```
<fieldset data-wb-component="form-section" data-wb-has-errors="false">
```

### `workbench:approval_panel`

```
<div data-wb-component="approval-panel" data-wb-subject="...">
```

### `workbench:activity_timeline`

```
<div data-wb-component="activity-timeline" data-wb-variant="default">
```
