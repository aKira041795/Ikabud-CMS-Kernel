// @ts-check
const { test, expect } = require('@playwright/test');

const CMS_URL = 'http://cmsnew.test';
const WIDGET_TEXT = 'Placement parity fixture';

async function login(page) {
    await page.goto(`${CMS_URL}/cms/login`);
    await page.getByText('Username or Email', { exact: true }).locator('..').getByRole('textbox').fill('cmsuitest');
    await page.getByText('Password', { exact: true }).locator('..').locator('input').fill('CmsTest123!');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(`${CMS_URL}/cms/admin`);
}

test('header widget preview uses the same placement band as the renderer', async ({ page }) => {
    await login(page);
    await page.goto(`${CMS_URL}/cms/admin/customize`);
    await page.getByRole('button', { name: 'Header', exact: true }).click();
    await expect(page.getByText('Header Preview', { exact: true })).toBeVisible();

    await page.locator('[x-data="themeCustomizer()"]')
        .evaluate((root, fixtureText) => {
            const state = root._x_dataStack && root._x_dataStack[0];
            if (!state) throw new Error('Customizer Alpine state did not initialize');
            state.headerSettings.show_topbar = 1;
            state.headerWidgets = [{
                id: 'placement_parity_fixture',
                type: 'text',
                props: { content: fixtureText, font_size: '13', font_weight: 'normal' },
                location: 'header',
            }];
        }, WIDGET_TEXT);

    const frame = page.getByText('Header Preview', { exact: true })
        .locator('xpath=../following-sibling::div')
        .locator('.cz-preview-frame')
        .first();
    const topbarBand = frame.locator(':scope > div').nth(1);
    const headerBand = frame.locator(':scope > div').nth(2);

    await expect.poll(async () => ({
        widget: WIDGET_TEXT,
        topbar: (await topbarBand.innerText()).includes(WIDGET_TEXT),
        header: (await headerBand.innerText()).includes(WIDGET_TEXT),
    }), `widget="${WIDGET_TEXT}"; topbar band must exclude it; header band must include it`).toEqual({
        widget: WIDGET_TEXT,
        topbar: false,
        header: true,
    });
});
