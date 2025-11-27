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
    DocumentFormattingParams,
    TextEdit,
    Range,
    Position,
    SignatureHelp,
    SignatureInformation,
    ParameterInformation
} from 'vscode-languageserver/node';

import {
    TextDocument
} from 'vscode-languageserver-textdocument';

// Create a connection for the server
const connection = createConnection(ProposedFeatures.all);

// Create a simple text document manager
const documents: TextDocuments<TextDocument> = new TextDocuments(TextDocument);

let hasConfigurationCapability = false;
let hasWorkspaceFolderCapability = false;

connection.onInitialize((params: InitializeParams) => {
    const capabilities = params.capabilities;

    hasConfigurationCapability = !!(
        capabilities.workspace && !!capabilities.workspace.configuration
    );
    hasWorkspaceFolderCapability = !!(
        capabilities.workspace && !!capabilities.workspace.workspaceFolders
    );

    const result: InitializeResult = {
        capabilities: {
            textDocumentSync: TextDocumentSyncKind.Incremental,
            completionProvider: {
                resolveProvider: true,
                triggerCharacters: ['{', '|', ':', '.', '"', "'", ' ']
            },
            hoverProvider: true,
            documentFormattingProvider: true,
            signatureHelpProvider: {
                triggerCharacters: [':', ',', '(']
            }
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
});

// Settings interface
interface DiSyLSettings {
    maxNumberOfProblems: number;
    validateOnType: boolean;
    formatOnSave: boolean;
    targetPlatform: string;
}

const defaultSettings: DiSyLSettings = {
    maxNumberOfProblems: 100,
    validateOnType: true,
    formatOnSave: true,
    targetPlatform: 'generic'
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

async function validateTextDocument(textDocument: TextDocument): Promise<void> {
    const settings = await getDocumentSettings(textDocument.uri);
    
    if (!settings.validateOnType) {
        return;
    }

    const text = textDocument.getText();
    const diagnostics: Diagnostic[] = [];
    let problems = 0;

    // Validate unclosed tags
    const openTags: { name: string; line: number; col: number }[] = [];
    const tagPattern = /\{(\/?)((ikb_[a-z_][a-z0-9_]*)|([a-z_][a-z0-9_]*:[a-z_][a-z0-9_]*)|(if|for|switch))(?:\s|\/|\})/g;
    let match;

    while ((match = tagPattern.exec(text)) !== null && problems < settings.maxNumberOfProblems) {
        const isClosing = match[1] === '/';
        const tagName = match[2];
        const pos = textDocument.positionAt(match.index);

        if (isClosing) {
            const lastOpen = openTags.pop();
            if (!lastOpen) {
                diagnostics.push({
                    severity: DiagnosticSeverity.Error,
                    range: {
                        start: pos,
                        end: textDocument.positionAt(match.index + match[0].length)
                    },
                    message: `Unexpected closing tag: {/${tagName}}`,
                    source: 'disyl'
                });
                problems++;
            } else if (lastOpen.name !== tagName) {
                diagnostics.push({
                    severity: DiagnosticSeverity.Error,
                    range: {
                        start: pos,
                        end: textDocument.positionAt(match.index + match[0].length)
                    },
                    message: `Mismatched tag: expected {/${lastOpen.name}}, found {/${tagName}}`,
                    source: 'disyl'
                });
                problems++;
            }
        } else {
            // Check if self-closing
            const fullMatch = text.substring(match.index, text.indexOf('}', match.index) + 1);
            if (!fullMatch.includes('/}')) {
                openTags.push({ name: tagName, line: pos.line, col: pos.character });
            }
        }
    }

    // Report unclosed tags
    for (const tag of openTags) {
        if (problems >= settings.maxNumberOfProblems) break;
        diagnostics.push({
            severity: DiagnosticSeverity.Error,
            range: {
                start: Position.create(tag.line, tag.col),
                end: Position.create(tag.line, tag.col + tag.name.length + 1)
            },
            message: `Unclosed tag: {${tag.name}}`,
            source: 'disyl'
        });
        problems++;
    }

    // Validate expressions
    const exprPattern = /\{([^{}]+)\}/g;
    while ((match = exprPattern.exec(text)) !== null && problems < settings.maxNumberOfProblems) {
        const content = match[1];
        
        // Skip comments, control structures, and components
        if (content.startsWith('!--') || 
            content.startsWith('/') ||
            content.startsWith('ikb_') ||
            /^(if|else|elseif|for|empty|switch|case|default|include)\b/.test(content)) {
            continue;
        }

        // Check for unescaped output (warning)
        const hasEscaping = /\|\s*(esc_html|esc_url|esc_attr|strip_tags|wp_kses_post)/.test(content);
        if (!hasEscaping && !content.includes('|')) {
            const pos = textDocument.positionAt(match.index);
            diagnostics.push({
                severity: DiagnosticSeverity.Warning,
                range: {
                    start: pos,
                    end: textDocument.positionAt(match.index + match[0].length)
                },
                message: `Expression "{${content}}" has no escaping filter. Consider using | esc_html`,
                source: 'disyl'
            });
            problems++;
        }

        // Check for invalid filter syntax
        const invalidFilterPattern = /\|\s*([a-z_][a-z0-9_]*)\s+[a-z_]/;
        if (invalidFilterPattern.test(content)) {
            const pos = textDocument.positionAt(match.index);
            diagnostics.push({
                severity: DiagnosticSeverity.Error,
                range: {
                    start: pos,
                    end: textDocument.positionAt(match.index + match[0].length)
                },
                message: `Invalid filter syntax. Use ":" for arguments: | filter:arg`,
                source: 'disyl'
            });
            problems++;
        }
    }

    // Validate for loop attributes
    const forPattern = /\{for\s+([^}]+)\}/g;
    while ((match = forPattern.exec(text)) !== null && problems < settings.maxNumberOfProblems) {
        const attrs = match[1];
        if (!attrs.includes('items=')) {
            const pos = textDocument.positionAt(match.index);
            diagnostics.push({
                severity: DiagnosticSeverity.Error,
                range: {
                    start: pos,
                    end: textDocument.positionAt(match.index + match[0].length)
                },
                message: `For loop requires "items" attribute`,
                source: 'disyl'
            });
            problems++;
        }
        if (!attrs.includes('as=')) {
            const pos = textDocument.positionAt(match.index);
            diagnostics.push({
                severity: DiagnosticSeverity.Error,
                range: {
                    start: pos,
                    end: textDocument.positionAt(match.index + match[0].length)
                },
                message: `For loop requires "as" attribute`,
                source: 'disyl'
            });
            problems++;
        }
    }

    // Validate if condition
    const ifPattern = /\{if\s+([^}]+)\}/g;
    while ((match = ifPattern.exec(text)) !== null && problems < settings.maxNumberOfProblems) {
        const attrs = match[1];
        if (!attrs.includes('condition=')) {
            const pos = textDocument.positionAt(match.index);
            diagnostics.push({
                severity: DiagnosticSeverity.Error,
                range: {
                    start: pos,
                    end: textDocument.positionAt(match.index + match[0].length)
                },
                message: `If statement requires "condition" attribute`,
                source: 'disyl'
            });
            problems++;
        }
    }

    connection.sendDiagnostics({ uri: textDocument.uri, diagnostics });
}

// Completion
connection.onCompletion(
    (params: TextDocumentPositionParams): CompletionItem[] => {
        const document = documents.get(params.textDocument.uri);
        if (!document) {
            return [];
        }

        const text = document.getText();
        const offset = document.offsetAt(params.position);
        const linePrefix = text.substring(text.lastIndexOf('\n', offset - 1) + 1, offset);

        // After pipe - suggest filters
        if (linePrefix.match(/\|\s*$/)) {
            return getFilterCompletions();
        }

        // After opening brace - suggest components or control structures
        if (linePrefix.match(/\{\s*$/)) {
            return [
                ...getComponentCompletions(),
                ...getControlStructureCompletions()
            ];
        }

        // After colon in filter - suggest named arguments
        if (linePrefix.match(/\|\s*[a-z_][a-z0-9_]*:\s*$/)) {
            return getFilterArgumentCompletions(linePrefix);
        }

        // Inside attribute value - suggest expressions
        if (linePrefix.match(/=\s*"\{?$/)) {
            return getExpressionCompletions();
        }

        return [];
    }
);

connection.onCompletionResolve(
    (item: CompletionItem): CompletionItem => {
        if (item.data === 'component') {
            item.detail = 'DiSyL Component';
        } else if (item.data === 'filter') {
            item.detail = 'DiSyL Filter';
        }
        return item;
    }
);

function getComponentCompletions(): CompletionItem[] {
    const components = [
        { name: 'ikb_section', desc: 'Container section for organizing content', attrs: 'type, padding, bg' },
        { name: 'ikb_container', desc: 'Responsive container', attrs: 'size' },
        { name: 'ikb_text', desc: 'Text component with styling', attrs: 'size, weight, class' },
        { name: 'ikb_button', desc: 'Interactive button component', attrs: 'variant, size, disabled' },
        { name: 'ikb_card', desc: 'Card component with shadow and padding', attrs: 'shadow, padding' },
        { name: 'ikb_image', desc: 'Image component with lazy loading', attrs: 'src, alt, lazy, width, height' },
        { name: 'ikb_grid', desc: 'Responsive grid layout', attrs: 'columns, gap' },
        { name: 'ikb_query', desc: 'Query and loop through data', attrs: 'type, limit, category' },
        { name: 'ikb_platform', desc: 'Platform declaration', attrs: 'type, targets, version' },
        { name: 'ikb_cms', desc: 'CMS declaration', attrs: 'type, set' }
    ];

    return components.map(comp => ({
        label: comp.name,
        kind: CompletionItemKind.Class,
        data: 'component',
        insertText: `${comp.name} $1}\n\t$0\n{/${comp.name}}`,
        insertTextFormat: 2,
        documentation: {
            kind: MarkupKind.Markdown,
            value: `**${comp.name}**\n\n${comp.desc}\n\n**Attributes:** ${comp.attrs}`
        }
    }));
}

function getFilterCompletions(): CompletionItem[] {
    const filters = [
        { name: 'esc_html', desc: 'Escape HTML entities', params: '' },
        { name: 'esc_url', desc: 'Escape URL', params: '' },
        { name: 'esc_attr', desc: 'Escape HTML attribute', params: '' },
        { name: 'strip_tags', desc: 'Remove HTML tags', params: 'allowed?' },
        { name: 'truncate', desc: 'Truncate text to specified length', params: 'length, append?' },
        { name: 'upper', desc: 'Convert to uppercase', params: '' },
        { name: 'lower', desc: 'Convert to lowercase', params: '' },
        { name: 'capitalize', desc: 'Capitalize first letter', params: '' },
        { name: 'trim', desc: 'Trim whitespace', params: '' },
        { name: 'date', desc: 'Format date', params: 'format' },
        { name: 'number_format', desc: 'Format number', params: 'decimals?, dec_point?, thousands_sep?' },
        { name: 'default', desc: 'Default value if empty', params: 'value' },
        { name: 'json', desc: 'JSON encode', params: '' },
        { name: 'raw', desc: 'Output raw HTML (unescaped)', params: '' }
    ];

    return filters.map(filter => ({
        label: filter.name,
        kind: CompletionItemKind.Function,
        data: 'filter',
        insertText: filter.params ? `${filter.name}:$1` : filter.name,
        insertTextFormat: 2,
        documentation: {
            kind: MarkupKind.Markdown,
            value: `**${filter.name}**\n\n${filter.desc}${filter.params ? `\n\n**Parameters:** ${filter.params}` : ''}`
        }
    }));
}

function getControlStructureCompletions(): CompletionItem[] {
    return [
        {
            label: 'if',
            kind: CompletionItemKind.Keyword,
            insertText: 'if condition="{$1}"}\n\t$0\n{/if}',
            insertTextFormat: 2,
            documentation: {
                kind: MarkupKind.Markdown,
                value: '**if** - Conditional statement\n\n```disyl\n{if condition="{expression}"}\n    content\n{/if}\n```'
            }
        },
        {
            label: 'for',
            kind: CompletionItemKind.Keyword,
            insertText: 'for items="{$1}" as="$2"}\n\t$0\n{/for}',
            insertTextFormat: 2,
            documentation: {
                kind: MarkupKind.Markdown,
                value: '**for** - Loop through items\n\n```disyl\n{for items="{collection}" as="item"}\n    {item.property}\n{/for}\n```'
            }
        },
        {
            label: 'switch',
            kind: CompletionItemKind.Keyword,
            insertText: 'switch value="{$1}"}\n\t{case match="$2"}\n\t\t$0\n\t{default}\n\t\t\n{/switch}',
            insertTextFormat: 2,
            documentation: {
                kind: MarkupKind.Markdown,
                value: '**switch** - Switch statement\n\n```disyl\n{switch value="{status}"}\n    {case match="active"}\n        Active\n    {default}\n        Unknown\n{/switch}\n```'
            }
        },
        {
            label: 'include',
            kind: CompletionItemKind.Keyword,
            insertText: 'include file="$1" /}',
            insertTextFormat: 2,
            documentation: {
                kind: MarkupKind.Markdown,
                value: '**include** - Include another DiSyL file\n\n```disyl\n{include file="components/header.disyl" /}\n```'
            }
        }
    ];
}

function getFilterArgumentCompletions(linePrefix: string): CompletionItem[] {
    // Extract filter name
    const filterMatch = linePrefix.match(/\|\s*([a-z_][a-z0-9_]*):/);
    if (!filterMatch) return [];

    const filterName = filterMatch[1];
    const filterArgs: Record<string, CompletionItem[]> = {
        'truncate': [
            { label: 'length', kind: CompletionItemKind.Property, insertText: 'length=$1', insertTextFormat: 2 },
            { label: 'append', kind: CompletionItemKind.Property, insertText: 'append="$1"', insertTextFormat: 2 }
        ],
        'date': [
            { label: 'format', kind: CompletionItemKind.Property, insertText: 'format="$1"', insertTextFormat: 2 }
        ],
        'number_format': [
            { label: 'decimals', kind: CompletionItemKind.Property, insertText: 'decimals=$1', insertTextFormat: 2 },
            { label: 'dec_point', kind: CompletionItemKind.Property, insertText: 'dec_point="$1"', insertTextFormat: 2 },
            { label: 'thousands_sep', kind: CompletionItemKind.Property, insertText: 'thousands_sep="$1"', insertTextFormat: 2 }
        ],
        'default': [
            { label: 'value', kind: CompletionItemKind.Property, insertText: '"$1"', insertTextFormat: 2 }
        ],
        'strip_tags': [
            { label: 'allowed', kind: CompletionItemKind.Property, insertText: 'allowed="$1"', insertTextFormat: 2 }
        ]
    };

    return filterArgs[filterName] || [];
}

function getExpressionCompletions(): CompletionItem[] {
    return [
        { label: 'item', kind: CompletionItemKind.Variable, insertText: 'item.$1', insertTextFormat: 2 },
        { label: 'post', kind: CompletionItemKind.Variable, insertText: 'post.$1', insertTextFormat: 2 },
        { label: 'user', kind: CompletionItemKind.Variable, insertText: 'user.$1', insertTextFormat: 2 },
        { label: 'site', kind: CompletionItemKind.Variable, insertText: 'site.$1', insertTextFormat: 2 }
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
        const surrounding = text.substring(Math.max(0, offset - 50), Math.min(text.length, offset + 50));
        
        // Check for component
        const componentMatch = surrounding.match(/\{(ikb_[a-z_][a-z0-9_]*)/);
        if (componentMatch) {
            return {
                contents: {
                    kind: MarkupKind.Markdown,
                    value: getComponentDocumentation(componentMatch[1])
                }
            };
        }

        // Check for filter
        const filterMatch = surrounding.match(/\|\s*([a-z_][a-z0-9_]*)/);
        if (filterMatch) {
            return {
                contents: {
                    kind: MarkupKind.Markdown,
                    value: getFilterDocumentation(filterMatch[1])
                }
            };
        }

        // Check for control structure
        const controlMatch = surrounding.match(/\{(if|for|switch|include)\b/);
        if (controlMatch) {
            return {
                contents: {
                    kind: MarkupKind.Markdown,
                    value: getControlDocumentation(controlMatch[1])
                }
            };
        }

        return null;
    }
);

// Signature Help
connection.onSignatureHelp(
    (params: TextDocumentPositionParams): SignatureHelp | null => {
        const document = documents.get(params.textDocument.uri);
        if (!document) {
            return null;
        }

        const text = document.getText();
        const offset = document.offsetAt(params.position);
        const linePrefix = text.substring(text.lastIndexOf('\n', offset - 1) + 1, offset);

        // Check for filter with arguments
        const filterMatch = linePrefix.match(/\|\s*([a-z_][a-z0-9_]*):/);
        if (filterMatch) {
            const filterName = filterMatch[1];
            const signature = getFilterSignature(filterName);
            if (signature) {
                return {
                    signatures: [signature],
                    activeSignature: 0,
                    activeParameter: 0
                };
            }
        }

        return null;
    }
);

function getFilterSignature(filterName: string): SignatureInformation | null {
    const signatures: Record<string, SignatureInformation> = {
        'truncate': {
            label: 'truncate(length: integer, append?: string = "...")',
            documentation: 'Truncate text to specified length',
            parameters: [
                { label: 'length', documentation: 'Maximum length of the text' },
                { label: 'append', documentation: 'String to append when truncated (default: "...")' }
            ]
        },
        'date': {
            label: 'date(format: string)',
            documentation: 'Format a date value',
            parameters: [
                { label: 'format', documentation: 'Date format string (e.g., "Y-m-d", "F j, Y")' }
            ]
        },
        'number_format': {
            label: 'number_format(decimals?: integer = 0, dec_point?: string = ".", thousands_sep?: string = ",")',
            documentation: 'Format a number with grouped thousands',
            parameters: [
                { label: 'decimals', documentation: 'Number of decimal places' },
                { label: 'dec_point', documentation: 'Decimal point character' },
                { label: 'thousands_sep', documentation: 'Thousands separator character' }
            ]
        },
        'default': {
            label: 'default(value: any)',
            documentation: 'Provide a default value if the input is empty',
            parameters: [
                { label: 'value', documentation: 'Default value to use' }
            ]
        },
        'strip_tags': {
            label: 'strip_tags(allowed?: string)',
            documentation: 'Remove HTML tags from text',
            parameters: [
                { label: 'allowed', documentation: 'Allowed HTML tags (e.g., "<p><br>")' }
            ]
        }
    };

    return signatures[filterName] || null;
}

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
    const indentStr = '    ';

    for (const line of lines) {
        const trimmed = line.trim();
        
        if (!trimmed) {
            formatted.push('');
            continue;
        }

        // Decrease indent for closing tags
        if (trimmed.match(/^\{\/(ikb_[a-z_][a-z0-9_]*|if|for|switch)\}/)) {
            indentLevel = Math.max(0, indentLevel - 1);
        }

        // Decrease indent for else/elseif/case/default (same level as if/switch)
        if (trimmed.match(/^\{(else|elseif|case|default)\b/)) {
            indentLevel = Math.max(0, indentLevel - 1);
        }

        // Add formatted line
        formatted.push(indentStr.repeat(indentLevel) + trimmed);

        // Increase indent for opening tags (not self-closing)
        if (trimmed.match(/^\{(ikb_[a-z_][a-z0-9_]*|if|for|switch)\s/) && !trimmed.includes('/}')) {
            indentLevel++;
        }

        // Increase indent after else/elseif/case/default
        if (trimmed.match(/^\{(else|elseif)\}/) || trimmed.match(/^\{(case|default)\b.*\}/)) {
            indentLevel++;
        }
    }

    return formatted.join('\n');
}

function getComponentDocumentation(component: string): string {
    const docs: Record<string, string> = {
        'ikb_section': '## ikb_section\n\nContainer section for organizing content.\n\n**Attributes:**\n- `type`: Section type (hero, main, footer)\n- `padding`: Padding size (small, medium, large)\n- `bg`: Background color or gradient',
        'ikb_container': '## ikb_container\n\nResponsive container.\n\n**Attributes:**\n- `size`: Container size (small, medium, large, xlarge)',
        'ikb_text': '## ikb_text\n\nText component with styling.\n\n**Attributes:**\n- `size`: Text size (xs, sm, base, lg, xl, 2xl, 3xl)\n- `weight`: Font weight (normal, semibold, bold)\n- `class`: Additional CSS classes',
        'ikb_button': '## ikb_button\n\nInteractive button component.\n\n**Attributes:**\n- `variant`: Button style (primary, secondary, outline)\n- `size`: Button size (small, medium, large)\n- `disabled`: Disable button (true/false)',
        'ikb_card': '## ikb_card\n\nCard component with shadow and padding.\n\n**Attributes:**\n- `shadow`: Shadow size (none, small, medium, large)\n- `padding`: Card padding (small, medium, large)',
        'ikb_image': '## ikb_image\n\nImage component with lazy loading.\n\n**Attributes:**\n- `src`: Image source URL\n- `alt`: Alt text\n- `lazy`: Enable lazy loading (true/false)\n- `width`: Image width\n- `height`: Image height',
        'ikb_grid': '## ikb_grid\n\nResponsive grid layout.\n\n**Attributes:**\n- `columns`: Number of columns (1-12)\n- `gap`: Gap size (small, medium, large)',
        'ikb_query': '## ikb_query\n\nQuery and loop through data.\n\n**Attributes:**\n- `type`: Content type (post, page, custom)\n- `limit`: Number of items\n- `category`: Filter by category',
        'ikb_platform': '## ikb_platform\n\nPlatform declaration.\n\n**Attributes:**\n- `type`: Platform type (web, mobile, desktop, universal)\n- `targets`: Target platforms (comma-separated)\n- `version`: DiSyL version\n- `features`: Enabled features',
        'ikb_cms': '## ikb_cms\n\nCMS declaration.\n\n**Attributes:**\n- `type`: CMS type (wordpress, joomla, drupal, ikabud, generic)\n- `set`: Feature set (components, filters, hooks, functions, all)'
    };

    return docs[component] || `## ${component}\n\nDiSyL component`;
}

function getFilterDocumentation(filter: string): string {
    const docs: Record<string, string> = {
        'esc_html': '## esc_html\n\nEscape HTML entities for safe output.\n\n**Usage:** `{variable | esc_html}`\n\n**Returns:** string',
        'esc_url': '## esc_url\n\nEscape and sanitize URL.\n\n**Usage:** `{url | esc_url}`\n\n**Returns:** url',
        'esc_attr': '## esc_attr\n\nEscape HTML attribute value.\n\n**Usage:** `{value | esc_attr}`\n\n**Returns:** string',
        'strip_tags': '## strip_tags\n\nRemove HTML tags from text.\n\n**Usage:** `{content | strip_tags}` or `{content | strip_tags:allowed="<p><br>"}`\n\n**Parameters:**\n- `allowed`: Allowed HTML tags\n\n**Returns:** string',
        'truncate': '## truncate\n\nTruncate text to specified length.\n\n**Usage:** `{text | truncate:50}` or `{text | truncate:length=50,append="..."}`\n\n**Parameters:**\n- `length`: Maximum length (required)\n- `append`: String to append (default: "...")\n\n**Returns:** string',
        'upper': '## upper\n\nConvert text to uppercase.\n\n**Usage:** `{text | upper}`\n\n**Returns:** string',
        'lower': '## lower\n\nConvert text to lowercase.\n\n**Usage:** `{text | lower}`\n\n**Returns:** string',
        'capitalize': '## capitalize\n\nCapitalize first letter.\n\n**Usage:** `{text | capitalize}`\n\n**Returns:** string',
        'trim': '## trim\n\nTrim whitespace from both ends.\n\n**Usage:** `{text | trim}`\n\n**Returns:** string',
        'date': '## date\n\nFormat a date value.\n\n**Usage:** `{date | date:format="Y-m-d"}`\n\n**Parameters:**\n- `format`: Date format string (required)\n\n**Returns:** string',
        'number_format': '## number_format\n\nFormat a number with grouped thousands.\n\n**Usage:** `{number | number_format:2}` or `{number | number_format:decimals=2,dec_point=".",thousands_sep=","}`\n\n**Parameters:**\n- `decimals`: Number of decimal places (default: 0)\n- `dec_point`: Decimal point character (default: ".")\n- `thousands_sep`: Thousands separator (default: ",")\n\n**Returns:** string',
        'default': '## default\n\nProvide a default value if empty.\n\n**Usage:** `{value | default:"N/A"}`\n\n**Parameters:**\n- `value`: Default value (required)\n\n**Returns:** any',
        'json': '## json\n\nJSON encode a value.\n\n**Usage:** `{data | json}`\n\n**Returns:** string',
        'raw': '## raw\n\nOutput raw HTML (unescaped).\n\n⚠️ **Security Warning:** Use with caution! Only use with trusted content.\n\n**Usage:** `{html | raw}`\n\n**Returns:** html'
    };

    return docs[filter] || `## ${filter}\n\nDiSyL filter`;
}

function getControlDocumentation(control: string): string {
    const docs: Record<string, string> = {
        'if': '## if\n\nConditional statement.\n\n**Syntax:**\n```disyl\n{if condition="{expression}"}\n    content\n{elseif condition="{other}"}\n    other content\n{else}\n    fallback\n{/if}\n```\n\n**Attributes:**\n- `condition`: Expression to evaluate (required)',
        'for': '## for\n\nLoop through items.\n\n**Syntax:**\n```disyl\n{for items="{collection}" as="item" key="index"}\n    {item.property}\n{empty}\n    No items found\n{/for}\n```\n\n**Attributes:**\n- `items`: Collection to iterate (required)\n- `as`: Variable name for each item (required)\n- `key`: Variable name for index (optional)',
        'switch': '## switch\n\nSwitch statement.\n\n**Syntax:**\n```disyl\n{switch value="{status}"}\n    {case match="active"}\n        Active\n    {case match="pending"}\n        Pending\n    {default}\n        Unknown\n{/switch}\n```\n\n**Attributes:**\n- `value`: Expression to match (required)',
        'include': '## include\n\nInclude another DiSyL file.\n\n**Syntax:**\n```disyl\n{include file="path/to/template.disyl" /}\n```\n\n**Attributes:**\n- `file`: Path to the file (required)'
    };

    return docs[control] || `## ${control}\n\nDiSyL control structure`;
}

// Make the text document manager listen on the connection
documents.listen(connection);

// Listen on the connection
connection.listen();
