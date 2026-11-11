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

import { BLOCK_PAIRS, BlockPair } from './validator';

export interface BlockSpan {
    /** Block name, e.g. `if`, `foreach`, `block`. */
    kind: string;
    /** Source text of the opening tag, collapsed to one line and capped. */
    label: string;
    startLine: number;
    startCol: number;
    /** Column just past the opening tag; used as the outline selection range. */
    labelEnd: number;
    /** Line of the closing tag, or the opening line when the block is unclosed. */
    endLine: number;
    endCol: number;
    children: BlockSpan[];
}

const PAIRED_BLOCKS: BlockPair[] = BLOCK_PAIRS.filter((pair) => pair.close !== null);

/** Replace a matched region with spaces, preserving newlines and therefore offsets. */
function blankOut(match: string): string {
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
function maskInertRegions(text: string): string {
    return text
        .replace(/<!--[\s\S]*?-->/g, blankOut)
        .replace(/\{!--[\s\S]*?--\}/g, blankOut)
        .replace(/<script\b[\s\S]*?<\/script\s*>/gi, blankOut)
        .replace(/<style\b[\s\S]*?<\/style\s*>/gi, blankOut)
        .replace(/\{(verbatim|literal)\}[\s\S]*?\{\/\1\}/g, blankOut)
        .replace(/\{\{[\s\S]*?\}\}/g, blankOut);
}

function escapeRegExp(value: string): string {
    return value.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
}

const STRUCTURE_RE = new RegExp(
    `(${PAIRED_BLOCKS.map((pair) => escapeRegExp(pair.open)).join('|')})` +
    `|(${PAIRED_BLOCKS.map((pair) => escapeRegExp(pair.close)).join('|')})`,
    'g'
);

function shorten(value: string): string {
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
export function scanBlocks(text: string): BlockSpan[] {
    const masked = maskInertRegions(text);
    // Labels are read from the ORIGINAL lines; masking preserves offsets exactly.
    const originalLines = text.split('\n');
    const lines = masked.split('\n');
    const roots: BlockSpan[] = [];
    const stack: BlockSpan[] = [];

    for (let lineNo = 0; lineNo < lines.length; lineNo++) {
        const line = lines[lineNo];
        const original = originalLines[lineNo] ?? '';
        STRUCTURE_RE.lastIndex = 0;
        let match: RegExpExecArray | null;

        while ((match = STRUCTURE_RE.exec(line)) !== null) {
            const token = match[0];

            const opener = PAIRED_BLOCKS.find((pair) => pair.open === token);
            if (opener) {
                const braceEnd = original.indexOf('}', match.index);
                const labelEnd = braceEnd >= 0
                    ? braceEnd + 1
                    : Math.min(original.length, match.index + token.length);
                const span: BlockSpan = {
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
            if (!closer) continue;

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
function extendToContainChildren(span: BlockSpan): void {
    for (const child of span.children) {
        extendToContainChildren(child);
        if (child.endLine > span.endLine ||
            (child.endLine === span.endLine && child.endCol > span.endCol)) {
            span.endLine = child.endLine;
            span.endCol = child.endCol;
        }
    }
}
