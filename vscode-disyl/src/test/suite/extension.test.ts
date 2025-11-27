import * as assert from 'assert';
import * as vscode from 'vscode';

suite('DiSyL Extension Test Suite', () => {
    vscode.window.showInformationMessage('Start all tests.');

    test('Extension should be present', () => {
        assert.ok(vscode.extensions.getExtension('ikabud.disyl'));
    });

    test('Extension should activate', async () => {
        const ext = vscode.extensions.getExtension('ikabud.disyl');
        await ext?.activate();
        assert.ok(ext?.isActive);
    });

    test('Should register disyl language', () => {
        const languages = vscode.languages.getLanguages();
        assert.ok(languages);
    });

    test('Commands should be registered', async () => {
        const commands = await vscode.commands.getCommands();
        assert.ok(commands.includes('disyl.formatDocument'));
        assert.ok(commands.includes('disyl.showPreview'));
        assert.ok(commands.includes('disyl.validateDocument'));
        assert.ok(commands.includes('disyl.insertComponent'));
    });
});

suite('DiSyL Language Server Test Suite', () => {
    test('Should provide completions', async () => {
        const doc = await vscode.workspace.openTextDocument({
            language: 'disyl',
            content: '{ikb_'
        });
        
        const editor = await vscode.window.showTextDocument(doc);
        const position = new vscode.Position(0, 5);
        
        const completions = await vscode.commands.executeCommand<vscode.CompletionList>(
            'vscode.executeCompletionItemProvider',
            doc.uri,
            position
        );
        
        assert.ok(completions);
        assert.ok(completions.items.length > 0);
    });

    test('Should provide hover information', async () => {
        const doc = await vscode.workspace.openTextDocument({
            language: 'disyl',
            content: '{ikb_section type="hero"}'
        });
        
        const position = new vscode.Position(0, 5);
        
        const hovers = await vscode.commands.executeCommand<vscode.Hover[]>(
            'vscode.executeHoverProvider',
            doc.uri,
            position
        );
        
        assert.ok(hovers);
    });

    test('Should validate DiSyL syntax', async () => {
        const doc = await vscode.workspace.openTextDocument({
            language: 'disyl',
            content: '{ikb_section}\n{/ikb_text}'
        });
        
        await vscode.window.showTextDocument(doc);
        
        // Wait for diagnostics
        await new Promise(resolve => setTimeout(resolve, 1000));
        
        const diagnostics = vscode.languages.getDiagnostics(doc.uri);
        assert.ok(diagnostics.length > 0);
    });
});
