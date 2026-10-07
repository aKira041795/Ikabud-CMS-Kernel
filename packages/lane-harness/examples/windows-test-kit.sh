#!/usr/bin/env bash
#
# examples/windows-test-kit.sh — test the lane harness on the machine in front of you.
#
# WHY THIS EXISTS: "try it and tell me if it works" is not a test. This runs the checks that
# actually matter, prints PASS/FAIL against the EXPECTED output, and ends with a block you can send
# back verbatim. It knows which platform it is on and SKIPS — loudly — what it cannot test there,
# rather than pretending.
#
# The most valuable check is #1. A Windows unzip tool that rewrites line endings breaks every shell
# script, and the symptom ("$'\r': command not found") looks like a bug in the harness.
#
# Usage:
#   bash examples/windows-test-kit.sh            # everything this platform allows (~3 min)
#   bash examples/windows-test-kit.sh --fast     # skip the two long self-tests (~30 s)
#
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PKG="$(cd "$HERE/.." && pwd)"
FAST=0
[ "${1:-}" = "--fast" ] && FAST=1

pass=0; fail=0; skipped=0
ok()   { printf '   [PASS] %s\n' "$1"; pass=$((pass+1)); }
bad()  { printf '   [FAIL] %s\n' "$1"; fail=$((fail+1)); }
skip() { printf '   [SKIP] %s\n' "$1"; skipped=$((skipped+1)); }
note() { printf '          %s\n' "$1"; }

case "$(uname -s 2>/dev/null)" in
  MINGW*|MSYS*|CYGWIN*) PLAT="windows-posix";;
  Darwin)               PLAT="macos";;
  Linux)
    if [ -r /proc/version ] && grep -qi microsoft /proc/version 2>/dev/null; then PLAT="wsl2"
    elif [ -n "${WSL_DISTRO_NAME:-}" ]; then PLAT="wsl2"
    else PLAT="linux"; fi;;
  *)                    PLAT="unknown";;
esac

echo "=============================================="
echo " lane-harness test kit"
echo "=============================================="
echo "   package:  $PKG"
echo "   platform: $PLAT  ($(uname -s 2>/dev/null) $(uname -r 2>/dev/null))"
case "$PLAT" in
  wsl2)          echo "   path:     FULL MODE — this is the supported Windows setup";;
  linux)         echo "   path:     FULL MODE";;
  windows-posix) echo "   path:     DEGRADED — Git Bash/MSYS; WSL2 is the supported path";;
  macos)         echo "   path:     DEGRADED unless util-linux is installed";;
  *)             echo "   path:     unknown platform — results below may not mean much";;
esac
echo

# ── 1. LINE ENDINGS — the failure that masquerades as a broken harness ───────────────────────────
echo "-- 1. line endings (the #1 Windows failure) --"
crlf=0
for f in "$PKG"/tools/*.sh; do
  [ -f "$f" ] || continue
  cr=$(LC_ALL=C tr -cd '\r' < "$f" | wc -c | tr -d ' ')
  if [ "${cr:-0}" -gt 0 ]; then
    bad "$(basename "$f") has $cr carriage return(s) — this unzip rewrote line endings"
    crlf=$((crlf + 1))
  fi
done
if [ "$crlf" -eq 0 ]; then
  ok "all $(ls "$PKG"/tools/*.sh | wc -l | tr -d ' ') shell files are LF — nothing mangled them"
else
  note "Re-extract with Windows Explorer or 7-Zip (no line-ending conversion), then re-run."
fi

# ── 2. THE INSTALL GUARD — must refuse Git Bash, must allow it on request ────────────────────────
echo
echo "-- 2. the Windows install guard --"
SCRATCH="${TMPDIR:-/tmp}/lh-test-$$"
rm -rf "$SCRATCH"; mkdir -p "$SCRATCH"
( cd "$SCRATCH" && git init -q && echo x > f.txt && git add -A \
  && git -c user.email=t@t -c user.name=t commit -qm init ) >/dev/null 2>&1

if [ "$PLAT" = "windows-posix" ]; then
  bash "$PKG/install.sh" "$SCRATCH" --no-verify > "$SCRATCH/refuse.log" 2>&1
  rc=$?
  if [ "$rc" -eq 2 ] && grep -q 'REFUSING' "$SCRATCH/refuse.log" \
     && grep -q 'wsl --install' "$SCRATCH/refuse.log"; then
    ok "Git Bash without --allow-degraded is REFUSED, and the refusal names wsl --install (rc=$rc)"
  else
    bad "the Git Bash guard did not refuse properly (rc=$rc)"
    sed 's/^/          | /' "$SCRATCH/refuse.log" | head -5
  fi
  bash "$PKG/install.sh" "$SCRATCH" --no-verify --allow-degraded > "$SCRATCH/allow.log" 2>&1
  rc=$?
  if [ "$rc" -eq 0 ] && grep -q 'WARNING' "$SCRATCH/allow.log"; then
    ok "--allow-degraded proceeds, and says so (rc=$rc)"
  else
    bad "--allow-degraded did not proceed (rc=$rc)"
  fi
else
  bash "$PKG/install.sh" "$SCRATCH" --no-verify > "$SCRATCH/install.log" 2>&1
  rc=$?
  if [ "$rc" -eq 0 ] && grep -qE 'tools: +[0-9]+ added' "$SCRATCH/install.log"; then
    ok "install into a scratch repo succeeded (rc=$rc)"
  else
    bad "install failed on this platform (rc=$rc)"
    sed 's/^/          | /' "$SCRATCH/install.log" | tail -8
  fi
  skip "the Git Bash refusal guard (this is not Git Bash) — tested separately in the repo's own suite"
fi

# ── 3. PREFLIGHT — does it name the platform correctly? ──────────────────────────────────────────
echo
echo "-- 3. preflight --"
# NOT --quiet: this check asserts on preflight's TEXT, and --quiet suppresses exactly that text, so
# the assertion could never be true. It stayed hidden because the Linux branch only checks the exit
# code — the Windows branch is where it surfaced. A check whose input is suppressed is not a check.
bash "$SCRATCH/tools/preflight.sh" > "$SCRATCH/preflight.log" 2>&1
prc=$?
echo "          exit=$prc (0 = nothing REQUIRED is missing)"
grep -E '^\s+(platform|\[ok\]|\[MISSING\]|\[absent\]|\[degrade\]|->)' "$SCRATCH/preflight.log" | sed 's/^/          /'
case "$PLAT" in
  wsl2) if grep -q 'WSL2 inside Windows' "$SCRATCH/preflight.log"; then
          ok "preflight identifies WSL2 and reports full support"
        else bad "preflight did not identify WSL2"; fi;;
  windows-posix) if grep -q 'WSL2 is the SUPPORTED path' "$SCRATCH/preflight.log"; then
          ok "preflight names WSL2 as the supported path, with the remedy"
        else bad "preflight did not name the WSL2 remedy"; fi;;
  *) ok "preflight ran (exit=$prc)";;
esac

# ── 4. THE SELF-TESTS ────────────────────────────────────────────────────────────────────────────
echo
echo "-- 4. the harness checking itself --"
if [ "$FAST" -eq 1 ]; then
  skip "platform selftest (--fast)"
else
  out="$(cd "$SCRATCH" && bash tools/lane-platform-selftest.sh 2>&1)"
  last="$(printf '%s' "$out" | grep -E 'lane-platform-selftest:' | tail -1)"
  pty="$(printf '%s' "$out" | grep -E 'native pty mode:' | tail -1 | awk '{print $NF}')"
  det="$(printf '%s' "$out" | grep -E 'native detach mode:' | tail -1 | awk '{print $NF}')"
  if printf '%s' "$last" | grep -q '0 failed'; then
    ok "platform selftest: $last  (pty=$pty detach=$det)"
    # Every combination is named. An earlier version matched only full-mode-pty and no-pty, so
    # Git Bash WITH util-linux installed (which MSYS2 can provide) fell through every case and
    # asserted NOTHING - a check that reports nothing reads as a pass and is worse than no check.
    case "$PLAT" in
      wsl2|linux)
        case "$pty" in
          util-linux) ok "pty mode is util-linux, which is what full mode requires";;
          *) bad "full-mode platform but pty mode is '$pty' — check that you are really inside WSL/Linux";;
        esac;;
      windows-posix|macos)
        case "$pty" in
          none)       ok "no pty on this platform — and the suite proves the no-pty path carries a real lane";;
          util-linux) ok "pty mode is util-linux — better than expected for this platform";;
          *)          bad "unexpected pty mode '$pty'";;
        esac;;
      *) note "pty mode: $pty (platform unrecognised, not asserting a requirement)";;
    esac
  else
    bad "platform selftest: ${last:-no summary line}"
  fi

  out="$(cd "$SCRATCH" && bash tools/lane.sh selftest 2>&1)"
  last="$(printf '%s' "$out" | grep -E '^== selftest:' | tail -1)"
  if printf '%s' "$last" | grep -q '0 failed'; then ok "harness selftest: $last"
  else bad "harness selftest: ${last:-no summary line}"; fi

  out="$(cd "$SCRATCH" && bash tools/lane-model-selftest.sh 2>&1)"
  last="$(printf '%s' "$out" | grep -E '^selftest:' | tail -1)"
  if printf '%s' "$last" | grep -q '0 failed'; then ok "model selftest: $last"
  else bad "model selftest: ${last:-no summary line}"; fi
fi

# ── 5. THE THING THAT MATTERS — does it refuse a lane that only CLAIMS success? ──────────────────
echo
echo "-- 5. two lanes, the same 'status: PASS', opposite verdicts --"
: > "$SCRATCH/stub-honest.sh"
cat > "$SCRATCH/stub-honest.sh" <<'EOF'
#!/usr/bin/env bash
: > demo-artifact.txt
echo "I have completed the task."
echo "status: PASS"
exit 0
EOF
cat > "$SCRATCH/stub-liar.sh" <<'EOF'
#!/usr/bin/env bash
echo "I have completed the task."
echo "status: PASS"
exit 0
EOF
chmod +x "$SCRATCH/stub-honest.sh" "$SCRATCH/stub-liar.sh"
cat > "$SCRATCH/tools/lane-demo.sh" <<'EOF'
#!/usr/bin/env bash
set -u
cd "$(git rev-parse --show-toplevel 2>/dev/null || pwd)" || exit 1
source tools/lane-model.sh
lane_model_run "demo-model" "create demo-artifact.txt, then report status PASS" /tmp/lh-testkit-demo
exit $?
EOF
chmod +x "$SCRATCH/tools/lane-demo.sh"

cd "$SCRATCH" || exit 1
rm -f demo-artifact.txt
LANE_MODEL_CMD="$SCRATCH/stub-honest.sh" bash tools/lane.sh run kit-honest tools/lane-demo.sh \
  --timeout=60 --wait-grace=3 --slice=40 \
  --acceptance="test -f $SCRATCH/demo-artifact.txt" \
  --pass-looks-like="demo-artifact.txt exists" > "$SCRATCH/honest.log" 2>&1
verdict="$(grep -o '"verdict": "[A-Z_]*"' .ai/runs/kit-honest.landed.json 2>/dev/null | cut -d'"' -f4)"
if [ "$verdict" = "VERIFIED" ]; then
  ok "the lane that DID the work -> VERIFIED"
else
  bad "a lane that delivered was not VERIFIED (verdict='${verdict:-none}')"
fi

rm -f never-created.txt
LANE_MODEL_CMD="$SCRATCH/stub-liar.sh" bash tools/lane.sh run kit-liar tools/lane-demo.sh \
  --timeout=60 --wait-grace=3 --slice=40 \
  --acceptance="test -f $SCRATCH/never-created.txt" \
  --pass-looks-like="never-created.txt exists" > "$SCRATCH/liar.log" 2>&1
verdict2="$(grep -o '"verdict": "[A-Z_]*"' .ai/runs/kit-liar.landed.json 2>/dev/null | cut -d'"' -f4)"
self2="$(grep -o '"status_line": "[^"]*"' .ai/runs/kit-liar.landed.json 2>/dev/null | cut -d'"' -f4)"
if [ "$verdict2" = "NOT_VERIFIED" ]; then
  ok "the lane that only CLAIMED success -> NOT_VERIFIED (its own report said '${self2:-?}')"
else
  bad "a lane that claimed success without delivering was not refused (verdict='${verdict2:-none}')"
fi

if grep -q 'ALREADY PASSES' "$SCRATCH"/*.log 2>/dev/null; then
  bad "something reached the gate that should already have been refused"
else
  out="$(bash tools/lane.sh run kit-gate tools/lane-demo.sh \
        --acceptance="test -f $SCRATCH/tools/lane.sh" \
        --pass-looks-like="a file that already exists" 2>&1)"
  if printf '%s' "$out" | grep -q 'ALREADY PASSES'; then
    ok "the gate REFUSED a criterion that already passes (that is the whole point)"
  else
    bad "the gate accepted a criterion that already passes"
  fi
fi

# ── summary ──────────────────────────────────────────────────────────────────────────────────────
echo
echo "=============================================="
echo " $pass passed, $fail failed, $skipped skipped"
echo "=============================================="
rm -rf "$SCRATCH"
if [ "$fail" -ne 0 ]; then
  echo " SEND THIS BACK: the [FAIL] lines above, plus the platform line, plus the output of"
  echo "                 'bash tools/preflight.sh' (it contains no secrets)."
  exit 1
fi
echo " Nothing failed. If you want to report the run, send just the platform line and the counts."
exit 0
