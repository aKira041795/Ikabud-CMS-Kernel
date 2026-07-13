/**
 * Browser tests for ARK Workbench app-shell component.
 *
 * INTEGRITY: fingerprints the app_shell.disyl source template.
 * Gaps documented once (not duplicated). Auto-recorded pass/fail.
 *
 * Run: npx playwright test tests/browser/workbench/app-shell.spec.js
 */

// @ts-check
const { test, expect } = require('../WorkbenchFixture');

var GAPS = [
    'Mobile: sidebar collapse at <768px wide viewport',
    'Mobile: bottom-nav items accessible via tap targets',
    'Keyboard: Tab cycles through nav items in order',
    'Keyboard: Enter activates sidebar section toggle',
    'Keyboard: Escape closes mobile drawer',
    'Keyboard: Skip-to-content first tab stop',
    'Focus: focus-visible ring on nav items',
    'Focus: no focus trap when sidebar closed',
    'A11y: aria-current="page" on active nav item',
    'A11y: sidebar sections use aria-expanded',
    'A11y: mobile menu button aria-controls sidebar',
    'A11y: overlay has aria-label="Close menu"',
    'A11y: color contrast WCAG AA 4.5:1',
    'Perf: shell renders <2s on 3G throttled',
    'Perf: 30+ nav items scroll without frame drop',
    'Responsive: tablet 768px collapsed sidebar',
];

test.describe('workbench:app_shell', function() {

    test.beforeAll(async function({ integrity }) {
        integrity.fingerprint('storage/application-profiles/ark-workbench/components/shell/app_shell.disyl');
        GAPS.forEach(function(g) { integrity.gap(g); });
    });

    test.afterEach(async function({ integrity }, testInfo) {
        integrity.record(testInfo.title, testInfo.status === 'passed', testInfo.error ? testInfo.error.message : '');
    });

    test.afterAll(async function({ integrity }) {
        await integrity.writeResults();
    });

    test('renders app shell with data-wb-component attribute', async function({ page }) {
        var shell = page.locator('[data-wb-component="app-shell"]');
        await expect(shell).toBeVisible();
        await expect(shell).toHaveAttribute('data-wb-component', 'app-shell');
    });

    test('has skip-to-content link', async function({ page }) {
        var skipLink = page.locator('[data-wb-role="skip-link"]');
        await expect(skipLink).toBeVisible();
        await expect(skipLink).toHaveAttribute('href', '#wb-main');
    });

    test('sidebar has navigation landmarks', async function({ page }) {
        var sidebar = page.locator('#wb-sidebar');
        await expect(sidebar).toBeVisible();
        await expect(sidebar.locator('nav')).toHaveAttribute('aria-label', 'Main navigation');
    });

    test('main content landmark', async function({ page }) {
        await expect(page.locator('#wb-main')).toBeVisible();
    });

    test('sidebar nav items have links', async function({ page }) {
        var link = page.locator('[data-wb-role="nav-item"]').first();
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('href');
    });

    test('active nav item highlighted', async function({ page }) {
        var active = page.locator('[data-wb-role="nav-item"].is-active').first();
        await expect(active).toBeVisible();
    });

    test('navigation sections exist', async function({ page }) {
        var sections = page.locator('[data-wb-role="sidebar-section"]');
        expect(await sections.count()).toBeGreaterThanOrEqual(1);
        await expect(sections.first()).toBeVisible();
    });

    test('mobile menu button exists', async function({ page }) {
        var btn = page.locator('#wb-menu-btn');
        await expect(btn).toHaveCount(1);
        await expect(btn).toHaveAttribute('aria-label', 'Open menu');
    });

    test('displays app name', async function({ page }) {
        var h1 = page.locator('#wb-sidebar h1');
        await expect(h1).toBeVisible();
        await expect(h1).not.toBeEmpty();
    });

    test('displays current user name', async function({ page }) {
        var user = page.locator('[data-wb-role="current-user"]');
        await expect(user).toBeVisible();
        await expect(user).not.toBeEmpty();
    });

    test('toast container present', async function({ page }) {
        var toast = page.locator('#wb-toast-container');
        await expect(toast).toHaveCount(1);
        await expect(toast).toHaveAttribute('role', 'status');
    });
});
