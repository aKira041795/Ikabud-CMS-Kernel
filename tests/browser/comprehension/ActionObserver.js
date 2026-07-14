/**
 * ActionObserver — Collects runtime evidence during Playwright tests
 * for the Comprehension Engine.
 *
 * Tracks:
 *   - Button visibility and clicks
 *   - HTTP requests and responses
 *   - Console errors
 *   - URL changes
 *   - Page state (visible entity IDs, statuses)
 *
 * Usage in a test:
 *   var observer = new ActionObserver(page);
 *   await observer.start();
 *   // ... perform actions ...
 *   var evidence = await observer.stop();
 *   // evidence can be fed to comprehension engine
 */

// @ts-check

class ActionObserver {
    /**
     * @param {import('@playwright/test').Page} page
     */
    constructor(page) {
        this.page = page;
        /** @type {Array<{step: string, description: string, category: string, value: any}>} */
        this.evidence = [];
        this._requestListeners = [];
        this._responseListeners = [];
        this._consoleListeners = [];
        this._startTime = null;
    }

    /**
     * Start observing. Call before the action under test.
     */
    async start() {
        this._startTime = Date.now();
        this.evidence = [];

        // Track HTTP requests
        var self = this;
        this._requestListener = function (request) {
            self.evidence.push({
                step: 'http.request',
                description: request.method() + ' ' + request.url(),
                category: 'http',
                value: { method: request.method(), url: request.url(), timestamp: Date.now() },
            });
        };
        this.page.on('request', this._requestListener);

        // Track HTTP responses
        this._responseListener = function (response) {
            var status = response.status();
            self.evidence.push({
                step: status >= 200 && status < 300 ? 'http.response_ok' : 'http.response_error',
                description: response.method() + ' ' + response.url() + ' → ' + status,
                category: 'http',
                value: { status: status, url: response.url(), ok: status >= 200 && status < 300 },
            });
        };
        this.page.on('response', this._responseListener);

        // Track console errors
        this._consoleListener = function (msg) {
            if (msg.type() === 'error') {
                self.evidence.push({
                    step: 'console.error',
                    description: msg.text(),
                    category: 'ui',
                    value: { text: msg.text(), type: msg.type() },
                });
            }
        };
        this.page.on('console', this._consoleListener);
    }

    /**
     * Record a button visibility check.
     * @param {string} selector
     * @param {boolean} visible
     */
    async recordButtonVisible(selector, visible) {
        this.evidence.push({
            step: 'button.visible',
            description: 'Button ' + selector + ' visible: ' + visible,
            category: 'ui',
            value: { selector: selector, visible: visible },
        });
    }

    /**
     * Record that a button was clicked.
     * @param {string} selector
     */
    async recordButtonClick(selector) {
        this.evidence.push({
            step: 'button.clicked',
            description: 'Clicked: ' + selector,
            category: 'ui',
            value: { selector: selector, timestamp: Date.now() },
        });
    }

    /**
     * Record the current page URL.
     */
    async recordUrl() {
        var url = this.page.url();
        this.evidence.push({
            step: 'ui.url',
            description: 'Current URL: ' + url,
            category: 'ui',
            value: { url: url },
        });
    }

    /**
     * Record a DB-level probe result (called from test via API).
     * @param {string} step
     * @param {*} value
     */
    async recordProbe(step, value) {
        this.evidence.push({
            step: step,
            description: 'Probe: ' + step + ' = ' + JSON.stringify(value),
            category: 'db',
            value: value,
        });
    }

    /**
     * Stop observing and return collected evidence.
     */
    async stop() {
        if (this._requestListener) {
            this.page.removeListener('request', this._requestListener);
        }
        if (this._responseListener) {
            this.page.removeListener('response', this._responseListener);
        }
        if (this._consoleListener) {
            this.page.removeListener('console', this._consoleListener);
        }

        var duration = Date.now() - (this._startTime || Date.now());

        return {
            duration_ms: duration,
            steps: this.evidence,
            summary: this._summarize(),
        };
    }

    /**
     * Build a summary: key steps with pass/fail.
     */
    _summarize() {
        var summary = {};
        for (var i = 0; i < this.evidence.length; i++) {
            var e = this.evidence[i];
            if (!summary[e.step]) {
                summary[e.step] = { count: 0, ok: true, last_value: null };
            }
            summary[e.step].count++;
            if (e.category === 'http' && e.value && e.value.ok === false) {
                summary[e.step].ok = false;
            }
            summary[e.step].last_value = e.value;
        }
        return summary;
    }
}

module.exports = { ActionObserver };
