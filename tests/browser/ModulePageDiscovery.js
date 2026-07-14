/**
 * ModulePageDiscovery — Reads a module.json manifest and auto-discovers
 * all navigable pages with their sidebar labels, URLs, groups, and roles.
 *
 * The Comprehension Engine should use this instead of hardcoded page lists.
 * Any module with a proper nav section in module.json becomes testable
 * without writing per-module test specs.
 *
 * Output structure:
 *   {
 *     moduleId: string,
 *     navItems: [{ label, url, group, roles, icon }],
 *     groups: [{ label, items: [...] }],
 *     allUrls: string[],
 *   }
 */

// @ts-check
const fs = require('fs');
const path = require('path');

class ModulePageDiscovery {

    /**
     * @param {string} modulePath - Absolute path to the module directory
     */
    constructor(modulePath) {
        this.modulePath = modulePath;
        this.manifestPath = path.join(modulePath, 'module.json');
        /** @type {import('./ModulePageDiscovery').Manifest|null} */
        this.manifest = null;
        /** @type {Array<{label:string, url:string, group:string|null, roles:string[], icon:string}>|null} */
        this._navItems = null;
    }

    /**
     * Load and parse the module.json manifest.
     * @returns {object} The parsed manifest
     */
    load() {
        if (this.manifest) return this.manifest;
        const raw = fs.readFileSync(this.manifestPath, 'utf-8');
        this.manifest = JSON.parse(raw);
        return this.manifest;
    }

    /**
     * Get the module ID.
     * @returns {string}
     */
    get moduleId() {
        return this.load().id || path.basename(this.modulePath);
    }

    /**
     * Get the module name.
     * @returns {string}
     */
    get moduleName() {
        return this.load().name || this.moduleId;
    }

    /**
     * Extract all navigable sidebar items from the manifest.
     * Handles both flat nav arrays and grouped structures.
     * @returns {Array<{label:string, url:string, group:string|null, roles:string[], icon:string}>}
     */
    get navItems() {
        if (this._navItems) return this._navItems;

        const manifest = this.load();
        const nav = manifest.nav || manifest.sidebar || [];
        const items = [];
        /** @type {string|null} */
        let currentGroup = null;

        for (const entry of nav) {
            if (typeof entry === 'string') {
                // Section header / separator
                currentGroup = entry;
                continue;
            }

            if (!entry || typeof entry !== 'object') continue;

            // If it has children, it's a section/group
            if (entry.children && Array.isArray(entry.children)) {
                currentGroup = entry.label || null;
                for (const child of entry.children) {
                    if (child && child.url) {
                        items.push({
                            label: child.label || child.title || '?',
                            url: child.url,
                            group: currentGroup,
                            roles: child.roles || entry.roles || [],
                            icon: child.icon || '',
                        });
                    }
                }
                continue;
            }

            // Flat nav item
            if (entry.url) {
                items.push({
                    label: entry.label || entry.title || '?',
                    url: entry.url,
                    group: currentGroup,
                    roles: entry.roles || [],
                    icon: entry.icon || '',
                });
            }
        }

        this._navItems = items;
        return items;
    }

    /**
     * Get sidebar groups (sections) with their child items.
     * @returns {Array<{label:string, items:Array}>}
     */
    get groups() {
        const items = this.navItems;
        const groupMap = new Map();

        for (const item of items) {
            const g = item.group || '_ungrouped';
            if (!groupMap.has(g)) groupMap.set(g, []);
            groupMap.get(g).push(item);
        }

        return Array.from(groupMap.entries()).map(([label, navItems]) => ({
            label,
            items: navItems,
        }));
    }

    /**
     * Get all unique URLs from the nav.
     * @returns {string[]}
     */
    get allUrls() {
        return [...new Set(this.navItems.map(i => i.url))];
    }

    /**
     * Get pages that are likely form pages (URL contains /create, /edit, /add, /new).
     * @returns {Array}
     */
    get formPages() {
        return this.navItems.filter(i =>
            /\/create|\/edit|\/add|\/new|\/settings|\/configure/.test(i.url)
        );
    }

    /**
     * Get pages that are likely list/detail pages.
     * @returns {Array}
     */
    get listPages() {
        return this.navItems.filter(i =>
            !/\/create|\/edit|\/add|\/new|\/settings|\/configure/.test(i.url)
        );
    }

    /**
     * Get all defined routes from the manifest (for API route testing).
     * @returns {Array<{method:string, path:string, handler:string}>}
     */
    get routes() {
        const manifest = this.load();
        const routeDefs = manifest.routes || {};
        const result = [];

        for (const [method, routes] of Object.entries(routeDefs)) {
            if (typeof routes === 'object' && routes !== null) {
                for (const [path, handler] of Object.entries(routes)) {
                    result.push({ method: method.toUpperCase(), path, handler: String(handler) });
                }
            }
        }

        return result;
    }
}

module.exports = { ModulePageDiscovery };
