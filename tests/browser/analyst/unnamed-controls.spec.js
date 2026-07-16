// @ts-check
const { test, expect } = require('../WorkbenchFixture');
const { ExperienceAnalyst } = require('./ExperienceAnalyst');

const paths = String(process.env.WB_ANALYST_PATHS || '').split(',').map(x => x.trim()).filter(Boolean);

test.describe('System analyst: unnamed control inventory', () => {
    test.skip(paths.length === 0, 'Set WB_ANALYST_PATHS to comma-separated application paths');

    for (const target of paths) {
        test(`inventory ${target}`, async ({ page }) => {
            const separator = target.includes('?') ? '&' : '?';
            await page.goto(`${target}${separator}wb_inspect=1&disyl_nocache=1`);
            await page.waitForSelector('[data-wb-component="app-shell"]', { timeout: 15000 });
            const result = await new ExperienceAnalyst(page).inspect(target, target);
            console.log(`UNNAMED_CONTROLS ${target} ${JSON.stringify(result.unnamed_controls || [])}`);
            expect(result.metrics.unnamed_controls).toBeGreaterThanOrEqual(0);
        });
    }
});
