#!/usr/bin/env bash
#
# Lane: sol-disyl-dx-review - ADVERSARIAL REVIEW of the DiSyL authoring-experience work,
# before the rest of it is built.
#
# Read-only. Sol must NOT commit or edit. The deliverable is the report.
#
# WHY NOW: the extension work is half-shipped (scanner + outline + folding + snippets compile),
# but the OWNER POLICY issued 2026-11-11 changes what "close the gap" means:
#   "if policies restrict disyl, improve disyl rather than keep the restrictions"
# That invalidates part of the chair's plan (editor rules that reject unimplemented constructs),
# so the plan needs falsifying before more code is written on top of it.

cd /var/www/html/applicationostest || exit 1
source tools/lane-model.sh

PROMPT="$(cat <<'PROMPT_EOF'
You are an ADVERSARIAL REVIEWER in the Ikabud repo at /var/www/html/applicationostest.
Read-only: do NOT commit, do NOT edit, do NOT fix code. Report only. Never claim a fact you
did not read or run.

# THE OWNER'S GOAL (verbatim intent)
A MID-LEVEL VIBE CODER should readily understand DiSyL syntax and produce templates and entity
views faster, and ideally an AI agent should also be able to author DiSyL ("vibe coding").

# THE OWNER POLICY THAT NOW GOVERNS THIS AREA (issued 2026-11-11, verbatim)
"if policies restrict disyl, improve disyl rather than keep the restrictions"

The chair wrote this into .github/instructions/disyl-grammar-gaps.instructions.md as:
- an unimplemented capability must NOT be turned into a permanent lint/editor rule, because a rule
  saying "DiSyL forbids ?->" makes the restriction true and the gap never gets closed;
- inventory entries carrying lsp:false are ENGINE work items by default, not lint work items;
- exception: constructs the engine ALREADY supports where only tooling is missing;
- the one boundary that stays is arbitrary raw PHP execution (governed escape hatches instead).

# WHAT THE CHAIR JUST SHIPPED (compiles clean, tsc exit 0, not yet installed)
New:
  extensions/disyl-lsp/src/structure.ts   shared block scanner; imports BLOCK_PAIRS from validator
  extensions/disyl-lsp/src/symbols.ts     DocumentSymbolProvider + FoldingRangeProvider
  extensions/disyl-lsp/snippets/disyl.json 17 grounded snippets
Changed:
  extensions/disyl-lsp/src/validator.ts        exported BlockPair/BLOCK_PAIRS
  extensions/disyl-lsp/src/extension.ts        registers both providers; filter completion now also
                                               fires on a partial name (|up -> upper, replaces the
                                               typed prefix); duplicate 'date' filter removed
  extensions/disyl-lsp/language-configuration.json  blockComment was "{!"/"!}" but the engine only
                                               strips {!-- --} (TemplateEngine.php:1845), so Ctrl+/
                                               emitted invalid DiSyL. Now {!-- / --}.
Not yet done: package.json contributes.snippets, README corrections, reinstall, browser verification.

# MEASURED FACTS THE CHAIR OBTAINED (you may re-run these; do not take them on trust)
- The PHP linter tracks LINE ONLY, no columns: issues are ['type'=>'error','line'=>$lineNum] and
  render as "L7 error: ...".
- On a probe containing {items[0]}, {a <=> b}, {user?->name}, {a . b}, {@expr}, {if x instanceof y}
  and {break} outside a loop, `php ikabud disyl:lint` reported ONLY "1 unclosed {if}". Every
  jurisdiction violation passed silently.
- Brace-style components dominate ({ikb_*}: 61 files) vs HTML-style (<ikb_*>: 2).
- Comments: {!-- --} in ~100 files; {! !} in zero.
- Closers confirmed in templates: {/if}, {/foreach}, {/match}, {/when}, {/cache}, {/await}, {/parallel}.
  README claims {endif}; real templates use {/if} 171 times.

# YOUR JOB: FALSIFY THE CHAIR'S PLAN. DO NOT BLESS IT.

Q1. Attack the scanner. structure.ts is a regex scan, not a lexer, and it keeps a stack.
    Where does it produce a WRONG outline or WRONG fold for real templates? Consider: two tags on
    one line; an opener inside a string or inside an HTML attribute; {verbatim}/{literal} bodies
    (the chair suppresses inner matches - is that sufficient?); a mismatched closer unwinding frames;
    {{ }} escapes; unclosed blocks; 500-line templates with 171 {if}s. Give concrete failing input.

Q2. Split the 10 inventory entries with lsp:false into ENGINE work vs EDITOR work under the policy.
    Entries: break-stmt, comment-strip, continue-stmt, keyof, prohibited-arbitrary-php,
    prohibited-error-suppression, prohibited-instanceof, prohibited-nullsafe-property,
    prohibited-php-concat-dot, prohibited-spaceship.
    The chair believes break/continue/comment-strip are already engine-supported (only tooling
    missing) and therefore correctly EDITOR work. Verify that claim against the engine.
    For the 6 prohibited ones: which are capability gaps to IMPLEMENT, and which are the sandbox
    boundary to KEEP? Justify each. Be specific about whether DiSyL should really accept
    '.' concatenation given dot is already property access - that one may be impossible, not
    merely unimplemented.

Q3. For the capability gaps (?-> nullsafe, <=> spaceship, instanceof, {arr[0]} bracket access,
    ... spread, string interpolation), give an implementation order from safest to most
    grammar-changing. For each: the exact files to touch, and whether interpreted/compiled parity
    is achievable without changing existing template output. Name any that would break the
    byte-identical render guarantee.

Q4. Highest-throughput single change. The chair believes it is entity-source and entity-view
    completion ({ikb_entity_list source="..." view="..."}), because source/view names are invisible
    today and "list X" is the highest-volume request. Argue for or against, and say what the
    runner-up is. If you disagree, say what actually moves authoring speed most.

Q5. Is `php ikabud disyl:lint --json` (structured diagnostics with stable codes and spans) still the
    right first kernel change under the new policy, given the linter currently accepts prohibited
    constructs silently? Or does the policy mean the engine should gain the constructs FIRST so
    there is nothing to report? Answer with the order and the reasoning, not both-sides hedging.

Q6. What in the chair's current plan should be DELETED as premature? Be blunt. Include anything
    among the shipped snippets or providers that a mid-level vibe coder will never use.

# METHOD
- Read: extensions/disyl-lsp/src/{structure,symbols,extension,validator}.ts,
  extensions/disyl-lsp/{package.json,language-configuration.json,snippets/disyl.json},
  config/disyl-feature-inventory.json, .github/instructions/disyl-grammar-gaps.instructions.md,
  kernel/DiSyL/Grammar/Planned.php.
- Verify engine support claims by reading kernel/DiSyL/{Parser.php,TemplateEngine.php,
  Compiler/TemplateCompiler.php}. Do not infer support from documentation alone.
- You may run read-only commands (grep, php ikabud disyl:lint on a temp file, tsc). Show output.

# REPORT - exactly this shape
verdict: CONFIRMED | PARTIALLY_CONFIRMED | FALSIFIED  (of the chair's plan)
q1_scanner_failures: concrete failing inputs + what breaks
q2_lsp_false_split: each of the 10 -> ENGINE | EDITOR | KEEP-BOUNDARY, with justification
q3_implementation_order: ordered, with files and parity risk per item
q4_highest_throughput: your pick, with the runner-up
q5_json_vs_engine_first: the order you would actually use, and why
q6_delete_as_premature: exact items to cut
acceptance_checks: falsifiable one-liners that FAIL today and PASS when the goal is met
weakest_claim: the part of your own report you would least defend
unreached: what you could not determine
PROMPT_EOF
)"

lane_model_run "$LANE_MODEL_CHAIN" "$PROMPT" /tmp/lane-sol-disyl-dx-review
