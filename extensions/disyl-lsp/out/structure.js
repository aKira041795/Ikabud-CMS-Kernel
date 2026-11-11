"use strict";
/**
 * DiSyL structural scanner.
 *
 * One scanner feeds both the outline (document symbols) and folding ranges, so
 * the two views are derived from a single parse and cannot disagree. The block
 * definitions themselves are imported from `validator.ts`, which stays the
 * single source of block-pair truth.
 *
 * This is a lexer-free scan: it recognises confirmed opening/closing tags by
 * literal text. It deliberately does not validate anything — `validator.ts`
 * owns diagnostics, and the PHP analyzer owns semantic validation.
 */
Object.defineProperty(exports, "__esModule", { value: true });
exports.scanBlocks = scanBlocks;
const validator_1 = require("./validator");
const PAIRED_BLOCKS = validator_1.BLOCK_PAIRS.filter((pair) => pair.close !== null);
/** Replace a matched region with spaces, preserving newlines and therefore offsets. */
function blankOut(match) {
    return match.replace(/[^\n]/g, ' ');
}
/**
 * Blank every region whose contents are not template structure.
 *
 * Without this, a raw regex scan treats documentation text as structure: real
 * templates carry `{if}` examples inside HTML comments, and escaped `{{if x}}`
 * is not a tag at all.
 *
 * `{verbatim}`/`{literal}` regions are removed whole (tags included) only when
 * properly closed. An unclosed `{verbatim}` is deliberately NOT treated as opaque,
 * because the interpreted engine also keeps processing tags inside it - its
 * extraction requires a matching closing tag.
 */
function maskInertRegions(text) {
    return text
        .replace(/<!--[\s\S]*?-->/g, blankOut)
        .replace(/\{!--[\s\S]*?--\}/g, blankOut)
        .replace(/<script\b[\s\S]*?<\/script\s*>/gi, blankOut)
        .replace(/<style\b[\s\S]*?<\/style\s*>/gi, blankOut)
        .replace(/\{(verbatim|literal)\}[\s\S]*?\{\/\1\}/g, blankOut)
        .replace(/\{\{[\s\S]*?\}\}/g, blankOut);
}
function escapeRegExp(value) {
    return value.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
}
const STRUCTURE_RE = new RegExp(`(${PAIRED_BLOCKS.map((pair) => escapeRegExp(pair.open)).join('|')})` +
    `|(${PAIRED_BLOCKS.map((pair) => escapeRegExp(pair.close)).join('|')})`, 'g');
function shorten(value) {
    const collapsed = value.replace(/\s+/g, ' ').trim();
    return collapsed.length > 72 ? collapsed.slice(0, 71) + '\u2026' : collapsed;
}
/**
 * Scan template text and return the nested block structure.
 *
 * Unclosed blocks still produce a span so the outline shows where they begin;
 * `endLine` simply stays on the opening line. Reporting that they are unclosed
 * is the validator's job, not this scanner's.
 */
function scanBlocks(text) {
    const masked = maskInertRegions(text);
    // Labels are read from the ORIGINAL lines; masking preserves offsets exactly.
    const originalLines = text.split('\n');
    const lines = masked.split('\n');
    const roots = [];
    const stack = [];
    for (let lineNo = 0; lineNo < lines.length; lineNo++) {
        const line = lines[lineNo];
        const original = originalLines[lineNo] ?? '';
        STRUCTURE_RE.lastIndex = 0;
        let match;
        while ((match = STRUCTURE_RE.exec(line)) !== null) {
            const token = match[0];
            const opener = PAIRED_BLOCKS.find((pair) => pair.open === token);
            if (opener) {
                const braceEnd = original.indexOf('}', match.index);
                const labelEnd = braceEnd >= 0
                    ? braceEnd + 1
                    : Math.min(original.length, match.index + token.length);
                const span = {
                    kind: opener.name,
                    label: shorten(original.slice(match.index, labelEnd)),
                    startLine: lineNo,
                    startCol: match.index,
                    labelEnd,
                    endLine: lineNo,
                    endCol: labelEnd,
                    children: [],
                };
                const parent = stack.length > 0 ? stack[stack.length - 1] : null;
                (parent ? parent.children : roots).push(span);
                stack.push(span);
                continue;
            }
            const closer = PAIRED_BLOCKS.find((pair) => pair.close === token);
            if (!closer)
                continue;
            // Unwind to the matching frame. Frames above it are unclosed; they
            // keep their opening position so the outline still shows them.
            let target = -1;
            for (let i = stack.length - 1; i >= 0; i--) {
                if (stack[i].kind === closer.name) {
                    target = i;
                    break;
                }
            }
            if (target >= 0) {
                stack[target].endLine = lineNo;
                stack[target].endCol = match.index + token.length;
                stack.length = target;
            }
        }
    }
    roots.forEach(extendToContainChildren);
    return roots;
}
/**
 * A DocumentSymbol's range must contain its children's ranges. An unclosed parent
 * would otherwise end on its opening line while its children extend below it, and
 * the hierarchy becomes invalid. Extend to the last child.
 */
function extendToContainChildren(span) {
    for (const child of span.children) {
        extendToContainChildren(child);
        if (child.endLine > span.endLine ||
            (child.endLine === span.endLine && child.endCol > span.endCol)) {
            span.endLine = child.endLine;
            span.endCol = child.endCol;
        }
    }
}
//# sourceMappingURL=structure.js.map