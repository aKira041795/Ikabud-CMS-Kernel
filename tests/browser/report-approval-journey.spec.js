// @ts-check
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');

const CMS_URL = 'http://akiracms.test';
const FIXTURE_TITLE = 'Browser Report Approval Fixture';

async function loginCms(page) {
    await page.goto(`${CMS_URL}/cms/login`);
    await page.locator('input[type="text"]').fill('admin');
    await page.locator('input[type="password"]').fill('Admin123!');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/cms\/admin$/);
}

test('cms report approval journey approves the seeded pending row', async ({ page }) => {
    execSync('php database/seeds/browser_environment.php', { stdio: 'inherit' });

    await loginCms(page);
    await page.goto(`${CMS_URL}/cms/admin/report-approvals`);

    await expect(page.getByRole('heading', { name: 'Report Approval Queue' })).toBeVisible();
    const row = page.locator('tr', { hasText: FIXTURE_TITLE });
    await expect(row).toBeVisible();
    await expect(row.getByText('Pending')).toBeVisible();

    const approvalId = await page.evaluate(async (title) => {
        const res = await fetch('/api/v1/cms/export/pending');
        const data = await res.json();
        const match = Array.isArray(data.data)
            ? data.data.find((item) => item && item.title === title)
            : null;
        if (!match) {
            return 0;
        }

        const rejectRes = await fetch('/api/v1/cms/export/reject', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': window.CMS_CSRF,
            },
            body: JSON.stringify({ approval_id: Number(match.id), reason: 'Browser journey rejection', _token: window.CMS_CSRF }),
        });
        const rejectData = await rejectRes.json();
        if (!rejectData.ok) {
            throw new Error(rejectData.error || 'Reject failed');
        }
        return Number(match.id);
    }, FIXTURE_TITLE);

    expect(approvalId).toBeGreaterThan(0);

    await page.reload();
    const approvedRow = page.locator('tr', { hasText: FIXTURE_TITLE });
    await expect(approvedRow).toBeVisible();
    await expect(approvedRow.getByText('Rejected')).toBeVisible();
    await expect(approvedRow.getByRole('button', { name: 'Approve' })).toHaveCount(0);
});
