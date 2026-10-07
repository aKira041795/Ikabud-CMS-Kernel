// @ts-check
const { test, expect } = require('@playwright/test');

const CMS_URL = 'http://cmsnew.test';
const FIELD_LABEL = 'Title Clamp';

async function login(page) {
    await page.goto(`${CMS_URL}/cms/login`);
    await page.getByText('Username or Email', { exact: true }).locator('..').getByRole('textbox').fill('cmsuitest');
    await page.getByText('Password', { exact: true }).locator('..').locator('input').fill('CmsTest123!');
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(`${CMS_URL}/cms/admin`);
}

async function openEntities(page) {
    await page.goto(`${CMS_URL}/cms/admin/customize`);
    await page.getByRole('button', { name: 'Entities', exact: true }).click();
    await expect(page.getByText('Entity Presentation', { exact: true })).toBeVisible();
}

function titleClampInput(page) {
    return page.getByText(FIELD_LABEL, { exact: true }).locator('..').getByRole('combobox');
}

async function selectExample(page, name) {
    await page.getByRole('button', { name, exact: true }).click();
    await expect(titleClampInput(page)).toBeVisible();
}

async function saveChanges(page) {
    await page.getByRole('button', { name: 'Save Changes', exact: true }).click();
    await expect(page.getByText('All changes saved', { exact: true })).toBeVisible({ timeout: 15000 });
}

async function flushWebCache(page) {
    const response = await page.request.get(`${CMS_URL}/_tmp_cache_flush.php?full=1`);
    expect(response.ok()).toBeTruthy();
}

test('entity presentation values persist per entity type and affect only that public type', async ({ page }) => {
    test.setTimeout(60000);
    await login(page);
    await openEntities(page);

    const originalSettings = await page.locator('#cz-entity-presentation-settings').evaluate((node) =>
        JSON.parse(node.textContent || '{}')
    );
    const globalValue = String(originalSettings.entity_list_title_lines ?? 2);
    const originalCourseValue = String(originalSettings.by_type?.course?.entity_list_title_lines ?? globalValue);
    const distinctiveValue = ['4', '3', '1', '2'].find((value) => value !== originalCourseValue && value !== globalValue);
    expect(distinctiveValue).toBeTruthy();

    try {
        await selectExample(page, 'Guidance');
        await titleClampInput(page).selectOption(distinctiveValue);
        await saveChanges(page);
        await flushWebCache(page);

        await page.reload();
        await page.getByRole('button', { name: 'Entities', exact: true }).click();
        await selectExample(page, 'Guidance');
        await expect(titleClampInput(page), 'course override survives reload').toHaveValue(distinctiveValue);
        console.log(`PERSISTENCE course=${distinctiveValue} after reload`);

        await selectExample(page, 'Content');
        await expect(titleClampInput(page), 'page type remains on the global value').toHaveValue(globalValue);
        console.log(`ISOLATION course=${distinctiveValue} page=${globalValue}`);

        const course = await page.request.get(`${CMS_URL}/cms/course`);
        const lesson = await page.request.get(`${CMS_URL}/cms/lesson`);
        const courseHtml = await course.text();
        const lessonHtml = await lesson.text();
        expect(courseHtml).toContain(`--theme-entity-list-title-lines:${distinctiveValue}`);
        expect(lessonHtml).toContain(`--theme-entity-list-title-lines:${globalValue}`);
        expect(lessonHtml).not.toContain(`--theme-entity-list-title-lines:${distinctiveValue}`);
        console.log(`PUBLIC course=--theme-entity-list-title-lines:${distinctiveValue} lesson=--theme-entity-list-title-lines:${globalValue}`);
    } finally {
        await page.goto(`${CMS_URL}/cms/admin/customize`);
        const restore = await page.evaluate(async (settings) => {
            const root = document.querySelector('[x-data="themeCustomizer()"]');
            const state = root && root._x_dataStack && root._x_dataStack[0];
            const apiBase = state && state.customizerApiBase;
            const response = await fetch(apiBase + '/entity_presentation', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CMS_CSRF },
                body: JSON.stringify({ _token: CMS_CSRF, settings }),
            });
            return { ok: response.ok, body: await response.text() };
        }, originalSettings);
        expect(restore.ok, `restore response: ${restore.body}`).toBeTruthy();
        await flushWebCache(page);
        await page.reload();
        const restoredSettings = await page.locator('#cz-entity-presentation-settings').evaluate((node) =>
            JSON.parse(node.textContent || '{}')
        );
        expect(restoredSettings).toEqual(originalSettings);
        console.log(`RESTORED global=${globalValue} course=${originalCourseValue} exact-settings=true`);
    }
});
