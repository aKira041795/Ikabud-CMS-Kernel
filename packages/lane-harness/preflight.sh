#!/usr/bin/env bash
#
# preflight.sh — does THIS machine have what the harness needs, and which mode will it run in?
#
# Run this before install.sh and again inside the target repo. It reports REQUIRED gaps as failures
# (exit 1) and OPTIONAL gaps with exactly what degrades, so a Windows or macOS user learns what they
# are getting instead of discovering it as a silent hang.
#
# Usage:
#   bash preflight.sh [--check-only] [--quiet]
#
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Installed mode: this script lives in <repo>/tools/. Package mode: alongside ./tools/ in the archive.
if [ -f "$HERE/tools/lane.sh" ]; then ROOT="$HERE"; MODE="package"
elif [ -f "$HERE/../tools/lane.sh" ]; then ROOT="$(cd "$HERE/.." && pwd)"; MODE="installed"
else ROOT="$HERE"; MODE="package"; fi

quiet=0
for arg in "$@"; do
  case "$arg" in
    --check-only) : ;;          # the default behaviour; accepted so documented usage never errors
    --quiet) quiet=1;;
    -h|--help) sed -n '2,12p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0;;
  esac
done

say() { [ "$quiet" -eq 1 ] || echo "$@"; }
req_fail=0
opt_fail=0

say "== lane-harness preflight =="
say "   mode:     $MODE"
say "   location: $ROOT"

# ── platform ─────────────────────────────────────────────────────────────────────────────────────
os="unknown"
case "$(uname -s 2>/dev/null)" in
  Linux) os="linux";;
  Darwin) os="macos";;
  MINGW*|MSYS*|CYGWIN*) os="windows-posix";;
esac
is_wsl=0
if [ -r /proc/version ] && grep -qi microsoft /proc/version 2>/dev/null; then is_wsl=1; fi
[ -n "${WSL_DISTRO_NAME:-}" ] && is_wsl=1

case "$os" in
  linux)         say "   platform: Linux$([ "$is_wsl" -eq 1 ] && echo ' (WSL2 inside Windows)') — fully supported";;
  windows-posix) say "   platform: Windows via MSYS/Git Bash — runs WITHOUT a pty (degraded). WSL2 is recommended.";;
  macos)         say "   platform: macOS — runs WITHOUT a pty unless util-linux is installed (degraded).";;
  *)             say "   platform: unrecognised — the harness will try to run and may degrade.";;
esac

need() { # need <command> <why> <remedy>
  if command -v "$1" > /dev/null 2>&1; then
    say "   [ok]      $1"
  else
    echo "   [MISSING] $1 — $2"
    echo "             fix: $3"
    req_fail=$((req_fail + 1))
  fi
}
nice_to_have() { # nice_to_have <command> <what degrades>
  if command -v "$1" > /dev/null 2>&1; then
    say "   [ok]      $1 (optional)"
  else
    say "   [absent]  $1 (optional) — $2"
    opt_fail=$((opt_fail + 1))
  fi
}

say
say "-- required --"
if [ "${BASH_VERSINFO[0]}" -ge 4 ]; then say "   [ok]      bash ${BASH_VERSION%% *} (>= 4)"
else echo "   [MISSING] bash >= 4 (found ${BASH_VERSION:-unknown})"; req_fail=$((req_fail + 1)); fi
need git      "the landing record and changed-file attribution read git status" "install git"
need timeout  "every lane and every model attempt is bounded by a hard timeout" "coreutils (Linux) / 'brew install coreutils' (macOS)"
need mktemp   "the landing marker is written atomically via a temp file" "coreutils"
need nohup    "the fallback used to detach the watchdog when setsid is unavailable" "coreutils"

say
say "-- optional (each one changes what you get) --"
if command -v script > /dev/null 2>&1; then
  if script --version 2>/dev/null | grep -q util-linux; then
    say "             -> pty mode: util-linux (full mode)"
  else
    say "   [degrade] script is present but is NOT util-linux -> no pty"
    say "             fix (macOS): brew install util-linux && export PATH=\"\$(brew --prefix util-linux)/bin:\$PATH\""
    opt_fail=$((opt_fail + 1))
  fi
else
  say "   [degrade] no 'script' -> LANE_PTY_MODE=none, lanes run without a terminal"
  say "             fix (Windows): use WSL2 for full mode — see docs/01-WINDOWS-AND-WSL.md"
  opt_fail=$((opt_fail + 1))
fi
if command -v setsid > /dev/null 2>&1; then
  say "             -> detach: setsid (new session)"
else
  say "   [degrade] no setsid -> detach falls back to nohup (ignores SIGHUP only)"
  opt_fail=$((opt_fail + 1))
fi
nice_to_have php  "the harness self-test's JSON validity check is skipped; the 28-case selftest needs it"
nice_to_have notify-send "desktop pop-ups are unavailable; the durable journal still records every landing (and pop-ups are off by default anyway)"
nice_to_have node "the optional ChatGPT advisor cannot run"
nice_to_have python3 "the optional ChatGPT advisor cannot run"
if [ -f "$ROOT/tools/model-chain.txt" ] && [ "$(grep -cvE '^[[:space:]]*(#|$)' "$ROOT/tools/model-chain.txt")" -gt 0 ]; then
  say "   [ok]      tools/model-chain.txt ($(grep -cvE '^[[:space:]]*(#|$)' "$ROOT/tools/model-chain.txt") models) — edit it for your providers"
else
  say "   [MISSING] tools/model-chain.txt has no models — every lane will stop immediately"
  req_fail=$((req_fail + 1))
fi

say
say "-- model CLI --"
if command -v pi > /dev/null 2>&1; then
  say "   [ok]      pi ($(command -v pi))"
  say "             check auth with:  pi auth check --provider <id> --json"
elif [ -n "${LANE_MODEL_CMD:-}" ]; then
  say "   [ok]      LANE_MODEL_CMD is set: $LANE_MODEL_CMD"
else
  say "   [absent]  pi — you must set LANE_MODEL_CMD to your own model CLI"
  say "             expected shape:  <cmd> --model <name> <prompt>"
  opt_fail=$((opt_fail + 1))
fi

say
say "-- the scripts themselves --"
# LF line endings, checked explicitly. On Windows (and anywhere with core.autocrlf=true) a checkout
# gives every .sh a trailing CR, and bash then fails with things like
#     tools/lane.sh: line 95: $'\r': command not found
# or a shebang that cannot find its interpreter. It is the single most common Windows failure and it
# looks like a bug in the harness, so it is checked here rather than diagnosed later.
TOOLS_DIR=""
[ -f "$HERE/tools/lane.sh" ] && TOOLS_DIR="$HERE/tools"
[ -f "$HERE/../tools/lane.sh" ] && TOOLS_DIR="$(cd "$HERE/../tools" && pwd)"
if [ -n "$TOOLS_DIR" ]; then
  crlf_hits=0
  for f in "$TOOLS_DIR"/*.sh; do
    [ -f "$f" ] || continue
    cr=$(LC_ALL=C tr -cd '\r' < "$f" | wc -c | tr -d ' ')
    if [ "${cr:-0}" -gt 0 ]; then
      echo "   [BROKEN]  $(basename "$f") contains $cr carriage return(s) — CRLF line endings"
      crlf_hits=$((crlf_hits + 1))
    fi
  done
  if [ "$crlf_hits" -gt 0 ]; then
    echo "             fix:  git config core.autocrlf false"
    echo "                   printf '*.sh text eol=lf\\n' >> .gitattributes   # then re-checkout"
    echo "                   sed -i 's/\\r$//' tools/*.sh                     # or dos2unix tools/*.sh"
    req_fail=$((req_fail + crlf_hits))
  else
    say "   [ok]      all shell files use LF line endings"
  fi
fi

say
if [ "$req_fail" -gt 0 ]; then
  echo "== preflight: $req_fail REQUIRED item(s) missing — the harness will not run correctly =="
  exit 1
fi
if [ "$opt_fail" -gt 0 ]; then
  say "== preflight: OK — $opt_fail optional item(s) absent, so some capability is degraded =="
else
  say "== preflight: OK — full mode =="
fi
exit 0
