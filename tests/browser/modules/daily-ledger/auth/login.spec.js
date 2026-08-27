/**
 * Daily Ledger — Authentication Spec
 *
 * Validates login, logout, session persistence, password reset flow,
 * rate limiting, and role-based redirect behaviour.
 *
 * Env:
 *   TEST_BASE_URL   — target site (e.g. https://baronledger.test)
 *   TEST_ADMIN_USER — admin username
 *   TEST_ADMIN_PASS — admin password
 */

// @ts-check
var { test, expect } = require('../daily-ledger-adapter');

var APP_URL = process.env.TEST_BASE_URL || process.env.APP_URL || 'http://baronledger.test';

test.describe('Daily Ledger — Authentication', () => {

    // ════════════════════════════════════════════════════════════
    // 1. LOGIN PAGE
    // ════════════════════════════════════════════════════════════
    test.describe('Login Page', () => {

        test('login page renders: branding, form fields, submit button', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/login');
            await page.waitForLoadState('networkidle');

            // Branding — app name visible
            var appName = page.locator('h1, .app-name, [data-wb-component="page-header"] h1').first();
            await expect(appName, 'Login page must show app name').toBeVisible();

            // Form fields
            await expect(page.locator('input[name="username"]'), 'Username field required').toBeVisible();
            await expect(page.locator('input[name="full_name"]'), 'Full name field required').toBeVisible();
            await expect(page.locator('input[name="password"]'), 'Password field required').toBeVisible();
            await expect(page.locator('button[type="submit"]'), 'Submit button required').toBeVisible();

            // Forgot password link
            var forgotLink = page.locator('a[href*="forgot-password"]');
            var forgotCount = await forgotLink.count();
            expect(forgotCount, 'Forgot password link must be present').toBeGreaterThanOrEqual(1);
        });

        test('login with valid admin credentials redirects to admin dashboard', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/login');
            await page.fill('input[name="username"]', process.env.TEST_ADMIN_USER || 'Ledger-Admin');
            await page.fill('input[name="full_name"]', process.env.TEST_ADMIN_NAME || 'Test Admin');
            await page.fill('input[name="password"]', process.env.TEST_ADMIN_PASS || 'ledger123');
            await page.click('button[type="submit"]');

            // Admin role lands on dashboard
            await page.waitForURL('**/daily-ledger/admin/dashboard**', { timeout: 15000 });

            // Should be on an authenticated page
            await expect(page.locator('[data-wb-component="app-shell"]').first(),
                'Must render app shell after admin login').toBeVisible({ timeout: 10000 });

            // Top nav must show the entered full name beside the username
            await expect(page.locator('header'), 'Top nav must show entered full name')
                .toContainText(process.env.TEST_ADMIN_NAME || 'Test Admin');
            await expect(page.locator('header'), 'Top nav must show the username')
                .toContainText(process.env.TEST_ADMIN_USER || 'Ledger-Admin');
        });

        test('login with invalid credentials shows error message', async ({ page, integrity }) => {
            await page.goto(APP_URL + '/daily-ledger/login');
            await page.fill('input[name="username"]', 'nonexistent-user');
            await page.fill('input[name="password"]', 'wrong-password');
            await page.click('button[type="submit"]');

            // Should stay on login page or show error
            var errorEl = page.locator('#login-error, .alert-error, [role="alert"]').first();
            try {
                await errorEl.waitFor({ state: 'visible', timeout: 5000 });
                var errorText = await errorEl.textContent();
                expect(errorText?.length || 0, 'Error message must have content').toBeGreaterThan(0);
            } catch (e) {
                // Some implementations redirect back to login without inline error
                var currentUrl = page.url();
                expect(currentUrl, 'Must stay on or return to login page').toMatch(/login/);
                integrity.gap('Login error message: inline error element not found; page-level redirect used instead');
            }
        });

        test('empty form submission shows validation', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/login');
            await page.click('button[type="submit"]');

            // HTML5 validation or inline error
            var usernameInput = page.locator('input[name="username"]');
            var validity = await usernameInput.evaluate(function (el) { return el.validity.valid; });
            // If browser validation intercepted, field should still be focused on login page
            var currentUrl = page.url();
            expect(currentUrl, 'Must remain on login page after empty submit').toMatch(/login/);
        });
    });

    // ════════════════════════════════════════════════════════════
    // 2. SESSION PERSISTENCE
    // ════════════════════════════════════════════════════════════
    test.describe('Session Persistence', () => {

        test('authenticated session survives page reload on admin dashboard', async ({ page, shell }) => {
            await page.goto(APP_URL + '/daily-ledger/admin/dashboard');
            await page.waitForLoadState('networkidle');

            await shell.expectVisible();
            await expect(page.locator('[data-wb-component="app-shell"]').first(),
                'App shell must render on admin dashboard').toBeVisible();

            // Reload and verify session persists
            await page.reload();
            await page.waitForLoadState('networkidle');
            await shell.expectVisible();
        });

        test('authenticated session persists across admin pages', async ({ page, shell }) => {
            await page.goto(APP_URL + '/daily-ledger/admin/dashboard');
            await page.waitForLoadState('networkidle');
            await shell.expectVisible();

            // Navigate to another admin page
            await page.goto(APP_URL + '/daily-ledger/admin/sales');
            await page.waitForLoadState('networkidle');
            await shell.expectVisible();

            // Navigate to another
            await page.goto(APP_URL + '/daily-ledger/admin/products');
            await page.waitForLoadState('networkidle');
            await shell.expectVisible();
        });
    });

    // ════════════════════════════════════════════════════════════
    // 3. LOGOUT
    // ════════════════════════════════════════════════════════════
    test.describe('Logout', () => {

        test('logout redirects to login page', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/admin/dashboard');
            await page.waitForLoadState('networkidle');

            // Logout
            await page.goto(APP_URL + '/daily-ledger/logout');
            await page.waitForLoadState('networkidle');

            // Should redirect to login
            var currentUrl = page.url();
            expect(currentUrl, 'Logout must redirect to login').toMatch(/login/);
        });

        test('after logout, protected pages redirect to login', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/logout');
            await page.waitForLoadState('networkidle');

            await page.goto(APP_URL + '/daily-ledger/admin/dashboard');
            await page.waitForLoadState('networkidle');

            var currentUrl = page.url();
            expect(currentUrl, 'Must redirect to login after logout').toMatch(/login/);
        });
    });

    // ════════════════════════════════════════════════════════════
    // 4. FORGOT / RESET PASSWORD PAGES
    // ════════════════════════════════════════════════════════════
    test.describe('Password Reset Pages', () => {

        test('forgot password page renders form', async ({ page }) => {
            await page.goto(APP_URL + '/daily-ledger/forgot-password');
            await page.waitForLoadState('networkidle');

            await expect(page.locator('input[name="username"], input[name="email"], input[type="text"]').first(),
                'Forgot password form must have identity input').toBeVisible();
            await expect(page.locator('button[type="submit"]'),
                'Forgot password form must have submit button').toBeVisible();
        });

        test('reset password page renders form with token', async ({ page }) => {
            // Visit with a dummy token — should render the form (token validation happens server-side)
            await page.goto(APP_URL + '/daily-ledger/reset-password?token=invalid-test-token');
            await page.waitForLoadState('networkidle');

            // Should show the reset form or an error
            var hasForm = await page.locator('input[type="password"]').count();
            var hasError = await page.locator('.alert-error, [role="alert"], #login-error').count();
            expect(hasForm + hasError, 'Reset page must show form or error message').toBeGreaterThanOrEqual(1);
        });
    });

    // ════════════════════════════════════════════════════════════
    // 5. DOCUMENTED GAPS
    // ════════════════════════════════════════════════════════════
    test.describe('Documented Gaps', () => {

        test('rate limiting gaps documented', async ({ integrity }) => {
            integrity.gap('Rate-limit: login brute-force protection — requires sustained request simulation');
            integrity.gap('Rate-limit: forgot-password throttling — requires cache inspection');
            integrity.gap('Rate-limit: reset-password throttling — requires cache inspection');
        });

        test('multi-role login redirects documented', async ({ integrity }) => {
            integrity.gap('Cashier role: login redirects to /daily-ledger/ledger — needs cashier credentials');
            integrity.gap('Production-in-charge role: login redirects to /daily-ledger/admin/production-output — needs PIC credentials');
            integrity.gap('Supervisor role: multiple landing pages — needs supervisor credentials');
        });
    });
});
