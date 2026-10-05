#!/usr/bin/env node
/**
 * Chair verification instrument for the HARPP UI work.
 * Audits the SHIPPED, COMPUTED page - not the source. Inline styles and compiled DiSyL both
 * escape a token change, so source greps are not sufficient evidence.
 *
 * Reports per nav destination: HTTP status, console errors, failed requests, border-radius
 * violations (> 3px) on computed styles, and the worst text/background contrast pairs.
 */
// Resolve playwright from the REPO ROOT, not from this file's directory. Node resolves requires
// relative to the script, so a copy of this tool run from anywhere else used to die with
// "Cannot find module 'playwright'" unless NODE_PATH was set. That footgun cost a run.
// Named nodePath, NOT path: the page loop below destructures [label, path], which would shadow
// the module and make path.join() a TypeError. That bug shipped once and was caught only by
// running this tool from another directory.
const nodePath = require('path');
const { createRequire } = require('module');
const repoRequire = createRequire(nodePath.join(__dirname, '..', 'package.json'));
const { chromium } = repoRequire('playwright');
const fs = require('fs');

const BASE = process.env.HARPP_BASE || 'http://harpp.test';
const OUT_DIR = process.env.HARPP_OUT || '/tmp/chair-verify';
const NOCACHE = '?disyl_nocache=1';
const PAGES = [
    ['Messenger', '/harpp'],
    ['Advisor', '/harpp/advisor'],
    ['Status', '/harpp/status'],
    ['Runners', '/harpp/runners'],
    ['Notifications', '/harpp/notifications'],
    ['Users', '/harpp/users'],
    ['Workspaces', '/harpp/workspaces'],
    ['Settings', '/harpp/settings'],
    ['Deploy', '/harpp/deploy'],
];

// Runs in the page. Radius audit + contrast sampler.
function auditInPage() {
    const parse = (v) => {
        if (!v) return null;
        if (v.endsWith('%')) return { pct: parseFloat(v) };
        const n = parseFloat(v);
        return isNaN(n) ? null : { px: n };
    };
    const out = { radii: [], contrast: [] };
    const els = document.querySelectorAll('*');
    for (const el of els) {
        const cs = getComputedStyle(el);
        const r = parse(cs.borderTopLeftRadius);
        if (!r) continue;
        // percentage radii are circles/pills - report separately, they are shape not styling
        if (r.pct !== undefined) {
            if (r.pct > 0) out.radii.push({ px: r.pct + '%', sel: describe(el), kind: 'percent' });
            continue;
        }
        if (r.px > 3.01) out.radii.push({ px: r.px, sel: describe(el), kind: 'px' });
    }
    function describe(el) {
        let s = el.tagName.toLowerCase();
        if (el.id) s += '#' + el.id;
        if (el.className && typeof el.className === 'string') {
            s += '.' + el.className.trim().split(/\s+/).slice(0, 3).join('.');
        }
        return s;
    }
    // contrast: sample elements that have their own text
    const lum = (c) => {
        const [r, g, b] = c;
        const f = (x) => { x /= 255; return x <= 0.03928 ? x / 12.92 : Math.pow((x + 0.055) / 1.055, 2.4); };
        return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
    };
    const toRgb = (s) => { const m = (s || '').match(/\d+(\.\d+)?/g); return m ? m.slice(0, 3).map(Number) : null; };
    const ratio = (a, b) => {
        const l1 = lum(a), l2 = lum(b);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    };
    const effectiveBg = (el) => {
        let n = el;
        while (n && n !== document.documentElement) {
            const bg = getComputedStyle(n).backgroundColor;
            const rgba = (bg || '').match(/[\d.]+/g);
            if (rgba && rgba.length >= 3 && (rgba.length === 3 || parseFloat(rgba[3]) > 0.5)) return toRgb(bg);
            n = n.parentElement;
        }
        return [255, 255, 255];
    };
    for (const el of els) {
        const txt = (el.textContent || '').trim();
        if (!txt || el.children.length > 0) continue;
        const cs = getComputedStyle(el);
        if (cs.visibility === 'hidden' || cs.display === 'none') continue;
        const fg = toRgb(cs.color);
        if (!fg) continue;
        const bg = effectiveBg(el);
        const cr = ratio(fg, bg);
        if (cr < 4.5) {
            out.contrast.push({ ratio: Math.round(cr * 100) / 100, fg: cs.color, bg: 'rgb(' + bg.join(',') + ')', sel: describe(el), text: txt.slice(0, 24) });
        }
    }
    return out;
}

(async () => {
  fs.mkdirSync(OUT_DIR, { recursive: true });
    const browser = await chromium.launch();
    const ctx = await browser.newContext({ viewport: { width: 1440, height: 1000 } });
    const page = await ctx.newPage();

    // ---- login ----
    await page.goto(BASE + '/harpp/login' + NOCACHE, { waitUntil: 'domcontentloaded' });
    await page.fill('input[name="email"]', process.env.HARPP_USER || 'owner@harpp.local');
    await page.fill('input[name="password"]', process.env.HARPP_PASS || 'admin1234');
    await Promise.all([
        page.waitForURL(/\/harpp(\/|$|\?)/, { timeout: 20000 }).catch(() => { }),
        page.click('button[type="submit"]'),
    ]);
    await page.waitForTimeout(800);
    console.log('logged_in_url: ' + page.url());

    const summary = [];
    for (const [label, path] of PAGES) {
        const consoleErrors = [];
        const failedReqs = [];
        const onConsole = (m) => { if (m.type() === 'error') consoleErrors.push(m.text().slice(0, 160)); };
        const onFail = (r) => failedReqs.push(r.url().slice(0, 120) + ' :: ' + (r.failure() && r.failure().errorText));
        page.on('console', onConsole);
        page.on('requestfailed', onFail);

        let status = 0;
        try {
            const resp = await page.goto(BASE + path + NOCACHE, { waitUntil: 'networkidle', timeout: 25000 });
            status = resp ? resp.status() : 0;
        } catch (e) {
            status = -1;
            consoleErrors.push('navigation: ' + e.message.slice(0, 120));
        }
        await page.waitForTimeout(500);
        let audit = { radii: [], contrast: [] };
        try { audit = await page.evaluate(auditInPage); } catch (e) { consoleErrors.push('audit: ' + e.message.slice(0, 120)); }

        page.off('console', onConsole);
        page.off('requestfailed', onFail);

        const px = audit.radii.filter((r) => r.kind === 'px');
        const pct = audit.radii.filter((r) => r.kind === 'percent');
        summary.push({ label, path, status, consoleErrors, failedReqs, radiusViolations: px, percentRadii: pct, lowContrast: audit.contrast });

        console.log('\n=== ' + label + ' (' + path + ') ===');
        console.log('  status           : ' + status);
        console.log('  console errors   : ' + consoleErrors.length + (consoleErrors.length ? ' -> ' + consoleErrors.join(' | ') : ''));
        console.log('  failed requests  : ' + failedReqs.length + (failedReqs.length ? ' -> ' + failedReqs.join(' | ') : ''));
        console.log('  radius > 3px     : ' + px.length);
        for (const v of px.slice(0, 6)) console.log('      ' + v.px + 'px  ' + v.sel);
        if (pct.length) {
            console.log('  percent radii    : ' + pct.length + ' (check these are deliberate shapes)');
            for (const v of pct.slice(0, 4)) console.log('      ' + v.px + '  ' + v.sel);
        }
        console.log('  contrast < 4.5   : ' + audit.contrast.length);
        for (const c of audit.contrast.sort((a, b) => a.ratio - b.ratio).slice(0, 5)) {
            console.log('      ' + c.ratio + '  ' + c.sel + '  fg=' + c.fg + ' bg=' + c.bg + '  "' + c.text + '"');
        }
        await page.screenshot({ path: nodePath.join(OUT_DIR, 'harpp-' + label.toLowerCase() + '.png'), fullPage: true });
    }

  fs.writeFileSync(nodePath.join(OUT_DIR, 'harpp-audit.json'), JSON.stringify(summary, null, 2));
  console.log('\nwrote ' + nodePath.join(OUT_DIR, 'harpp-audit.json') + '  + screenshots');
    await browser.close();
})().catch((e) => { console.error('FATAL: ' + e.message); process.exit(1); });
