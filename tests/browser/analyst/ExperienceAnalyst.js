// @ts-check

/** Collects deterministic usability, accessibility and information-design signals. */
class ExperienceAnalyst {
    constructor(page) { this.page = page; }

    async inspect(pageId, url) {
        return this.page.evaluate(({ pageId, url }) => {
            const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            const controls = [...document.querySelectorAll('button,a[href],input,select,textarea')].filter(visible);
            const unnamed = controls.filter(el => {
                if (['INPUT', 'SELECT', 'TEXTAREA'].includes(el.tagName)) {
                    const id = el.getAttribute('id');
                    return !(el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('placeholder') || el.closest('label') || (id && document.querySelector(`label[for="${CSS.escape(id)}"]`)));
                }
                return !(el.textContent || '').trim() && !el.getAttribute('aria-label') && !el.getAttribute('title');
            });
            const headings = [...document.querySelectorAll('h1,h2,h3,h4,h5,h6')].filter(visible).map(h => ({ level: Number(h.tagName.slice(1)), text: (h.textContent || '').trim() }));
            const headingJumps = headings.filter((h, i) => i && h.level > headings[i - 1].level + 1).length;
            const tables = [...document.querySelectorAll('table')].filter(visible);
            const forms = [...document.querySelectorAll('form')].filter(visible);
            const duplicateIds = [...document.querySelectorAll('[id]')].map(e => e.id).filter((id, i, all) => all.indexOf(id) !== i);
            const horizontalOverflow = document.documentElement.scrollWidth > document.documentElement.clientWidth + 2;
            const actionLabels = [...document.querySelectorAll('[data-wb-action],button,a[role="button"]')].filter(visible).map(el => ({
                key: el.getAttribute('data-wb-action') || '',
                label: (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 100),
            })).filter(x => x.label);
            const fieldLabels = [...document.querySelectorAll('label')].filter(visible).map(el => (el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 100)).filter(Boolean);
            const pathDepth = location.pathname.split('/').filter(Boolean).length;
            return {
                page_id: pageId, url, title: document.title,
                metrics: {
                    controls: controls.length, forms: forms.length, tables: tables.length,
                    headings: headings.length, h1_count: headings.filter(h => h.level === 1).length,
                    unnamed_controls: unnamed.length, heading_jumps: headingJumps,
                    duplicate_ids: new Set(duplicateIds).size, horizontal_overflow: horizontalOverflow,
                    path_depth: pathDepth,
                },
                unnamed_controls: unnamed.map(el => ({
                    tag: el.tagName.toLowerCase(),
                    id: el.id || '', name: el.getAttribute('name') || '',
                    type: el.getAttribute('type') || '', action: el.getAttribute('data-wb-action') || '',
                    model: el.getAttribute('x-model') || '', href: el.getAttribute('href') || '',
                    classes: String(el.className || '').slice(0, 120),
                    html: el.outerHTML.slice(0, 300),
                })),
                terminology: { actions: actionLabels, fields: fieldLabels, headings: headings.map(h => h.text) },
                findings: [
                    ...(headings.filter(h => h.level === 1).length !== 1 ? [{ discipline: 'design-frontend', severity: 'major', rule: 'single-page-h1', detail: `Expected one page H1; found ${headings.filter(h => h.level === 1).length}` }] : []),
                    ...(unnamed.length ? [{ discipline: 'design-frontend', severity: 'major', rule: 'accessible-name', detail: `${unnamed.length} visible controls lack an accessible name` }] : []),
                    ...(headingJumps ? [{ discipline: 'design-frontend', severity: 'minor', rule: 'heading-order', detail: `${headingJumps} heading-level jumps found` }] : []),
                    ...(duplicateIds.length ? [{ discipline: 'frontend', severity: 'major', rule: 'unique-dom-id', detail: `${new Set(duplicateIds).size} duplicate DOM IDs found` }] : []),
                    ...(horizontalOverflow ? [{ discipline: 'design-frontend', severity: 'major', rule: 'viewport-overflow', detail: 'Page has horizontal overflow at the tested viewport' }] : []),
                ],
            };
        }, { pageId, url }).catch(error => ({ page_id: pageId, url, metrics: {}, findings: [{ discipline: 'frontend', severity: 'major', rule: 'analysis-failed', detail: error.message }] }));
    }

    async inspectKeyboard(maxTabs = 12) {
        await this.page.locator('body').click({ position: { x: 2, y: 2 } }).catch(() => {});
        const sequence = [];
        for (let i = 0; i < maxTabs; i++) {
            await this.page.keyboard.press('Tab');
            const focus = await this.page.evaluate(() => {
                const el = document.activeElement;
                if (!el || el === document.body) return null;
                const rect = el.getBoundingClientRect();
                const style = getComputedStyle(el);
                return {
                    tag: el.tagName.toLowerCase(),
                    key: el.getAttribute('data-wb-action') || el.getAttribute('name') || el.getAttribute('href') || '',
                    label: (el.getAttribute('aria-label') || el.textContent || '').trim().replace(/\s+/g, ' ').slice(0, 80),
                    visible: rect.width > 0 && rect.height > 0,
                    focus_visible: style.outlineStyle !== 'none' || style.boxShadow !== 'none',
                };
            });
            if (focus) sequence.push(focus);
        }
        const unique = new Set(sequence.map(x => `${x.tag}|${x.key}|${x.label}`));
        return {
            tabs: maxTabs, reached: sequence.length, unique_reached: unique.size,
            invisible_focus: sequence.filter(x => !x.visible).length,
            without_visible_focus: sequence.filter(x => !x.focus_visible).length,
            sequence,
        };
    }

    async inspectResponsive(viewports = [{ name: 'mobile', width: 390, height: 844 }, { name: 'tablet', width: 768, height: 1024 }]) {
        const original = this.page.viewportSize() || { width: 1280, height: 720 };
        const desktopVisiblePrimaryActions = await this.page.evaluate(() => {
            const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
            return [...document.querySelectorAll('button[type="submit"],[data-wb-action],a[role="button"]')].filter(visible).length;
        });
        const results = [];
        for (const viewport of viewports) {
            await this.page.setViewportSize({ width: viewport.width, height: viewport.height });
            await this.page.waitForTimeout(100);
            results.push(await this.page.evaluate(v => {
                const visible = el => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
                const primary = [...document.querySelectorAll('button[type="submit"],[data-wb-action],a[role="button"]')];
                return {
                    name: v.name, width: v.width,
                    horizontal_overflow: document.documentElement.scrollWidth > document.documentElement.clientWidth + 2,
                    visible_primary_actions: primary.filter(visible).length,
                    total_primary_actions: primary.length,
                    desktop_visible_primary_actions: v.desktopVisiblePrimaryActions,
                };
            }, { ...viewport, desktopVisiblePrimaryActions }));
        }
        await this.page.setViewportSize(original);
        return results;
    }
}

module.exports = { ExperienceAnalyst };
