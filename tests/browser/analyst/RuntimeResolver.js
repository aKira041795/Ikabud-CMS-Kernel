// @ts-check

/** Resolves routes and entity examples from the inspected application itself. */
class RuntimeResolver {
    constructor(page, manifest) {
        this.page = page;
        this.manifest = manifest || {};
        this.baseUrl = process.env.APP_URL || 'http://palsystem.test';
        this.links = new Set();
    }

    static withInspection(url) {
        if (!url) return url;
        const separator = url.includes('?') ? '&' : '?';
        return /(?:\?|&)wb_inspect=/.test(url) ? url : `${url}${separator}wb_inspect=1&disyl_nocache=1`;
    }

    async observeCurrentPage() {
        const hrefs = await this.page.locator('a[href]').evaluateAll(nodes => nodes.map(n => n.getAttribute('href')).filter(Boolean)).catch(() => []);
        for (const href of hrefs) {
            if (href.startsWith('/') && !href.startsWith('//')) this.links.add(href.split('?')[0]);
        }
        return hrefs;
    }

    async seedFromNavigation() {
        const nav = this.manifest.nav || this.manifest.sidebar || [];
        const visit = value => {
            if (!value || typeof value !== 'object') return;
            if (value.url) this.links.add(String(value.url).split('?')[0]);
            for (const child of value.children || []) visit(child);
        };
        nav.forEach(visit);
        return this.links;
    }

    resolve(templateUrl) {
        if (!templateUrl) return null;
        if (!/[{:]\w+|\/\d+(?=\/|$)/.test(templateUrl)) {
            const clean = templateUrl.split('?')[0].replace(/\/$/, '') || '/';
            const observed = [...this.links].some(link => (link.replace(/\/$/, '') || '/') === clean);
            return observed ? RuntimeResolver.withInspection(templateUrl) : null;
        }
        const canonical = templateUrl
            .replace(/:\w+/g, '{id}')
            .replace(/\/\d+(?=\/|$)/g, '/{id}');
        const escaped = canonical.replace(/[.*+?^${}()|[\]\\]/g, '\\$&').replace('\\{id\\}', '(?:[^/]+)');
        const matcher = new RegExp(`^${escaped}$`);
        const reserved = new Set(['create', 'edit', 'new', 'settings', 'reports', 'approvals', 'audit-trail', 'movements', 'returns']);
        const candidate = [...this.links].find(link => {
            if (!matcher.test(link)) return false;
            const templateParts = canonical.split('/');
            const linkParts = link.split('/');
            for (let i = 0; i < templateParts.length; i++) {
                if (templateParts[i] === '{id}' && reserved.has(String(linkParts[i] || '').toLowerCase())) return false;
            }
            return true;
        });
        return candidate ? RuntimeResolver.withInspection(candidate) : null;
    }

    async discover(templateUrl) {
        let resolved = this.resolve(templateUrl);
        if (resolved) return resolved;
        const marker = templateUrl.search(/\{\w+\}|:\w+|\/\d+(?=\/|$)/);
        const clean = templateUrl.split('?')[0].replace(/\/$/, '');
        const prefix = marker < 0
            ? clean.replace(/\/(?:create|new|edit)$/, '')
            : templateUrl.slice(0, marker).replace(/\/$/, '');
        if (!prefix) return null;
        try {
            await this.page.goto(`${this.baseUrl}${RuntimeResolver.withInspection(prefix)}`, { waitUntil: 'networkidle', timeout: 15000 });
            await this.observeCurrentPage();
            resolved = this.resolve(templateUrl);
        } catch (_) { return null; }
        return resolved;
    }

    classifyUnresolved(templateUrl) {
        return {
            severity: 'note',
            component: 'route-prerequisite',
            expected: `A discoverable runtime instance for ${templateUrl}`,
            actual: 'No valid entity link was discovered',
            cause: 'The route requires an entity or workflow prerequisite not present in the observed state',
            fix: 'Seed or discover a valid prerequisite through a list/detail relationship before testing this route',
            classification: 'unmet-prerequisite',
            route_template: templateUrl,
        };
    }
}

module.exports = { RuntimeResolver };
