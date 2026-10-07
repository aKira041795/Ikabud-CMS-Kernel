// @ts-check
/**
 * Responsive shell ratchet over assembled CMS pages (not widget fixtures).
 *
 * Widths cover compact mobile (375), the existing stack boundary (768), tablet
 * and small desktop (1024/1152), the 1280px ARK cap, and wider desktops
 * (1440/1920). Contact and blog exercise the configured sidebar; home proves
 * that the no-sidebar builder shell also stays bounded.
 *
 * Run: APP_URL=http://cmsnew.test npx playwright test tests/browser/ark-real-page-responsive.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

const APP_URL = process.env.APP_URL || 'http://cmsnew.test';
const widths = [375, 768, 1024, 1152, 1280, 1440, 1920];
const pages = [
    { name: 'contact', path: '/cms/page/contact', sidebar: true },
    { name: 'blog', path: '/cms/blog', sidebar: true },
    { name: 'home', path: '/', sidebar: false },
];

test('assembled ARK pages never create horizontal page scroll', async ({ page }) => {
    test.setTimeout(120_000);
    const offenders = [];
    const measurements = [];

    for (const target of pages) {
        for (const width of widths) {
            const flush = await page.request.get(`${APP_URL}/_tmp_cache_flush.php?full=1`);
            expect(flush.ok(), `cache flush before ${target.name} at ${width}px`).toBe(true);
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`${APP_URL}${target.path}`, { waitUntil: 'domcontentloaded' });

            const geometry = await page.evaluate(() => {
                const root = document.documentElement;
                const clientWidth = root.clientWidth;
                const scrollWidth = root.scrollWidth;
                const candidates = [...document.body.querySelectorAll('*')]
                    .map(element => {
                        const rect = element.getBoundingClientRect();
                        let depth = 0;
                        for (let node = element; node && node !== document.body; node = node.parentElement) depth++;
                        return { element, rect, depth };
                    })
                    .filter(({ rect }) => rect.left >= -1 && rect.right >= scrollWidth - 1)
                    .sort((a, b) => a.depth - b.depth || b.rect.right - a.rect.right);
                const culprit = candidates[0];
                const describeElement = element => {
                    if (!element) return 'unknown';
                    let label = element.tagName.toLowerCase();
                    if (element.id) label += `#${element.id}`;
                    if (element.classList.length) label += `.${[...element.classList].join('.')}`;
                    return label;
                };
                return {
                    clientWidth,
                    scrollWidth,
                    overflow: scrollWidth > clientWidth,
                    culprit: describeElement(culprit?.element),
                    culpritRight: culprit ? Math.round(culprit.rect.right * 100) / 100 : null,
                    sidebarRendered: Boolean(document.querySelector('.ark-region-sidebar')),
                };
            });

            measurements.push(`${target.name} ${width}px: clientW=${geometry.clientWidth} scrollW=${geometry.scrollWidth} overflow=${geometry.overflow}`);
            if (geometry.overflow) {
                offenders.push(`${target.name} ${width}px: ${geometry.culprit} right=${geometry.culpritRight} clientW=${geometry.clientWidth} scrollW=${geometry.scrollWidth}`);
            }
            expect(geometry.sidebarRendered, `${target.name} sidebar presence at ${width}px`).toBe(target.sidebar);
        }
    }

    console.log('ARK assembled-page responsive measurements');
    for (const measurement of measurements) console.log(`  ${measurement}`);
    console.log(`RESPONSIVE OFFENDERS (${offenders.length}):`);
    for (const offender of offenders) console.log(`  ${offender}`);

    expect(offenders, `Horizontal page overflow:\n${offenders.join('\n')}`).toEqual([]);
});
