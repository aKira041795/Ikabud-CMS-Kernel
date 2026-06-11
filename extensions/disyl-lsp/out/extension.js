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
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot)
        return;
    const phpCmd = vscode.workspace.getConfiguration('disyl').get('phpCommand', 'php');
    const ikabudPath = resolveIkabudPath(workspaceRoot);
    if (!ikabudPath)
        return;
    const relativePath = path.relative(workspaceRoot, doc.uri.fsPath);
    try {
        const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:lint', relativePath, '--verbose'], { cwd: workspaceRoot });
        const diagnostics = parseLintOutput(stdout, doc);
        collection.set(doc.uri, diagnostics);
    }
    catch (err) {
        const output = err.stdout || err.stderr || '';
        const diagnostics = parseLintOutput(output, doc);
        collection.set(doc.uri, diagnostics);
    }
}
// ── Parse linter output into VS Code diagnostics ──
function parseLintOutput(output, doc) {
    const diagnostics = [];
    const lines = output.split('\n');
    for (const line of lines) {
        // Pattern: "  ⚠ warn: N unclosed {component_name} component(s) — missing {/component_name}"
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
    { label: 'truncate', detail: 'Truncate text', docs: 'Truncates text to a specified length.\n\n`{$body|truncate:100:"..."}`' },
    { label: 'pluralize', detail: 'Pluralize word', docs: 'Pluralizes a word based on count.\n\n`{$count|pluralize:"item":"items"}`' },
    { label: 'markdown', detail: 'Parse markdown', docs: 'Parses Markdown text to HTML.\n\n`{$content|markdown}`' },
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
    'foreach', '/foreach', 'for', '/for',
    'include', 'set',
    'component', '/component', 'slot', '/slot',
    'verbatim', '/verbatim', 'literal', '/literal',
    'match', '/match', 'trans', '/trans',
    'cache', '/cache', 'experiment', '/experiment',
    'sandbox', '/sandbox', 'trusted', '/trusted', 'untrusted', '/untrusted',
    'parallel', '/parallel', 'await', '/await',
    'federated_query', '/federated_query',
    'ai_generate', '/ai_generate', 'ai_query', '/ai_query', 'ai_complete', '/ai_complete',
];
//# sourceMappingURL=extension.js.map