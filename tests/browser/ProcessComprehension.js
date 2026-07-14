/**
 * ProcessComprehension — Understands WHY elements exist and WHERE data flows.
 *
 * Beyond structural discovery: parses templates, traces field → handler →
 * capability → database → display page chains. Answers questions like:
 *   - "Why is there an inline edit 'Other' field?"
 *   - "Where does this data go when saved?"
 *   - "Which page consumes this data downstream?"
 *
 * This is the bridge between the Comprehension Engine (static analysis)
 * and human debugging simulation (runtime behavior understanding).
 *
 * @see kernel/Workbench/Comprehension/Contracts/ModuleComprehensionProvider.php
 */

// @ts-check
const fs = require('fs');
const path = require('path');

class ProcessComprehension {

    /**
     * @param {string} modulePath - Absolute path to the module directory
     * @param {object} manifest - Parsed module.json
     * @param {object} [comprehensionProvider] - PHP provider data (entities, actions, workflows)
     */
    constructor(modulePath, manifest, comprehensionProvider) {
        this.modulePath = modulePath;
        this.manifest = manifest;
        this.provider = comprehensionProvider || {};
        this.templateDir = path.join(modulePath, 'templates', manifest.id || path.basename(modulePath), 'pages');
        /** @type {Array} */
        this._fieldAnalysis = null;
        /** @type {Array} */
        this._dataFlowMap = null;
    }

    /**
     * Analyze all page templates and extract field definitions with semantics.
     * @returns {Array<{page:string, fields:Array, creatables:Array, actions:Array}>}
     */
    analyzeTemplates() {
        if (this._fieldAnalysis) return this._fieldAnalysis;

        const results = [];
        if (!fs.existsSync(this.templateDir)) return results;

        const files = fs.readdirSync(this.templateDir).filter(f => f.endsWith('.disyl'));

        for (const file of files) {
            const content = fs.readFileSync(path.join(this.templateDir, file), 'utf-8');
            const pageName = file.replace('.disyl', '');

            const fields = this._extractFields(content);
            const creatables = this._extractCreatables(content);
            const actions = this._extractActions(content);
            const entityRefs = this._extractEntityRefs(content);
            const tableRefs = this._extractTableRefs(content);

            results.push({
                page: pageName,
                file,
                fields,
                creatables,
                actions,
                entityRefs,
                tableRefs,
            });
        }

        this._fieldAnalysis = results;
        return results;
    }

    /**
     * Extract form field definitions from template content.
     * Detects: name, type, required, entity binding, default value.
     */
    _extractFields(content) {
        const fields = [];
        // Match <input>, <select>, <textarea> elements
        const inputRegex = /<(input|select|textarea)\s[^>]*?name="([^"]+)"[^>]*?(required)?[^>]*?(type="([^"]*)")?[^>]*?(value="\{?([^}]+)\}?")?[^>]*?\/?>/gi;
        let match;
        while ((match = inputRegex.exec(content)) !== null) {
            const type = match[1];
            const name = match[2]; // Just the field name, no name=" prefix
            const required = !!match[3];
            const inputType = match[5] || type;
            const valueBinding = match[7] || '';

            // Determine entity and column from name pattern (e.g., category_id → pal_expense.category_id)
            const entityHint = this._resolveEntityColumn(name);

            fields.push({
                name,
                type: type === 'select' ? 'select' : inputType,
                required,
                valueBinding,
                entity: entityHint.entity,
                column: entityHint.column,
                html: match[0].substring(0, 150),
            });
        }
        return fields;
    }

    /**
     * Extract creatable (inline-edit/Other) fields - these allow users to
     * type new values not in the dropdown, which creates records on the fly.
     */
    _extractCreatables(content) {
        const creatables = [];
        // Match <select ... data-creatable="X"> to capture the name from the SAME element
        const regex = /<select[^>]*?name="([^"]+)"[^>]*?data-creatable="([^"]+)"[^>]*?>/gi;
        let match;
        while ((match = regex.exec(content)) !== null) {
            const fieldName = match[1];
            const target = match[2];
            const entityInfo = this._resolveCreatableTarget(target);

            creatables.push({
                field: fieldName,
                targetEntity: target,
                description: entityInfo.description,
                createsRecordIn: entityInfo.table,
                createsPage: entityInfo.createPage,
                listsPage: entityInfo.listPage,
                reason: `User can type a new ${target.replace('_', ' ')} not in the predefined list`,
            });
        }
        return creatables;
    }

    /**
     * Extract form actions (where the form submits to).
     */
    _extractActions(content) {
        const actions = [];
        // Match form action attribute
        const actionRegex = /<form[^>]*?action="([^"]+)"/gi;
        let match;
        while ((match = actionRegex.exec(content)) !== null) {
            actions.push({ type: 'form_action', url: match[1] });
        }

        // Match data-wb-action attributes (workbench action pattern)
        const wbActionRegex = /data-wb-action="([^"]+)"/gi;
        while ((match = wbActionRegex.exec(content)) !== null) {
            actions.push({ type: 'wb_action', action: match[1] });
        }

        // Match API endpoint references in JavaScript
        const apiRegex = /fetch\(['"]([^'"]*\/api\/[^'"]+)['"]\)/gi;
        while ((match = apiRegex.exec(content)) !== null) {
            actions.push({ type: 'api_call', url: match[1] });
        }

        return actions;
    }

    /**
     * Extract entity references (ikb_entity_list, data-wb-entity).
     */
    _extractEntityRefs(content) {
        const refs = [];
        const entityRegex = /data-wb-entity="([^"]+)"/gi;
        let match;
        while ((match = entityRegex.exec(content)) !== null) {
            refs.push({ type: 'wb_entity', entity: match[1] });
        }

        const ikbRegex = /ikb_entity_list\s+entity="([^"]+)"/gi;
        while ((match = ikbRegex.exec(content)) !== null) {
            refs.push({ type: 'ikb_list', entity: match[1] });
        }

        return refs;
    }

    /**
     * Extract table references from template (which DB tables are read/displayed).
     */
    _extractTableRefs(content) {
        const refs = [];
        // Match owned/co-owned table references in template comments or data attributes
        const tableRegex = /table["':\s]+["']?([a-z_]+(?:projects|expenses|inventory|sales|purchases|clients|suppliers|approvals))["']?/gi;
        let match;
        while ((match = tableRegex.exec(content)) !== null) {
            refs.push(match[1]);
        }
        return [...new Set(refs)];
    }

    /**
     * Build a data flow map: track data from entry → handler → DB → display.
     * This answers "where does this field's data go?"
     */
    buildDataFlow() {
        if (this._dataFlowMap) return this._dataFlowMap;

        const templates = this.analyzeTemplates();
        const routes = this._parseRoutes();
        const entities = this.provider.entities || [];
        const capabilities = this.provider.capabilities || [];

        const flows = [];

        for (const tmpl of templates) {
            // For each creatable field, trace the full data flow
            for (const c of tmpl.creatables) {
                const flow = {
                    origin: {
                        page: tmpl.page,
                        field: c.field,
                        description: c.description,
                    },
                    destination: {
                        entity: c.targetEntity,
                        table: c.createsRecordIn,
                        listPage: c.listsPage,
                    },
                    process: `User enters new ${c.targetEntity.replace('_', ' ')} → ` +
                             `POST to API → INSERT into ${c.createsRecordIn || c.targetEntity + 's'} → ` +
                             `displayed on ${c.listsPage || tmpl.page} list`,
                    routes: routes.filter(r =>
                        r.path.includes(c.targetEntity.replace('_', '-')) ||
                        r.path.includes(c.targetEntity.toLowerCase())
                    ),
                };
                flows.push(flow);
            }

            // Map each field to its entity and trace destination
            for (const f of tmpl.fields) {
                if (f.entity) {
                    const displayPages = templates.filter(t =>
                        t.entityRefs.some(e => e.entity === f.entity) ||
                        t.tableRefs.some(tbl => tbl === f.column)
                    ).map(t => t.page);

                    flows.push({
                        origin: {
                            page: tmpl.page,
                            field: f.name,
                            type: f.type,
                        },
                        destination: {
                            entity: f.entity,
                            column: f.column,
                            displayedOn: displayPages.length > 0 ? displayPages : ['same page (detail view)'],
                        },
                        process: `Field ${f.name} collected on ${tmpl.page} → stored in ${f.entity}.${f.column} → displayed on ${displayPages.join(', ') || 'detail page'}`,
                    });
                }
            }
        }

        this._dataFlowMap = flows;
        return flows;
    }

    /**
     * Generate a human-readable process report.
     */
    generateReport() {
        const templates = this.analyzeTemplates();
        const flows = this.buildDataFlow();

        const report = {
            module: this.manifest.name || this.manifest.id,
            pages: templates.length,
            fields: templates.reduce((s, t) => s + t.fields.length, 0),
            creatables: templates.reduce((s, t) => s + t.creatables.length, 0),
            dataFlows: flows.length,
            details: {
                templates: templates.map(t => ({
                    page: t.page,
                    fieldCount: t.fields.length,
                    creatableCount: t.creatables.length,
                    creatables: t.creatables.map(c => ({
                        field: c.field,
                        reason: c.reason,
                        createsRecord: c.createsRecordIn,
                        displayPage: c.listsPage,
                    })),
                    entityRefs: t.entityRefs,
                })),
                dataFlows: flows.slice(0, 20), // limit for readability
            },
        };

        return report;
    }

    // ─── Private helpers ─────────────────────────────────

    /**
     * Resolve a field name to entity + column.
     * E.g., category_id → {entity: 'pal_expense', column: 'category_id'}
     */
    _resolveEntityColumn(fieldName) {
        // Use the manifest's owns_tables to map fields to tables
        const ownedTables = this.manifest.owns_tables || [];
        const coOwnedTables = this.manifest.co_owns_tables || [];
        const allTables = [...ownedTables, ...coOwnedTables];

        // Guess entity from field name conventions
        if (fieldName.endsWith('_id')) {
            const prefix = fieldName.replace(/_id$/, '');
            return {
                entity: `pal_${prefix}`,
                column: fieldName,
            };
        }

        // General field → try to match to a known table
        for (const table of allTables) {
            const entityName = table.replace(/^pal_/, 'pal.');
            return { entity: entityName, column: fieldName };
        }

        return { entity: '', column: fieldName };
    }

    /**
     * Resolve what a data-creatable target means — uses manifest's owns_tables
     * to find the correct table name, provider entities for display context.
     */
    _resolveCreatableTarget(target) {
        const ownedTables = this.manifest.owns_tables || [];
        const providerEntities = this.provider.entities || [];

        // Guess table name from target: expense_category → pal_expense_categories
        const candidateNames = [
            `pal_${target}s`,           // pal_expense_categories, pal_projects
            `pal_${target}`,             // pal_client? No, pal_clients
            `${target}s`,                // expense_categories
            target,                      // expense_category
        ];

        let table = candidateNames.find(t => ownedTables.includes(t)) || `pal_${target}s`;
        let entity = `pal.${target}`;

        // Check provider entities for display page
        const providerEntity = providerEntities.find(e => e.id === entity || e.table === table);
        const listPage = providerEntity ? `/${entity.replace('.', '/')}s` : `/${target}s`;

        // Build description from context
        const targetLabel = target.replace(/_/g, ' ');
        const description = `New ${targetLabel} (user-defined, created on-the-fly)`;

        return { table, entity, description, listPage, createPage: listPage + '/create' };
    }

    /**
     * Parse module routes from manifest.
     */
    _parseRoutes() {
        const routeDefs = this.manifest.routes || {};
        const routes = [];
        for (const [method, defs] of Object.entries(routeDefs)) {
            if (typeof defs === 'object' && defs !== null) {
                for (const [path, handler] of Object.entries(defs)) {
                    routes.push({ method: method.toUpperCase(), path, handler: String(handler) });
                }
            }
        }
        return routes;
    }
}

module.exports = { ProcessComprehension };
