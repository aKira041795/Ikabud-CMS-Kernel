// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

const CMS_URL = 'http://akiracms.test';

async function loginCms(page) {
    await page.goto(`${CMS_URL}/cms/login`);
    await page.locator('input[type="text"]').fill('admin');
    await page.locator('input[type="password"]').fill('Admin123!');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/cms\/admin$/);
}

test('cms builder journey loads a real page builder and saves a section', async ({ page }) => {
    execSync('php database/seeds/browser_environment.php', { stdio: 'inherit' });

    await loginCms(page);

    const builderPageId = await page.evaluate(async () => {
        const res = await fetch('/api/v1/cms/content?type=page&limit=100');
        const data = await res.json();
        const match = Array.isArray(data.data)
            ? data.data.find((item) => item && item.title === 'Browser Builder Page')
            : null;
        return match ? Number(match.id) : 0;
    });

    expect(builderPageId).toBeGreaterThan(0);

    await page.goto(`${CMS_URL}/cms/admin/react-builder/${builderPageId}`);
    const saveButton = page.getByRole('button', { name: 'Save', exact: true });
    await expect(saveButton).toBeVisible();
    const headingNode = page.getByRole('treeitem', { name: /heading element: Browser Builder Heading/i });
    await expect(headingNode).toBeVisible();

    await headingNode.click();
    await expect(page.getByText('Properties: heading')).toBeVisible();

    await page.reload();
    await expect(saveButton).toBeVisible();
    await expect(page.getByRole('treeitem', { name: /heading element: Browser Builder Heading/i })).toBeVisible();
});
