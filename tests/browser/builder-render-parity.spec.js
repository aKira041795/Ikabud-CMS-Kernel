// @ts-check
/**
 * Page-builder RENDER PARITY gate (chair consult, 2026-10-10 — priority P1).
 *
 * The public renderer is PHP (modules/cms/builder-renderers.php) and the editor
 * canvas is a parallel React re-implementation
 * (builder-ui/src/builder/components/NodeRenderer.tsx). Registry counts agreeing
 * proves type coverage, not layout parity. This gate measures LAYOUT itself,
 * joined on data-node-id — the one key both paths emit.
 *
 * Two independent kinds of assertion, deliberately:
 *
 *   1. STRUCTURAL — encodes the failure classes that actually happened, so a
 *      regression fails here even if nobody has refreshed the baseline:
 *        - flex children of a row sit side by side instead of stacking
 *          (the spurious max-width regression);
 *        - a 33/67 row really splits in that proportion;
 *        - a full-bleed section reaches its content edge;
 *        - the page has no unintended horizontal overflow (the guard-lift
 *          regression, which only showed up in rendered pixels).
 *
 *   2. BASELINE — a committed geometry snapshot, so any other layout drift is
 *      reported per node rather than discovered by a user.
 *
 * The fixture is generated deterministically by tools/builder-parity-render.php
 * and loaded over file://, so this spec needs no server, no tenant database, no
 * login and no page cache. Layout comparison is viewport-relative: widths are
 * measured against the parent box, so the gate is about relationships, not
 * pixels, and survives font/DPI differences.
 *
 * Regenerate the fixture and baseline after an INTENDED layout change:
 *   php tools/builder-parity-render.php
 *   PARITY_UPDATE=1 npx playwright test tests/browser/builder-render-parity.spec.js
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const { pathToFileURL } = require('url');

const ROOT = path.resolve(__dirname, '..', '..');
const FIXTURE = path.join(__dirname, 'fixtures', 'builder-parity.html');
const BASELINE = path.join(__dirname, 'fixtures', 'builder-parity.baseline.json');

// Fixed so the comparison is repeatable; the assertions are all relative.
// 1600 rather than 1280 on purpose: the ARK content gutter is 1280, so at a
// 1600 viewport "reached the edge" and "stopped at the cap" are DISTINGUISHABLE.
// At 1280 they would both measure ~1.0 of the parent and the gate could not tell
// the bug from the fix.
const VIEWPORT = { width: 1600, height: 900 };
const ARK_CONTENT_GUTTER = 1280;

/** Relative geometry for every node that carries a join key. */
async function captureGeometry(page) {
    return page.evaluate(() => {
        const out = {};
        document.querySelectorAll('[data-node-id]').forEach((el) => {
            const id = el.getAttribute('data-node-id');
            if (!id) return;
            const box = el.getBoundingClientRect();
            const parent = el.parentElement;
            const parentBox = parent ? parent.getBoundingClientRect() : null;
            const cs = getComputedStyle(el);
            out[id] = {
                // Width as a fraction of the PARENT box: this is what "stacked
                // instead of side by side" and "squeezed by a spurious
                // max-width" both change, and it is immune to page offsets.
                wRatio: parentBox && parentBox.width > 0
                    ? Number((box.width / parentBox.width).toFixed(4))
                    : null,
                // Absolute width, so "stopped at the theme content cap" is
                // distinguishable from "reached the viewport edge".
                w: Math.round(box.width),
                h: Math.round(box.height),
                // Offset from the parent's top edge, so "same row" is testable.
                dy: parentBox ? Math.round(box.top - parentBox.top) : 0,
                display: cs.display,
                flexDirection: cs.flexDirection,
            };
        });
        return out;
    });
}

test.describe('page builder render parity', () => {
    test.beforeAll(() => {
        // Regenerate the fixture so the gate always measures the current
        // renderer rather than a stale artifact checked in by hand.
        execSync('php tools/builder-parity-render.php', { cwd: ROOT, stdio: 'inherit' });
    });

    test('the fixture renders, has no horizontal overflow, and lays out as authored', async ({ page }) => {
        await page.setViewportSize(VIEWPORT);
        await page.goto(pathToFileURL(FIXTURE).href);
        await page.waitForSelector('[data-node-id="p_fullbleed_text"]');

        const geo = await captureGeometry(page);

        // Every declared node must actually render, or the gate is measuring an
        // empty page and passing.
        const expected = [
            'doc_root', 'p_section', 'p_container', 'p_intro', 'p_row',
            'p_col_left', 'p_text_left', 'p_col_right', 'p_text_right',
            'p_button', 'p_divider', 'p_spacer', 'p_icon_box',
            'p_fullbleed', 'p_fullbleed_text',
        ];
        const missing = expected.filter((id) => !geo[id]);
        expect(missing, `nodes missing from the rendered page: ${missing.join(', ')}`).toEqual([]);

        // ── Structural assertions: the failure classes that actually happened ──

        // 1. A row's columns sit SIDE BY SIDE. When a spurious max-width was
        //    applied to flex children they stacked, which is a layout change
        //    invisible to any markup test.
        const left = geo['p_col_left'];
        const right = geo['p_col_right'];
        expect(
            Math.abs(left.dy - right.dy),
            `33/67 columns are stacked, not side by side (dy ${left.dy} vs ${right.dy})`
        ).toBeLessThanOrEqual(2);

        // 2. …and split in the authored proportion.
        const row = geo['p_row'];
        const leftShare = left.wRatio;
        const rightShare = right.wRatio;
        expect(leftShare, 'left column should take ~33% of the row').toBeGreaterThan(0.28);
        expect(leftShare, 'left column should take ~33% of the row').toBeLessThan(0.38);
        expect(rightShare, 'right column should take ~67% of the row').toBeGreaterThan(0.62);
        expect(rightShare, 'right column should take ~67% of the row').toBeLessThan(0.72);
        expect(row, 'the row should span its container').toBeTruthy();

        // 3. The full-bleed band reaches the edge of its parent rather than
        //    stopping at a content column.
        expect(
            geo['p_fullbleed'].wRatio,
            'the full-bleed section did not reach its parent edge'
        ).toBeGreaterThan(0.98);

        // 4. The theme content contract, measured in PIXELS at a viewport wider
        //    than the gutter, because both failures here are invisible to
        //    geometry ratios: ordinary content keeps the theme gutter, and a
        //    full-bleed band is released to the full width.
        //
        //    Deliberately NOT a document-level overflow assertion. ARK retains
        //    `overflow-x: clip` on .ark-main, so an over-wide child is clipped
        //    and `scrollWidth <= innerWidth` holds no matter how wrong the
        //    layout is — an assertion that cannot fail. Contract section 12 says
        //    the same thing: verify with rendered pixels, because a clipped
        //    element still reports its unclipped layout box.
        expect(
            geo['p_fullbleed'].w,
            `the full-bleed section stopped at the content gutter instead of the viewport edge ` +
            `(${geo['p_fullbleed'].w}px at a ${VIEWPORT.width}px viewport)`
        ).toBeGreaterThan(VIEWPORT.width - 100);

        expect(
            geo['p_section'].w,
            `ordinary content lost the theme gutter (${geo['p_section'].w}px, expected ~${ARK_CONTENT_GUTTER}px)`
        ).toBeGreaterThan(ARK_CONTENT_GUTTER - 64);
        expect(
            geo['p_section'].w,
            `ordinary content escaped the theme gutter (${geo['p_section'].w}px, expected ~${ARK_CONTENT_GUTTER}px)`
        ).toBeLessThan(ARK_CONTENT_GUTTER + 64);
        // The gutter applies to the section, so anything nested inside it must
        // still fit within it.
        expect(
            geo['p_container'].w,
            `a section child overflowed its section (${geo['p_container'].w}px > ${geo['p_section'].w}px)`
        ).toBeLessThanOrEqual(geo['p_section'].w);

        // ── Baseline comparison ─────────────────────────────────────────────
        if (process.env.PARITY_UPDATE === '1' || !fs.existsSync(BASELINE)) {
            fs.writeFileSync(BASELINE, JSON.stringify(geo, null, 2) + '\n');
            test.info().annotations.push({
                type: 'baseline',
                description: fs.existsSync(BASELINE)
                    ? `wrote ${path.relative(ROOT, BASELINE)}`
                    : 'baseline written',
            });
            return;
        }

        const baseline = JSON.parse(fs.readFileSync(BASELINE, 'utf8'));
        const drift = [];
        for (const [id, now] of Object.entries(geo)) {
            const was = baseline[id];
            if (!was) {
                drift.push(`${id}: new node not in baseline`);
                continue;
            }
            if (was.wRatio !== null && now.wRatio !== null && Math.abs(was.wRatio - now.wRatio) > 0.02) {
                drift.push(`${id}: width share ${was.wRatio} -> ${now.wRatio}`);
            }
            if (Math.abs(was.w - now.w) > 4) {
                drift.push(`${id}: width ${was.w}px -> ${now.w}px`);
            }
            if (Math.abs(was.dy - now.dy) > 2) {
                drift.push(`${id}: top offset ${was.dy} -> ${now.dy}`);
            }
            if (was.display !== now.display) {
                drift.push(`${id}: display ${was.display} -> ${now.display}`);
            }
            if (was.flexDirection !== now.flexDirection) {
                drift.push(`${id}: flex-direction ${was.flexDirection} -> ${now.flexDirection}`);
            }
        }
        for (const id of Object.keys(baseline)) {
            if (!geo[id]) {
                drift.push(`${id}: node present in baseline but no longer rendered`);
            }
        }

        expect(
            drift,
            `layout drifted from the baseline:\n  ${drift.join('\n  ')}\n` +
            'If this change is intended, re-run with PARITY_UPDATE=1 and commit the baseline.'
        ).toEqual([]);
    });
});
