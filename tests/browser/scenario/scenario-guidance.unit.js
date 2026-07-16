const assert = require('assert');
const { ScenarioGuidance } = require('./ScenarioGuidance');

const scenario = {
    schema: 'ark.scenario.v1', scenario_id: 'sample',
    directions: [
        { direction_id: 'd1', statement: 'Route exists', check: 'route_available', route: '/admin/approvals' },
        { direction_id: 'd2', statement: 'Human judges label clarity', check: 'question', route: '/admin/approvals' },
    ],
    questions: ['Why is approval unavailable?'],
};
const result = ScenarioGuidance.evaluate(scenario, {
    pages: [{ url: '/admin/approvals' }],
    issues: [{ kind: 'http-error', detail: 'HTTP 404', url: '/admin/approvals' }],
}, 'final');
assert.strictEqual(result.directions[0].status, 'failed');
assert.strictEqual(result.directions[1].status, 'requires-human-validation');
assert.strictEqual(result.questions[0].status, 'evidence-found');
assert.strictEqual(result.summary.answered, 2);
assert.strictEqual(result.summary.unresolved, 1);
const provisional = ScenarioGuidance.evaluate(scenario, { pages: [{ url: '/admin/approvals' }], issues: [] });
assert.strictEqual(provisional.directions[0].status, 'provisionally-supported');
console.log('scenario-guidance.unit: all assertions passed');
