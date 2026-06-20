/**
 * DiSyL EBNF-based Validator
 * 
 * Lightweight in-process validator for .disyl template files.
 * Implements structural validation based on the canonical EBNF grammar
 * (docs/disyl/disyl-grammar-v4.7.ebnf). No PHP dependency.
 * 
 * Validates:
 *   - Block balancing: {if}↔{/if}, {foreach}↔{/foreach}, {block}↔{/block}, etc.
 *   - Component tag balancing: <ikb_*>↔</ikb_*>
 *   - String quoting: matched '...' and "..."
 *   - Basic expression structure
 * 
 * Does NOT validate:
 *   - Variable existence (requires runtime context)
 *   - Capability availability (requires kernel boot)
 *   - Filter validity against runtime registry (use PHP linter for that)
 */

import * as vscode from 'vscode';

// ── Block pair definitions ───────────────────────────────────────────────

interface BlockPair {
    open: string;      // Opening pattern (used as regex substring)
    close: string;     // Closing pattern
    name: string;      // Display name for errors
}

const BLOCK_PAIRS: BlockPair[] = [
    { open: '{if ', close: '{/if}', name: 'if' },
    { open: '{elseif ', close: null as any, name: 'elseif' },  // matches with if
    { open: '{else}', close: null as any, name: 'else' },       // matches with if
    { open: '{foreach ', close: '{/foreach}', name: 'foreach' },
    { open: '{foreachelse}', close: null as any, name: 'foreachelse' },
    { open: '{for ', close: '{/for}', name: 'for' },
    { open: '{while ', close: '{/while}', name: 'while' },
    { open: '{block ', close: '{/block}', name: 'block' },
    { open: '{verbatim}', close: '{/verbatim}', name: 'verbatim' },
    { open: '{literal}', close: '{/literal}', name: 'literal' },
    { open: '{macro ', close: '{/macro}', name: 'macro' },
    { open: '{component ', close: '{/component}', name: 'component' },
    // 4.x extensions
    { open: '{match ', close: '{/match}', name: 'match' },
    { open: '{cache ', close: '{/cache}', name: 'cache' },
    { open: '{sandbox ', close: '{/sandbox}', name: 'sandbox' },
    { open: '{trusted}', close: '{/trusted}', name: 'trusted' },
    { open: '{untrusted}', close: '{/untrusted}', name: 'untrusted' },
    { open: '{parallel ', close: '{/parallel}', name: 'parallel' },
    { open: '{await ', close: '{/await}', name: 'await' },
    { open: '{suspense ', close: '{/suspense}', name: 'suspense' },
];

// ── Component patterns ────────────────────────────────────────────────────

// All governed ikb_* components from the EBNF + ComponentRegistry
const GOV_COMPONENTS = [
    'ikb_section', 'ikb_container', 'ikb_grid', 'ikb_panel',
    'ikb_block', 'ikb_entity_list', 'ikb_entity_detail', 'ikb_stat_card',
    'ikb_timeline', 'ikb_audit_log', 'ikb_table', 'ikb_badge',
    'ikb_form', 'ikb_input', 'ikb_textarea', 'ikb_select', 'ikb_button',
    'ikb_export_button', 'ikb_confirm_action', 'ikb_card', 'ikb_modal',
    'ikb_drawer', 'ikb_alert', 'ikb_spinner', 'ikb_text', 'ikb_image',
    'ikb_icon', 'ikb_link', 'ikb_report', 'ikb_signature_block',
    'ikb_ai_summary', 'ikb_ai_assist', 'ikb_query',
];

const COMP_OPEN = /<(ikb_[a-zA-Z_]+)(\s[^>]*)?\/?>/g;
const COMP_CLOSE = /<\/(ikb_[a-zA-Z_]+)\s*>/g;

// ── Diagnostic helpers ────────────────────────────────────────────────────

interface DiagEntry {
    line: number;       // 0-based
    col: number;        // 0-based
    endCol: number;
    message: string;
    severity: vscode.DiagnosticSeverity;
}

function diag(
    line: number, col: number, endCol: number,
    message: string, severity: vscode.DiagnosticSeverity = vscode.DiagnosticSeverity.Error
): DiagEntry {
    return { line, col, endCol, message, severity };
}

// ── Main validator ────────────────────────────────────────────────────────

export function validateDisylDocument(document: vscode.TextDocument): vscode.Diagnostic[] {
    const text = document.getText();
    const lines = text.split('\n');
    const diagnostics: DiagEntry[] = [];

    checkBlockBalance(text, lines, diagnostics);
    checkComponentBalance(text, lines, diagnostics);
    checkStringQuoting(text, lines, diagnostics);
    checkMalformedExpressions(text, lines, diagnostics);

    return diagnostics.map(d => {
        const range = new vscode.Range(d.line, d.col, d.line, d.endCol);
        return new vscode.Diagnostic(range, d.message, d.severity);
    });
}

// ── Block balance check ───────────────────────────────────────────────────

function checkBlockBalance(text: string, lines: string[], diagnostics: DiagEntry[]): void {
    interface StackEntry {
        pair: BlockPair;
        line: number;
        col: number;
    }
    const stack: StackEntry[] = [];

    // Build a combined regex that matches any block opener or closer
    const allOpens = BLOCK_PAIRS.filter(p => p.close !== null).map(p => p.open);
    const allCloses = BLOCK_PAIRS.filter(p => p.close !== null).map(p => p.close.replace('/', '\\/'));
    const openRe = new RegExp(allOpens.join('|'), 'g');
    const closeRe = new RegExp(allCloses.join('|'), 'g');

    // Find all openers
    const openMatches: { pair: BlockPair; line: number; col: number }[] = [];
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        let match: RegExpExecArray | null;
        openRe.lastIndex = 0;
        while ((match = openRe.exec(line)) !== null) {
            const found = BLOCK_PAIRS.find(p => p.close !== null && match![0].startsWith(p.open));
            if (found) {
                openMatches.push({ pair: found, line: i, col: match.index });
            }
        }

        closeRe.lastIndex = 0;
        while ((match = closeRe.exec(line)) !== null) {
            const found = BLOCK_PAIRS.find(p => p.close !== null && match![0] === p.close);
            if (!found) continue;

            // Find matching opener
            let matched = false;
            for (let j = openMatches.length - 1; j >= 0; j--) {
                if (openMatches[j].pair === found) {
                    openMatches.splice(j, 1);
                    matched = true;
                    break;
                }
                // Skip non-matching intermediates (elseif/else match with if)
                if (found.name === 'if' && (openMatches[j].pair.name === 'elseif' || openMatches[j].pair.name === 'else')) {
                    openMatches.splice(j, 1);
                    continue;
                }
            }
            if (!matched) {
                diagnostics.push(diag(i, match.index, match.index + match[0].length,
                    `Unexpected ${match[0]} — no matching opener found`, vscode.DiagnosticSeverity.Error));
            }
        }
    }

    // Remaining openers are unclosed
    for (const entry of openMatches) {
        const openStr = entry.pair.open.endsWith(' ') ? entry.pair.open.trimEnd() : entry.pair.open;
        diagnostics.push(diag(entry.line, entry.col, entry.col + openStr.length,
            `Unclosed {${entry.pair.name}} block (missing ${entry.pair.close})`, vscode.DiagnosticSeverity.Error));
    }
}

// ── Component balance check ───────────────────────────────────────────────

function checkComponentBalance(text: string, lines: string[], diagnostics: DiagEntry[]): void {
    interface CompEntry { name: string; line: number; col: number; selfClosing: boolean; }
    const stack: CompEntry[] = [];

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        // Find opens (including self-closing)
        let match: RegExpExecArray | null;
        COMP_OPEN.lastIndex = 0;
        while ((match = COMP_OPEN.exec(line)) !== null) {
            const name = match[1];
            const full = match[0];
            const selfClosing = full.endsWith('/>');
            stack.push({ name, line: i, col: match.index, selfClosing });
        }

        // Find closes
        COMP_CLOSE.lastIndex = 0;
        while ((match = COMP_CLOSE.exec(line)) !== null) {
            const name = match[1];
            // Search backwards for matching opener (skipping self-closing)
            let matched = false;
            for (let j = stack.length - 1; j >= 0; j--) {
                if (stack[j].selfClosing) {
                    stack.splice(j, 1);  // Remove self-closing, they don't pair
                    continue;
                }
                if (stack[j].name === name) {
                    stack.splice(j, 1);
                    matched = true;
                    break;
                }
                // Mismatched — intervening unclosed component
                diagnostics.push(diag(stack[j].line, stack[j].col, stack[j].col + stack[j].name.length + 2,
                    `Unclosed <${stack[j].name}> (missing </${stack[j].name}>)`, vscode.DiagnosticSeverity.Error));
                stack.splice(j, 1);
            }
            if (!matched) {
                diagnostics.push(diag(i, match.index, match.index + match[0].length,
                    `Unexpected </${name}> — no matching opener`, vscode.DiagnosticSeverity.Error));
            }
        }
    }

    // Remaining unclosed components
    for (const entry of stack) {
        if (entry.selfClosing) continue;
        diagnostics.push(diag(entry.line, entry.col, entry.col + entry.name.length + 2,
            `Unclosed <${entry.name}> (missing </${entry.name}>)`, vscode.DiagnosticSeverity.Error));
    }
}

// ── String quoting check ──────────────────────────────────────────────────

function checkStringQuoting(text: string, lines: string[], diagnostics: DiagEntry[]): void {
    // Simple check: count single and double quotes within DiSyL expressions
    // Skip lines inside {verbatim} / {literal} blocks
    let inVerbatim = false;
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        if (/\{verbatim\}/.test(line)) inVerbatim = true;
        if (/\{\/verbatim\}/.test(line)) { inVerbatim = false; continue; }
        if (/\{literal\}/.test(line)) inVerbatim = true;
        if (/\{\/literal\}/.test(line)) { inVerbatim = false; continue; }
        if (inVerbatim) continue;

        // Check for unbalanced quotes in expressions
        const exprMatch = line.match(/\{([^}]*)\}/g);
        if (!exprMatch) continue;
        for (const expr of exprMatch) {
            const inner = expr.slice(1, -1); // strip { }
            const singles = (inner.match(/'/g) || []).length;
            const doubles = (inner.match(/"/g) || []).length;
            if (singles % 2 !== 0) {
                const col = line.indexOf(expr);
                diagnostics.push(diag(i, col, col + expr.length,
                    `Unbalanced single quotes in expression: ${expr}`, vscode.DiagnosticSeverity.Warning));
            }
            if (doubles % 2 !== 0) {
                const col = line.indexOf(expr);
                diagnostics.push(diag(i, col, col + expr.length,
                    `Unbalanced double quotes in expression: ${expr}`, vscode.DiagnosticSeverity.Warning));
            }
        }
    }
}

// ── Malformed expression check ────────────────────────────────────────────

function checkMalformedExpressions(text: string, lines: string[], diagnostics: DiagEntry[]): void {
    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];

        // Empty expression {}
        let match: RegExpExecArray | null;
        const emptyRe = /\{\s*\}/g;
        while ((match = emptyRe.exec(line)) !== null) {
            diagnostics.push(diag(i, match.index, match.index + match[0].length,
                'Empty expression {} — remove or add content', vscode.DiagnosticSeverity.Warning));
        }

        // Missing space after {if, {foreach, {for, {while
        const keywordRe = /\{(if|elseif|foreach|for|while|set|block|extends|include)([^\s}])/g;
        while ((match = keywordRe.exec(line)) !== null) {
            if (match[2] !== ' ' && match[2] !== '}') {
                diagnostics.push(diag(i, match.index, match.index + match[0].length,
                    `Missing space after {${match[1]} — should be '{${match[1]} ...'`, vscode.DiagnosticSeverity.Warning));
            }
        }
    }
}
