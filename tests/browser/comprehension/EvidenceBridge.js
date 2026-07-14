/**
 * EvidenceBridge — Feeds browser-discovered data into the PHP Comprehension Engine.
 *
 * Takes observations from:
 *   - ProcessComprehension (static structure)
 *   - ModuleDiagnostic (rendering checks)
 *   - BehaviorFlow (runtime interactions)
 *
 * And formats them as evidence JSON that the PHP Comprehension Engine's
 * BayesianReasoner can consume. This closes the loop: the JS tools
 * discover issues → the PHP engine analyzes them with Bayesian priors →
 * the result feeds back into the test harness.
 *
 * Usage:
 *   node -e "require('./EvidenceBridge').bridge(observations).write()"
 *   php kernel/Workbench/Comprehension/run.php project-audit-ledger --evidence=evidence-bridge.json
 *
 * @see kernel/Workbench/Comprehension/
 * @see kernel/Workbench/Comprehension/Analyzers/BayesianReasoner.php
 */

// @ts-check
const fs = require('fs');
const path = require('path');

class EvidenceBridge {

    /**
     * @param {string} moduleId
     * @param {string} [outputDir] - Where to write evidence files
     */
    constructor(moduleId, outputDir) {
        this.moduleId = moduleId;
        this.outputDir = outputDir || path.resolve(__dirname, '../../../test_results/evidence');
        /** @type {Array} */
        this.steps = [];
        this.meta = {
            generated_at: new Date().toISOString(),
            source: 'browser-test-harness',
            module: moduleId,
            tool_version: '2.0-hybrid',
        };
    }

    /**
     * Add a diagnostic observation as an evidence step.
     * @param {object} observation - From ModuleDiagnostic or BehaviorFlow
     * @param {string} actionId - e.g. 'pal.job-order.create'
     * @param {string} stepName - e.g. 'form.fields.render'
     */
    addObservation(observation, actionId, stepName) {
        const step = {
            action: actionId,
            step: stepName,
            success: observation.severity !== 'error' && observation.severity !== 'critical',
            count: 1,
            detail: observation.message || observation.expected || '',
            source: observation.type || 'diagnostic',
            timestamp: new Date().toISOString(),
        };
        this.steps.push(step);
        return this;
    }

    /**
     * Add multiple observations at once, grouped by action and step.
     * @param {Array} observations
     * @param {string} actionId
     */
    addObservations(observations, actionId) {
        // Group by step name derived from the observation type
        const grouped = {};
        for (const obs of observations) {
            const step = obs.type || 'check';
            const key = `${actionId}:${step}`;
            if (!grouped[key]) {
                grouped[key] = {
                    action: actionId,
                    step: step,
                    success: 0,
                    fail: 0,
                    details: [],
                };
            }
            if (obs.severity === 'error' || obs.severity === 'critical' || obs.severity === 'warning') {
                grouped[key].fail++;
                grouped[key].details.push(obs.message || obs.expected || '');
            } else {
                grouped[key].success++;
            }
        }

        for (const g of Object.values(grouped)) {
            this.steps.push({
                action: g.action,
                step: g.step,
                success: g.fail === 0,
                attempts: g.success + g.fail,
                successes: g.success,
                failures: g.fail,
                detail: g.details.join('; '),
                source: 'diagnostic',
                timestamp: new Date().toISOString(),
            });
        }
        return this;
    }

    /**
     * Add static structure data from ProcessComprehension.
     * @param {object} processReport
     */
    addProcessData(processReport) {
        this.meta.pages_discovered = processReport.pages;
        this.meta.fields_discovered = processReport.fields;
        this.meta.creatables_discovered = processReport.creatables;
        this.meta.data_flows_mapped = processReport.dataFlows;

        // Add step for each creatable — these are known behavioral patterns
        if (processReport.details && processReport.details.templates) {
            for (const tmpl of processReport.details.templates) {
                if (tmpl.creatables && tmpl.creatables.length > 0) {
                    for (const c of tmpl.creatables) {
                        this.steps.push({
                            action: `${this.moduleId}.${tmpl.page}`,
                            step: `creatable.${c.field}`,
                            success: true,
                            count: 1,
                            detail: c.reason,
                            source: 'static-analysis',
                            timestamp: new Date().toISOString(),
                        });
                    }
                }
            }
        }
        return this;
    }

    /**
     * Add behavioral flow observations.
     * @param {Array} flowObservations
     */
    addBehavioralData(flowObservations) {
        for (const obs of flowObservations) {
            this.steps.push({
                action: `${this.moduleId}.behavioral`,
                step: obs.type,
                success: obs.severity !== 'error' && obs.severity !== 'warning',
                count: 1,
                detail: obs.message,
                source: 'behavioral-flow',
                timestamp: new Date().toISOString(),
            });
        }
        return this;
    }

    /**
     * Write the evidence file and return the path.
     * @returns {string} Path to written file
     */
    write() {
        fs.mkdirSync(this.outputDir, { recursive: true });

        const evidence = {
            _meta: {
                ...this.meta,
                step_count: this.steps.length,
            },
            steps: this.steps,
        };

        const filename = `${this.moduleId}-bridge.json`;
        const filepath = path.join(this.outputDir, filename);
        fs.writeFileSync(filepath, JSON.stringify(evidence, null, 2));
        console.log(`  📄 Evidence written: ${filepath} (${this.steps.length} steps)`);
        return filepath;
    }

    /**
     * Run the PHP Comprehension Engine with this evidence.
     * @returns {string} Command to run
     */
    getRunCommand() {
        const enginePath = path.resolve(__dirname, '../../../kernel/Workbench/Comprehension/run.php');
        const evidencePath = path.join(this.outputDir, `${this.moduleId}-bridge.json`);
        return `php ${enginePath} ${this.moduleId} --evidence=${evidencePath}`;
    }
}

module.exports = { EvidenceBridge };
