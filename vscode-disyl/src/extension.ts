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
        documentSelector: [{ scheme: 'file', language: 'disyl' }],
        synchronize: {
            fileEvents: vscode.workspace.createFileSystemWatcher('**/.disyl')
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

            panel.webview.html = getPreviewHtml();
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
                'ikb_section',
                'ikb_container',
                'ikb_text',
                'ikb_button',
                'ikb_card',
                'ikb_image',
                'ikb_grid',
                'ikb_query'
            ];

            const selected = await vscode.window.showQuickPick(components, {
                placeHolder: 'Select a DiSyL component to insert'
            });

            if (selected) {
                const editor = vscode.window.activeTextEditor;
                if (editor) {
                    const snippet = new vscode.SnippetString(`{${selected} $1}\n\t$0\n{/${selected}}`);
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

function getPreviewHtml(): string {
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
            background: #1e1e1e;
            color: #d4d4d4;
        }
        .component {
            border: 1px solid #3c3c3c;
            border-radius: 4px;
            padding: 15px;
            margin: 10px 0;
            background: #252526;
        }
        .component-name {
            color: #4ec9b0;
            font-weight: bold;
            margin-bottom: 10px;
        }
        h1 {
            color: #569cd6;
        }
    </style>
</head>
<body>
    <h1>DiSyL Component Preview</h1>
    <p>Select a DiSyL component in your editor to see a live preview here.</p>
    
    <div class="component">
        <div class="component-name">ikb_section</div>
        <p>A container section for organizing content with customizable padding and styling.</p>
    </div>
    
    <div class="component">
        <div class="component-name">ikb_text</div>
        <p>Text component with size, weight, and styling options.</p>
    </div>
    
    <div class="component">
        <div class="component-name">ikb_button</div>
        <p>Interactive button with variant and size options.</p>
    </div>
</body>
</html>`;
}
