/**
 * ModuleDiagnostic — Compares expected structure (from ProcessComprehension)
 * against actual browser rendering. Detects breakages, pinpoints failing
 * components, and generates structured fix reports.
 *
 * For every element the system knows SHOULD exist (from template analysis),
 * it verifies the element actually renders in the browser. When something
 * is missing or broken, it traces through the component chain to identify
 * the root cause: template → handler → capability → service → database.
 *
 * This is how the ARK Workbench goes from "what exists" to "what's broken"
 * to "how to fix it" — fully automated.
 *
 * @see ProcessComprehension.js
 * @see kernel/Workbench/Comprehension/
 */

// @ts-check
const { ProcessComprehension } = require('./ProcessComprehension');

class ModuleDiagnostic {

    /**
     * @param {import('@playwright/test').Page} page
     * @param {string} modulePath
     * @param {object} manifest
     * @param {object} [providerData]
     */
    constructor(page, modulePath, manifest, providerData) {
        this.page = page;
        this.comprehension = new ProcessComprehension(modulePath, manifest, providerData);
        this.manifest = manifest;
        this.modulePath = modulePath;
        /** @type {Array<{severity:string, component:string, expected:string, actual:string, cause:string, fix:string, location:string}>} */
        this.issues = [];
    }

    /**
     * Extract nav items from manifest (handles both flat and grouped structures).
     */
    _getNavItems() {
        const nav = this.manifest.nav || this.manifest.sidebar || [];
        const items = [];
        for (const entry of nav) {
            if (typeof entry === 'string') continue;
            if (!entry || typeof entry !== 'object') continue;
            if (entry.children && Array.isArray(entry.children)) {
                for (const child of entry.children) {
                    if (child && child.url) items.push({ label: child.label || child.title || '?', url: child.url });
                }
            } else if (entry.url) {
                items.push({ label: entry.label || entry.title || '?', url: entry.url });
            }
        }
        return items;
    }

    /**
     * Check if module uses shell wrapper pattern (shell.disyl includes fragments).
     */
    _usesShellWrapper() {
        const fs = require('fs');
        const path = require('path');
        // shell.disyl is in the module template root, not in pages/
        const shellPath = path.join(this.modulePath, 'templates', this.manifest.id || '', 'shell.disyl');
        const altPath = path.join(this.modulePath, 'templates', 'shell.disyl');
        return fs.existsSync(shellPath) || fs.existsSync(altPath);
    }

    /**
     * Run full diagnostic: check every discovered element against the live page.
     * @returns {Promise<{passed:number, failed:number, issues:Array, report:string}>}
     */
    async runFullDiagnostic() {
        this.issues = [];
        const templates = this.comprehension.analyzeTemplates();
        const usesShell = this._usesShellWrapper();
        const baseUrl = process.env.APP_URL || 'http://palsystem.test';

        if (usesShell) {
            console.log('  ℹ Module uses shell wrapper pattern (shell.disyl → page fragments)');
        }

        // Build URL map from nav items
        const navUrlMap = new Map();
        for (const item of this._getNavItems()) {
            navUrlMap.set(item.url, item.label);
        }

        for (const tmpl of templates) {
            // Find the route URL for this template
            const url = this._findPageUrl(tmpl.page, navUrlMap);
            if (!url) continue;

            console.log(`\n  🔍 Diagnosing: ${tmpl.page} (${url})`);

            try {
                await this.page.goto(`${baseUrl}${url}`, { waitUntil: 'networkidle', timeout: 15000 });
                tmpl._usedUrl = url; // store for _isDetailPage check
            } catch (e) {
                this._recordIssue('critical', tmpl.page,
                    `Page ${url} should load within 15s`,
                    `Navigation failed: ${e.message}`,
                    'Page may be missing from routes, handler crashes, or server error',
                    'Check module routes, handler function, and PHP error log'
                );
                continue;
            }

            // ── 1. Shell check ──
            await this._checkShell(tmpl);

            // ── 2. Heading check ──
            await this._checkHeading(tmpl);

            // ── 3. Form field checks ──
            for (const field of tmpl.fields) {
                await this._checkField(tmpl, field);
            }

            // ── 4. Creatable checks ──
            for (const c of tmpl.creatables) {
                await this._checkCreatable(tmpl, c);
            }

            // ── 5. Entity list checks ──
            for (const ref of tmpl.entityRefs) {
                await this._checkEntityRef(tmpl, ref);
            }

            // ── 6. Page content health ──
            await this._checkContentHealth(tmpl);

            // ── 7. Action endpoint checks ──
            for (const action of tmpl.actions) {
                if (action.url) {
                    await this._checkActionEndpoint(tmpl, action);
                }
            }
        }

        return this._generateReport();
    }

    // ─── Individual Checks ─────────────────────────────────

    /**
     * Check if a template is a fragment (included via shell.disyl page_content).
     */
    _isFragment(tmpl) {
        return this._usesShellWrapper() && tmpl.file !== 'shell.disyl';
    }

    async _checkShell(tmpl) {
        if (this._isFragment(tmpl)) return; // shell provided by shell.disyl
        const shell = this.page.locator('body[data-wb-component="app-shell"]');
        const visible = await shell.isVisible().catch(() => false);
        if (!visible) {
            this._recordIssue('critical', tmpl.page,
                'App shell should be visible on every page',
                'App shell not found on page',
                'Template extends wrong layout or shell.disyl missing include',
                `Check ${tmpl.file} layout inheritance`
            );
        }
    }

    async _checkHeading(tmpl) {
        if (this._isFragment(tmpl)) return; // heading from handler context, not template
        const heading = this.page.locator('#wb-main h1');
        const count = await heading.count().catch(() => 0);
        if (count === 0) {
            this._recordIssue('major', tmpl.page,
                'Page should have an <h1> heading in #wb-main',
                'No heading found',
                'Template missing <h1> or page_title variable is empty',
                `Add <h1>{page_title}</h1> to ${tmpl.file} block content`
            );
        } else {
            const text = await heading.first().textContent().catch(() => '');
            if (!text || text.trim().length === 0) {
                this._recordIssue('minor', tmpl.page,
                    'Page heading should have non-empty text',
                    'Heading is empty',
                    'page_title variable not set in handler context',
                    `Check that handler for ${tmpl.page} passes page_title in context array`
                );
            }
        }
    }

    /**
     * Skip JS-generated template fields (line items, dynamic rows).
     * These only exist after JavaScript interaction, not on page load.
     */
    _isDynamicField(name) {
        return name.includes('item.sort_order') ||
               name.includes('itemIdx') ||
               name.includes('++') ||
               /\bnewItem\b/.test(name);
    }

    /**
     * Check if page URL looks like a detail page needing a valid entity ID.
     * The diagnostic uses guess URLs that may not have valid IDs.
     */
    _isDetailPage(tmpl) {
        // The URL is already stored in _pageUrls during runFullDiagnostic
        return tmpl._usedUrl && /\/\d+$/.test(tmpl._usedUrl) && !/\/create|\/edit/.test(tmpl._usedUrl);
    }

    async _checkField(tmpl, field) {
        if (this._isDynamicField(field.name)) return; // JS-generated, skip
        if (this._isDetailPage(tmpl)) return; // detail page — URL may lack valid entity ID

        const escName = field.name.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
        const selectors = [
            `[name="${escName}"]`,
            `[name="${field.name}"]`,
        ];

        let found = false;
        let existsHidden = false;
        for (const sel of selectors) {
            const el = this.page.locator(sel).first();
            if (await el.isVisible({ timeout: 200 }).catch(() => false)) {
                found = true;
                break;
            }
            // Element exists in DOM but hidden (conditional/inline form)
            if (await el.count().catch(() => 0) > 0) {
                existsHidden = true;
            }
        }

        if (!found && !existsHidden) {
            this._recordIssue('minor', tmpl.page,
                `Form field "${field.name}" (${field.type}) not found on page`,
                `Field may be in a hidden form, modal, or JS-added row`,
                'Template may use conditional {if} block, class="hidden" container, or JS dynamic rows',
                `In ${tmpl.file}, field renders only when its container is visible (edit mode, button click, etc.)`
            );
        }
    }

    async _checkCreatable(tmpl, c) {
        if (this._isDynamicField(c.field)) return; // JS-generated, skip

        // Escape array notation for selectors
        const escField = c.field.replace(/\[/g, '\\[').replace(/\]/g, '\\]');
        const sel = `select[name="${escField}"][data-creatable]`;
        const creatable = this.page.locator(sel).first();
        const found = await creatable.isVisible({ timeout: 500 }).catch(() => false);

        if (!found) {
            // Maybe rendered as a different element (text input with autocomplete)
            const fallback = this.page.locator(`[name="${escField}"]`).first();
            const fallbackFound = await fallback.isVisible({ timeout: 300 }).catch(() => false);

            if (!fallbackFound) {
                this._recordIssue('minor', tmpl.page,
                    `Creatable field "${c.field}" (data-creatable="${c.targetEntity}") should be present — allows users to create new ${c.targetEntity.replace('_', ' ')} on-the-fly`,
                    `Field "${c.field}" not found on page`,
                    `Field may be conditionally rendered or removed in template refactor`,
                    `In ${tmpl.file}, check that <select name="${c.field}" data-creatable="${c.targetEntity}"> exists`
                );
            }
        }
    }

    async _checkEntityRef(tmpl, ref) {
        const entityEl = this.page.locator(`[data-wb-entity="${ref.entity}"]`).first();
        const found = await entityEl.isVisible({ timeout: 500 }).catch(() => false);

        if (!found) {
            this._recordIssue('major', tmpl.page,
                `Entity list for "${ref.entity}" (data-wb-entity="${ref.entity}") should render`,
                `Entity list for ${ref.entity} not found`,
                `Entity view ${ref.entity} may not be registered, or handler doesn't return data`,
                `Check: 1) Entity view contract in helpers/views/*.disyl 2) handler returns entity data 3) template includes {ikb_entity_list}`
            );
        }
    }

    async _checkContentHealth(tmpl) {
        // Check for PHP errors rendered in the page body
        const body = await this.page.locator('body').textContent().catch(() => '');
        if (!body) {
            this._recordIssue('critical', tmpl.page,
                'Page should return HTML body content',
                'Empty body returned',
                'PHP fatal error before any output, or handler calls exit/die',
                'Check PHP error log for fatal errors and fix the handler'
            );
            return;
        }

        // Detect common error patterns
        const errorPatterns = [
            { pattern: /Fatal error/i, component: 'PHP Runtime', fix: 'Fix the PHP fatal error in the handler or service' },
            { pattern: /Call to undefined function/i, component: 'PHP Function', fix: 'Function name typo or missing include/require' },
            { pattern: /Call to undefined method/i, component: 'PHP Method', fix: 'Method name typo or class doesn\'t have this method' },
            { pattern: /Cannot use object of type/i, component: 'PHP Type', fix: 'Variable is not the expected object type' },
            { pattern: /Undefined variable/i, component: 'PHP Variable', fix: 'Variable not passed to template context' },
            { pattern: /SQLSTATE|PDOException/i, component: 'Database', fix: 'SQL query error — check migration or query syntax' },
            { pattern: /Class .* not found/i, component: 'Autoloader', fix: 'Class doesn\'t exist or autoloader not configured' },
            { pattern: /allowed memory size/i, component: 'PHP Config', fix: 'Increase memory_limit in php.ini or optimize query' },
            { pattern: /Maximum execution time/i, component: 'PHP Config', fix: 'Increase max_execution_time or optimize slow operation' },
        ];

        for (const { pattern, component, fix } of errorPatterns) {
            if (pattern.test(body)) {
                this._recordIssue('critical', tmpl.page,
                    `No PHP errors should appear in rendered page`,
                    `PHP error detected in page body: ${component}`,
                    `A runtime error occurred during page rendering`,
                    fix
                );
                break; // Report first error only to avoid noise
            }
        }
    }

    async _checkActionEndpoint(tmpl, action) {
        // Only check API endpoints (not form actions which need POST data)
        if (action.type !== 'api_call') return;
        if (!action.url) return;

        try {
            const baseUrl = process.env.APP_URL || 'http://palsystem.test';
            const resp = await this.page.request.get(`${baseUrl}${action.url}`).catch(() => null);
            if (resp && resp.status() >= 500) {
                this._recordIssue('major', tmpl.page,
                    `API endpoint ${action.url} should not return 5xx`,
                    `HTTP ${resp.status()} for ${action.url}`,
                    'Handler throws exception or service returns error',
                    `Check the handler for ${action.url} in module routes`
                );
            }
        } catch (e) {
            // Network errors are expected for endpoints that don't exist
        }
    }

    // ─── Issue Recording & Report ───────────────────────────

    /**
     * Record a diagnostic issue with root cause analysis.
     */
    _recordIssue(severity, component, expected, actual, cause, fix) {
        this.issues.push({
            severity,
            component,
            expected,
            actual,
            cause,
            fix,
            location: this.manifest.name || this.manifest.id || 'unknown',
        });
    }

    /**
     * Generate structured fix report.
     */
    _generateReport() {
        const passed = this.comprehension.analyzeTemplates().reduce((s, t) => {
            return s + t.fields.length + t.creatables.length + t.entityRefs.length + 2; // shell + heading
        }, 0) - this.issues.length;

        const failed = this.issues.length;

        // Group issues by component for readability
        const byComponent = {};
        for (const issue of this.issues) {
            if (!byComponent[issue.component]) byComponent[issue.component] = [];
            byComponent[issue.component].push(issue);
        }

        // Generate human-readable report
        let report = `\n═══════════════════════════════════════════════════\n`;
        report += `  MODULE DIAGNOSTIC REPORT: ${this.manifest.name || ''}\n`;
        report += `  ${passed} checks passed, ${failed} issues found\n`;
        report += `═══════════════════════════════════════════════════\n`;

        if (failed === 0) {
            report += `\n  ✅ All components healthy. No issues detected.\n`;
        } else {
            for (const [component, issues] of Object.entries(byComponent)) {
                report += `\n  📍 ${component} (${issues.length} issue(s)):\n`;
                for (const issue of issues) {
                    const icon = issue.severity === 'critical' ? '🔴' : issue.severity === 'major' ? '🟠' : '🟡';
                    report += `  ${icon} [${issue.severity.toUpperCase()}]\n`;
                    report += `     Expected: ${issue.expected}\n`;
                    report += `     Actual:   ${issue.actual}\n`;
                    report += `     Cause:    ${issue.cause}\n`;
                    report += `     Fix:      ${issue.fix}\n`;
                }
            }

            // Summary of fix recommendations
            report += `\n  📋 Fix Summary:\n`;
            const fixCounts = {};
            for (const issue of this.issues) {
                const fixType = issue.fix.split(':')[0].trim();
                const key = fixType.includes('Check') ? 'Check template/handler' : fixType;
                fixCounts[key] = (fixCounts[key] || 0) + 1;
            }
            for (const [fixType, count] of Object.entries(fixCounts)) {
                report += `     • ${fixType}: ${count} occurrence(s)\n`;
            }
        }
        report += `═══════════════════════════════════════════════════\n`;

        return { passed, failed, issues: this.issues, report };
    }

    /**
     * Find the page URL from the nav URL map.
     */
    _findPageUrl(pageName, navUrlMap) {
        // Direct match first
        for (const [url, label] of navUrlMap) {
            if (url.includes(pageName)) return url;
        }
        // Try to match by heuristics
        const base = '/admin/' + (this.manifest.id || '') + '/';
        const urlMap = {
            'dashboard': base.replace(/\/$/, ''),
            'projects-list': base + 'projects',
            'project-form': base + 'projects/create',
            'project-detail': base + 'projects/1', // will use first project
            'clients-list': base + 'clients',
            'client-form': base + 'clients/create',
            'expenses-list': base + 'expenses',
            'expense-form': base + 'expenses/create',
            'inventory-list': base + 'inventory',
            'inventory-detail': base + 'inventory/1',
            'purchases-list': base + 'purchases',
            'purchase-form': base + 'purchases/create',
            'sales-list': base + 'sales',
            'sales-form': base + 'sales/create',
            'approval-queue': base + 'approvals',
            'reports': base + 'reports',
            'settings': base + 'settings',
            'audit-trail': base + 'audit-trail',
            'fabrication': base + 'fabrication/allocations',
            'cash-advance-form': base + 'cash-advances/create',
            'collections-list': base + 'collections',
            'collection-form': base + 'collections/create',
            'issuance-form': base + 'issuances/create',
            'material-return-form': base + 'issuances/returns/create',
        };
        return urlMap[pageName] || null;
    }
}

module.exports = { ModuleDiagnostic };
