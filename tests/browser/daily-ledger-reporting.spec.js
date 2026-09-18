// @ts-check
const { test, expect } = require('@playwright/test');
const fs = require('fs');

test('authenticated Daily Ledger report filters and downloads PDF/CSV', async ({ page }) => {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill('browser-report-admin');
    await page.getByLabel('Full Name').fill('Browser Report Admin');
    await page.getByLabel('Password').fill('BrowserReport!2031');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/daily-ledger\/admin\//);

    await page.goto('/daily-ledger/admin/reports/sales?date_from=2031-03-15&date_to=2031-03-15&branch_id=99202&product_id=99202&shift=AM');
    await expect(page.getByRole('heading', { name: 'Daily Sales Report' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Browser Report Bread' })).toBeVisible();
    await expect(page.getByText('Official:', { exact: false })).toContainText('6');

    for (const [label, signature] of [['Download CSV', 'ledger_date'], ['Download PDF', '%PDF']]) {
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', { name: label }).click();
        const download = await downloadPromise;
        const path = await download.path();
        expect(path).not.toBeNull();
        const prefix = fs.readFileSync(path, 'utf8').slice(0, 64);
        expect(prefix).toContain(signature);
        expect(download.suggestedFilename()).toMatch(/^(sales|daily-sales)_browser_2031-03-15_2031-03-15_.*\.(csv|pdf)$/i);
    }
});

test('viewer role reaches Reports from the sidebar and downloads filtered PDF/CSV', async ({ page }) => {
    await page.goto('/daily-ledger/login');
    await page.getByLabel(/Username or Email/i).fill('browser-report-viewer');
    await page.getByLabel('Full Name').fill('Browser Report Viewer');
    await page.getByLabel('Password').fill('BrowserReport!2031');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/daily-ledger\/admin\//);

    // The Reports entry is part of the viewer's navigation, not just the admin's.
    await page.getByRole('link', { name: 'Reports' }).click();
    await page.waitForURL(/\/daily-ledger\/admin\/reports$/);
    await expect(page.getByRole('link', { name: /Daily Sales Report/ }).first()).toBeVisible();

    await page.goto('/daily-ledger/admin/reports/sales?date_from=2031-03-15&date_to=2031-03-15&branch_id=99202&product_id=99202&shift=AM');
    await expect(page.getByRole('heading', { name: 'Daily Sales Report' })).toBeVisible();
    await expect(page.getByRole('cell', { name: 'Browser Report Bread' })).toBeVisible();

    for (const [label, signature] of [['Download CSV', 'ledger_date'], ['Download PDF', '%PDF']]) {
        const downloadPromise = page.waitForEvent('download');
        await page.getByRole('link', { name: label }).click();
        const download = await downloadPromise;
        const path = await download.path();
        expect(path).not.toBeNull();
        expect(fs.readFileSync(path, 'utf8').slice(0, 64)).toContain(signature);
    }
});
