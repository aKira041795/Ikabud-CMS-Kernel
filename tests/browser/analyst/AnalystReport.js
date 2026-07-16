// @ts-check
const fs = require('fs');
const path = require('path');
const { IssueCorrelator } = require('./IssueCorrelator');
const { UxEvolution } = require('./UxEvolution');

class AnalystReport {
    static build(input) {
        const issues = IssueCorrelator.correlate(input.issues || []);
        const roles = { 'web-design': [], frontend: [], backend: [], product: [], qa: [] };
        for (const issue of issues) {
            const d = issue.component === 'route-prerequisite' ? ['backend', 'qa']
                : issue.kind === 'http-error' || issue.status >= 500 ? ['backend', 'qa']
                : issue.component === 'PHP Runtime' ? ['backend']
                : issue.source === 'ux' || /heading|accessible|overflow|layout|control/i.test(issue.detail) ? ['web-design', 'frontend']
                : ['frontend', 'qa'];
            d.forEach(role => roles[role].push(issue.fingerprint));
            roles.product.push(issue.fingerprint);
        }
        const uxEvolution = UxEvolution.score({ pages: input.pages || [], task: input.task || {} });
        return {
            schema: 'ark.system-analyst-report.v1',
            run_id: input.runId,
            module: input.module,
            generated_at: new Date().toISOString(),
            understanding: input.process,
            coverage: input.coverage,
            pages: input.pages || [],
            task_effort: input.task || {},
            ux_evolution: uxEvolution,
            issues,
            role_indexes: Object.fromEntries(Object.entries(roles).map(([k, v]) => [k, [...new Set(v)]])),
            confidence: {
                process: input.process && input.process.dataFlows ? 'measured' : 'partial',
                runtime: (input.pages || []).length ? 'measured' : 'missing',
                issue_correlation: 'measured',
            },
        };
    }

    static write(report, rootDir) {
        const baselineFile = path.join(path.dirname(rootDir), 'ux-baselines', `${report.module}.json`);
        const baseline = UxEvolution.loadBaseline(baselineFile);
        report.ux_evolution.comparison = UxEvolution.compare(report.ux_evolution, baseline);
        report.ux_evolution.baseline_file = baselineFile;
        const mode = String(process.env.WB_UX_BASELINE_MODE || 'off').toLowerCase();
        report.ux_evolution.baseline_mode = mode;
        if (mode === 'update') {
            UxEvolution.writeBaseline(baselineFile, report.ux_evolution);
            report.ux_evolution.comparison = { status: 'updated', delta: 0, regressions: [] };
        }
        const threshold = Number(process.env.WB_UX_MIN_SCORE || 0);
        report.ux_evolution.gate = {
            threshold,
            passed: report.ux_evolution.score >= threshold && report.ux_evolution.comparison.status !== 'regressed',
        };
        const dir = path.join(rootDir, report.run_id || 'latest');
        fs.mkdirSync(dir, { recursive: true });
        const file = path.join(dir, 'system-analyst-report.json');
        fs.writeFileSync(file, JSON.stringify(report, null, 2));
        return file;
    }
}

module.exports = { AnalystReport };
