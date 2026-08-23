// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
 
const APP_URL = process.env.APP_URL || 'http://palsystem.test';

test('async rendering journey shows both DiSyL async sections without console failures', async ({ page }) => {
    const consoleErrors = [];
    page.on('console', (message) => {
        if (message.type() === 'error') {
            consoleErrors.push(message.text());
        }
    });

    execSync('php database/seeds/browser_environment.php', { stdio: 'inherit' });

    await page.goto(`${APP_URL}/project-audit-ledger/login`);
    await page.fill('input[name="username"]', 'admin');
    await page.fill('input[name="password"]', 'Admin123!');
    await page.click('button[type="submit"]');
    await page.waitForURL('**/admin/project-audit-ledger');

    await page.goto(`${APP_URL}/admin/project-audit-ledger/settings/async-rendering?tab=async-rendering`);
    await expect(page.getByRole('heading', { name: 'Async Rendering Demo' })).toBeVisible();
    await expect(page.getByText('Async Section A')).toBeVisible();
    await expect(page.getByText('Alpha Section Ready')).toBeVisible();
    await expect(page.getByText('Async Section B')).toBeVisible();
    await expect(page.getByText('Beta Section Ready')).toBeVisible();
    expect(consoleErrors).toEqual([]);
});
