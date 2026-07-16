// @ts-check

const fs = require('fs');

class ScenarioGuidance {
    static load(file) {
        if (!file || !fs.existsSync(file)) return null;
        const scenario = JSON.parse(fs.readFileSync(file, 'utf8'));
        if (scenario.schema !== 'ark.scenario.v1') throw new Error('Unsupported scenario schema');
        return scenario;
    }

    static evaluate(scenario, diagnosticResult, phase = 'provisional') {
        if (!scenario) return { directions: [], questions: [], summary: { answered: 0, unresolved: 0 } };
        const pages = diagnosticResult.pages || [];
        const issues = diagnosticResult.issues || [];
        const directions = (scenario.directions || []).map(direction => {
            const route = direction.route || null;
            const pageEvidence = route ? pages.filter(page => String(page.url || page.route || '').includes(route)) : pages;
            const issueEvidence = route ? issues.filter(issue => String(issue.url || issue.route || '').includes(route)) : issues;
            let status = 'requires-human-validation';
            if (direction.check === 'route_available' && route) {
                const failed = issueEvidence.some(i => /http-error|404|500/i.test(`${i.kind || i.type || ''} ${i.detail || i.message || ''}`));
                status = failed ? 'failed' : (phase === 'final' ? (pageEvidence.length ? 'supported' : 'unresolved') : (pageEvidence.length ? 'provisionally-supported' : 'unresolved'));
            }
            return { ...direction, status, evidence: { matching_pages: pageEvidence.length, matching_issues: issueEvidence.length } };
        });
        const questions = (scenario.questions || []).map((question, index) => {
            const terms = question.toLowerCase().split(/[^a-z0-9]+/).filter(term => term.length > 3);
            const matches = issues.filter(issue => terms.some(term => JSON.stringify(issue).toLowerCase().includes(term)));
            return { question_id: `question-${index + 1}`, question, status: matches.length ? 'evidence-found' : 'requires-human-validation', evidence_issue_count: matches.length };
        });
        const resolved = [...directions, ...questions].filter(item => ['supported', 'failed', 'evidence-found'].includes(item.status)).length;
        return { schema: 'ark.scenario-guidance-result.v1', phase, scenario_id: scenario.scenario_id, directions, questions, summary: { answered: resolved, unresolved: directions.length + questions.length - resolved } };
    }
}

module.exports = { ScenarioGuidance };
