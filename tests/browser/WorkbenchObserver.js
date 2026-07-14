/**
 * Workbench ActionObserver — captures runtime evidence from Playwright tests.
 *
 * Observes a user action's causal chain (UI → HTTP → DB) and writes
 * structured evidence files for the Comprehension Engine.
 *
 * Usage:
 *   var observer = new WorkbenchObserver(page, {
 *       action: 'pal.job-order.submit',
 *       module: 'project-audit-ledger',
 *       entityType: 'pal.project',
 *       entityId: projectId,
 *       title: PROJECT_TITLE,
 *       tenantId: 502,
 *       runId: RUN_ID,
 *   });
 *
 *   await observer.step('button.visible', async () => {
 *       await expect(btn).toBeVisible();
 *   });
 *
 *   await observer.step('button.clicked', async () => {
 *       await btn.click();
 *   });
 *
 *   var resp = await observer.waitForResponse('http.response', /api\/v1\/project-audit-ledger\/projects\/.*\/status/');
    *
 * await observer.checkDb('db.status_change', async () => {
 *       return await page.evaluate(async () => { /* ... *\/ });
 *   });
 *
 *   await observer.done(); // writes evidence JSON
 */

        var path = require('path');
        var fs = require('fs');

        class WorkbenchObserver {
            /**
             * @param {import('@playwright/test').Page} page
             * @param {{
             *   action: string,
             *   module: string,
             *   entityType: string,
             *   entityId: number|string,
             *   title?: string,
             *   tenantId?: number,
             *   runId?: string,
             *   evidenceDir?: string,
             * }} opts
             */
            constructor(page, opts) {
                this.page = page;
                this.action = opts.action;
                this.module = opts.module;
                this.entityType = opts.entityType;
                this.entityId = opts.entityId;
                this.title = opts.title || '';
                this.tenantId = opts.tenantId || null;
                this.runId = opts.runId || String(Date.now());

                this.evidenceDir = opts.evidenceDir || path.resolve('test_results', 'evidence');
                this.steps = [];
                this.summary = {};
                this.httpRequests = [];
                this.httpResponses = [];
                this.dbChecks = [];
                this.errors = [];
                this.startTime = Date.now();

                // Auto-register network listener
                this._setupNetworkCapture();
            }

            /** @returns {number} Unix microtime */
            _now() {
                var t = process.hrtime();
                return t[0] + t[1] / 1e9;
            }

            /** @returns {string} ISO timestamp */
            _iso() { return new Date().toISOString(); }

            /** Listen to all network requests/responses */
            _setupNetworkCapture() {
                if (!this.page) return;
                var self = this;

                this.page.on('request', function (req) {
                    self.httpRequests.push({
                        url: req.url(),
                        method: req.method(),
                        headers: req.headers(),
                        timestamp: self._now(),
                    });
                });

                this.page.on('response', function (res) {
                    self.httpResponses.push({
                        url: res.url(),
                        status: res.status(),
                        statusText: res.statusText(),
                        timestamp: self._now(),
                    });
                });

                this.page.on('console', function (msg) {
                    if (msg.type() === 'error') {
                        self.errors.push({
                            type: msg.type(),
                            text: msg.text(),
                            timestamp: self._iso(),
                        });
                    }
                });
            }

            /**
             * Record a step in the causal chain.
             * @param {string} stepName - e.g. 'button.visible'
             * @param {() => Promise<void>} action - async function that performs the step
             * @param {string} [category] - 'ui', 'http', 'service', 'db', 'event', 'audit', 'verify'
             */
            async step(stepName, action, category) {
                var start = this._now();
                var ok = false;
                var error = null;

                try {
                    await action();
                    ok = true;
                } catch (e) {
                    error = e.message || String(e);
                    this.errors.push({ type: 'step_error', step: stepName, text: error, timestamp: this._iso() });
                }

                var elapsed = this._now() - start;

                this.steps.push({
                    step: stepName,
                    category: category || 'ui',
                    value: ok,
                    error: error,
                    timestamp: start,
                    elapsed: elapsed,
                });

                this.summary[stepName] = { ok: ok, elapsed: elapsed, error: error };

                return ok;
            }

            /**
             * Record an HTTP response value directly.
             * @param {string} stepName
             * @param {number} statusCode
             * @param {object} body
             */
            recordHttp(stepName, statusCode, body) {
                var strBody = typeof body === 'string' ? body : JSON.stringify(body);
                this.steps.push({
                    step: stepName,
                    category: 'http',
                    value: statusCode >= 200 && statusCode < 300,
                    status: statusCode,
                    body: strBody.substring(0, 500),
                    timestamp: this._now(),
                });
                this.summary[stepName] = { ok: statusCode >= 200 && statusCode < 300, status: statusCode };
            }

            /**
             * Record a DB state check.
             * @param {string} stepName
             * @param {*} actualValue
             * @param {*} expectedValue
             */
            recordDb(stepName, actualValue, expectedValue) {
                var ok = actualValue === expectedValue;
                this.steps.push({
                    step: stepName,
                    category: 'db',
                    value: ok,
                    actual: actualValue,
                    expected: expectedValue,
                    timestamp: this._now(),
                });
                this.summary[stepName] = { ok: ok, actual: actualValue, expected: expectedValue };
            }

            /**
             * Record a log entry as evidence.
             * @param {string} stepName
             * @param {string} logText
             */
            recordLog(stepName, logText) {
                this.steps.push({
                    step: stepName,
                    category: 'audit',
                    value: true,
                    log: logText.substring(0, 500),
                    timestamp: this._now(),
                });
                this.summary[stepName] = { ok: true };
            }

            /**
             * Capture the response of a matching network request.
             * Returns {status, body} or null if not captured.
             * @param {string} stepName
             * @param {string|RegExp} urlPattern
             * @returns {Promise<{status: number, body: object}|null>}
             */
            /**
             * Wait for a specific network response matching urlPattern.
             * @param {string} stepName
             * @param {string|RegExp} urlPattern
             * @returns {Promise<{status: number, body: object}>}
             */
            async waitForResponse(stepName, urlPattern) {
                var self = this;
                var pattern = typeof urlPattern === 'string' ? new RegExp(urlPattern.replace(/\*/g, '.*')) : urlPattern;

                var response = await this.page.waitForResponse(pattern, { timeout: 10000 });
                var status = response.status();
                var body;

                try {
                    body = await response.json();
                } catch (e) {
                    try {
                        body = await response.text();
                    } catch (e2) {
                        body = '(unreadable)';
                    }
                }

                var strBody = typeof body === 'string' ? body : JSON.stringify(body);
                var ok = status >= 200 && status < 300;

                this.steps.push({
                    step: stepName,
                    category: 'http',
                    value: ok,
                    status: status,
                    body: strBody.substring(0, 500),
                    timestamp: this._now(),
                });
                this.summary[stepName] = { ok: ok, status: status, body: strBody.substring(0, 200) };

                return { status: status, body: body };
            }

            /**
             * Finish observation and write evidence file.
             * @returns {string} Path to written evidence file
             */
            async done() {
                var evidence = {
                    _meta: {
                        action: this.action,
                        module: this.module,
                        entity_type: this.entityType,
                        entity_id: this.entityId,
                        title: this.title,
                        tenant_id: this.tenantId,
                        run_id: this.runId,
                        recorded_at: this._iso(),
                        duration_ms: Date.now() - this.startTime,
                    },
                    steps: this.steps,
                    summary: this.summary,
                    errors: this.errors,
                    http_requests: this.httpRequests,
                    http_responses: this.httpResponses,
                };

                // Create dir if needed
                if (!fs.existsSync(this.evidenceDir)) {
                    fs.mkdirSync(this.evidenceDir, { recursive: true });
                }

                var safeAction = this.action.replace(/[^a-zA-Z0-9.-]/g, '_');
                var filePath = path.join(this.evidenceDir, safeAction + '.json');
                fs.writeFileSync(filePath, JSON.stringify(evidence, null, 2));

                return filePath;
            }
        }

        module.exports = { WorkbenchObserver };
