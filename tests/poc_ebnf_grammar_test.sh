#!/bin/bash
# POC Validation — EBNF Grammar Consistency
# Validates the DiSyL EBNF grammar against the TextMate grammar and LSP validator.
# Usage: bash tests/poc_ebnf_grammar_test.sh
set -e
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
PASS=0; FAIL=0

t() { local label="$1" cond="$2" detail="${3:-}"
    if [ "$cond" = "true" ] || [ "$cond" = "0" ]; then
        PASS=$((PASS + 1)); echo "  ✅ $label"
    else
        FAIL=$((FAIL + 1)); echo "  ❌ $label${detail:+ — $detail}"
    fi
}

EBNF="$ROOT/docs/disyl/disyl-grammar-v4.7.ebnf"
MD="$ROOT/docs/disyl/disyl-grammar-v4.7.md"
TM="$ROOT/extensions/disyl-lsp/syntaxes/disyl.tmLanguage.json"
VAL="$ROOT/extensions/disyl-lsp/src/validator.ts"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  POC Validation — EBNF Grammar Consistency                   ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""

# ── E1: File Integrity ──
echo "── E1: EBNF File Integrity ──"
t "EBNF file exists" "$([ -f "$EBNF" ] && echo true)"
t "EBNF file > 4000 chars" "$([ $(wc -c < "$EBNF") -gt 4000 ] && echo true)"
t "Markdown reference exists" "$([ -f "$MD" ] && echo true)"
t "TextMate grammar exists" "$([ -f "$TM" ] && echo true)"
t "LSP validator exists" "$([ -f "$VAL" ] && echo true)"
PRODS=$(grep -cE '^[a-z_]+\s*=' "$EBNF" 2>/dev/null || echo 0)
t "EBNF has 50+ rules (found: $PRODS)" "$([ "$PRODS" -ge 50 ] && echo true)"
echo ""

# ── E2: Key Productions ──
echo "── E2: Key Productions Present ──"
for prod in template expression if_block foreach_block for_block while_block \
    component_tag variable filter control comment block_def extends_stmt \
    include_stmt macro_def macro_call verbatim_block set_stmt ternary arithmetic; do
    t "Production '$prod' defined" "$(grep -qE "^${prod}\s*=" "$EBNF" && echo true)"
done
echo ""

# ── E3: TextMate ↔ EBNF ──
echo "── E3: TextMate ↔ EBNF Cross-Reference ──"
TM_PATTERNS=(comments block-control conditional loops components governed-components disyl-4x-tags includes-extends variables-filters set-statements)
for pat in "${TM_PATTERNS[@]}"; do
    t "TextMate has '$pat' pattern" "$(grep -q "\"$pat\"" "$TM" && echo true)"
done
COMP_COUNT=$(grep -oP 'ikb_[a-z_]+' "$TM" 2>/dev/null | sort -u | wc -l)
t "TextMate has 30+ components (found: $COMP_COUNT)" "$([ "$COMP_COUNT" -ge 30 ] && echo true)"
t "EBNF references ikb_ components" "$(grep -q 'ikb_' "$EBNF" && echo true)"
echo ""

# ── E4: LSP Validator ↔ EBNF ──
echo "── E4: LSP Validator ↔ EBNF Cross-Reference ──"
for block in if foreach for while block macro component match cache sandbox verbatim literal; do
    t "Validator has '$block' block pair" "$(grep -q "'$block'" "$VAL" && echo true)"
done
GOV_COUNT=$(grep -oP "'ikb_[a-z_]+'" "$VAL" 2>/dev/null | sort -u | wc -l)
t "Validator has 30+ components (found: $GOV_COUNT)" "$([ "$GOV_COUNT" -ge 30 ] && echo true)"
t "Validator checks block balance" "$(grep -q 'checkBlockBalance' "$VAL" && echo true)"
t "Validator checks component balance" "$(grep -q 'checkComponentBalance' "$VAL" && echo true)"
t "Validator checks string quoting" "$(grep -q 'checkStringQuoting' "$VAL" && echo true)"
t "Validator checks malformed expressions" "$(grep -q 'checkMalformedExpressions' "$VAL" && echo true)"
echo ""

# ── E5: Grammar Structure ──
echo "── E5: Grammar Structural Check ──"
REAL_RULES=$(grep -cE '^[a-z_]+\s*=' "$EBNF")
# Count semicolons on all non-comment, non-blank EBNF lines
SEMI_COUNT=$(grep -v '^(\*' "$EBNF" | grep -v '^\s*$' | grep -c ';' 2>/dev/null || echo 0)
t "Grammar has ; terminators (rules: $REAL_RULES, semicolons: $SEMI_COUNT)" \
    "$([ "$SEMI_COUNT" -ge "$REAL_RULES" ] && echo true)" \
    "$([ "$SEMI_COUNT" -lt "$REAL_RULES" ] && echo "expected >= $REAL_RULES")"
echo ""

# ── Summary ──
echo "╔══════════════════════════════════════════════════════════════╗"
printf "║  Results:  %2d passed  %2d failed                            ║\n" $PASS $FAIL
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
[ "$FAIL" -gt 0 ] && echo "❌ VALIDATION FAILED — $FAIL assertions failed." && exit 1
echo "✅ ALL $PASS ASSERTIONS PASSED — EBNF grammar is consistent."
exit 0
