// @ts-check
/**
 * Daily Ledger — PRINT output of the Daily Production Sheet.
 *
 * Diagnostic + oracle harness. Captures what the browser ACTUALLY puts on paper
 * (`page.pdf()` under print media) and dumps the computed styles that decide it, so
 * a print defect is judged from evidence rather than from reading CSS.
 *
 * Run:  APP_URL=http://baronledger.test npx playwright test tests/browser/daily-ledger-print-daily-sheet.spec.js --reporter=line
 */
const { test, expect } = require('@playwright/test');

// Producing the PDF and emulating print media on a 2.5MB sheet needs more than the 30s default.
test.setTimeout(180000);

const PRODUCTION = { username: 'prod-rizal', fullName: 'Sheila Baina', password: 'prodrizal123' };

async function login(page, account) {
    await page.goto('/daily-ledger/login');
    await page.fill('input[name="username"]', account.username);
    await page.fill('input[name="full_name"]', account.fullName);
    await page.fill('input[name="password"]', account.password);
    await Promise.all([
        page.waitForURL((u) => !u.pathname.includes('/login'), { timeout: 25000 }),
        page.click('button[type="submit"], input[type="submit"]'),
    ]);
    await page.waitForLoadState('networkidle');
}

/** What the print stylesheet actually does to the sheet. */
async function printDiagnostics(page) {
    return page.evaluate(() => {
        const cs = (sel) => {
            const el = document.querySelector(sel);
            if (!el) return null;
            const s = getComputedStyle(el);
            return {
                display: s.display,
                overflow: s.overflow,
                overflowX: s.overflowX,
                height: s.height,
                position: s.position,
            };
        };
        const shell = document.querySelector('main') ? document.querySelector('main').parentElement : null;
        const main = document.querySelector('main');
        const wrap = document.querySelector('#tab-daily-sheet .table-wrap');
        const table = document.querySelector('#tab-daily-sheet table');
        let pageRuleText = '';
        let pageRuleCount = 0;
        for (const sheet of Array.from(document.styleSheets)) {
            let rules = [];
            try { rules = Array.from(sheet.cssRules || []); } catch (e) { continue; }
            for (const r of rules) {
                if (r.constructor.name === 'CSSPageRule' || (r.cssText || '').startsWith('@page')) {
                    pageRuleCount++;
                    pageRuleText += r.cssText;
                }
            }
        }
        return {
            sidebar: cs('#wb-sidebar'),
            topHeader: cs('header'),
            shell: shell ? { display: getComputedStyle(shell).display, overflow: getComputedStyle(shell).overflow, height: getComputedStyle(shell).height } : null,
            main: cs('main'),
            tableWrap: cs('#tab-daily-sheet .table-wrap'),
            // Clipping test: does the sheet's container hide content taller/wider than it?
            mainClipped: main ? { clientHeight: main.clientHeight, scrollHeight: main.scrollHeight, clipped: main.scrollHeight > main.clientHeight + 4 } : null,
            printHeaderHeight: (() => {
                const h = document.querySelector('#daily-sheet-print-header');
                return h ? { exists: true, display: getComputedStyle(h).display, height: h.getBoundingClientRect().height } : { exists: false };
            })(),
            // Clipping test: does the table overflow its wrapper horizontally?
            tableOverflow: (wrap && table)
                ? { wrapClientWidth: wrap.clientWidth, tableScrollWidth: table.scrollWidth, overflows: table.scrollWidth > wrap.clientWidth + 4, columns: document.querySelectorAll('#tab-daily-sheet thead th').length }
                : null,
            // The sheet's identity must survive printing.
            printedIdentity: {
                titleVisible: !!document.querySelector('#tab-daily-sheet .card-header')
                    && getComputedStyle(document.querySelector('#tab-daily-sheet .card-header')).display !== 'none',
                commissaryName: ((document.querySelector('#production-commissary-name') || {}).textContent || '').trim() || null,
                commissaryNameVisible: !!document.querySelector('#production-commissary-name')
                    && getComputedStyle(document.querySelector('#production-commissary-name')).display !== 'none',
            },
            pageRuleCount,
            pageRuleText: pageRuleText.slice(0, 200),
        };
    });
}

test('print evidence: what the Daily Production Sheet actually puts on paper', async ({ page }) => {
    await login(page, PRODUCTION);
    await page.goto('/daily-ledger/admin/commissary');
    await page.waitForLoadState('networkidle');
    // Wait for the sheet itself rather than racing the layout of a 2.5MB, 178-row document.
    // The print header is display:none on screen by design, so wait for ATTACHMENT, not visibility.
    await page.waitForSelector('#daily-sheet-print-header', { state: 'attached', timeout: 30000 });
    await page.waitForSelector('#tab-daily-sheet table tbody tr.daily-sheet-product-row', { timeout: 30000 });

    await page.screenshot({ path: 'test-results/print-screen-before.png', fullPage: false });

    // The default print path: exactly what Ctrl+P produces with no extra options.
    const pdf = await page.pdf({ path: 'test-results/print-daily-sheet-default.pdf', printBackground: true, preferCSSPageSize: true });
    const pdfText = pdf.toString('latin1');
    const mediaBox = pdfText.match(/\/MediaBox\s*\[\s*([\d.]+)\s+([\d.]+)\s+([\d.]+)\s+([\d.]+)\s*\]/);
    const pageCount = (pdfText.match(/\/Type\s*\/Page[^s]/g) || []).length;
    const paper = mediaBox ? { width: Number(mediaBox[3]), height: Number(mediaBox[4]) } : null;

    // Inspect the print stylesheet's effect on the live DOM.
    await page.emulateMedia({ media: 'print' });
    const diag = await printDiagnostics(page);
    await page.screenshot({ path: 'test-results/print-emulated-screen.png', fullPage: true });
    await page.emulateMedia({ media: 'screen' });

    console.log('PRINT_DIAGNOSTICS ' + JSON.stringify({ diag, paper, pageCount }));

    // The print contract: no app chrome, no clipping, a landscape page, and an identified sheet.
    expect(diag.sidebar && diag.sidebar.display, 'print must not carry the app sidebar').toBe('none');
    expect(diag.topHeader && diag.topHeader.display, 'print must not carry the top header bar').toBe('none');
    expect(diag.main && diag.main.overflow, 'print must not clip the sheet to a scroll container').toBe('visible');
    expect(diag.mainClipped && diag.mainClipped.clipped, 'print must not clip the sheet to one screen').toBe(false);
    expect(diag.pageRuleText, 'the sheet must declare a landscape @page rule').toMatch(/landscape/i);
    // The printed table must FIT the paper. This was dropped once and the print output silently
    // regressed to a table 73px wider than the page, cutting the last column off the sheet.
    expect(
        diag.tableOverflow.overflows,
        `the printed table must fit the page width (table ${diag.tableOverflow.tableScrollWidth}px vs available ${diag.tableOverflow.wrapClientWidth}px)`,
    ).toBe(false);
    expect(paper, 'the printed page must actually be landscape').not.toBeNull();
    expect(paper && paper.width > paper.height, 'paper MediaBox must be landscape (width > height)').toBe(true);
    expect(pageCount, 'a 15-column sheet cannot fit on one printed page').toBeGreaterThan(1);
    expect(diag.printHeaderHeight.exists && diag.printHeaderHeight.display !== 'none', 'the printed sheet must carry a print identity header').toBe(true);
    expect(diag.printedIdentity.commissaryName, 'the printed sheet must name its commissary').toBeTruthy();
    expect(diag.printedIdentity.commissaryNameVisible, 'the printed commissary name must be visible').toBe(true);
});
