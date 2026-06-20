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
const DIAGNOSTIC_COLLECTION = 'disyl';
function activate(context) {
    console.log('DiSyL language support activated');
    const diagCollection = vscode.languages.createDiagnosticCollection(DIAGNOSTIC_COLLECTION);
    context.subscriptions.push(diagCollection);
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
            // Filter autocomplete after |
            if (lineBefore.endsWith('|') || lineBefore.match(/\|\s*$/)) {
                return DISYL_FILTERS.map((f) => {
                    const item = new vscode.CompletionItem(f.label, vscode.CompletionItemKind.Function);
                    item.detail = f.detail;
                    item.documentation = new vscode.MarkdownString(f.docs);
                    return item;
                });
            }
            // Component autocomplete after {ikb_
            const compMatch = lineBefore.match(/\{(ikb_[a-zA-Z_]*)$/);
            if (compMatch) {
                const prefix = compMatch[1];
                return GOVERNED_COMPONENTS
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
            return [];
        }
    }, '{', '|', ' '));
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
                const filter = DISYL_FILTERS.find((f) => f.label === word);
                if (filter) {
                    return new vscode.Hover(new vscode.MarkdownString(`**${filter.label}** — DiSyL Filter\n\n${filter.docs}`));
                }
            }
            // Hover on governed components
            if (GOVERNED_COMPONENTS.includes(word)) {
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
    try {
        const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:lint', relativePath, '--verbose'], { cwd: workspaceRoot });
        const phpDiagnostics = parseLintOutput(stdout, doc);
        // Merge: EBNF catches structure, PHP catches semantics
        collection.set(doc.uri, [...ebnfDiagnostics, ...phpDiagnostics]);
    }
    catch (err) {
        const output = err.stdout || err.stderr || '';
        const phpDiagnostics = parseLintOutput(output, doc);
        collection.set(doc.uri, [...ebnfDiagnostics, ...phpDiagnostics]);
    }
}
// ── Parse linter output into VS Code diagnostics ──
function parseLintOutput(output, doc) {
    const diagnostics = [];
    const lines = output.split('\n');
    for (const line of lines) {
        // Pattern: "  ✗ L42 error: message" or "  ⚠ L15 warn: message" or "  ⚠ warn: message"
        const diagMatch = line.match(/^\s*[✗⚠]\s*(L(\d+)\s*)?\s*(error|warn):\s+(.+)/);
        if (diagMatch) {
            const severity = diagMatch[3] === 'error'
                ? vscode.DiagnosticSeverity.Error
                : vscode.DiagnosticSeverity.Warning;
            const message = diagMatch[4].trim();
            const lineNum = diagMatch[2] ? parseInt(diagMatch[2], 10) - 1 : 0;
            const lineText = lineNum < doc.lineCount ? doc.lineAt(lineNum).text : '';
            const range = new vscode.Range(lineNum, 0, lineNum, lineText.length || 1);
            diagnostics.push({ message, range, severity, source: 'DiSyL' });
            continue;
        }
        // Legacy pattern: "  ⚠ warn: N unclosed ..."
        const warnMatch = line.match(/warn:\s+(.+)/);
        if (warnMatch) {
            const message = warnMatch[1].trim();
            const range = new vscode.Range(0, 0, 0, doc.lineAt(0).text.length);
            // Try to find the unclosed component in the file
            const compName = message.match(/unclosed \{(\w+)\}/);
            if (compName) {
                const openTag = `{${compName[1]}`;
                for (let i = 0; i < doc.lineCount; i++) {
                    const text = doc.lineAt(i).text;
                    if (text.includes(openTag) && !text.includes(`{/${compName[1]}`)) {
                        const range = new vscode.Range(i, text.indexOf(openTag), i, text.indexOf(openTag) + openTag.length);
                        diagnostics.push({
                            message: `Unclosed component: ${message}`,
                            range,
                            severity: vscode.DiagnosticSeverity.Warning,
                            source: 'DiSyL'
                        });
                        break;
                    }
                }
            }
            else {
                diagnostics.push({
                    message,
                    range,
                    severity: vscode.DiagnosticSeverity.Warning,
                    source: 'DiSyL'
                });
            }
        }
    }
    return diagnostics;
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
const DISYL_FILTERS = [
    { label: 'upper', detail: 'Convert to uppercase', docs: 'Converts the value to uppercase.\n\n`{"hello"|upper}` → `"HELLO"`' },
    { label: 'lower', detail: 'Convert to lowercase', docs: 'Converts the value to lowercase.\n\n`{"HELLO"|lower}` → `"hello"`' },
    { label: 'raw', detail: 'Output without HTML escaping', docs: 'Outputs the value without HTML escaping. Use carefully.\n\n`{$html|raw}`' },
    { label: 'default', detail: 'Fallback value', docs: 'Returns the value if truthy, otherwise the fallback.\n\n`{$name|default:"Guest"}`' },
    { label: 'json', detail: 'JSON encode', docs: 'Encodes the value as JSON. Output is raw (not HTML-escaped).\n\n`{$data|json}`' },
    { label: 'date', detail: 'Format date', docs: 'Formats a date string/timestamp.\n\n`{$created|date:"M d, Y"}`' },
    { label: 'length', detail: 'Array/string length', docs: 'Returns the length of an array or string.\n\n`{$items|length}`' },
    { label: 'trim', detail: 'Trim whitespace', docs: 'Trims whitespace from both ends of a string.\n\n`{$name|trim}`' },
    { label: 'nl2br', detail: 'Newlines to <br>', docs: 'Converts newlines to `<br>` tags.\n\n`{$text|nl2br}`' },
    { label: 'number_format', detail: 'Format number', docs: 'Formats a number with grouped thousands.\n\n`{$price|number_format}`' },
    { label: 'first', detail: 'First element', docs: 'Returns the first element of an array.\n\n`{$items|first}`' },
    { label: 'last', detail: 'Last element', docs: 'Returns the last element of an array.\n\n`{$items|last}`' },
    { label: 'keys', detail: 'Array keys', docs: 'Returns the keys of an array.\n\n`{$data|keys}`' },
    { label: 'values', detail: 'Array values', docs: 'Returns the values of an array (re-indexed).\n\n`{$data|values}`' },
    { label: 'sort', detail: 'Sort array', docs: 'Sorts an array by values.\n\n`{$items|sort}`' },
    { label: 'reverse', detail: 'Reverse array', docs: 'Reverses an array.\n\n`{$items|reverse}`' },
    { label: 'slice', detail: 'Slice array', docs: 'Extracts a slice of an array.\n\n`{$items|slice:0:5}`' },
    { label: 'escape', detail: 'HTML escape', docs: 'HTML-escapes the value (default behaviour).\n\n`{$html|escape}`' },
    { label: 'url_encode', detail: 'URL encode', docs: 'URL-encodes the value.\n\n`{$slug|url_encode}`' },
    { label: 'strip_tags', detail: 'Strip HTML tags', docs: 'Strips HTML and PHP tags from a string.\n\n`{$html|strip_tags}`' },
    { label: 'truncate', detail: 'Truncate text', docs: 'Truncates text to a specified length. Named arg support (v4.8).\n\n`{$body|truncate:100}` or `{$body|truncate:length=100}`' },
    { label: 'date', detail: 'Format date', docs: 'Formats a date string/timestamp. Named arg support (v4.8).\n\n`{$created|date:"M d, Y"}` or `{$created|date:format="M d, Y"}`' },
    { label: 'json_attr', detail: 'JSON + HTML-escape for attributes', docs: 'JSON-encodes then HTML-escapes for safe use in HTML attributes (e.g. x-data).\n\n`{$data|json_attr}`' },
];
const GOVERNED_COMPONENTS = [
    'ikb_section', 'ikb_container', 'ikb_grid', 'ikb_panel',
    'ikb_entity_list', 'ikb_entity_detail', 'ikb_stat_card', 'ikb_timeline',
    'ikb_audit_log', 'ikb_table', 'ikb_badge',
    'ikb_form', 'ikb_input', 'ikb_textarea', 'ikb_select',
    'ikb_button', 'ikb_export_button', 'ikb_confirm_action',
    'ikb_card', 'ikb_modal', 'ikb_drawer', 'ikb_alert', 'ikb_spinner',
    'ikb_text', 'ikb_image', 'ikb_icon', 'ikb_link',
    'ikb_report', 'ikb_signature_block',
    'ikb_ai_summary', 'ikb_ai_assist',
];
const BLOCK_KEYWORDS = [
    'extends', 'block', '/block', 'parent',
    'if', 'elseif', 'else', '/if',
    'foreach', '/foreach', 'for', '/for', 'empty',
    'include', 'set',
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
    'extends': 'Template inheritance: `{extends "layouts/main.disyl"}`',
    'block': 'Named block for inheritance: `{block name}...{/block}`',
    'include': 'Include another template: `{include "partials/header.disyl"}`',
    'verbatim': 'Raw content — no DiSyL processing',
    'literal': 'Raw content — no DiSyL processing (alias)',
    'sandbox': 'Restrict allowed HTML tags for child {untrusted} blocks',
};
//# sourceMappingURL=extension.js.map