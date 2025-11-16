import {
    createConnection,
    TextDocuments,
    Diagnostic,
    DiagnosticSeverity,
    ProposedFeatures,
    InitializeParams,
    DidChangeConfigurationNotification,
    CompletionItem,
    CompletionItemKind,
    TextDocumentPositionParams,
    TextDocumentSyncKind,
    InitializeResult,
    Hover,
    MarkupKind,
    SignatureHelp,
    DocumentFormattingParams,
    TextEdit,
    Range,
    Position
} from 'vscode-languageserver/node';

import { TextDocument } from 'vscode-languageserver-textdocument';

// Create a connection for the server
const connection = createConnection(ProposedFeatures.all);

// Create a simple text document manager
const documents: TextDocuments<TextDocument> = new TextDocuments(TextDocument);

let hasConfigurationCapability = false;
let hasWorkspaceFolderCapability = false;
let hasDiagnosticRelatedInformationCapability = false;

connection.onInitialize((params: InitializeParams) => {
    const capabilities = params.capabilities;

    hasConfigurationCapability = !!(
        capabilities.workspace && !!capabilities.workspace.configuration
    );
    hasWorkspaceFolderCapability = !!(
        capabilities.workspace && !!capabilities.workspace.workspaceFolders
    );
    hasDiagnosticRelatedInformationCapability = !!(
        capabilities.textDocument &&
        capabilities.textDocument.publishDiagnostics &&
        capabilities.textDocument.publishDiagnostics.relatedInformation
    );

    const result: InitializeResult = {
        capabilities: {
            textDocumentSync: TextDocumentSyncKind.Incremental,
            completionProvider: {
                resolveProvider: true,
                triggerCharacters: ['{', '|', ' ', '=', '"']
            },
            hoverProvider: true,
            signatureHelpProvider: {
                triggerCharacters: ['(', ',']
            },
            documentFormattingProvider: true,
            documentSymbolProvider: true,
            definitionProvider: true
        }
    };

    if (hasWorkspaceFolderCapability) {
        result.capabilities.workspace = {
            workspaceFolders: {
                supported: true
            }
        };
    }

    return result;
});

connection.onInitialized(() => {
    if (hasConfigurationCapability) {
        connection.client.register(DidChangeConfigurationNotification.type, undefined);
    }
    if (hasWorkspaceFolderCapability) {
        connection.workspace.onDidChangeWorkspaceFolders(_event => {
            connection.console.log('Workspace folder change event received.');
        });
    }
});

// DiSyL language configuration
interface DiSyLSettings {
    maxNumberOfProblems: number;
    validateOnType: boolean;
    formatOnSave: boolean;
}

const defaultSettings: DiSyLSettings = {
    maxNumberOfProblems: 100,
    validateOnType: true,
    formatOnSave: true
};

let globalSettings: DiSyLSettings = defaultSettings;
const documentSettings: Map<string, Thenable<DiSyLSettings>> = new Map();

connection.onDidChangeConfiguration(change => {
    if (hasConfigurationCapability) {
        documentSettings.clear();
    } else {
        globalSettings = <DiSyLSettings>(
            (change.settings.disyl || defaultSettings)
        );
    }

    documents.all().forEach(validateTextDocument);
});

function getDocumentSettings(resource: string): Thenable<DiSyLSettings> {
    if (!hasConfigurationCapability) {
        return Promise.resolve(globalSettings);
    }
    let result = documentSettings.get(resource);
    if (!result) {
        result = connection.workspace.getConfiguration({
            scopeUri: resource,
            section: 'disyl'
        });
        documentSettings.set(resource, result);
    }
    return result;
}

documents.onDidClose(e => {
    documentSettings.delete(e.document.uri);
});

documents.onDidChangeContent(change => {
    validateTextDocument(change.document);
});

// Validation
async function validateTextDocument(textDocument: TextDocument): Promise<void> {
    const settings = await getDocumentSettings(textDocument.uri);
    const text = textDocument.getText();
    const diagnostics: Diagnostic[] = [];

    // Validate DiSyL syntax
    const componentPattern = /\{(ikb_\w+|if|for|include)\s/g;
    const closingPattern = /\{\/(ikb_\w+|if|for)\}/g;
    
    const openTags: Array<{ name: string; line: number }> = [];
    const lines = text.split('\n');

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        
        // Check for unclosed tags
        let match;
        while ((match = componentPattern.exec(line)) !== null) {
            const tagName = match[1];
            if (tagName !== 'include') {
                openTags.push({ name: tagName, line: i });
            }
        }

        // Check for closing tags
        while ((match = closingPattern.exec(line)) !== null) {
            const tagName = match[1];
            const lastOpen = openTags.pop();
            
            if (!lastOpen) {
                diagnostics.push({
                    severity: DiagnosticSeverity.Error,
                    range: {
                        start: { line: i, character: match.index },
                        end: { line: i, character: match.index + match[0].length }
                    },
                    message: `Unexpected closing tag: {/${tagName}}`,
                    source: 'disyl'
                });
            } else if (lastOpen.name !== tagName) {
                diagnostics.push({
                    severity: DiagnosticSeverity.Error,
                    range: {
                        start: { line: i, character: match.index },
                        end: { line: i, character: match.index + match[0].length }
                    },
                    message: `Mismatched closing tag: expected {/${lastOpen.name}}, found {/${tagName}}`,
                    source: 'disyl'
                });
            }
        }

        // Check for invalid filter syntax
        const filterPattern = /\{[^}]+\|([^}|]+)/g;
        while ((match = filterPattern.exec(line)) !== null) {
            const filterChain = match[1];
            const filters = filterChain.split('|').map(f => f.trim());
            
            for (const filter of filters) {
                const filterName = filter.split(':')[0].trim();
                if (!isValidFilter(filterName)) {
                    diagnostics.push({
                        severity: DiagnosticSeverity.Warning,
                        range: {
                            start: { line: i, character: match.index },
                            end: { line: i, character: match.index + match[0].length }
                        },
                        message: `Unknown filter: ${filterName}`,
                        source: 'disyl'
                    });
                }
            }
        }
    }

    // Check for unclosed tags at end of document
    for (const tag of openTags) {
        diagnostics.push({
            severity: DiagnosticSeverity.Error,
            range: {
                start: { line: tag.line, character: 0 },
                end: { line: tag.line, character: lines[tag.line].length }
            },
            message: `Unclosed tag: {${tag.name}}`,
            source: 'disyl'
        });
    }

    connection.sendDiagnostics({ uri: textDocument.uri, diagnostics: diagnostics.slice(0, settings.maxNumberOfProblems) });
}

function isValidFilter(filterName: string): boolean {
    const validFilters = [
        'esc_html', 'esc_url', 'esc_attr', 'esc_js',
        'strip_tags', 'truncate', 'upper', 'lower',
        'date', 'number_format', 'raw', 'default',
        'length', 'first', 'last', 'join', 'split',
        'replace', 'trim', 'capitalize'
    ];
    return validFilters.includes(filterName);
}

// Completion
connection.onCompletion(
    (_textDocumentPosition: TextDocumentPositionParams): CompletionItem[] => {
        return [
            ...getComponentCompletions(),
            ...getFilterCompletions(),
            ...getControlStructureCompletions()
        ];
    }
);

connection.onCompletionResolve(
    (item: CompletionItem): CompletionItem => {
        if (item.data === 'component') {
            item.detail = 'DiSyL Component';
            item.documentation = getComponentDocumentation(item.label);
        } else if (item.data === 'filter') {
            item.detail = 'DiSyL Filter';
            item.documentation = getFilterDocumentation(item.label);
        }
        return item;
    }
);

function getComponentCompletions(): CompletionItem[] {
    const components = [
        { name: 'ikb_section', desc: 'Container section for organizing content' },
        { name: 'ikb_container', desc: 'Responsive container with size options' },
        { name: 'ikb_text', desc: 'Text component with styling' },
        { name: 'ikb_button', desc: 'Interactive button component' },
        { name: 'ikb_card', desc: 'Card component with shadow and padding' },
        { name: 'ikb_image', desc: 'Image component with lazy loading' },
        { name: 'ikb_grid', desc: 'Responsive grid layout' },
        { name: 'ikb_query', desc: 'Query and loop through data' }
    ];

    return components.map(comp => ({
        label: comp.name,
        kind: CompletionItemKind.Class,
        data: 'component',
        insertText: `${comp.name} $1}\n\t$0\n{/${comp.name}}`,
        insertTextFormat: 2, // Snippet format
        documentation: comp.desc
    }));
}

function getFilterCompletions(): CompletionItem[] {
    const filters = [
        { name: 'esc_html', desc: 'Escape HTML entities' },
        { name: 'esc_url', desc: 'Escape URL' },
        { name: 'esc_attr', desc: 'Escape HTML attribute' },
        { name: 'strip_tags', desc: 'Remove HTML tags' },
        { name: 'truncate', desc: 'Truncate text to specified length' },
        { name: 'upper', desc: 'Convert to uppercase' },
        { name: 'lower', desc: 'Convert to lowercase' },
        { name: 'date', desc: 'Format date' },
        { name: 'number_format', desc: 'Format number' },
        { name: 'raw', desc: 'Output raw HTML (unescaped)' }
    ];

    return filters.map(filter => ({
        label: filter.name,
        kind: CompletionItemKind.Function,
        data: 'filter',
        documentation: filter.desc
    }));
}

function getControlStructureCompletions(): CompletionItem[] {
    return [
        {
            label: 'if',
            kind: CompletionItemKind.Keyword,
            insertText: 'if condition="{$1}"}\n\t$0\n{/if}',
            insertTextFormat: 2,
            documentation: 'Conditional statement'
        },
        {
            label: 'for',
            kind: CompletionItemKind.Keyword,
            insertText: 'for items="{$1}" as="$2"}\n\t$0\n{/for}',
            insertTextFormat: 2,
            documentation: 'Loop through items'
        },
        {
            label: 'include',
            kind: CompletionItemKind.Keyword,
            insertText: 'include file="$1" /}',
            insertTextFormat: 2,
            documentation: 'Include another DiSyL file'
        }
    ];
}

// Hover
connection.onHover(
    (params: TextDocumentPositionParams): Hover | null => {
        const document = documents.get(params.textDocument.uri);
        if (!document) {
            return null;
        }

        const text = document.getText();
        const offset = document.offsetAt(params.position);
        
        // Find component or filter at cursor position
        const componentMatch = text.substring(Math.max(0, offset - 50), offset + 50).match(/\{(ikb_\w+)/);
        if (componentMatch) {
            return {
                contents: {
                    kind: MarkupKind.Markdown,
                    value: getComponentDocumentation(componentMatch[1])
                }
            };
        }

        const filterMatch = text.substring(Math.max(0, offset - 20), offset + 20).match(/\|\s*(\w+)/);
        if (filterMatch) {
            return {
                contents: {
                    kind: MarkupKind.Markdown,
                    value: getFilterDocumentation(filterMatch[1])
                }
            };
        }

        return null;
    }
);

// Formatting
connection.onDocumentFormatting(
    (params: DocumentFormattingParams): TextEdit[] => {
        const document = documents.get(params.textDocument.uri);
        if (!document) {
            return [];
        }

        const text = document.getText();
        const formatted = formatDisylDocument(text);
        
        return [
            TextEdit.replace(
                Range.create(
                    Position.create(0, 0),
                    document.positionAt(text.length)
                ),
                formatted
            )
        ];
    }
);

function formatDisylDocument(text: string): string {
    const lines = text.split('\n');
    let indentLevel = 0;
    const formatted: string[] = [];

    for (const line of lines) {
        const trimmed = line.trim();
        
        // Decrease indent for closing tags
        if (trimmed.match(/^\{\/(ikb_\w+|if|for)\}/)) {
            indentLevel = Math.max(0, indentLevel - 1);
        }

        // Add formatted line
        formatted.push('    '.repeat(indentLevel) + trimmed);

        // Increase indent for opening tags
        if (trimmed.match(/^\{(ikb_\w+|if|for)\s/) && !trimmed.includes('/}')) {
            indentLevel++;
        }
    }

    return formatted.join('\n');
}

function getComponentDocumentation(component: string): string {
    const docs: Record<string, string> = {
        'ikb_section': '**ikb_section** - Container section for organizing content\n\n**Attributes:**\n- `type`: Section type (hero, main, footer)\n- `padding`: Padding size (small, medium, large)',
        'ikb_container': '**ikb_container** - Responsive container\n\n**Attributes:**\n- `size`: Container size (small, medium, large, xlarge)',
        'ikb_text': '**ikb_text** - Text component\n\n**Attributes:**\n- `size`: Text size (xs, sm, base, lg, xl, 2xl, 3xl)\n- `weight`: Font weight (normal, semibold, bold)\n- `class`: Additional CSS classes',
        'ikb_button': '**ikb_button** - Interactive button\n\n**Attributes:**\n- `variant`: Button style (primary, secondary, outline)\n- `size`: Button size (small, medium, large)\n- `disabled`: Disable button (true/false)',
        'ikb_card': '**ikb_card** - Card component\n\n**Attributes:**\n- `shadow`: Shadow size (none, small, medium, large)\n- `padding`: Card padding (small, medium, large)',
        'ikb_image': '**ikb_image** - Image component\n\n**Attributes:**\n- `src`: Image source URL\n- `alt`: Alt text\n- `lazy`: Enable lazy loading (true/false)\n- `width`: Image width\n- `height`: Image height',
        'ikb_grid': '**ikb_grid** - Responsive grid layout\n\n**Attributes:**\n- `columns`: Number of columns (1-12)\n- `gap`: Gap size (small, medium, large)',
        'ikb_query': '**ikb_query** - Query and loop through data\n\n**Attributes:**\n- `type`: Content type (post, page, custom)\n- `limit`: Number of items\n- `category`: Filter by category'
    };

    return docs[component] || `**${component}** - DiSyL component`;
}

function getFilterDocumentation(filter: string): string {
    const docs: Record<string, string> = {
        'esc_html': '**esc_html** - Escape HTML entities for safe output\n\nUsage: `{variable | esc_html}`',
        'esc_url': '**esc_url** - Escape and sanitize URL\n\nUsage: `{url | esc_url}`',
        'esc_attr': '**esc_attr** - Escape HTML attribute value\n\nUsage: `{value | esc_attr}`',
        'strip_tags': '**strip_tags** - Remove HTML tags from text\n\nUsage: `{content | strip_tags}`',
        'truncate': '**truncate** - Truncate text to specified length\n\nUsage: `{text | truncate:length=100,append="..."}`',
        'upper': '**upper** - Convert text to uppercase\n\nUsage: `{text | upper}`',
        'lower': '**lower** - Convert text to lowercase\n\nUsage: `{text | lower}`',
        'date': '**date** - Format date\n\nUsage: `{date | date:format="Y-m-d"}`',
        'number_format': '**number_format** - Format number\n\nUsage: `{number | number_format:decimals=2}`',
        'raw': '**raw** - Output raw HTML (unescaped)\n\n⚠️ Use with caution!\n\nUsage: `{html | raw}`'
    };

    return docs[filter] || `**${filter}** - DiSyL filter`;
}

// Make the text document manager listen on the connection
documents.listen(connection);

// Listen on the connection
connection.listen();
