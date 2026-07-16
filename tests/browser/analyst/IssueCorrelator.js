// @ts-check
const crypto = require('crypto');

/** Correlates many observations into one actionable, evidence-backed issue. */
class IssueCorrelator {
    static correlate(observations) {
        const groups = new Map();
        for (const raw of observations || []) {
            const issue = this.normalize(raw);
            const key = this.key(issue);
            if (!groups.has(key)) groups.set(key, { ...issue, evidence: [], occurrences: 0 });
            const aggregate = groups.get(key);
            aggregate.occurrences++;
            aggregate.evidence.push({
                kind: issue.kind,
                source: issue.source,
                url: issue.url,
                status: issue.status,
                detail: issue.detail,
                timestamp: issue.timestamp,
            });
            if (this.rank(issue.severity) < this.rank(aggregate.severity)) aggregate.severity = issue.severity;
        }
        return Array.from(groups.values()).map(issue => {
            issue.evidence_kinds = [...new Set(issue.evidence.map(e => e.kind))].sort();
            issue.fingerprint = crypto.createHash('sha256').update(this.key(issue)).digest('hex').slice(0, 20);
            issue.confidence = Math.min(0.99, 0.55 + (issue.evidence_kinds.length - 1) * 0.15 + Math.min(0.2, issue.occurrences * 0.02));
            return issue;
        });
    }

    static normalize(raw) {
        const where = String(raw.where || raw.url || raw.location || '');
        const detail = String(raw.detail || raw.actual || raw.message || '');
        const kind = raw.kind || raw.type || 'diagnostic';
        const explicitStatus = `${where} ${detail}`.match(/(?:HTTP(?:\s+status)?|status\s*[=:]?)\s*([1-5]\d{2})\b/i);
        const errorStatus = /^(?:http|console)-error$/.test(kind) ? detail.match(/\b([45]\d{2})\b/) : null;
        const match = explicitStatus || errorStatus;
        const urlMatch = where.match(/https?:\/\/[^\s]+|\/[A-Za-z0-9_?&=./{}:-]+/);
        return {
            kind,
            severity: raw.severity || 'minor',
            component: raw.component || raw.page || 'unknown',
            source: raw.source || raw.kind || 'diagnostic',
            url: urlMatch ? urlMatch[0].replace(/[),.;]+$/, '') : '',
            status: match ? Number(match[1]) : null,
            detail,
            expected: raw.expected || '',
            cause: raw.cause || '',
            fix: raw.fix || raw.recommendation || '',
            timestamp: raw.timestamp || null,
            classification: raw.classification || 'unconfirmed',
        };
    }

    static key(issue) {
        if (issue.url && issue.status) return `http|${issue.status}|${this.canonicalUrl(issue.url)}`;
        return `${issue.component}|${this.signature(issue.detail || issue.expected)}`;
    }

    static canonicalUrl(url) {
        return String(url).replace(/^https?:\/\/[^/]+/, '').replace(/\/\d+(?=\/|$)/g, '/{id}').replace(/[?&](?:_?t|timestamp|nonce)=[^&]*/gi, '');
    }

    static signature(text) {
        return String(text).toLowerCase().replace(/\b\d+\b/g, '{n}').replace(/\s+/g, ' ').trim().slice(0, 240);
    }

    static rank(severity) {
        return ({ critical: 0, major: 1, minor: 2, note: 3 })[severity] ?? 9;
    }
}

module.exports = { IssueCorrelator };
