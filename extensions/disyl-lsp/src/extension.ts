import * as vscode from 'vscode';
import * as path from 'path';
import * as cp from 'child_process';
import * as fs from 'fs';
import { validateDisylDocument } from './validator';

const DIAGNOSTIC_COLLECTION = 'disyl';

export function activate(context: vscode.ExtensionContext) {
    console.log('DiSyL language support activated');

    const diagCollection = vscode.languages.createDiagnosticCollection(DIAGNOSTIC_COLLECTION);
    context.subscriptions.push(diagCollection);

    // ── Lint on save ──
    const lintOnSave = vscode.workspace.getConfiguration('disyl').get<boolean>('lintOnSave', true);
    if (lintOnSave) {
        context.subscriptions.push(
            vscode.workspace.onDidSaveTextDocument((doc) => {
                if (doc.languageId === 'disyl') {
                    lintDocument(doc, diagCollection);
                }
            })
        );
    }

    // ── Lint on type (EBNF validator only — instant, no PHP) ──
    const lintOnType = vscode.workspace.getConfiguration('disyl').get<boolean>('lintOnType', true);
    if (lintOnType) {
        // Debounce: run EBNF validation 300ms after last keystroke
        let typeTimer: NodeJS.Timeout | undefined;
        context.subscriptions.push(
            vscode.workspace.onDidChangeTextDocument((event) => {
                if (event.document.languageId !== 'disyl') return;
                if (typeTimer) clearTimeout(typeTimer);
                typeTimer = setTimeout(() => {
                    const ebnfDiagnostics = validateDisylDocument(event.document);
                    diagCollection.set(event.document.uri, ebnfDiagnostics);
                }, 300);
            })
        );
    }

    // ── Lint on open ──
    context.subscriptions.push(
        vscode.window.onDidChangeActiveTextEditor((editor) => {
            if (editor && editor.document.languageId === 'disyl') {
                lintDocument(editor.document, diagCollection);
            }
        })
    );

    // Lint any already-open disyl tabs
    vscode.window.visibleTextEditors.forEach((editor) => {
        if (editor.document.languageId === 'disyl') {
            lintDocument(editor.document, diagCollection);
        }
    });

    // ── Commands ──
    context.subscriptions.push(
        vscode.commands.registerCommand('disyl.lint', () => {
            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'disyl') {
                lintDocument(editor.document, diagCollection);
            }
        })
    );

    context.subscriptions.push(
        vscode.commands.registerCommand('disyl.lintAll', async () => {
            const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
            if (!workspaceRoot) {
                vscode.window.showErrorMessage('No workspace folder open.');
                return;
            }

            const phpCmd = vscode.workspace.getConfiguration('disyl').get<string>('phpCommand', 'php');
            const ikabudPath = resolveIkabudPath(workspaceRoot);
            if (!ikabudPath) return;

            try {
                const { stdout } = await execCommand(phpCmd, [ikabudPath, 'disyl:lint'], { cwd: workspaceRoot });
                const channel = vscode.window.createOutputChannel('DiSyL Lint');
                channel.clear();
                channel.append(stdout);
                channel.show();
            } catch (err: any) {
                const channel = vscode.window.createOutputChannel('DiSyL Lint');
                channel.clear();
                channel.append(err.stdout || err.stderr || err.message);
                channel.show();
            }
        })
    );

    // ── Cheatsheet command ──
    context.subscriptions.push(
        vscode.commands.registerCommand('disyl.cheatsheet', () => {
            const items: vscode.QuickPickItem[] = [
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
        })
    );

    // ── Open Quickstart command ──
    context.subscriptions.push(
        vscode.commands.registerCommand('disyl.openQuickstart', () => {
            const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
            if (!workspaceRoot) {
                vscode.window.showErrorMessage('No workspace folder open.');
                return;
            }
            const quickstartPath = path.join(workspaceRoot, 'docs', 'disyl', 'quickstart.md');
            if (fs.existsSync(quickstartPath)) {
                vscode.commands.executeCommand('markdown.showPreview', vscode.Uri.file(quickstartPath));
            } else {
                vscode.window.showWarningMessage('DiSyL quickstart guide not found. Run `php ikabud disyl:lint` first.');
            }
        })
    );

    // ── Autocomplete: DiSyL filters ──
    context.subscriptions.push(
        vscode.languages.registerCompletionItemProvider('disyl', {
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
        }, '{', '|', ' ')
    );

    // ── Hover: filter documentation ──
    context.subscriptions.push(
        vscode.languages.registerHoverProvider('disyl', {
            provideHover(document, position) {
                const wordRange = document.getWordRangeAtPosition(position);
                if (!wordRange) return null;

                const word = document.getText(wordRange);
                const line = document.lineAt(position).text;

                // Hover on filters after |
                if (line.includes('|')) {
                    const filter = DISYL_FILTERS.find((f) => f.label === word);
                    if (filter) {
                        return new vscode.Hover(new vscode.MarkdownString(
                            `**${filter.label}** — DiSyL Filter\n\n${filter.docs}`
                        ));
                    }
                }

                // Hover on governed components
                if (GOVERNED_COMPONENTS.includes(word)) {
                    return new vscode.Hover(new vscode.MarkdownString(
                        `**${word}** — Governed DiSyL Component\n\nSee [ComponentRegistry docs](https://github.com/aKira041795/Ikabud-CMS-Kernel/blob/master/docs/kernel/disyl-component-system.md) for attribute schemas.`
                    ));
                }

                // Hover on block keywords (v4.8)
                if (BLOCK_KEYWORD_DOCS[word]) {
                    return new vscode.Hover(new vscode.MarkdownString(
                        `**{${word}}** — DiSyL Block Keyword\n\n${BLOCK_KEYWORD_DOCS[word]}`
                    ));
                }

                return null;
            }
        })
    );

    // ── Go to definition: {extends} and {include} paths ──
    context.subscriptions.push(
        vscode.languages.registerDefinitionProvider('disyl', {
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

                // Close-tag auto-insertion
                const precedingText = line.substring(0, position.character);
                const openTagMatch = precedingText.match(/\{(\/?)([a-z_]+)?$/);
                if (openTagMatch && openTagMatch[1] !== '/') {
                    const tagName = openTagMatch[2] || '';
                    if (tagName && CLOSE_TAG_MAP[tagName]) {
                        const item = new vscode.CompletionItem('/' + tagName, vscode.CompletionItemKind.Keyword);
                        item.insertText = '/' + tagName + '}';
                        item.filterText = '/' + tagName;
                        return [item];
                    }
                }

                return null;
            }
        }, '|') // Trigger after pipe for filters
    );

    // ── Hover: show docs for block keywords & components ──
    context.subscriptions.push(
        vscode.languages.registerHoverProvider('disyl', {
            provideHover(document, position) {
                const range = document.getWordRangeAtPosition(position, /\{(\/)?([a-zA-Z_][a-zA-Z0-9_]*)\}/);
                if (!range) return null;

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
        })
    );

    // ── Go-to-definition: {include "..."} → file ──
    context.subscriptions.push(
        vscode.languages.registerDefinitionProvider('disyl', {
            provideDefinition(document, position) {
                const range = document.getWordRangeAtPosition(position, /\{include\s+"([^"]+)"\}/);
                if (!range) return null;

                const text = document.getText(range);
                const match = text.match(/\{include\s+"([^"]+)"\}/);
                if (!match) return null;

                const target = match[1];
                return resolveTemplatePath(document, target);
            }
        })
    );
}

export function deactivate() {
    // Clean up
}

// ── Lint a single document ──
async function lintDocument(doc: vscode.TextDocument, collection: vscode.DiagnosticCollection) {
    // Phase 1: EBNF-based structural validation (instant, no PHP required)
    const ebnfDiagnostics = validateDisylDocument(doc);

    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) {
        // No workspace — return EBNF results only
        collection.set(doc.uri, ebnfDiagnostics);
        return;
    }

    // Phase 2: PHP-based semantic validation (capabilities, variables, filters)
    const phpCmd = vscode.workspace.getConfiguration('disyl').get<string>('phpCommand', 'php');
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
    } catch (err: any) {
        const output = err.stdout || err.stderr || '';
        const phpDiagnostics = parseLintOutput(output, doc);
        collection.set(doc.uri, [...ebnfDiagnostics, ...phpDiagnostics]);
    }
}

// ── Parse linter output into VS Code diagnostics ──
function parseLintOutput(output: string, doc: vscode.TextDocument): vscode.Diagnostic[] {
    const diagnostics: vscode.Diagnostic[] = [];
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
            } else {
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
function resolveIkabudPath(workspaceRoot: string): string | null {
    const configuredPath = vscode.workspace.getConfiguration('disyl').get<string>('ikabudPath', '');
    if (configuredPath) {
        return configuredPath;
    }
    const defaultPath = path.join(workspaceRoot, 'ikabud');
    return defaultPath;
}

// ── Execute a shell command ──
function execCommand(command: string, args: string[], options: cp.ExecOptions): Promise<{ stdout: string; stderr: string }> {
    const fullCmd = [command, ...args.map(a => `"${a.replace(/"/g, '\\"')}"`)].join(' ');
    return new Promise((resolve, reject) => {
        cp.exec(fullCmd, { ...options, timeout: 30000, encoding: 'utf-8' } as cp.ExecOptions, (error: any, stdout: any, stderr: any) => {
            if (error) {
                reject(Object.assign(error, { stdout, stderr }));
            } else {
                resolve({ stdout: stdout as string, stderr: stderr as string });
            }
        });
    });
}

// ── Resolve a template path to a file URI ──
function resolveTemplatePath(document: vscode.TextDocument, target: string): vscode.Location | null {
    const workspaceRoot = vscode.workspace.workspaceFolders?.[0]?.uri.fsPath;
    if (!workspaceRoot) return null;

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

const DISYL_FILTERS: { label: string; detail: string; docs: string }[] = [
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

const GOVERNED_COMPONENTS: string[] = [
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

const BLOCK_KEYWORDS: string[] = [
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

const BLOCK_KEYWORD_DOCS: Record<string, string> = {
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

const CLOSE_TAG_MAP: Record<string, string> = {
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

const GOVERNED_COMPONENT_DOCS: Record<string, { category: string; description: string; attributes: string[] }> = {
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
