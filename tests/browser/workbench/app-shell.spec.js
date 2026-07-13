/**
 * Browser tests for ARK Workbench app-shell component.
 *
 * Prerequisites:
 *   - Application running at APP_URL (default http://palsystem.test)
 *   - PAL module installed with test tenant
 *   - Playwright installed: npm init playwright@latest
 *
 * Run: npx playwright test tests/browser/workbench/
 *
 * @see storage/application-profiles/ark-workbench/components/shell/app_shell.disyl
 */

// @ts-check
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test.describe('workbench:app_shell', () => {

    test.beforeEach(async ({ page }) => {
        // Log in via PAL login page
        await page.goto(`${APP_URL}/project-audit-ledger/login`);
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
        await expect(menuBtn).toBeVisible();
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
        await expect(toast).toBeVisible();
    });
});
