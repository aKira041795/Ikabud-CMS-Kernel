/**
 * Browser tests for Guidance Monitoring Dashboard
 *
 * Uses GuidanceAdapter for tenant-specific auth.
 *
 * Run: npx playwright test tests/browser/modules/guidance/
 */

// @ts-check
const { test, expect } = require('../../GuidanceAdapter');

var GAPS = [
    'Sidebar navigation items reflect guidance routes',
    'Summary cards display correct counts',
    'Quick-action buttons navigate to create pages',
    'Mobile responsive layout at 375px',
    'Page title matches nav item label',
];

test.describe('guidance:dashboard', function() {

    test.beforeAll(async function({ integrity }) {
        integrity.fingerprint('modules/guidance/helpers.php');
        GAPS.forEach(function(g) { integrity.gap(g); });
    });

    test.afterEach(async function({ integrity }, testInfo) {
        integrity.record(testInfo.title, testInfo.status === 'passed', testInfo.error ? testInfo.error.message : '');
    });

    test.afterAll(async function({ integrity }) {
        await integrity.writeResults();
    });

    test('renders with app shell', async function({ page, shell }) {
        await shell.expectVisible();
    });

    test('dashboard page title is visible', async function({ page }) {
        await expect(page.locator('h1')).toBeVisible();
    });
});
