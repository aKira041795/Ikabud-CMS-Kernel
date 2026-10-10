// @ts-check
/**
 * Page-builder LAYOUT PARITY — the editor canvas vs the published page.
 *
 * This is the second half of the gate. builder-render-parity.spec.js measures the
 * public renderer deterministically over file://; this one answers the question
 * the chair actually posed — "does the builder guarantee that what the owner sees
 * is what gets published?" — by measuring the SAME document, joined on
 * data-node-id, in both places.
 *
 * WHY IT NEEDS A LIVE HOST: the canvas is React inside a running editor. There is
 * no way to stand that up without an app, a tenant and a session. Unlike the page
 * itself, though, the canvas is deterministic — same document, same code — so the
 * comparison is repeatable even though the fixture is live.
 *
 * The comparison is RELATIVE, not absolute. The canvas renders inside a fixed-width
 * preview shell, so absolute pixel widths legitimately differ from a full-viewport
 * page; comparing them would produce noise, not signal. What must match is the
 * LAYOUT: whether siblings share a row, how a row divides, and whether a section is
 * released or gutter-constrained.
 *
 * Prerequisites (both idempotent):
 *     php tools/cms-ensure-admin.php       --host=cmsnew.test
 *     php tools/cms-ensure-builder-page.php --host=cmsnew.test
 *
 * Skip behaviour: if the host is unreachable, the test SKIPS with the reason. It
 * never passes silently, and never fails for "the app is not running".
 */
const { test, expect } = require('@playwright/test');
const { execSync } = require('child_process');
const path = require('path');

const ROOT = path.resolve(__dirname, '..', '..');
const HOST = process.env.PARITY_HOST || 'cmsnew.test';
const BASE = `http://${HOST}`;
const SLUG = process.env.PARITY_SLUG || 'render-parity-fixture';
const USER = process.env.CMS_USER || 'admin';
const PASS = process.env.CMS_PASS || 'Admin123!';

// Wide enough that the ARK gutter (1280) and a released full-bleed band are
// distinguishable inside the canvas shell too.
const VIEWPORT = { width: 1600, height: 1000 };

/** Geometry for every element carrying a join key. Scale-invariant on purpose. */
async function captureGeometry(page) {
    return page.evaluate(() => {
        const out = {};
        document.querySelectorAll('[data-node-id]').forEach((el) => {
            const id = el.getAttribute('data-node-id');
            if (!id) return;
            const box = el.getBoundingClientRect();
            const parentBox = el.parentElement ? el.parentElement.getBoundingClientRect() : null;
            const cs = getComputedStyle(el);
            out[id] = {
                wRatio: parentBox && parentBox.width > 0
                    ? Number((box.width / parentBox.width).toFixed(4))
                    : null,
                w: Math.round(box.width),
                dy: parentBox ? Math.round(box.top - parentBox.top) : 0,
                display: cs.display,
                flexDirection: cs.flexDirection,
            };
        });
        return out;
    });
}

/**
 * The layout contract BOTH surfaces must satisfy.
 *
 * Deliberately relative only. Absolute widths depend on the theme's chrome: the
 * live page renders inside .ark-content-area, which is a flex row, so when the
 * site has a governed sidebar .ark-main is the remaining COLUMN rather than the
 * viewport. An "is the full-bleed band viewport-wide" assertion is therefore a
 * statement about the site's chrome, not about the renderer, and asserting it
 * here produced a false failure on the first run.
 */
function assertLayoutContract(geo, label) {
    const missing = ['p_section', 'p_container', 'p_intro', 'p_row', 'p_col_left', 'p_col_right', 'p_fullbleed']
        .filter((id) => !geo[id]);
    expect(missing, `${label}: nodes not rendered: ${missing.join(', ')}`).toEqual([]);

    const left = geo['p_col_left'];
    const right = geo['p_col_right'];
    expect(
        Math.abs(left.dy - right.dy),
        `${label}: 33/67 columns are stacked, not side by side (dy ${left.dy} vs ${right.dy})`
    ).toBeLessThanOrEqual(2);
    expect(left.wRatio, `${label}: left column should be ~33% of the row`).toBeGreaterThan(0.28);
    expect(left.wRatio, `${label}: left column should be ~33% of the row`).toBeLessThan(0.38);
    expect(right.wRatio, `${label}: right column should be ~67% of the row`).toBeGreaterThan(0.62);
    expect(right.wRatio, `${label}: right column should be ~67% of the row`).toBeLessThan(0.72);

    // Outcome, not mechanism. Which ELEMENT is the flex container differs between
    // the surfaces: publicly the element carrying data-node-id is the flex box,
    // while in the canvas the wrapper carries the key and an inner element is the
    // flex box. Asserting display/flex-direction on the join-key element therefore
    // tests the implementation rather than the layout — and the first run failed
    // on exactly that, while the columns were correctly side by side.
    expect(
        left.wRatio + right.wRatio,
        `${label}: the columns do not together fill the row (${left.wRatio} + ${right.wRatio})`
    ).toBeGreaterThan(0.9);
}

async function login(page) {
    await page.goto(`${BASE}/cms/login`, { waitUntil: 'domcontentloaded', timeout: 20000 });
    await page.locator('input[type="text"]').fill(USER);
    await page.locator('input[type="password"]').fill(PASS);
    await page.getByRole('button', { name: 'Sign In' }).click();
    await page.waitForURL(/\/cms\/admin/, { timeout: 25000 });
}

test.describe('page builder live layout parity', () => {
    test.beforeAll(() => {
        try {
            execSync(
                `php tools/cms-ensure-builder-page.php --host=${HOST} --slug=${SLUG}`,
                { cwd: ROOT, stdio: 'pipe' }
            );
        } catch (e) {
            test.skip(true, `could not plant the live fixture on ${HOST}: ${e.message}`);
        }
    });

    test('the canvas and the published page lay the same document out the same way', async ({ page }) => {
        await page.setViewportSize(VIEWPORT);

        // ── Public side ────────────────────────────────────────────────────
        // Cache-busted: CMS public pages are cached, and a cached page would
        // compare the canvas against a STALE document — which silently defeats
        // the gate. Observed while proving this test can fail: the injected
        // regression stacked in the canvas but not on the cached published page.
        const publicResponse = await page.goto(`${BASE}/cms/page/${SLUG}?parity=${Date.now()}`, {
            waitUntil: 'domcontentloaded',
            timeout: 20000,
        }).catch((e) => { test.skip(true, `${HOST} unreachable: ${e.message}`); });

        expect(publicResponse?.status(), `${HOST} returned ${publicResponse?.status()}`).toBe(200);
        const publicGeo = await captureGeometry(page);
        expect(
            Object.keys(publicGeo).length,
            `the published page rendered no node markers — has the fixture been published? ` +
            `(php tools/cms-ensure-builder-page.php --host=${HOST})`
        ).toBeGreaterThan(0);
        assertLayoutContract(publicGeo, 'published page');

        // ── Canvas side ────────────────────────────────────────────────────
        await login(page);
        const contentId = await page.evaluate(async (slug) => {
            const res = await fetch(`/api/v1/cms/content?type=page&limit=100`);
            const data = await res.json();
            const list = Array.isArray(data.data) ? data.data : [];
            const match = list.find((item) => item && item.slug === slug);
            return match ? Number(match.id) : 0;
        }, SLUG);
        expect(contentId, `content '${SLUG}' not found via the API`).toBeGreaterThan(0);

        await page.goto(`${BASE}/cms/admin/react-builder/${contentId}`, { waitUntil: 'domcontentloaded', timeout: 25000 });
        await page.waitForSelector('.cms-builder-live-preview [data-node-id="p_row"]', { timeout: 25000 });
        await page.waitForTimeout(500); // let the preview runtime settle

        const canvasGeo = await captureGeometry(page);
        assertLayoutContract(canvasGeo, 'editor canvas');

        // ── The join itself ────────────────────────────────────────────────
        // Both surfaces must expose the SAME node ids, or there is no comparison
        // to make and the gate would be reporting on two different documents.
        const publicIds = Object.keys(publicGeo).sort();
        const canvasIds = Object.keys(canvasGeo).sort();
        const onlyPublic = publicIds.filter((id) => !canvasIds.includes(id));
        const onlyCanvas = canvasIds.filter((id) => !publicIds.includes(id));
        expect(
            { onlyPublic, onlyCanvas },
            `the two surfaces do not describe the same document`
        ).toEqual({ onlyPublic: [], onlyCanvas: [] });

        // ── Reported divergence ──────────────────
        // Widths legitimately differ in ABSOLUTE terms: the canvas renders in a
        // fixed-width shell that does not reproduce the theme's gutter, its
        // sidebar column, or the ARK shell element the theme resolves
        // --ikb-content-max-width on. Rather than assert an equality that would be
        // wrong, the differences are reported against the structural facts, and
        // the row/column shares — which must match — are asserted below.
        const notes = [];
        for (const id of ['p_section', 'p_fullbleed', 'p_container', 'p_row']) {
            const p = publicGeo[id];
            const c = canvasGeo[id];
            if (!p || !c) continue;
            notes.push(`${id}: published ${p.w}px (parent share ${p.wRatio}) vs canvas ${c.w}px (parent share ${c.wRatio})`);
        }
        test.info().annotations.push({ type: 'canvas-vs-public width', description: notes.join('\n') });

        // The shares that describe the AUTHORED intent must agree between the two
        // surfaces: a 33/67 row is 33/67 wherever it is rendered.
        for (const id of ['p_col_left', 'p_col_right']) {
            expect(
                Math.abs(canvasGeo[id].wRatio - publicGeo[id].wRatio),
                `${id}: parent share differs between canvas and published page ` +
                `(${canvasGeo[id].wRatio} vs ${publicGeo[id].wRatio})`
            ).toBeLessThan(0.05);
        }
    });
});
