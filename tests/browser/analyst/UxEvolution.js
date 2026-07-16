// @ts-check
const fs = require('fs');
const path = require('path');

class UxEvolution {
    static terminology(pages) {
        const byAction = new Map();
        const variants = new Map();
        for (const page of pages || []) {
            for (const action of page.terminology?.actions || []) {
                if (action.key) {
                    if (!byAction.has(action.key)) byAction.set(action.key, new Set());
                    byAction.get(action.key).add(action.label);
                }
                const presentation = action.label
                    .replace(/^[^\p{L}\p{N}]+/u, '')
                    .replace(/[+−-]\s*$/u, '')
                    .trim();
                const canonical = presentation.toLowerCase().replace(/[^a-z0-9]+/g, ' ').trim();
                if (!variants.has(canonical)) variants.set(canonical, new Set());
                variants.get(canonical).add(presentation);
            }
        }
        const inconsistentActions = [...byAction.entries()].filter(([, labels]) => labels.size > 1).map(([key, labels]) => ({ key, labels: [...labels] }));
        const casingVariants = [...variants.entries()].filter(([, labels]) => labels.size > 1).map(([term, labels]) => ({ term, labels: [...labels] }));
        return { inconsistent_actions: inconsistentActions, casing_variants: casingVariants };
    }

    static score(input) {
        const pages = input.pages || [];
        const sum = key => pages.reduce((n, p) => n + Number(p.metrics?.[key] || 0), 0);
        const responsiveFailures = pages.flatMap(p => p.responsive || []).filter(r => r.horizontal_overflow || r.visible_primary_actions === 0 && r.desktop_visible_primary_actions > 0).length;
        const keyboardFailures = pages.filter(p => p.keyboard && (p.keyboard.invisible_focus > 0 || p.keyboard.unique_reached < Math.min(3, p.keyboard.tabs))).length;
        const term = this.terminology(pages);
        const penalties = {
            heading: Math.min(20, sum('h1_count') === pages.length ? 0 : pages.filter(p => p.metrics?.h1_count !== 1).length * 2),
            accessible_names: Math.min(25, sum('unnamed_controls')),
            structure: Math.min(10, sum('heading_jumps') + sum('duplicate_ids') * 2),
            responsive: Math.min(20, responsiveFailures * 3),
            keyboard: Math.min(15, keyboardFailures * 2),
            terminology: Math.min(10, term.inconsistent_actions.length * 2 + term.casing_variants.length),
            task_effort: Math.min(10, Math.max(0, Number(input.task?.interactions || 0) - Number(input.task?.successful_steps || 0) * 2)),
        };
        const totalPenalty = Object.values(penalties).reduce((a, b) => a + b, 0);
        return { score: Math.max(0, 100 - totalPenalty), penalties, terminology: term };
    }

    static compare(current, baseline) {
        if (!baseline) return { status: 'baseline-missing', delta: null, regressions: [] };
        const delta = Number((current.score - baseline.score).toFixed(2));
        const regressions = [];
        for (const [key, value] of Object.entries(current.penalties || {})) {
            const previous = Number(baseline.penalties?.[key] || 0);
            if (value > previous) regressions.push({ metric: key, previous, current: value, delta: value - previous });
        }
        return { status: regressions.length ? 'regressed' : delta > 0 ? 'improved' : 'stable', delta, regressions };
    }

    static loadBaseline(file) {
        try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch (_) { return null; }
    }

    static writeBaseline(file, result) {
        fs.mkdirSync(path.dirname(file), { recursive: true });
        fs.writeFileSync(file, JSON.stringify(result, null, 2));
    }
}

module.exports = { UxEvolution };
