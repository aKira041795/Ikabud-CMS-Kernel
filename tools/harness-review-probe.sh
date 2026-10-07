#!/usr/bin/env bash
#
# tools/harness-review-probe.sh
#
# COMPLETENESS check for the adversarial harness review. It does NOT check that the review is
# RIGHT - only that it is complete enough to judge, and that the reviewer left the tree as it
# found it.
#
# This distinction is deliberate. A probe that "checks the review found something" would reward
# inventing findings, which is the opposite of what is wanted. Review value is judged by the chair
# reading it; this probe only removes the two ways a review can waste everyone's time:
#   (a) it is too thin to judge, i.e. guards are missing with no mutation and no observed result;
#   (b) it left a mutation in the tree, silently corrupting the thing under review.
#
# Exit 0 = the review is complete enough to read and the tree is clean. Non-zero = incomplete or
# the tree was left mutated.
#
set -u
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 1

ART="${1:-docs/reviews/harness-v2-adversarial-review-2026-10-07.md}"
pass=0; fail=0
ok()  { echo "   PASS  $1"; pass=$((pass+1)); }
bad() { echo "   FAIL  $1"; fail=$((fail+1)); }

# Every guard added by the 2026-10-07 harness work. Each must be named, given a mutation, and
# given the OBSERVED result - "READ" is an acceptable observed result only when a mutation genuinely
# cannot be run, and it must say why.
GUARDS="probe-A probe-B probe-C probe-D S12 S12b S12c S13 S13b S14 S14b S14c L1 L2 L3 L4 D1 D2 D3 D4"

echo "== is the adversarial review complete enough to judge? =="
echo "   artifact: $ART"

if [ -f "$ART" ]; then
  ok "the review artifact exists"
else
  bad "the review artifact is missing"
  echo "== probe: $pass passed, $fail failed =="
  exit 1
fi

lines=$(wc -l < "$ART")
if [ "$lines" -ge 80 ]; then
  ok "the review is substantive ($lines lines)"
else
  bad "the review is too thin to judge ($lines lines, want >= 80)"
fi

missing=""
for g in $GUARDS; do
  grep -q "$g" "$ART" || missing="$missing $g"
done
if [ -z "$missing" ]; then
  ok "every guard is named (20 expected)"
else
  bad "these guards are not named at all:$missing"
fi

# A named guard with no observed result is a claim, not a review.
nores=""
for g in $GUARDS; do
  grep -q "$g" "$ART" || continue
  # the guard's line (or the 3 lines after it) must carry an observed result
  grep -A3 -- "$g" "$ART" | grep -qiE 'observ|result|red|green|pass|fail' || nores="$nores $g"
done
if [ -z "$nores" ]; then
  ok "every named guard carries an observed result"
else
  bad "these guards are named but carry no observed result:$nores"
fi

# The review must state plainly whether any guard cannot fail - including "none found".
if grep -qiE 'cannot fail|decorative|no guard .*(found|identified)|none found' "$ART"; then
  ok "the review states whether any guard cannot fail"
else
  bad "the review never says whether any guard cannot fail"
fi

# At least three mutations must have actually been RUN, not merely described.
ran=$(grep -ciE '^\|.*\b(run|ran|executed)\b|\bRUN\b' "$ART")
if [ "${ran:-0}" -ge 3 ]; then
  ok "at least three mutations were RUN, not merely described ($ran rows)"
else
  bad "fewer than three mutations were RUN ($ran rows) - a described mutation is not a measurement"
fi

# ── the tree must be exactly as the reviewer found it ─────────────────────────
dirty=$(git status --porcelain -- tools/lane.sh tools/lane-model.sh tools/lane-watch.sh | wc -l)
if [ "$dirty" -eq 0 ]; then
  ok "the mutation runs were all restored (the harness itself is unmodified)"
else
  bad "$dirty harness file(s) left modified - a mutation was not restored:"
  git status --porcelain -- tools/lane.sh tools/lane-model.sh tools/lane-watch.sh | sed 's/^/         /'
fi

for f in tools/lane.sh tools/lane-model.sh; do
  bash -n "$f" 2>/dev/null && ok "$f is syntactically valid" || bad "$f does not parse"
done

echo
echo "== probe: $pass passed, $fail failed =="
[ "$fail" -eq 0 ] || exit 1
exit 0
