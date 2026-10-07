// @ts-check
/**
 * Declared widget type -> does its offered region survive real browser layout?
 *
 * Run: npx playwright test tests/browser/ark-region-widget-context-matrix.spec.js --reporter=line
 * Header ceiling: 96px. The normal 40px brand plus 24px vertical padding is 64px;
 * 32px tolerance permits font/platform variance without permitting a second card row.
 * Topbar ceiling: 64px, twice its normal ~32px line, for the same reason.
 */
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const payload = JSON.parse(execFileSync('php', [
    path.join(root, 'tests/fixtures/ark_region_widget_matrix_fixture.php'),
], { cwd: root, encoding: 'utf8', maxBuffer: 10 * 1024 * 1024 }));

function shell(region, widgetHtml) {
    if (region === 'header') {
        return `<header class="ark-region-header"><div class="ark-region-header__inner" style="flex-wrap:wrap;padding:12px 1rem;margin:0 auto;max-width:1280px"><div class="ark-region-header__brand"><a class="ark-region-header__site-link">ARK</a></div><div class="ark-region-header__widgets" style="display:flex;flex-wrap:wrap;gap:1rem 1.5rem;align-items:center">${widgetHtml}</div><nav class="ark-region-header__nav"><a>Home</a></nav></div></header>`;
    }
    if (region === 'topbar') {
        return `<div class="ark-topbar" style="padding:8px 0"><div class="ark-topbar__inner"><div class="ark-topbar__left">Tagline</div><div class="ark-topbar__right">${widgetHtml}</div></div></div>`;
    }
    if (region === 'sidebar') {
        return `<main style="display:flex;max-width:1280px;margin:auto"><section style="flex:1">Content</section><aside class="ark-region-sidebar" style="width:300px;flex-shrink:0;margin-left:32px"><div class="ark-region-sidebar__widget">${widgetHtml}</div></aside></main>`;
    }
    return `<footer class="ark-region-footer"><div class="ark-region-footer__inner"><div class="ark-region-footer__widgets" style="display:grid;gap:1.5rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr))">${widgetHtml}</div></div></footer>`;
}

test('every widget offered by every ARK region preserves that region layout', async ({ page }, testInfo) => {
    await page.setViewportSize({ width: 1280, height: 720 });
    const offenders = [];
    const measurements = [];

    for (const item of payload.cases) {
        await page.setContent(`<!doctype html><style>*{box-sizing:border-box}html,body{margin:0;width:100%}${payload.css}</style>${shell(item.region, item.html)}`);
        const geometry = await page.evaluate(({ region, type }) => {
            const bandSelector = region === 'header' ? '.ark-region-header__inner' : region === 'topbar' ? '.ark-topbar' : region === 'sidebar' ? '.ark-region-sidebar' : '.ark-region-footer';
            const band = document.querySelector(bandSelector);
            const widget = document.querySelector(`[data-widget-type="${type}"]`);
            return {
                bandHeight: band ? Math.round(band.getBoundingClientRect().height * 100) / 100 : 0,
                bandOverflow: band ? band.scrollWidth > band.clientWidth + 1 : true,
                widgetOverflow: widget ? widget.scrollWidth > widget.clientWidth + 1 : false,
                pageOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 1,
                rendered: Boolean(widget),
            };
        }, item);
        measurements.push({ region: item.region, type: item.type, ...geometry });
        const ceiling = item.region === 'header' ? 96 : item.region === 'topbar' ? 64 : null;
        const reasons = [];
        if (!geometry.rendered) reasons.push('not-rendered');
        if (ceiling !== null && geometry.bandHeight > ceiling) reasons.push(`height=${geometry.bandHeight}>${ceiling}`);
        if (geometry.bandOverflow) reasons.push('region-horizontal-overflow');
        if (geometry.widgetOverflow) reasons.push('widget-horizontal-overflow');
        if (geometry.pageOverflow) reasons.push('page-horizontal-scrollbar');
        if (reasons.length) offenders.push(`${item.region}/${item.type}: ${reasons.join(', ')}`);
    }

    // Expected appearance stated before inspection: a <=96px horizontal header; contact info is
    // one inline phone/email item with no address, title, border card, or stacked labelled rows.
    const contact = payload.cases.find(item => item.region === 'header' && item.type === 'contact_info');
    await page.setContent(`<!doctype html><style>*{box-sizing:border-box}html,body{margin:0;width:100%}${payload.css}</style>${shell('header', contact.html)}`);
    await testInfo.attach('expected-horizontal-header-contact-info.png', {
        body: await page.screenshot({ fullPage: true }), contentType: 'image/png',
    });

    console.log('ARK region-widget context matrix measurements');
    for (const row of measurements) console.log(`  ${row.region}/${row.type}: height=${row.bandHeight}px rendered=${row.rendered} bandOverflow=${row.bandOverflow} widgetOverflow=${row.widgetOverflow} pageOverflow=${row.pageOverflow}`);
    console.log(`OFFENDERS (${offenders.length}):`);
    for (const offender of offenders) console.log(`  ${offender}`);

    expect(offenders, `Full offender list:\n${offenders.join('\n')}`).toEqual([]);
});
