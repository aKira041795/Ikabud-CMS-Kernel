// @ts-check
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const fixture = path.join(__dirname, '..', 'daily-ledger', 'daily_ledger_overview_browser_fixture.php');

function runFixture(mode) {
    execFileSync('php', [fixture, mode], { cwd: path.join(__dirname, '..', '..'), stdio: 'inherit' });
}

function collectBrowserErrors(page) {
    const errors = { console: [], page: [], requestFailed: [], server: [] };
    page.on('console', (message) => {
        if (message.type() === 'error') {
            errors.console.push(message.text());
        }
    });
    page.on('pageerror', (error) => errors.page.push(String(error)));
    page.on('requestfailed', (request) => {
        const failure = request.failure();
        errors.requestFailed.push(`${request.method()} ${request.url()} ${failure ? failure.errorText : ''}`.trim());
    });
    page.on('response', (response) => {
        if (response.status() >= 500) {
            errors.server.push(`${response.status()} ${response.url()}`);
        }
    });
    return errors;
}

function expectNoBrowserErrors(errors) {
    expect(errors.console, 'console.error messages').toEqual([]);
    expect(errors.page, 'uncaught page errors').toEqual([]);
    expect(errors.requestFailed, 'failed network requests').toEqual([]);
    expect(errors.server, '5xx responses').toEqual([]);
}

async function login(page, username, fullName) {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill(username);
    await page.getByLabel('Full Name').fill(fullName);
    await page.getByLabel('Password').fill('BrowserOverview!2031');
    await page.getByRole('button', { name: 'Sign In' }).click();
}

test.beforeEach(() => {
    runFixture('setup');
});

test.afterEach(() => {
    runFixture('cleanup');
});

test('authenticated viewer sees amount-ranked products, Pareto, configurable net sales and forecast', async ({ page }) => {
    const errors = collectBrowserErrors(page);

    await login(page, 'browser-overview-viewer', 'Browser Overview Viewer');
    await page.waitForURL(/\/daily-ledger\/admin\/overview/);

    await page.goto('/daily-ledger/admin/overview?date_from=2031-05-01&date_to=2031-05-02&branch_id=&forecast_window=14&forecast_period=daily');
    await expect(page.getByRole('heading', { name: 'Business Overview' })).toBeVisible();
    await expect(page.getByText('Gross Sales', { exact: true })).toBeVisible();
    await expect(page.getByText('PHP 475.00').first()).toBeVisible();
    await expect(page.getByText('Net Sales', { exact: true })).toBeVisible();
    await expect(page.getByText('PHP 427.50')).toBeVisible();
    await expect(page.getByText('10.00% deduction from PHP 475.00 gross')).toBeVisible();

    const topCard = page.locator('.card', { hasText: 'Top Saleable Products' }).first();
    const firstTopRow = topCard.locator('tbody tr').first();
    await expect(firstTopRow).toContainText('Overview P3');
    await expect(firstTopRow).toContainText('PHP 300.00');

    const branchCard = page.locator('.card', { hasText: 'Top Products by Branch' }).first();
    await expect(branchCard).toContainText('Overview Alpha');
    await expect(branchCard).toContainText('Overview Beta');
    await expect(branchCard).toContainText('Overview P3');

    const paretoCard = page.locator('.card', { hasText: 'Pareto 80/20' }).first();
    await expect(paretoCard).toContainText('2 of 3 products drive');
    await expect(paretoCard).toContainText('84.2%');
    await expect(paretoCard).toContainText('Overview P3');
    await expect(paretoCard).toContainText('Overview P1');

    const forecastCard = page.locator('.card', { hasText: 'Production Forecast' }).first();
    await expect(forecastCard).toContainText('history 2031-04-19 to 2031-05-02');
    await expect(forecastCard).toContainText('14-day window');

    // Scope to a single branch and switch the forecast period in place.
    await page.goto('/daily-ledger/admin/overview?date_from=2031-05-01&date_to=2031-05-02&branch_id=99351&forecast_window=14&forecast_period=weekly');
    await expect(page.getByText('PHP 150.00').first()).toBeVisible();

    const weeklyForecast = page.locator('.card', { hasText: 'Production Forecast' }).first();
    await expect(weeklyForecast).toContainText('weekly Projection');
    await expect(weeklyForecast.locator('tbody tr', { hasText: 'Overview P2' })).toContainText('70');
    await expect(weeklyForecast.locator('tbody tr', { hasText: 'Overview P1' })).toContainText('35');

    await page.selectOption('select[name="forecast_period"]', 'monthly');
    await page.getByRole('button', { name: 'Apply Filters' }).click();
    await page.waitForURL(/forecast_period=monthly/);

    // Filter persistence: date/branch/window stay selected after the in-place load.
    await expect(page.locator('input[name="date_from"]')).toHaveValue('2031-05-01');
    await expect(page.locator('input[name="date_to"]')).toHaveValue('2031-05-02');
    await expect(page.locator('select[name="branch_id"]')).toHaveValue('99351');
    await expect(page.locator('input[name="forecast_window"]')).toHaveValue('14');
    await expect(page.locator('select[name="forecast_period"]')).toHaveValue('monthly');

    const monthlyForecast = page.locator('.card', { hasText: 'Production Forecast' }).first();
    await expect(monthlyForecast).toContainText('monthly Projection');
    await expect(monthlyForecast.locator('tbody tr', { hasText: 'Overview P2' })).toContainText('300');
    await expect(monthlyForecast.locator('tbody tr', { hasText: 'Overview P1' })).toContainText('150');

    expectNoBrowserErrors(errors);
});

test('viewer has no mutation controls and cashier cannot open the overview', async ({ page }) => {
    const errors = collectBrowserErrors(page);
    await login(page, 'browser-overview-viewer', 'Browser Overview Viewer');
    await page.waitForURL(/\/daily-ledger\/admin\/overview/);
    await page.goto('/daily-ledger/admin/overview?date_from=2031-05-01&date_to=2031-05-02&branch_id=99351');
    await expect(page.getByRole('heading', { name: 'Business Overview' })).toBeVisible();
    expect(await page.locator('form[method="post" i]').count()).toBe(0);
    expect(await page.locator('[name="net_sales_deduction_percent"]').count()).toBe(0);
    expect(await page.getByRole('button', { name: /save|delete|submit/i }).count()).toBe(0);

    // Cashier role is rejected by the overview role gate. Clear the viewer
    // session first (the page shares its browser context).
    await page.context().clearCookies();
    await login(page, 'browser-overview-cashier', 'Browser Overview Cashier');
    await page.waitForURL(/\/daily-ledger\/ledger/);
    await page.goto('/daily-ledger/admin/overview');
    await expect(page).toHaveURL(/\/daily-ledger\/(ledger|login)/);
    await expect(page.getByRole('heading', { name: 'Business Overview' })).toHaveCount(0);

    expectNoBrowserErrors(errors);
});
