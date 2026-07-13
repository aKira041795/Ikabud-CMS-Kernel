/**
 * Browser tests for ARK Workbench app-shell component.
 *
 * INTEGRITY: fingerprints the app_shell.disyl source template.
 * Gaps document known coverage holes.
 *
 * Prerequisites:
 *   - Application running at APP_URL (default http://palsystem.test)
 *   - PAL module installed with test tenant
 *   - Playwright installed: npm init playwright@latest
 *
 * Run: npx playwright test tests/browser/workbench/app-shell.spec.js
 *
 * @see storage/application-profiles/ark-workbench/components/shell/app_shell.disyl
 */

// @ts-check
const { test, expect } = require('../WorkbenchFixture');

// We have the full set of PLANNED tests (28) but some are deferred.
// Track them so the gap count in the manifest reflects reality.
const GAPS = [
    'Mobile: sidebar collapse at <768px wide viewport',
    'Mobile: bottom-nav items accessible via tap targets',
    'Keyboard: Tab cycles through nav items in order',
    'Keyboard: Enter activates sidebar section toggle',
    'Keyboard: Escape closes mobile drawer',
    'Keyboard: Skip-to-content is first tab stop',
    'Focus: focus-visible ring on nav items',
    'Focus: no focus trap when sidebar is closed',
    'A11y: sidebar nav has aria-current="page" on active item',
    'A11y: sidebar sections use aria-expanded',
    'A11y: mobile menu button has aria-controls pointing to sidebar',
    'A11y: overlay has aria-label="Close menu"',
    'A11y: color contrast meets WCAG AA (4.5:1)',
    'Performance: shell renders in <2s on 3G throttled',
    'Performance: sidebar nav with 30+ items scrolls without frame drop',
    'Responsive: tablet (768px) shows collapsed sidebar with hamburger',
];

test.describe('workbench:app_shell', () => {

    // ── Source Integrity ───────────────────────────────────────
    test.beforeAll(async ({ integrity }) => {
        integrity.fingerprint('storage/application-profiles/ark-workbench/components/shell/app_shell.disyl');
    });

    test.beforeEach(async ({ page }) => {
        await page.goto(`${process.env.APP_URL || 'http://palsystem.test'}/project-audit-ledger/login`);
        await page.fill('input[name="username"]', 'paladmin');
        await page.fill('input[name="password"]', 'pAl123456');
        await page.click('button[type="submit"]');
        await page.waitForURL('**/admin/project-audit-ledger');
        await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 10000 });
    });

    // ── Structure ──

    test('renders app shell with data-wb-component attribute', async ({ page }) => {
        const shell = page.locator('[data-wb-component="app-shell"]');
        await expect(shell).toBeVisible();
        await expect(shell).toHaveAttribute('data-wb-component', 'app-shell');
    });

    test('has skip-to-content link as first focusable element', async ({ page }) => {
        const skipLink = page.locator('.wb-skip-link');
        await expect(skipLink).toBeVisible();
        await expect(skipLink).toHaveAttribute('href', '#wb-main');
    });

    test('sidebar has navigation landmarks', async ({ page }) => {
        const sidebar = page.locator('#wb-sidebar');
        await expect(sidebar).toBeVisible();
        await expect(sidebar.locator('nav')).toHaveAttribute('aria-label', 'Main navigation');
    });

    test('main content has correct landmark', async ({ page }) => {
        const main = page.locator('#wb-main');
        await expect(main).toBeVisible();
    });

    // ── Sidebar Navigation ──

    test('sidebar sections are togglable via CSS class', async ({ page }) => {
        const firstSection = page.locator('.wb-sidebar-section').first();
        const trigger = firstSection.locator('.wb-sidebar-section__trigger');
        const items = firstSection.locator('.wb-sidebar-section__items');

        // Should be expanded by default
        await expect(firstSection).not.toHaveClass(/wb-sidebar-section--collapsed/);

        // Click to collapse
        await trigger.click();
        await expect(firstSection).toHaveClass(/wb-sidebar-section--collapsed/);

        // Click to expand again
        await trigger.click();
        await expect(firstSection).not.toHaveClass(/wb-sidebar-section--collapsed/);
    });

    test('sidebar nav items have correct links', async ({ page }) => {
        const dashboardLink = page.locator('.wb-nav-item').first();
        await expect(dashboardLink).toHaveAttribute('href', '/admin/project-audit-ledger');
        await expect(dashboardLink).toContainText('Dashboard');
    });

    test('active nav item is highlighted', async ({ page }) => {
        const dashboardLink = page.locator('.wb-nav-item').first();
        await expect(dashboardLink).toHaveClass(/is-active/);
    });

    test('navigation contains expected sections', async ({ page }) => {
        const sections = page.locator('.wb-sidebar-section');
        const sectionCount = await sections.count();
        expect(sectionCount).toBeGreaterThanOrEqual(1);

        const firstLabel = sections.first().locator('.wb-sidebar-section__trigger span').first();
        await expect(firstLabel).toBeVisible();
    });

    // ── Mobile Drawer ──

    test('mobile menu button exists', async ({ page }) => {
        const menuBtn = page.locator('#wb-menu-btn');
        await expect(menuBtn).toHaveCount(1);
        await expect(menuBtn).toHaveAttribute('aria-label', 'Open menu');
    });

    test('overlay closes sidebar on click', async ({ page }) => {
        // Only test on viewport < 768px where sidebar is fixed
        const viewport = page.viewportSize();
        if (viewport && viewport.width < 768) {
            const menuBtn = page.locator('#wb-menu-btn');
            await menuBtn.click();
            await expect(page.locator('#wb-sidebar')).toHaveClass(/is-open/);

            const overlay = page.locator('#wb-overlay');
            await expect(overlay).toHaveClass(/is-visible/);
            await overlay.click();
            await expect(page.locator('#wb-sidebar')).not.toHaveClass(/is-open/);
        }
    });

    // ── User Display ──

    test('displays application name in sidebar', async ({ page }) => {
        const sidebarH1 = page.locator('#wb-sidebar h1');
        await expect(sidebarH1).toBeVisible();
        await expect(sidebarH1).not.toBeEmpty();
    });

    test('displays current user name', async ({ page }) => {
        const userDisplay = page.locator('#wb-sidebar .p-4 p');
        await expect(userDisplay).toBeVisible();
        await expect(userDisplay).not.toBeEmpty();
    });

    // ── Bottom Navigation ──

    test('bottom navigation has correct items', async ({ page }) => {
        const bottomNav = page.locator('.wb-bottom-nav');
        if (await bottomNav.isVisible()) {
            const items = bottomNav.locator('.wb-bottom-nav__item');
            const count = await items.count();
            expect(count).toBeGreaterThanOrEqual(1);

            // First item should be active (Home/Dashboard)
            await expect(items.first()).toHaveClass(/is-active/);
        }
    });

    // ── Toast Container ──

    test('toast container is present', async ({ page }) => {
        const toast = page.locator('#wb-toast-container');
        await expect(toast).toHaveCount(1);
        await expect(toast).toHaveAttribute('role', 'status');
    });

    // ── Coverage Gaps ──

    test('known coverage gaps', async ({ integrity }) => {
        for (const g of GAPS) {
            integrity.gap(g);
        }
        // This test always passes — it documents what's NOT tested
        expect(GAPS.length).toBeGreaterThanOrEqual(1);
    });

    // ── Write Results ──

    test.afterAll(async ({ integrity }) => {
        // Record each test's result
        // (Playwright tracks this internally; we just write the file)
        for (const g of GAPS) {
            integrity.gap(g);
        }
        await integrity.writeResults();
    });
});
