/**
 * Browser tests for Guidance Monitoring Dashboard
 *
 * Gaps/fingerprints via integrity annotations.
 * Pass/fail via WorkbenchReporter — no afterEach needed.
 */

// @ts-check
var { test, expect } = require('../../../GuidanceAdapter');

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

    test('renders with app shell', async function({ page, shell }) {
        await shell.expectVisible();
    });

    test('dashboard page title is visible', async function({ page }) {
        await expect(page.locator('h1')).toBeVisible();
    });
});
