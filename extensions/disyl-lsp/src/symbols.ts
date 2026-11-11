/**
 * DiSyL outline (document symbols) and folding ranges.
 *
 * Both providers read the shared structural scanner in `structure.ts`, so the
 * Outline panel and the fold gutter always describe the same nesting.
 */

import * as vscode from 'vscode';
import { BlockSpan, scanBlocks } from './structure';

const SYMBOL_KIND: Record<string, vscode.SymbolKind> = {
    block: vscode.SymbolKind.Module,
    component: vscode.SymbolKind.Function,
    macro: vscode.SymbolKind.Function,
    match: vscode.SymbolKind.Enum,
    foreach: vscode.SymbolKind.Array,
    for: vscode.SymbolKind.Array,
    while: vscode.SymbolKind.Array,
    if: vscode.SymbolKind.Boolean,
    cache: vscode.SymbolKind.Key,
    sandbox: vscode.SymbolKind.Namespace,
    await: vscode.SymbolKind.Event,
    parallel: vscode.SymbolKind.Event,
    suspense: vscode.SymbolKind.Event,
};

function toSymbol(span: BlockSpan): vscode.DocumentSymbol {
    const endLine = Math.max(span.startLine, span.endLine);
    const endCol = endLine === span.startLine
        ? Math.max(span.labelEnd, span.endCol)
        : span.endCol;

    const selectionRange = new vscode.Range(
        span.startLine, span.startCol,
        span.startLine, span.labelEnd
    );
    const range = new vscode.Range(span.startLine, span.startCol, endLine, endCol);

    const symbol = new vscode.DocumentSymbol(
        span.label,
        span.kind,
        SYMBOL_KIND[span.kind] ?? vscode.SymbolKind.Object,
        range,
        selectionRange
    );
    symbol.children = span.children.map(toSymbol);
    return symbol;
}

export class DisylDocumentSymbolProvider implements vscode.DocumentSymbolProvider {
    provideDocumentSymbols(document: vscode.TextDocument): vscode.DocumentSymbol[] {
        // Blocks that open and close on the same line have no structural body.
        // Inline conditionals inside HTML attributes are common (thousands in this
        // repository) and would otherwise flood the outline with unopenable entries.
        const isStructural = (span: BlockSpan): boolean =>
            span.endLine > span.startLine || span.children.length > 0;

        const convert = (span: BlockSpan): vscode.DocumentSymbol => {
            const symbol = toSymbol(span);
            symbol.children = span.children.filter(isStructural).map(convert);
            return symbol;
        };

        return scanBlocks(document.getText()).filter(isStructural).map(convert);
    }
}

export class DisylFoldingRangeProvider implements vscode.FoldingRangeProvider {
    provideFoldingRanges(document: vscode.TextDocument): vscode.FoldingRange[] {
        const ranges: vscode.FoldingRange[] = [];

        const collect = (spans: BlockSpan[]): void => {
            for (const span of spans) {
                // End at the line before the closing tag so the delimiter stays visible.
                const lastFoldedLine = span.endLine - 1;
                if (lastFoldedLine > span.startLine) {
                    ranges.push(new vscode.FoldingRange(
                        span.startLine,
                        lastFoldedLine,
                        vscode.FoldingRangeKind.Region
                    ));
                }
                collect(span.children);
            }
        };

        collect(scanBlocks(document.getText()));
        return ranges;
    }
}
