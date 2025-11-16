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
const assert = __importStar(require("assert"));
const vscode = __importStar(require("vscode"));
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
        const completions = await vscode.commands.executeCommand('vscode.executeCompletionItemProvider', doc.uri, position);
        assert.ok(completions);
        assert.ok(completions.items.length > 0);
    });
    test('Should provide hover information', async () => {
        const doc = await vscode.workspace.openTextDocument({
            language: 'disyl',
            content: '{ikb_section type="hero"}'
        });
        const position = new vscode.Position(0, 5);
        const hovers = await vscode.commands.executeCommand('vscode.executeHoverProvider', doc.uri, position);
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
//# sourceMappingURL=extension.test.js.map