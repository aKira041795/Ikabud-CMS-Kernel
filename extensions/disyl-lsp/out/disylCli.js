"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.parseDescribe = parseDescribe;
exports.parseSources = parseSources;
exports.parseLint = parseLint;
function parseEnvelope(stdout, tool) {
    let value;
    try {
        value = JSON.parse(stdout);
    }
    catch (error) {
        const detail = error instanceof Error ? error.message : String(error);
        throw new Error(`Invalid JSON from ${tool}: ${detail}`);
    }
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        throw new Error(`Invalid ${tool} response: expected a JSON object`);
    }
    const envelope = value;
    if (envelope.schemaVersion !== 1) {
        throw new Error(`Unsupported ${tool} schemaVersion: expected 1, received ${String(envelope.schemaVersion)}`);
    }
    return envelope;
}
function parseNames(value, field) {
    if (!Array.isArray(value)) {
        throw new Error(`Invalid disyl:describe response: ${field} must be an array`);
    }
    return value.map((entry, index) => {
        const name = typeof entry === 'string'
            ? entry
            : (typeof entry === 'object' && entry !== null ? entry.name : undefined);
        if (typeof name !== 'string') {
            throw new Error(`Invalid disyl:describe response: ${field}[${index}] must be a string or expose a string name`);
        }
        return name;
    });
}
function parseDescribe(stdout) {
    const envelope = parseEnvelope(stdout, 'disyl:describe');
    return {
        filters: parseNames(envelope.filters, 'filters'),
        components: parseNames(envelope.components, 'components'),
    };
}
function parseSources(stdout) {
    const envelope = parseEnvelope(stdout, 'disyl:list-sources');
    if (!Array.isArray(envelope.sources)) {
        throw new Error('Invalid disyl:list-sources response: sources must be an array');
    }
    return envelope.sources.map((value, index) => {
        if (typeof value !== 'object' || value === null || Array.isArray(value)) {
            throw new Error(`Invalid disyl:list-sources response: sources[${index}] must be an object`);
        }
        const source = value;
        if (typeof source.name !== 'string' || source.name === '') {
            throw new Error(`Invalid disyl:list-sources response: sources[${index}].name must be a non-empty string`);
        }
        if (!Array.isArray(source.views)) {
            throw new Error(`Invalid disyl:list-sources response: sources[${index}].views must be an array`);
        }
        const views = source.views.map((view, viewIndex) => {
            if (typeof view !== 'string' || view === '') {
                throw new Error(`Invalid disyl:list-sources response: sources[${index}].views[${viewIndex}] must be a non-empty string`);
            }
            return view;
        });
        return { name: source.name, views };
    });
}
function parseLint(stdout) {
    const envelope = parseEnvelope(stdout, 'disyl:lint');
    if (!Array.isArray(envelope.diagnostics)) {
        throw new Error('Invalid disyl:lint response: diagnostics must be an array');
    }
    return envelope.diagnostics.map((value, index) => {
        if (typeof value !== 'object' || value === null || Array.isArray(value)) {
            throw new Error(`Invalid disyl:lint response: diagnostics[${index}] must be an object`);
        }
        const diagnostic = value;
        const range = diagnostic.range;
        const start = typeof range === 'object' && range !== null && !Array.isArray(range)
            ? range.start
            : undefined;
        const position = typeof start === 'object' && start !== null && !Array.isArray(start)
            ? start
            : undefined;
        if (!position || !Number.isInteger(position.line) || !Number.isInteger(position.character)) {
            throw new Error(`Invalid disyl:lint response: diagnostics[${index}].range.start must contain integer line and character`);
        }
        if (diagnostic.severity !== 'error' && diagnostic.severity !== 'warning') {
            throw new Error(`Invalid disyl:lint response: diagnostics[${index}].severity must be error or warning`);
        }
        if (typeof diagnostic.code !== 'string' || typeof diagnostic.message !== 'string') {
            throw new Error(`Invalid disyl:lint response: diagnostics[${index}] must contain string code and message`);
        }
        return {
            line: position.line,
            character: position.character,
            severity: diagnostic.severity,
            code: diagnostic.code,
            message: diagnostic.message,
        };
    });
}
//# sourceMappingURL=disylCli.js.map