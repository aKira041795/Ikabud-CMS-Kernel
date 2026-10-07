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
// Widest classic (space-reserving) vertical scrollbar we must survive. Headless Chromium
// reserves none, so the shell is narrowed by this much to expose overflow that a real
// desktop browser at a narrow window (or any browser zoom on a <=768px layout) would show.
const CLASSIC_SCROLLBAR_PX = 48;
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
        // One flush per page, not per width. Flushing inside the width loop forced all
        // 21 loads through the cold path (~5s each), which overran the test timeout
        // before the assertion below could ever run -- so the ratchet proved nothing.
        // Layout depends on viewport and CSS, not on page-cache state.
        const flush = await page.request.get(`${APP_URL}/_tmp_cache_flush.php?full=1`);
        expect(flush.ok(), `cache flush before ${target.name}`).toBe(true);

        for (const width of widths) {
            await page.setViewportSize({ width, height: 900 });
            await page.goto(`${APP_URL}${target.path}`, { waitUntil: 'domcontentloaded' });

            const geometry = await page.evaluate(({ scrollbarPx }) => {
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
                    // The scrollWidth > clientWidth check above cannot see this class of defect:
                    // headless Chromium never reserves scrollbar space (measured: innerWidth ===
                    // clientWidth === viewport, and --disable-features=OverlayScrollbar does not
                    // change it), so a layout a few px too wide for a real client box still
                    // reports no overflow here. Emulate the real condition -- narrow the shell by
                    // the scrollbar shortfall and require the governed sidebar region to still
                    // fit inside it. Returns true when it fits, else the measured detail.
                    sidebarFitsNarrowShell: (() => {
                        const region = document.querySelector('.ark-sidebar-region');
                        const shell = region && region.parentElement;
                        if (!region || !shell) return true;
                        const original = shell.style.width;
                        shell.style.width = `${shell.clientWidth - scrollbarPx}px`;
                        const fits = region.getBoundingClientRect().width <= shell.clientWidth + 1;
                        const detail = `region ${Math.round(region.getBoundingClientRect().width)} > shell ${shell.clientWidth}`;
                        shell.style.width = original;
                        return fits ? true : detail;
                    })(),
                };
            }, { scrollbarPx: CLASSIC_SCROLLBAR_PX });

            measurements.push(`${target.name} ${width}px: clientW=${geometry.clientWidth} scrollW=${geometry.scrollWidth} overflow=${geometry.overflow} narrowShell=${geometry.sidebarFitsNarrowShell}`);
            if (geometry.overflow) {
                offenders.push(`${target.name} ${width}px: ${geometry.culprit} right=${geometry.culpritRight} clientW=${geometry.clientWidth} scrollW=${geometry.scrollWidth}`);
            }
            if (geometry.sidebarFitsNarrowShell !== true) {
                offenders.push(`${target.name} ${width}px: sidebar region overflows a scrollbar-narrowed shell (${geometry.sidebarFitsNarrowShell})`);
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
