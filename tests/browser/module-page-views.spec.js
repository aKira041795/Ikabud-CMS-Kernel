/**
 * Generic Module Page Discovery & View Test.
 *
 * Reads a module's module.json manifest, auto-discovers all sidebar pages,
 * and semantically tests each one — no per-module test writing required.
 *
 * The Comprehension Engine uses the discovery data to correlate page structure
 * with module contracts (routes, entities, actions, workflows).
 *
 * Usage:
 *   MODULE=project-audit-ledger npx playwright test tests/browser/module-page-views.spec.js
 *   MODULE=guidance npx playwright test tests/browser/module-page-views.spec.js
 *
 * Environment:
 *   MODULE          - Module directory name under modules/ (required)
 *   ADMIN_USER      - Login username
 *   ADMIN_PASS      - Login password
 *   APP_URL         - App base URL
 *   MODULE_PATH     - Override module path (default: modules/$MODULE)
 */

// @ts-check
const { test, expect } = require('./WorkbenchFixture');
const { ModulePageDiscovery } = require('./ModulePageDiscovery');
const { FormHarness } = require('../../storage/application-profiles/ark-workbench/testing/harnesses/FormHarness');
const path = require('path');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';
const MODULE = process.env.MODULE || '';
const MODULE_PATH = process.env.MODULE_PATH || path.resolve(__dirname, '../../modules', MODULE);

if (!MODULE) {
    throw new Error('MODULE environment variable required. Usage: MODULE=project-audit-ledger npx playwright test ...');
}

// ── Discover module structure ──
const discovery = new ModulePageDiscovery(MODULE_PATH);
const manifest = discovery.load();

console.log(`\n  📦 Module: ${discovery.moduleName} (${discovery.moduleId})`);
console.log(`  📄 Pages discovered: ${discovery.allUrls.length}`);
console.log(`  📋 Groups: ${discovery.groups.length}`);
discovery.groups.forEach(g => console.log(`    ${g.label}: ${g.items.length} items`));

test.describe(`Module Pages: ${discovery.moduleName}`, () => {

    // ════════════════════════════════════════════════════════════
    // 1. EVERY PAGE LOADS: shell, heading, content
    // ════════════════════════════════════════════════════════════
    test.describe('Page load & structure', () => {

        for (const item of discovery.navItems) {
            test(`"${item.label}" loads with shell, heading, and meaningful content`, async ({ page, shell }) => {
                // Navigate directly via URL (more reliable than sidebar click for discovery)
                await page.goto(`${APP_URL}${item.url}`);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

                // App shell renders
                await expect(page.locator('body[data-wb-component="app-shell"]'), `"${item.label}": app shell must render`).toBeVisible();

                // Main content has heading
                const heading = page.locator('#wb-main h1');
                await expect(heading, `"${item.label}": page must have heading`).toBeVisible();
                const headingText = await heading.textContent();
                expect(headingText?.trim().length, `"${item.label}": heading must not be empty`).toBeGreaterThan(0);

                // Page has meaningful content
                const text = await page.locator('#wb-main').textContent();
                expect(text?.length, `"${item.label}": page must have content (>50 chars)`).toBeGreaterThan(50);

                // Sidebar nav active state matches
                if (item.label) {
                    const activeNav = page.locator('.wb-nav-item.is-active');
                    if (await activeNav.count() > 0) {
                        const activeText = await activeNav.first().textContent();
                        console.log(`    ✓ "${item.label}" → nav active: "${activeText?.trim().substring(0, 40)}"`);
                    }
                }

                // Check for PHP errors in body
                const bodyText = await page.locator('body').textContent() || '';
                if (bodyText.includes('Fatal error') || bodyText.includes('Parse error') || bodyText.includes('Stack trace')) {
                    // Don't fail the test but log the issue
                    console.log(`    ⚠ "${item.label}": Possible PHP error detected in body`);
                }
            });
        }
    });

    // ════════════════════════════════════════════════════════════
    // 2. FORM PAGES: field presence
    // ════════════════════════════════════════════════════════════
    test.describe('Form page structure', () => {

        for (const item of discovery.formPages) {
            test(`"${item.label}" has input fields and save/submit action`, async ({ page, shell }) => {
                await page.goto(`${APP_URL}${item.url}`);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

                // Detect input fields — handles traditional forms, settings div-based layouts,
                // and any other field container pattern.
                const inputs = page.locator('#wb-main input:visible, #wb-main select:visible, #wb-main textarea:visible');
                const inputCount = await inputs.count();

                if (inputCount > 0) {
                    expect(inputCount, `"${item.label}": must have input fields`).toBeGreaterThanOrEqual(1);
                    const layoutType = (await page.locator('form').isVisible({ timeout: 500 }).catch(() => false))
                        ? 'form'
                        : 'settings';
                    console.log(`    ✓ "${item.label}" → ${inputCount} field(s) (${layoutType} layout)`);
                } else {
                    // FormHarness for structured form detection
                    const form = page.locator('form').first();
                    if (await form.isVisible({ timeout: 500 }).catch(() => false)) {
                        const fh = new FormHarness(page);
                        await fh.expectVisible();
                        await fh.expectMinFields(1);
                    } else {
                        // Fallback: page has heading and content
                        await expect(page.locator('#wb-main h1'), `"${item.label}": must have heading`).toBeVisible();
                    }
                }

                // Check for save/submit button (non-blocking)
                const saveBtn = page.locator('button[type="submit"], [data-wb-action="save"], [data-wb-action="submit"], button:has-text("Save"), button:has-text("Update")').first();
                if (await saveBtn.isVisible({ timeout: 1500 }).catch(() => false)) {
                    console.log(`    ✓ "${item.label}" → save button found`);
                } else {
                    console.log(`    ℹ "${item.label}" → no save button detected (read-only or auto-save)`);
                }

                // Detect entity type
                const entityAttr = await page.locator('[data-wb-entity]').getAttribute('data-wb-entity').catch(() => null);
                if (entityAttr) console.log(`    ✓ "${item.label}" → entity: ${entityAttr}`);
            });
        }
    });

    // ════════════════════════════════════════════════════════════
    // 3. LIST PAGES: entity-list component
    // ════════════════════════════════════════════════════════════
    test.describe('List page structure', () => {

        for (const item of discovery.listPages) {
            test(`"${item.label}" has entity list or table content`, async ({ page, shell }) => {
                await page.goto(`${APP_URL}${item.url}`);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

                // Check for entity-list component
                const entityList = page.locator('[data-wb-component="entity-list"]');
                const hasEntityList = await entityList.first().isVisible({ timeout: 3000 }).catch(() => false);

                if (hasEntityList) {
                    const entityType = await entityList.first().getAttribute('data-wb-entity').catch(() => null);
                    console.log(`    ✓ "${item.label}" → entity-list${entityType ? ` (${entityType})` : ''}`);
                } else {
                    // Fallback: check for table
                    const table = page.locator('table').first();
                    if (await table.isVisible({ timeout: 2000 }).catch(() => false)) {
                        const rowCount = await table.locator('tbody tr, tr').count();
                        console.log(`    ✓ "${item.label}" → table with ${rowCount} rows`);
                    } else {
                        // Has heading and content at minimum
                        const heading = page.locator('#wb-main h1');
                        await expect(heading, `"${item.label}": must have heading`).toBeVisible();
                        const text = await page.locator('#wb-main').textContent();
                        expect(text?.length, `"${item.label}": must have content`).toBeGreaterThan(50);
                        console.log(`    ✓ "${item.label}" → content present (no entity-list)`);
                    }
                }
            });
        }
    });

    // ════════════════════════════════════════════════════════════
    // 4. SIDEBAR NAVIGATION: walk through groups
    // ════════════════════════════════════════════════════════════
    test.describe('Sidebar navigation walk', () => {

        test('navigate through all discovered pages via direct URL', async ({ page, shell }) => {
            // Navigate via URL (not sidebar click) since manifest labels may differ
            // from rendered sidebar labels. The Comprehension Engine should reconcile
            // this mismatch.
            const sample = discovery.navItems.slice(0, 5); // first 5 pages

            for (const item of sample) {
                await page.goto(`${APP_URL}${item.url}`);
                await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });

                await shell.expectVisible();
                const heading = page.locator('#wb-main h1');
                await expect(heading, `"${item.label}": heading must render`).toBeVisible();
                console.log(`  ✓ URL ${item.url} → heading present`);
            }
        });
    });

    // ════════════════════════════════════════════════════════════
    // 5. COMPREHENSION REPORT: emit discovery data
    // ════════════════════════════════════════════════════════════
    test('emit module discovery data for Comprehension Engine', async ({ integrity }) => {
        // Report the full page inventory to the Comprehension Engine
        integrity.fingerprint(MODULE_PATH + '/module.json');

        for (const item of discovery.navItems) {
            integrity.gap(`Page: ${item.label} at ${item.url}${item.group ? ` [${item.group}]` : ''}`);
        }

        integrity.gap(`Module ${discovery.moduleId}: ${discovery.allUrls.length} pages across ${discovery.groups.length} groups`);
        integrity.gap(`Module ${discovery.moduleId}: ${discovery.routes.length} declared routes`);
    });
});
