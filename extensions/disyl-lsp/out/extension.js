"use strict";
var __createBinding = (this && this.__createBinding) || (Object.create ? (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    var desc = Object.getOwnPropertyDescriptor(m, k);
    if (!desc || ("get" in desc ? !m.__esModule : desc.writable || desc.configurable)) {
      desc = { enumerable: true, get: function() { return m[k]; } };
    }
    Object.defineProperty(o, k2, desc);
}) : (function(o, m, k, k2) {
    if (k2 === undefined) k2 = k;
    o[k2] = m[k];
}));
var __setModuleDefault = (this && this.__setModuleDefault) || (Object.create ? (function(o, v) {
    Object.defineProperty(o, "default", { enumerable: true, value: v });
}) : function(o, v) {
    o["default"] = v;
});
var __importStar = (this && this.__importStar) || (function () {
    var ownKeys = function(o) {
        ownKeys = Object.getOwnPropertyNames || function (o) {
            var ar = [];
            for (var k in o) if (Object.prototype.hasOwnProperty.call(o, k)) ar[ar.length] = k;
            return ar;
        };
        return ownKeys(o);
    };
    return function (mod) {
        if (mod && mod.__esModule) return mod;
        var result = {};
        if (mod != null) for (var k = ownKeys(mod), i = 0; i < k.length; i++) if (k[i] !== "default") __createBinding(result, mod, k[i]);
        __setModuleDefault(result, mod);
        return result;
    };
})();
Object.defineProperty(exports, "__esModule", { value: true });
exports.activate = activate;
exports.deactivate = deactivate;
const vscode = __importStar(require("vscode"));
const path = __importStar(require("path"));
const cp = __importStar(require("child_process"));
const fs = __importStar(require("fs"));
const validator_1 = require("./validator");
const symbols_1 = require("./symbols");
const disylCli_1 = require("./disylCli");
const DIAGNOSTIC_COLLECTION = 'disyl';
let disylFilters = [];
let governedComponents = [];
let entitySources = [];
async function activate(context) {
    console.log('DiSyL language support activated');
    const diagCollection = vscode.languages.createDiagnosticCollection(DIAGNOSTIC_COLLECTION);
    context.subscriptions.push(diagCollection);
    // Load completion catalogues once. A failed CLI lookup deliberately leaves
    // them empty rather than falling back to a stale extension-side copy.
    await Promise.all([loadCliDescription(), loadCliSources()]);
    // ── Lint on save ──
    const lintOnSave = vscode.workspace.getConfiguration('disyl').get('lintOnSave', true);
    if (lintOnSave) {
        context.subscriptions.push(vscode.workspace.onDidSaveTextDocument((doc) => {
            if (doc.languageId === 'disyl') {
                lintDocument(doc, diagCollection);
            }
        }));
    }
    // ── Lint on type (EBNF validator only — instant, no PHP) ──
    const lintOnType = vscode.workspace.getConfiguration('disyl').get('lintOnType', true);
    if (lintOnType) {
        // Debounce: run EBNF validation 300ms after last keystroke
        let typeTimer;
        context.subscriptions.push(vscode.workspace.onDidChangeTextDocument((event) => {
            if (event.document.languageId !== 'disyl')
                return;
            if (typeTimer)
                clearTimeout(typeTimer);
            typeTimer = setTimeout(() => {
                const ebnfDiagnostics = (0, validator_1.validateDisylDocument)(event.document);
                diagCollection.set(event.document.uri, ebnfDiagnostics);
            }, 300);
        }));
    }
    // ── Lint on open ──
    context.subscriptions.push(vscode.window.onDidChangeActiveTextEditor((editor) => {
        if (editor && editor.document.languageId === 'disyl') {
            lintDocument(editor.document, diagCollection);
        }
    }));
    // Lint any already-open disyl tabs
    vscode.window.visibleTextEditors.forEach((editor) => {
        if (editor.document.languageId === 'disyl') {
            lintDocument(editor.document, diagCollection);
        }
    });
    // ── Outline + folding (shared structural scanner, no PHP) ──
    context.subscriptions.push(vscode.languages.registerDocumentSymbolProvider('disyl', new symbols_1.DisylDocumentSymbolProvider()), vscode.languages.registerFoldingRangeProvider('disyl', new symbols_1.DisylFoldingRangeProvider()));
    // ── Commands ──
    context.subscriptions.push(vscode.commands.registerCommand('disyl.lint', () => {
        const editor = vscode.window.activeTextEditor;
        if (editor && editor.document.languageId === 'disyl') {
            lintDocument(editor.document, diagCollection);
        }
    }));
    context.subscriptions.push(vscode.commands.registerCommand('disyl.lintAll', async () => {
        const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
        if (!workspaceRoot) {
            vscode.window.showErrorMessage('No workspace folder open.');
            return;
        }
        const phpCmd = vscode.workspace.getConfiguration('disyl').get('phpCommand', 'php');
        const ikabudPath = resolveIkabudPath(workspaceRoot);
        if (!ikabudPath)
            return;
        try {
            const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:lint'], { cwd: workspaceRoot });
            const channel = vscode.window.createOutputChannel('DiSyL Lint');
            channel.clear();
            channel.append(stdout);
            channel.show();
        }
        catch (err) {
            const channel = vscode.window.createOutputChannel('DiSyL Lint');
            channel.clear();
            channel.append(err.stdout || err.stderr || err.message);
            channel.show();
        }
    }));
    // ── Cheatsheet command ──
    context.subscriptions.push(vscode.commands.registerCommand('disyl.cheatsheet', () => {
        const items = [
            { label: '{var}', description: 'Output variable', detail: '{user.name} → "John"' },
            { label: '{var|filter}', description: 'Output with filter', detail: '{price|money} → "₱1,234.56"' },
            { label: '{if cond}…{/if}', description: 'Conditional', detail: '{if user.role == "admin"}…{else}…{/if}' },
            { label: '{foreach arr as item}…{/foreach}', description: 'Loop over array', detail: '{foreach products as p}…{empty}No items{/foreach}' },
            { label: '{for i in range(1,n)}…{/for}', description: 'Range loop', detail: '{for i in range(1, 5)}{i}{/for}' },
            { label: '{set x = expr}', description: 'Computed variable', detail: '{set is_locked = status != "pending"}' },
            { label: '{extends "layout.disyl"}', description: 'Layout inheritance', detail: 'Child templates override {block} placeholders' },
            { label: '{block name}…{/block}', description: 'Named block', detail: 'Placeholder in layout, overridden in child' },
            { label: '{include "partial.disyl"}', description: 'Include partial', detail: 'Inlines another template' },
            { label: '{ikb_entity_list source="…" view="…"}', description: 'Entity list table/grid', detail: 'Database-driven table from entity views' },
            { label: '{empty}…', description: 'Empty state for loops', detail: 'Rendered when loop has zero items' },
            { label: '{!-- … --}', description: 'DiSyL comment', detail: 'Stripped at compile time' },
        ];
        vscode.window.showQuickPick(items, {
            placeHolder: 'DiSyL Cheatsheet — select to copy to clipboard',
            matchOnDescription: true,
        }).then((selected) => {
            if (selected) {
                vscode.env.clipboard.writeText(selected.label);
                vscode.window.showInformationMessage(`Copied: ${selected.label}`);
            }
        });
    }));
    // ── Open Quickstart command ──
    context.subscriptions.push(vscode.commands.registerCommand('disyl.openQuickstart', () => {
        const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
        if (!workspaceRoot) {
            vscode.window.showErrorMessage('No workspace folder open.');
            return;
        }
        const quickstartPath = path.join(workspaceRoot, 'docs', 'disyl', 'quickstart.md');
        if (fs.existsSync(quickstartPath)) {
            vscode.commands.executeCommand('markdown.showPreview', vscode.Uri.file(quickstartPath));
        }
        else {
            vscode.window.showWarningMessage('DiSyL quickstart guide not found. Run `php ikabud disyl:lint` first.');
        }
    }));
    // ── Autocomplete: DiSyL filters ──
    context.subscriptions.push(vscode.languages.registerCompletionItemProvider('disyl', {
        provideCompletionItems(document, position) {
            const line = document.lineAt(position).text;
            const lineBefore = line.substring(0, position.character);
            // Filter autocomplete: after a bare `|`, or while typing a partial
            // name such as `|up`. The typed prefix is replaced, not appended.
            const partialFilter = lineBefore.match(/\|\s*([a-zA-Z_]\w*)$/);
            if (partialFilter || /\|\s*$/.test(lineBefore)) {
                const typed = partialFilter ? partialFilter[1] : '';
                const replaceRange = typed
                    ? new vscode.Range(position.line, position.character - typed.length, position.line, position.character)
                    : undefined;
                return disylFilters
                    .filter((filter) => filter.startsWith(typed))
                    .map((filter) => {
                    const item = new vscode.CompletionItem(filter, vscode.CompletionItemKind.Function);
                    item.detail = 'Registered DiSyL filter';
                    if (replaceRange)
                        item.range = replaceRange;
                    return item;
                });
            }
            // Entity source/view values inside list and detail components.
            // The CLI catalogue is authoritative; no names live in the extension.
            const entityTag = lineBefore.match(/\{ikb_entity_(?:list|detail)\b[^{}]*$/);
            const entityAttribute = entityTag?.[0].match(/\b(source|view)\s*=\s*"([^"]*)$/);
            if (entityTag && entityAttribute) {
                const attribute = entityAttribute[1];
                const typed = entityAttribute[2];
                const replaceRange = new vscode.Range(position.line, position.character - typed.length, position.line, position.character);
                let names;
                if (attribute === 'source') {
                    names = entitySources.map((source) => source.name);
                }
                else {
                    const tagStart = lineBefore.lastIndexOf(entityTag[0]);
                    const tagEnd = line.indexOf('}', tagStart);
                    const fullTag = line.substring(tagStart, tagEnd === -1 ? line.length : tagEnd);
                    const selectedSource = fullTag.match(/\bsource\s*=\s*["']([^"']+)["']/)?.[1];
                    names = selectedSource !== undefined
                        ? (entitySources.find((source) => source.name === selectedSource)?.views ?? [])
                        : Array.from(new Set(entitySources.flatMap((source) => source.views))).sort();
                }
                return names
                    .filter((name) => name.startsWith(typed))
                    .map((name) => {
                    const item = new vscode.CompletionItem(name, vscode.CompletionItemKind.Value);
                    item.detail = attribute === 'source'
                        ? 'Registered entity source'
                        : 'Registered entity view';
                    item.range = replaceRange;
                    return item;
                });
            }
            // Component autocomplete after {ikb_
            const compMatch = lineBefore.match(/\{(ikb_[a-zA-Z_]*)$/);
            if (compMatch) {
                const prefix = compMatch[1];
                return governedComponents
                    .filter((c) => c.startsWith(prefix))
                    .map((c) => {
                    const item = new vscode.CompletionItem(c, vscode.CompletionItemKind.Class);
                    item.insertText = c;
                    return item;
                });
            }
            // Block keyword autocomplete
            if (lineBefore.match(/\{\s*$/)) {
                return BLOCK_KEYWORDS.map((k) => {
                    const item = new vscode.CompletionItem(k, vscode.CompletionItemKind.Keyword);
                    return item;
                });
            }
            // Close-tag auto-insertion (after an opening tag, suggest the matching close)
            const openTagMatch = lineBefore.match(/\{(\/?)([a-z_]+)?$/);
            if (openTagMatch && openTagMatch[1] !== '/') {
                const tagName = openTagMatch[2] || '';
                if (tagName && CLOSE_TAG_MAP[tagName]) {
                    const item = new vscode.CompletionItem('/' + tagName, vscode.CompletionItemKind.Keyword);
                    item.insertText = '/' + tagName + '}';
                    item.filterText = '/' + tagName;
                    return [item];
                }
            }
            return [];
        }
    }, '{', '|', ' ', '"'));
    // ── Hover: filter documentation ──
    context.subscriptions.push(vscode.languages.registerHoverProvider('disyl', {
        provideHover(document, position) {
            const wordRange = document.getWordRangeAtPosition(position);
            if (!wordRange)
                return null;
            const word = document.getText(wordRange);
            const line = document.lineAt(position).text;
            // Hover on filters after |
            if (line.includes('|')) {
                if (disylFilters.includes(word)) {
                    return new vscode.Hover(new vscode.MarkdownString(`**${word}** — Registered DiSyL Filter`));
                }
            }
            // Hover on governed components
            if (governedComponents.includes(word)) {
                return new vscode.Hover(new vscode.MarkdownString(`**${word}** — Governed DiSyL Component\n\nSee [ComponentRegistry docs](https://github.com/aKira041795/Ikabud-CMS-Kernel/blob/master/docs/kernel/disyl-component-system.md) for attribute schemas.`));
            }
            // Hover on block keywords (v4.8)
            if (BLOCK_KEYWORD_DOCS[word]) {
                return new vscode.Hover(new vscode.MarkdownString(`**{${word}}** — DiSyL Block Keyword\n\n${BLOCK_KEYWORD_DOCS[word]}`));
            }
            return null;
        }
    }));
    // ── Go to definition: {extends} and {include} paths ──
    context.subscriptions.push(vscode.languages.registerDefinitionProvider('disyl', {
        provideDefinition(document, position) {
            const line = document.lineAt(position).text;
            // {extends "layouts/admin.disyl"}
            const extendsMatch = line.match(/\{extends\s+\"([^\"]+)\"/);
            if (extendsMatch) {
                const target = extendsMatch[1];
                return resolveTemplatePath(document, target);
            }
            // {include "partials/header.disyl"}
            const includeMatch = line.match(/\{include\s+\"([^\"]+)\"/);
            if (includeMatch) {
                const target = includeMatch[1];
                return resolveTemplatePath(document, target);
            }
            // {component "partials/card" ...}
            const componentMatch = line.match(/\{component\s+\"([^\"]+)\"/);
            if (componentMatch) {
                const target = componentMatch[1] + '.disyl';
                return resolveTemplatePath(document, target);
            }
            return null;
        }
    }));
    // ── Hover: show docs for block keywords & components ──
    context.subscriptions.push(vscode.languages.registerHoverProvider('disyl', {
        provideHover(document, position) {
            const range = document.getWordRangeAtPosition(position, /\{(\/)?([a-zA-Z_][a-zA-Z0-9_]*)\}/);
            if (!range)
                return null;
            const word = document.getText(range);
            const inner = word.replace(/[{}]/g, '');
            const isClose = inner.startsWith('/');
            const keyword = isClose ? inner.substring(1) : inner;
            // Block keyword docs
            if (BLOCK_KEYWORD_DOCS[keyword]) {
                const md = new vscode.MarkdownString();
                md.appendMarkdown(BLOCK_KEYWORD_DOCS[keyword]);
                if (keyword === 'if') {
                    md.appendMarkdown('\n\nSupported operators: `==`, `!=`, `>`, `<`, `>=`, `<=`, `&&`, `||`, `!`');
                }
                return new vscode.Hover(md, range);
            }
            // Component docs
            const compInfo = GOVERNED_COMPONENT_DOCS[keyword];
            if (compInfo) {
                const md = new vscode.MarkdownString();
                md.appendMarkdown(`**${keyword}** — ${compInfo.category}\n\n`);
                md.appendMarkdown(compInfo.description);
                if (compInfo.attributes.length > 0) {
                    md.appendMarkdown('\n\n**Attributes:**\n');
                    for (const attr of compInfo.attributes) {
                        md.appendMarkdown(`\n- \`${attr}\``);
                    }
                }
                return new vscode.Hover(md, range);
            }
            return null;
        }
    }));
    // ── Go-to-definition: {include "..."} → file ──
    context.subscriptions.push(vscode.languages.registerDefinitionProvider('disyl', {
        provideDefinition(document, position) {
            const range = document.getWordRangeAtPosition(position, /\{include\s+"([^"]+)"\}/);
            if (!range)
                return null;
            const text = document.getText(range);
            const match = text.match(/\{include\s+"([^"]+)"\}/);
            if (!match)
                return null;
            const target = match[1];
            return resolveTemplatePath(document, target);
        }
    }));
}
function deactivate() {
    // Clean up
}
// ── Lint a single document ──
async function lintDocument(doc, collection) {
    // Phase 1: EBNF-based structural validation (instant, no PHP required)
    const ebnfDiagnostics = (0, validator_1.validateDisylDocument)(doc);
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) {
        // No workspace — return EBNF results only
        collection.set(doc.uri, ebnfDiagnostics);
        return;
    }
    // Phase 2: PHP-based semantic validation (capabilities, variables, filters)
    const phpCmd = vscode.workspace.getConfiguration('disyl').get('phpCommand', 'php');
    const ikabudPath = resolveIkabudPath(workspaceRoot);
    if (!ikabudPath) {
        collection.set(doc.uri, ebnfDiagnostics);
        return;
    }
    const relativePath = path.relative(workspaceRoot, doc.uri.fsPath);
    let output;
    try {
        const result = await execCommand(phpCmd, [ikabudPath, 'disyl:lint', relativePath, '--json'], { cwd: workspaceRoot });
        output = result.stdout;
    }
    catch (err) {
        // Invalid templates produce a non-zero exit code but still return the JSON contract.
        output = err.stdout || '';
    }
    try {
        const phpDiagnostics = (0, disylCli_1.parseLint)(output).map((diagnostic) => {
            const lineText = diagnostic.line < doc.lineCount ? doc.lineAt(diagnostic.line).text : '';
            const endCharacter = Math.max(diagnostic.character + 1, lineText.length);
            const range = new vscode.Range(diagnostic.line, diagnostic.character, diagnostic.line, endCharacter);
            const item = new vscode.Diagnostic(range, diagnostic.message, diagnostic.severity === 'error'
                ? vscode.DiagnosticSeverity.Error
                : vscode.DiagnosticSeverity.Warning);
            item.code = diagnostic.code;
            item.source = 'DiSyL';
            return item;
        });
        // Merge: EBNF catches structure, PHP catches semantics
        collection.set(doc.uri, [...ebnfDiagnostics, ...phpDiagnostics]);
    }
    catch (error) {
        console.warn('Unable to parse disyl:lint JSON; using syntax diagnostics only.', error);
        collection.set(doc.uri, ebnfDiagnostics);
    }
}
async function loadCliDescription() {
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) {
        vscode.window.showWarningMessage('DiSyL CLI catalogue unavailable: no workspace folder is open. Completions will be empty.');
        return;
    }
    const phpCmd = vscode.workspace.getConfiguration('disyl').get('phpCommand', 'php');
    const ikabudPath = resolveIkabudPath(workspaceRoot);
    if (!ikabudPath)
        return;
    try {
        const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:describe', '--json'], { cwd: workspaceRoot });
        const description = (0, disylCli_1.parseDescribe)(stdout);
        disylFilters = description.filters;
        governedComponents = description.components;
    }
    catch (error) {
        disylFilters = [];
        governedComponents = [];
        const detail = error instanceof Error ? error.message : String(error);
        vscode.window.showWarningMessage(`DiSyL CLI catalogue unavailable: ${detail}. Completions will be empty.`);
    }
}
async function loadCliSources() {
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) {
        entitySources = [];
        return;
    }
    const phpCmd = vscode.workspace.getConfiguration('disyl').get('phpCommand', 'php');
    const ikabudPath = resolveIkabudPath(workspaceRoot);
    if (!ikabudPath)
        return;
    try {
        const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:list-sources', '--json'], { cwd: workspaceRoot });
        entitySources = (0, disylCli_1.parseSources)(stdout);
    }
    catch (error) {
        entitySources = [];
        const detail = error instanceof Error ? error.message : String(error);
        vscode.window.showWarningMessage(`DiSyL entity catalogue unavailable: ${detail}. Source/view completions will be empty.`);
    }
}
// ── Resolve the ikabud CLI script path ──
function resolveIkabudPath(workspaceRoot) {
    const configuredPath = vscode.workspace.getConfiguration('disyl').get('ikabudPath', '');
    if (configuredPath) {
        return configuredPath;
    }
    const defaultPath = path.join(workspaceRoot, 'ikabud');
    return defaultPath;
}
// ── Execute a shell command ──
function execCommand(command, args, options) {
    const fullCmd = [command, ...args.map(a => `"${a.replace(/"/g, '\\"')}"`)].join(' ');
    return new Promise((resolve, reject) => {
        cp.exec(fullCmd, { ...options, timeout: 30000, encoding: 'utf-8' }, (error, stdout, stderr) => {
            if (error) {
                reject(Object.assign(error, { stdout, stderr }));
            }
            else {
                resolve({ stdout: stdout, stderr: stderr });
            }
        });
    });
}
// ── Resolve a template path to a file URI ──
function resolveTemplatePath(document, target) {
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot)
        return null;
    // Try relative to current file
    const currentDir = path.dirname(document.uri.fsPath);
    let resolved = path.resolve(currentDir, target);
    if (fs.existsSync(resolved)) {
        return new vscode.Location(vscode.Uri.file(resolved), new vscode.Position(0, 0));
    }
    // Try relative to templates/ root
    resolved = path.resolve(workspaceRoot, 'templates', target);
    if (fs.existsSync(resolved)) {
        return new vscode.Location(vscode.Uri.file(resolved), new vscode.Position(0, 0));
    }
    // Try with .disyl extension
    if (!target.endsWith('.disyl')) {
        resolved = path.resolve(workspaceRoot, 'templates', target + '.disyl');
        if (fs.existsSync(resolved)) {
            return new vscode.Location(vscode.Uri.file(resolved), new vscode.Position(0, 0));
        }
    }
    return null;
}
// ── Autocomplete data ──
const BLOCK_KEYWORDS = [
    'extends', 'block', '/block', 'parent',
    'if', 'elseif', 'else', '/if',
    'foreach', '/foreach', 'for', '/for', 'empty',
    'include', 'set',
    'math',
    'component', '/component', 'slot', '/slot',
    'verbatim', '/verbatim', 'literal', '/literal',
    'match', '/match', 'when', '/when', 'default',
    'macro', '/macro', 'call',
    'await', '/await', 'then', '/then', 'loading', '/loading', 'catch', '/catch',
    'debug',
    'trans', '/trans', 'cache', '/cache',
    'sandbox', '/sandbox', 'trusted', '/trusted', 'untrusted', '/untrusted',
    'parallel', '/parallel',
    'federated_query', '/federated_query',
    'ai_generate', '/ai_generate', 'ai_query', '/ai_query', 'ai_complete', '/ai_complete',
];
const BLOCK_KEYWORD_DOCS = {
    'if': 'Conditional rendering: `{if condition}...{elseif cond}...{else}...{/if}`',
    'elseif': 'Additional condition branch within `{if}`',
    'else': 'Fallback branch for `{if}` or `{match}`',
    'for': 'Loop over iterable: `{for item in list}...{empty}...{/for}`',
    'foreach': 'Loop with key/value: `{foreach items as key => value}...{/foreach}`',
    'empty': 'Rendered when a loop has zero items',
    'match': 'Pattern matching (v4.8): `{match expr}{when "val"}...{/when}{else}...{/match}`',
    'when': 'Match arm: `{when "value"}...{/when}`. Supports multi-pattern `"a","b"`, wildcard `_`, guards `{when "v" guard cond}`',
    'default': 'Default match arm (alias: `{else}`)',
    'macro': 'Define reusable template block (v4.8): `{macro name(params)}...{/macro}`',
    'call': 'Invoke a macro (v4.8): `{call name(args)}` or `{call name}`',
    'await': 'Async/sync value rendering (v4.8): `{await expr}{then}...{/then}{loading}...{/loading}{catch}...{/catch}{/await}`',
    'then': 'Renders when {await} resolves successfully. Binds value as `{value}`',
    'loading': 'Renders while {await} is pending',
    'catch': 'Renders on error. Optional `let=e` binds error: `{catch let=e}{e}{/catch}`',
    'debug': 'Pretty-print any variable (v4.8): `{debug myVar}`',
    'set': 'Assign variable: `{set name = value}` or typed (v4.8): `{set name: type = value}`',
    'math': 'Evaluate a DiSyL arithmetic expression: `{math equation="price * qty"}` with optional `format="decimals"` and `assign="var"`. Same expression surface + pipe-binding caveat as `{(...) }` — parenthesize before filters: `{math equation="(a + b) | round:2"}`',
    'extends': 'Template inheritance: `{extends "layouts/main.disyl"}`',
    'block': 'Named block for inheritance: `{block name}...{/block}`',
    'include': 'Include another template: `{include "partials/header.disyl"}`',
    'verbatim': 'Raw content — no DiSyL processing',
    'literal': 'Raw content — no DiSyL processing (alias)',
    'sandbox': 'Restrict allowed HTML tags for child {untrusted} blocks',
};
const CLOSE_TAG_MAP = {
    if: '/if', for: '/for', foreach: '/foreach', each: '/each',
    block: '/block', slot: '/slot', component: '/component',
    match: '/match', when: '/when',
    macro: '/macro', call: '/call',
    await: '/await', parallel: '/parallel',
    sandbox: '/sandbox', trusted: '/trusted', untrusted: '/untrusted',
    cache: '/cache', trans: '/trans',
    federated_query: '/federated_query',
    ai_generate: '/ai_generate', ai_query: '/ai_query', ai_complete: '/ai_complete',
};
const GOVERNED_COMPONENT_DOCS = {
    'ikb_section': { category: 'Structural', description: 'A semantic page section. Groups content into themed blocks.', attributes: ['tone', 'spacing', 'background', 'padding'] },
    'ikb_container': { category: 'Structural', description: 'A constrained-width wrapper. Centers content with optional max-width.', attributes: ['max_width', 'padding', 'class'] },
    'ikb_grid': { category: 'Structural', description: 'Responsive CSS grid layout. Arranges children in columns.', attributes: ['cols', 'gap', 'align'] },
    'ikb_panel': { category: 'Structural', description: 'Themed panel with header, body, footer slots. Uses design tokens for tone/spacing/radius.', attributes: ['tone', 'spacing', 'radius', 'title', 'variant'] },
    'ikb_entity_list': { category: 'Data', description: 'Database-driven table or card grid from entity views. Supports filter, sort, pagination, row actions.', attributes: ['source', 'view', 'filter', 'limit', 'layout', 'actions'] },
    'ikb_entity_detail': { category: 'Data', description: 'Single-entity detail view. Renders fields from a registered entity view contract.', attributes: ['source', 'view', 'id', 'fields'] },
    'ikb_stat_card': { category: 'Data', description: 'KPI/metric card with label, value, trend, and optional icon.', attributes: ['label', 'value', 'trend', 'icon', 'color'] },
    'ikb_timeline': { category: 'Data', description: 'Chronological event timeline. Each child is rendered as a timeline entry.', attributes: ['source', 'date_field', 'title_field', 'limit'] },
    'ikb_audit_log': { category: 'Data', description: 'Audit trail viewer. Shows who did what and when.', attributes: ['source', 'entity_type', 'entity_id', 'limit'] },
    'ikb_table': { category: 'Data', description: 'Generic data table with columns, sorting, and row actions.', attributes: ['source', 'columns', 'sortable', 'actions'] },
    'ikb_badge': { category: 'Data', description: 'Status badge with color mapping. Supports dynamic badge:map patterns.', attributes: ['value', 'map', 'color', 'size', 'variant'] },
    'ikb_form': { category: 'Form', description: 'Form wrapper with CSRF protection and validation.', attributes: ['action', 'method', 'enctype', 'class'] },
    'ikb_input': { category: 'Form', description: 'Text/number/email/password input field.', attributes: ['name', 'type', 'label', 'value', 'placeholder', 'required', 'class'] },
    'ikb_textarea': { category: 'Form', description: 'Multi-line text input.', attributes: ['name', 'label', 'value', 'rows', 'placeholder', 'class'] },
    'ikb_select': { category: 'Form', description: 'Dropdown select with options.', attributes: ['name', 'label', 'options', 'value', 'multiple', 'class'] },
    'ikb_button': { category: 'Interactive', description: 'Action button with style variants.', attributes: ['label', 'variant', 'size', 'icon', 'href', 'disabled', 'class'] },
    'ikb_export_button': { category: 'Interactive', description: 'Governed document download link with format selection.', attributes: ['source', 'format', 'variant', 'size', 'label'] },
    'ikb_confirm_action': { category: 'Interactive', description: 'Destructive action with confirmation dialog.', attributes: ['action', 'label', 'confirm_text', 'variant', 'icon', 'method'] },
    'ikb_card': { category: 'Layout', description: 'Content card with optional image, header, body, footer.', attributes: ['image', 'title', 'subtitle', 'variant', 'class'] },
    'ikb_modal': { category: 'Layout', description: 'Overlay modal dialog.', attributes: ['title', 'size', 'closeable', 'class'] },
    'ikb_drawer': { category: 'Layout', description: 'Slide-in side panel.', attributes: ['title', 'position', 'width', 'class'] },
    'ikb_alert': { category: 'Layout', description: 'Contextual alert/notification banner.', attributes: ['tone', 'dismissible', 'icon', 'title', 'class'] },
    'ikb_spinner': { category: 'Layout', description: 'Loading spinner indicator.', attributes: ['size', 'color', 'label'] },
    'ikb_text': { category: 'Content', description: 'Rich text content block. Supports HTML and inline DiSyL expressions.', attributes: ['content', 'tag', 'class'] },
    'ikb_image': { category: 'Content', description: 'Image with responsive srcset and lazy loading.', attributes: ['src', 'alt', 'width', 'height', 'class', 'loading'] },
    'ikb_icon': { category: 'Content', description: 'SVG icon from the icon library.', attributes: ['name', 'size', 'color', 'class'] },
    'ikb_link': { category: 'Content', description: 'Hyperlink with optional external icon.', attributes: ['href', 'label', 'target', 'class', 'external'] },
    'ikb_report': { category: 'Report', description: 'Report container with header, body, and signature sections.', attributes: ['title', 'source', 'format', 'variant'] },
    'ikb_signature_block': { category: 'Report', description: 'Signature lines by role for reports.', attributes: ['roles', 'label'] },
    'ikb_ai_summary': { category: 'AI', description: 'Governed AI summarization block with review badge.', attributes: ['source', 'model', 'max_tokens', 'review_badge'] },
    'ikb_ai_assist': { category: 'AI', description: 'Governed AI drafting block (draft_only/suggest/auto_publish modes).', attributes: ['mode', 'model', 'prompt', 'redaction'] },
};
//# sourceMappingURL=extension.js.map