// @ts-check
/**
 * Declared content types render in a real browser, under any theme.
 *
 * Prerequisites:
 *   - Application reachable at APP_URL (e.g. http://cmsnew.test)
 *   - The tenant declares content types (cms_content_types, is_active = 1)
 *
 * Deliberately theme-agnostic. The point of the substrate claim is that the
 * SAME declared entity renders whether the active theme is native-default or
 * ark, so this spec asserts only user-observable behaviour that both themes
 * must satisfy:
 *   - the page resolves (not a 404 or a 500)
 *   - the declared type's label is shown
 *   - detail links exist and navigate
 *   - the detail page shows content
 *
 * It does NOT assert CSS class names, because those are the theme's business.
 * A marker check accepts either the entity-view renderer's data-entity-* output
 * or ARK's ark-card fallback, so a theme swap cannot fail the suite for the
 * wrong reason.
 *
 * Run: APP_URL=http://cmsnew.test npx playwright test tests/browser/declared-content-type.spec.js
 */

const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://cmsnew.test';

// Types measured 2026-09-26 as declared and active, with no bespoke controller
// or template of their own. `page` and `post` are excluded on purpose - they
// predate the generic routes and have their own handlers, so they would not
// test the claim.
const DECLARED_TYPES = [
    { slug: 'course', label: 'Course' },
    { slug: 'lesson', label: 'Lesson' },
    { slug: 'portfolio-item', label: 'Portfolio Item' },
    { slug: 'product', label: 'Products' },
    { slug: 'service', label: 'Service' },
];

test.describe('Declared content types render without bespoke PHP', () => {

    for (const { slug, label } of DECLARED_TYPES) {

        test(`/cms/${slug} renders a listing with detail links`, async ({ page }) => {
            const consoleErrors = [];
            page.on('console', (m) => { if (m.type() === 'error') consoleErrors.push(m.text()); });

            const response = await page.goto(`${APP_URL}/cms/${slug}`, { waitUntil: 'domcontentloaded' });

            expect(response, `no response for /cms/${slug}`).toBeTruthy();
            expect(response.status(), `/cms/${slug} status`).toBe(200);

            // The declared label is shown to the user
            await expect(page.locator('body')).toContainText(label, { timeout: 10000 });

            // An entity renderer produced output: either the entity-view
            // renderer's marker or the theme's own fallback markup.
            const rendered = page.locator(
                '[data-entity-kind="list-item"], [data-entity-type], .entity-card, .ark-card'
            );
            expect(await rendered.count(), `no entity output on /cms/${slug}`).toBeGreaterThan(0);

            // Detail links exist and point at the type
            const detailLinks = page.locator(`a[href*="/cms/${slug}/"]`);
            const linkCount = await detailLinks.count();
            expect(linkCount, `no detail links on /cms/${slug}`).toBeGreaterThan(0);

            expect(consoleErrors, `console errors on /cms/${slug}`).toEqual([]);
        });

        test(`/cms/${slug} detail page opens from the listing`, async ({ page }) => {
            await page.goto(`${APP_URL}/cms/${slug}`, { waitUntil: 'domcontentloaded' });

            const firstLink = page.locator(`a[href*="/cms/${slug}/"]`).first();
            await expect(firstLink).toBeVisible({ timeout: 10000 });

            const href = await firstLink.getAttribute('href');
            expect(href, 'detail link has no href').toBeTruthy();

            const response = await page.goto(
                href.startsWith('http') ? href : `${APP_URL}${href}`,
                { waitUntil: 'domcontentloaded' }
            );
            expect(response.status(), `detail status for ${href}`).toBe(200);

            // A detail page must show more than the layout chrome
            const text = await page.locator('body').innerText();
            expect(text.trim().length, 'detail page is empty').toBeGreaterThan(200);
        });
    }
});
