"use strict";
/**
 * DiSyL outline (document symbols) and folding ranges.
 *
 * Both providers read the shared structural scanner in `structure.ts`, so the
 * Outline panel and the fold gutter always describe the same nesting.
 */
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
exports.DisylFoldingRangeProvider = exports.DisylDocumentSymbolProvider = void 0;
const vscode = __importStar(require("vscode"));
const structure_1 = require("./structure");
const SYMBOL_KIND = {
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
function toSymbol(span) {
    const endLine = Math.max(span.startLine, span.endLine);
    const endCol = endLine === span.startLine
        ? Math.max(span.labelEnd, span.endCol)
        : span.endCol;
    const selectionRange = new vscode.Range(span.startLine, span.startCol, span.startLine, span.labelEnd);
    const range = new vscode.Range(span.startLine, span.startCol, endLine, endCol);
    const symbol = new vscode.DocumentSymbol(span.label, span.kind, SYMBOL_KIND[span.kind] ?? vscode.SymbolKind.Object, range, selectionRange);
    symbol.children = span.children.map(toSymbol);
    return symbol;
}
class DisylDocumentSymbolProvider {
    provideDocumentSymbols(document) {
        // Blocks that open and close on the same line have no structural body.
        // Inline conditionals inside HTML attributes are common (thousands in this
        // repository) and would otherwise flood the outline with unopenable entries.
        const isStructural = (span) => span.endLine > span.startLine || span.children.length > 0;
        const convert = (span) => {
            const symbol = toSymbol(span);
            symbol.children = span.children.filter(isStructural).map(convert);
            return symbol;
        };
        return (0, structure_1.scanBlocks)(document.getText()).filter(isStructural).map(convert);
    }
}
exports.DisylDocumentSymbolProvider = DisylDocumentSymbolProvider;
class DisylFoldingRangeProvider {
    provideFoldingRanges(document) {
        const ranges = [];
        const collect = (spans) => {
            for (const span of spans) {
                // End at the line before the closing tag so the delimiter stays visible.
                const lastFoldedLine = span.endLine - 1;
                if (lastFoldedLine > span.startLine) {
                    ranges.push(new vscode.FoldingRange(span.startLine, lastFoldedLine, vscode.FoldingRangeKind.Region));
                }
                collect(span.children);
            }
        };
        collect((0, structure_1.scanBlocks)(document.getText()));
        return ranges;
    }
}
exports.DisylFoldingRangeProvider = DisylFoldingRangeProvider;
//# sourceMappingURL=symbols.js.map