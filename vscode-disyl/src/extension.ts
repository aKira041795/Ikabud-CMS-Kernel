import * as path from 'path';
import * as vscode from 'vscode';
import {
    LanguageClient,
    LanguageClientOptions,
    ServerOptions,
    TransportKind
} from 'vscode-languageclient/node';

let client: LanguageClient;

export function activate(context: vscode.ExtensionContext) {
    console.log('DiSyL extension is now active');

    // Start the language server
    client = startLanguageServer(context);

    // Register commands
    registerCommands(context);

    // Register webview provider
    registerWebviewProvider(context);
}

export function deactivate(): Thenable<void> | undefined {
    if (!client) {
        return undefined;
    }
    return client.stop();
}

function startLanguageServer(context: vscode.ExtensionContext): LanguageClient {
    // Server module path
    const serverModule = context.asAbsolutePath(
        path.join('out', 'server', 'server.js')
    );

    // Debug options for the server
    const debugOptions = { execArgv: ['--nolazy', '--inspect=6009'] };

    // Server options: run the server in Node.js
    const serverOptions: ServerOptions = {
        run: { module: serverModule, transport: TransportKind.ipc },
        debug: {
            module: serverModule,
            transport: TransportKind.ipc,
            options: debugOptions
        }
    };

    // Client options: configure the language client
    const clientOptions: LanguageClientOptions = {
        // Register the server for DiSyL documents
        documentSelector: [
            { scheme: 'file', language: 'disyl' },
            { scheme: 'untitled', language: 'disyl' }
        ],
        synchronize: {
            // Watch for .disyl files in the workspace
            fileEvents: vscode.workspace.createFileSystemWatcher('**/*.disyl')
        }
    };

    // Create and start the language client
    const client = new LanguageClient(
        'disylLanguageServer',
        'DiSyL Language Server',
        serverOptions,
        clientOptions
    );

    client.start();

    return client;
}

function registerCommands(context: vscode.ExtensionContext) {
    // Command: Format DiSyL Document
    const formatCommand = vscode.commands.registerCommand(
        'disyl.formatDocument',
        () => {
            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'disyl') {
                vscode.commands.executeCommand('editor.action.formatDocument');
            }
        }
    );

    // Command: Show Component Preview
    const previewCommand = vscode.commands.registerCommand(
        'disyl.showPreview',
        () => {
            const panel = vscode.window.createWebviewPanel(
                'disylPreview',
                'DiSyL Component Preview',
                vscode.ViewColumn.Beside,
                {
                    enableScripts: true,
                    retainContextWhenHidden: true
                }
            );

            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'disyl') {
                panel.webview.html = getPreviewHtml(editor.document.getText());
            } else {
                panel.webview.html = getPreviewHtml('');
            }
        }
    );

    // Command: Validate DiSyL Document
    const validateCommand = vscode.commands.registerCommand(
        'disyl.validateDocument',
        async () => {
            const editor = vscode.window.activeTextEditor;
            if (editor && editor.document.languageId === 'disyl') {
                await vscode.commands.executeCommand('disyl.validate', editor.document.uri);
                vscode.window.showInformationMessage('DiSyL document validated');
            }
        }
    );

    // Command: Insert Component Snippet
    const insertComponentCommand = vscode.commands.registerCommand(
        'disyl.insertComponent',
        async () => {
            const components = [
                { label: 'ikb_section', description: 'Container section' },
                { label: 'ikb_container', description: 'Responsive container' },
                { label: 'ikb_text', description: 'Text component' },
                { label: 'ikb_button', description: 'Button component' },
                { label: 'ikb_card', description: 'Card component' },
                { label: 'ikb_image', description: 'Image component' },
                { label: 'ikb_grid', description: 'Grid layout' },
                { label: 'ikb_query', description: 'Data query' },
                { label: 'ikb_platform', description: 'Platform declaration' },
                { label: 'ikb_cms', description: 'CMS declaration' }
            ];

            const selected = await vscode.window.showQuickPick(components, {
                placeHolder: 'Select a DiSyL component to insert'
            });

            if (selected) {
                const editor = vscode.window.activeTextEditor;
                if (editor) {
                    let snippet: vscode.SnippetString;
                    
                    if (selected.label === 'ikb_platform') {
                        snippet = new vscode.SnippetString('{ikb_platform type="${1|web,mobile,desktop,universal|}" targets="${2}" /}');
                    } else if (selected.label === 'ikb_cms') {
                        snippet = new vscode.SnippetString('{ikb_cms type="${1|wordpress,joomla,drupal,ikabud,generic|}" /}');
                    } else if (selected.label === 'ikb_image') {
                        snippet = new vscode.SnippetString('{ikb_image src="${1}" alt="${2}" /}');
                    } else {
                        snippet = new vscode.SnippetString(`{${selected.label} $1}\n\t$0\n{/${selected.label}}`);
                    }
                    
                    editor.insertSnippet(snippet);
                }
            }
        }
    );

    context.subscriptions.push(
        formatCommand,
        previewCommand,
        validateCommand,
        insertComponentCommand
    );
}

function registerWebviewProvider(context: vscode.ExtensionContext) {
    // Register a custom editor provider for .disyl files (optional advanced feature)
    // This would allow inline preview/editing
}

function getPreviewHtml(content: string): string {
    // Parse components from content
    const components: { name: string; attrs: string }[] = [];
    const componentRegex = /\{(ikb_[a-z_][a-z0-9_]*)([^}]*)\}/g;
    let match;
    
    while ((match = componentRegex.exec(content)) !== null) {
        components.push({
            name: match[1],
            attrs: match[2].trim()
        });
    }

    const componentList = components.length > 0 
        ? components.map(c => `
            <div class="component">
                <div class="component-name">${c.name}</div>
                <div class="component-attrs">${c.attrs || 'No attributes'}</div>
            </div>
        `).join('')
        : '<p class="no-components">No DiSyL components found in the current document.</p>';

    return `<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DiSyL Preview</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            padding: 20px;
            background: var(--vscode-editor-background);
            color: var(--vscode-editor-foreground);
        }
        h1 {
            color: var(--vscode-textLink-foreground);
            border-bottom: 1px solid var(--vscode-panel-border);
            padding-bottom: 10px;
        }
        .component {
            border: 1px solid var(--vscode-panel-border);
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
            background: var(--vscode-editor-inactiveSelectionBackground);
        }
        .component-name {
            color: var(--vscode-symbolIcon-classForeground);
            font-weight: bold;
            font-size: 14px;
            margin-bottom: 8px;
        }
        .component-attrs {
            color: var(--vscode-descriptionForeground);
            font-size: 12px;
            font-family: var(--vscode-editor-font-family);
        }
        .no-components {
            color: var(--vscode-descriptionForeground);
            font-style: italic;
        }
        .section {
            margin-top: 20px;
        }
        .section-title {
            color: var(--vscode-textLink-foreground);
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 10px;
        }
    </style>
</head>
<body>
    <h1>DiSyL Component Preview</h1>
    
    <div class="section">
        <div class="section-title">Components Found (${components.length})</div>
        ${componentList}
    </div>
    
    <div class="section">
        <div class="section-title">Quick Reference</div>
        <div class="component">
            <div class="component-name">Syntax</div>
            <div class="component-attrs">
                Components: {ikb_name attr="value"}...{/ikb_name}<br>
                Expressions: {variable | filter:arg}<br>
                Control: {if condition="{expr}"}...{/if}
            </div>
        </div>
    </div>
</body>
</html>`;
}
