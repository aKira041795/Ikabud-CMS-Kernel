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
const { RuntimeResolver } = require('./analyst/RuntimeResolver');
const { ExperienceAnalyst } = require('./analyst/ExperienceAnalyst');

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
        this.resolver = new RuntimeResolver(page, manifest);
        this.experience = new ExperienceAnalyst(page);
        this.getRoutes = this._parseGetRoutes();
        this.pageAnalyses = [];
        this.responsiveKinds = new Set();
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
        this.pageAnalyses = [];
        const templates = this.comprehension.analyzeTemplates();
        const usesShell = this._usesShellWrapper();
        const baseUrl = process.env.APP_URL || 'http://palsystem.test';

        if (usesShell) {
            console.log('  ℹ Module uses shell wrapper pattern (shell.disyl → page fragments)');
        }

        // Audit the navigation rendered to the authenticated user. Manifest-only
        // discovery cannot catch drift in helper-built or template-built sidebars.
        await this._auditRuntimeNavigation(baseUrl);

        // Build URL map from nav items
        const navUrlMap = new Map();
        for (const item of this._getNavItems()) {
            navUrlMap.set(item.url, item.label);
        }
        await this.resolver.seedFromNavigation();

        for (const tmpl of templates) {
            // Find the route URL for this template
            const templateUrl = this._findPageUrl(tmpl.page, navUrlMap);
            if (!templateUrl) {
                this._recordIssue('critical', tmpl.page,
                    `A resolvable page URL for template ${tmpl.page}`,
                    'Template was silently outside dynamic test coverage',
                    'No manifest navigation URL or conventional route mapping matched this template',
                    'Declare the page URL in module navigation/route metadata or extend the explicit route mapping',
                    { kind: 'coverage-gap' }
                );
                continue;
            }
            let url = await this.resolver.discover(templateUrl);
            if (!url && /(?:\{\w+\}|:\w+|\/\d+(?=\/|$))/.test(templateUrl)) {
                this.issues.push(this.resolver.classifyUnresolved(templateUrl));
                continue;
            }
            url = url || RuntimeResolver.withInspection(templateUrl);

            console.log(`\n  🔍 Diagnosing: ${tmpl.page} (${url})`);

            try {
                const response = await this.page.goto(`${baseUrl}${url}`, { waitUntil: 'networkidle', timeout: 15000 });
                const status = response ? response.status() : 0;
                if (status >= 400 || status === 0) {
                    this._recordIssue('critical', tmpl.page,
                        `Page ${url} returns a successful document response`,
                        `HTTP ${status || 'unavailable'}`,
                        status === 404 ? 'No GET route resolved for the diagnosed page' : 'The page document request failed',
                        'Correct the page-route contract or register a working GET handler',
                        { kind: 'page-http-error', url, status }
                    );
                    continue;
                }
                tmpl._usedUrl = url; // store for _isDetailPage check
                await this.resolver.observeCurrentPage();
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

            // ── 7. Deterministic experience and accessibility signals ──
            const experience = await this.experience.inspect(tmpl.page, url);
            experience.keyboard = await this.experience.inspectKeyboard();
            const pageKind = /form|create|edit/.test(tmpl.page) ? 'form'
                : /detail/.test(tmpl.page) ? 'detail'
                : /list|queue/.test(tmpl.page) ? 'list' : 'dashboard';
            if (!this.responsiveKinds.has(pageKind)) {
                experience.responsive = await this.experience.inspectResponsive();
                this.responsiveKinds.add(pageKind);
            }
            if (experience.keyboard.invisible_focus > 0) {
                experience.findings.push({ discipline: 'design-frontend', severity: 'major', rule: 'keyboard-visible-target', detail: `${experience.keyboard.invisible_focus} tab stops focus invisible controls` });
            }
            if (experience.keyboard.unique_reached < Math.min(3, experience.keyboard.tabs)) {
                experience.findings.push({ discipline: 'design-frontend', severity: 'major', rule: 'keyboard-progress', detail: `Tab navigation reached only ${experience.keyboard.unique_reached} unique controls` });
            }
            for (const responsive of experience.responsive || []) {
                if (responsive.horizontal_overflow) experience.findings.push({ discipline: 'design-frontend', severity: 'major', rule: `responsive-overflow-${responsive.name}`, detail: `Horizontal overflow at ${responsive.width}px` });
                if (responsive.desktop_visible_primary_actions > 0 && responsive.visible_primary_actions === 0) experience.findings.push({ discipline: 'design-frontend', severity: 'critical', rule: `responsive-actions-${responsive.name}`, detail: `${responsive.desktop_visible_primary_actions} desktop-visible primary actions become unavailable at ${responsive.width}px` });
            }
            this.pageAnalyses.push(experience);
            for (const finding of experience.findings || []) {
                this._recordIssue(finding.severity, tmpl.page,
                    `UX rule ${finding.rule} should pass`, finding.detail,
                    'Rendered information architecture or accessible semantics violate a deterministic rule',
                    `Review ${tmpl.file} for ${finding.rule}`,
                    { kind: 'ux', source: 'ux', rule: finding.rule, discipline: finding.discipline }
                );
            }

            // ── 8. Action endpoint checks ──
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
    _recordIssue(severity, component, expected, actual, cause, fix, metadata = {}) {
        this.issues.push({
            severity,
            component,
            expected,
            actual,
            cause,
            fix,
            location: this.manifest.name || this.manifest.id || 'unknown',
            ...metadata,
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

        return { passed, failed, issues: this.issues, pages: this.pageAnalyses, report };
    }

    /**
     * Verify every internal link in the sidebar actually rendered by the app.
     * Uses the authenticated browser context, but does not mutate page state.
     */
    async _auditRuntimeNavigation(baseUrl) {
        const hrefs = await this.page.locator('#wb-sidebar .wb-nav-item[href]')
            .evaluateAll(nodes => nodes.map(node => node.getAttribute('href')).filter(Boolean))
            .catch(() => []);
        const origin = new URL(baseUrl).origin;
        const urls = [...new Set(hrefs)].filter(href => {
            try {
                const target = new URL(href, baseUrl);
                return target.origin === origin && target.pathname.startsWith('/')
                    && !/\/(?:logout|download)(?:\/|$)/.test(target.pathname);
            } catch (_) {
                return false;
            }
        });

        if (urls.length === 0) {
            this._recordIssue('critical', 'runtime-navigation',
                'At least one authenticated sidebar navigation link',
                'No internal sidebar links were discoverable',
                'The rendered shell is missing navigation or uses an unsupported contract',
                'Render internal navigation as #wb-sidebar .wb-nav-item[href]',
                { kind: 'navigation-coverage' }
            );
            return;
        }

        const request = this.page.context().request;
        await Promise.all(urls.map(async href => {
            const target = new URL(href, baseUrl).toString();
            try {
                const response = await request.get(target, { maxRedirects: 0, timeout: 15000 });
                const status = response.status();
                const location = response.headers()['location'] || '';
                if (status >= 400 || (status >= 300 && /\/login(?:\?|$)/.test(location))) {
                    this._recordIssue('critical', href,
                        'Rendered sidebar navigation returns a usable authenticated page',
                        `HTTP ${status}${location ? ` → ${location}` : ''}`,
                        status === 404
                            ? 'Rendered navigation URL has no registered GET route'
                            : 'Rendered navigation is unavailable or redirects out of the authenticated application',
                        'Register the GET route or correct the rendered sidebar URL',
                        { kind: 'broken-navigation', url: href, status }
                    );
                }
            } catch (error) {
                this._recordIssue('critical', href,
                    'Rendered sidebar navigation is reachable',
                    `Navigation probe failed: ${error.message || error}`,
                    'The route could not be reached by the authenticated Workbench browser context',
                    'Check the route, server availability, and authentication middleware',
                    { kind: 'broken-navigation', url: href, status: 0 }
                );
            }
        }));
    }

    /**
     * Find the page URL from the nav URL map.
     */
    _findPageUrl(pageName, navUrlMap) {
        const explicit = this.manifest.workbench?.page_routes?.[pageName];
        if (typeof explicit === 'string' && explicit.startsWith('/')) return explicit;

        // Direct match first
        for (const [url, label] of navUrlMap) {
            const moduleBase = `/admin/${this.manifest.id || ''}/`;
            const semanticPath = url.startsWith(moduleBase) ? url.slice(moduleBase.length) : url;
            const semanticName = semanticPath.replace(/^\/+|\/+$/g, '').replace(/\//g, '-');
            if (url.includes(pageName) || semanticName === pageName) return url;
        }
        // Infer conventional paths from the template's semantic name. Entity IDs
        // remain placeholders and are resolved from links observed at runtime.
        const base = '/admin/' + (this.manifest.id || '') + '/';
        if (pageName === 'dashboard' || pageName === 'index') return base.replace(/\/$/, '');
        const normalized = String(pageName).replace(/_/g, '-');
        const special = {
            'approval-queue': 'approvals',
            'audit-trail': 'audit-trail',
            'bill-of-materials': 'bom',
            'reports-center': 'reports',
        };
        if (special[normalized]) return base + special[normalized];

        const semanticRoute = this._findDeclaredRouteBySemanticName(normalized);
        if (semanticRoute) return semanticRoute;

        const match = normalized.match(/^(.+?)-(list|form|detail|create|edit)$/);
        if (!match) return null;
        let entity = match[1];
        const navCollection = [...navUrlMap.keys()].find(url => {
            const leaf = String(url).split('?')[0].replace(/\/$/, '').split('/').pop() || '';
            return leaf === entity || leaf === `${entity}s` || leaf === `${entity}es` ||
                (entity.endsWith('y') && leaf === `${entity.slice(0, -1)}ies`);
        });
        let collection = navCollection || '';
        if (!collection) {
            const uncountable = new Set(['inventory', 'equipment', 'information', 'staff']);
            if (uncountable.has(entity) || entity.endsWith('s')) collection = base + entity;
            else if (entity.endsWith('y') && !/[aeiou]y$/.test(entity)) collection = base + entity.slice(0, -1) + 'ies';
            else if (/(?:ch|sh|x|z)$/.test(entity)) collection = base + entity + 'es';
            else collection = base + entity + 's';
        }
        collection = collection.replace(/\/$/, '');
        let candidate = null;
        if (match[2] === 'list') candidate = collection;
        if (match[2] === 'form' || match[2] === 'create') candidate = collection + '/create';
        if (match[2] === 'detail') candidate = collection + '/{id}';
        if (match[2] === 'edit') candidate = collection + '/{id}/edit';
        if (candidate && this.getRoutes.some(route => this._routePatternsEquivalent(route, candidate))) return candidate;
        return null;
    }

    _parseGetRoutes() {
        const fs = require('fs');
        const path = require('path');
        const routesPath = path.join(this.modulePath, 'routes.php');
        if (!fs.existsSync(routesPath)) return [];
        const source = fs.readFileSync(routesPath, 'utf-8');
        const getMatch = /['"]GET['"]\s*=>\s*\[/.exec(source);
        if (!getMatch) return [];
        const start = getMatch.index + getMatch[0].lastIndexOf('[');
        let depth = 0;
        let quote = '';
        let escaped = false;
        let end = source.length;
        for (let i = start; i < source.length; i++) {
            const ch = source[i];
            if (quote) {
                if (escaped) escaped = false;
                else if (ch === '\\') escaped = true;
                else if (ch === quote) quote = '';
                continue;
            }
            if (ch === "'" || ch === '"') { quote = ch; continue; }
            if (ch === '[') depth++;
            if (ch === ']' && --depth === 0) { end = i; break; }
        }
        const block = source.slice(start + 1, end);
        return [...block.matchAll(/['"]([^'"]+)['"]\s*=>/g)].map(match => match[1]);
    }

    _routePatternsEquivalent(left, right) {
        const normalize = value => String(value)
            .split('?')[0]
            .replace(/\{[^}]+\}|:[A-Za-z_][A-Za-z0-9_]*/g, '{id}')
            .replace(/\/$/, '') || '/';
        return normalize(left) === normalize(right);
    }

    _findDeclaredRouteBySemanticName(pageName) {
        const base = `/admin/${this.manifest.id || ''}/`;
        const singular = value => {
            if (/ies$/.test(value)) return value.slice(0, -3) + 'y';
            if (/s$/.test(value) && !/ss$/.test(value)) return value.slice(0, -1);
            return value;
        };
        for (const route of this.getRoutes) {
            if (!route.startsWith(base)) continue;
            const parts = route.slice(base.length).split('/').filter(Boolean);
            const hasTrailingId = /^(?:\{[^}]+\}|:[A-Za-z_])/.test(parts.at(-1) || '');
            const hasEdit = parts.at(-1) === 'edit';
            const hasCreate = parts.at(-1) === 'create';
            const staticParts = parts.filter(part => !/^(?:\{[^}]+\}|:[A-Za-z_])/.test(part));
            if (hasEdit || hasCreate) staticParts.pop();
            const raw = staticParts.join('-');
            const singularName = staticParts.map(singular).join('-');
            const candidates = new Set([raw, singularName, `${raw}-list`, `${singularName}-list`]);
            if (hasCreate || hasEdit) candidates.add(`${singularName}-form`);
            if (hasTrailingId) candidates.add(`${singularName}-detail`);
            if (candidates.has(pageName)) return route;
        }
        return null;
    }
}

module.exports = { ModuleDiagnostic };
