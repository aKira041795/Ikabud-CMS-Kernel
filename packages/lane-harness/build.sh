#!/usr/bin/env bash
#
# packages/lane-harness/build.sh — assemble a shareable, self-contained lane-harness package.
#
# DESIGN RULES, and why:
#
#  1. THE PAYLOAD IS AN ALLOWLIST, never a glob. A package that copies "tools/*.sh" would ship this
#     repository's 56 per-task lane briefs, its HARPP bridge and its deploy scripts the first time
#     someone adds one. An explicit list cannot leak by accident; it can only leak if a human adds a
#     name to it, which is a reviewable act.
#
#  2. THE SOURCE OF TRUTH IS ../../tools. Nothing is duplicated into this directory, so the package
#     can never drift from the harness that is actually verified here.
#
#  3. THE SECRET SCAN RUNS ON THE ASSEMBLED OUTPUT, not on the input list, and the build FAILS on a
#     hit. It also refuses credential-shaped FILES (auth.json, .env, *.pem, private keys, browser
#     profiles) by name. `--selftest` plants a key and proves the scanner refuses, because a scanner
#     nobody has seen fail is not a scanner.
#
#  4. THE COUPLING SCAN fails the build if this repository's names appear in the payload, so the
#     package is honest about being generic.
#
# Usage:
#   bash packages/lane-harness/build.sh                 # build dist/lane-harness-<version>/
#   bash packages/lane-harness/build.sh --selftest      # prove the secret scanner refuses
#   bash packages/lane-harness/build.sh --out=/tmp/pkg  # choose the output directory
#
set -euo pipefail

PKG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO="$(cd "$PKG_DIR/../.." && pwd)"
VERSION="$(cat "$PKG_DIR/VERSION" 2>/dev/null || echo 0.0.0)"

out_root="$PKG_DIR/dist"
selftest=0
for arg in "$@"; do
  case "$arg" in
    --selftest) selftest=1;;
    --out=*)    out_root="${arg#*=}";;
    *) echo "build.sh: unknown argument '$arg'" >&2; exit 2;;
  esac
done

# ── the payload: an explicit allowlist ───────────────────────────────────────────────────────────
PAYLOAD_TOOLS=(
  lane.sh                          # dispatch + gate + landing record + the 28-case selftest
  lane-platform.sh                 # pty + detach primitives, with selectable fallbacks
  lane-model.sh                    # model chain with budget-aware caps and unavailability fallback
  lane-watch.sh                    # one-shot watcher; exits so the agent gets a completion signal
  model-chain.txt                  # the ordered chain, one model per line
  model-unavailable.patterns       # the ONE unavailability signature list
  lane-platform-selftest.sh        # proves both OS branches, including no-pty end to end
  lane-model-selftest.sh           # proves the chain, the budget division and the fallback
  harness-acceptance-verify-probe.sh   # does the harness verify an outcome, or take the report's word?
  harness-changed-files-probe.sh       # does the landing record describe the lane, or the tree?
  harness-chain-liveness-probe.sh      # can one hanging model spend the whole budget?
  harness-review-probe.sh              # completeness of an adversarial review (not correctness)
)

# ── secret shapes ────────────────────────────────────────────────────────────────────────────────
# Bounded on both sides so a bare number or a short word cannot match: this repository has already
# paid once for an unbounded `429` matching a timestamp.
SECRET_PATTERNS=(
  'sk-[A-Za-z0-9_-]{16,}'                       # OpenAI-style
  'sk-ant-[A-Za-z0-9_-]{16,}'                   # Anthropic
  'sk-or-v1-[a-f0-9]{16,}'                      # OpenRouter
  'gsk_[A-Za-z0-9]{20,}'                        # Groq
  'AIza[0-9A-Za-z_-]{30,}'                      # Google
  '(ghp|gho|ghs|ghr)_[A-Za-z0-9]{20,}'         # GitHub
  'github_pat_[A-Za-z0-9_]{20,}'
  'xox[baprs]-[A-Za-z0-9-]{10,}'                # Slack
  'eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.' # JWT
  '-----BEGIN [A-Z ]*PRIVATE KEY-----'
  '(DEEPSEEK|OPENAI|ANTHROPIC|OPENROUTER|GROQ|GEMINI|OPENAI_IDEATION)_API_KEY[[:space:]]*=[[:space:]]*[^[:space:]]{8,}'
  '(api[_-]?key|apikey|auth[_-]?token|access[_-]?token|client[_-]?secret|bearer)[[:space:]]*[:=][[:space:]]*["'"'"'][A-Za-z0-9_./+=-]{20,}["'"'"']'
)
# Credential-shaped FILES that must never be in a package, whatever they contain.
SECRET_FILES='(^|/)(auth\.json|\.env|\.env\..*|credentials|credentials\.json|id_rsa|id_ed25519|.*\.pem|.*\.key|.*\.p12|.*\.pfx)$|chatgpt-profile|/sessions/|/tokens?/'

red()  { printf '\033[31m%s\033[0m\n' "$*"; }
grn()  { printf '\033[32m%s\033[0m\n' "$*"; }

sha256_of() { # sha256_of <file>
  if command -v sha256sum > /dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1
  else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

# ── the scanner ──────────────────────────────────────────────────────────────────────────────────
# Prints every finding and returns 1 on any hit. Deliberately reports the FILE and the PATTERN NAME,
# never the matched value - a scanner that echoes a live key into a build log has leaked it.
scan_for_secrets() { # scan_for_secrets <dir>
  local dir="$1" hits=0 f pat name
  while IFS= read -r f; do
    for pat in "${SECRET_PATTERNS[@]}"; do
      if grep -qIE -- "$pat" "$f" 2>/dev/null; then
        red "   SECRET  ${f#"$dir"/}  matches: ${pat:0:28}..."
        hits=$((hits + 1))
      fi
    done
  done < <(find "$dir" -type f 2>/dev/null)

  while IFS= read -r f; do
    local rel="${f#"$dir"/}"
    if printf '%s' "$rel" | grep -qIE "$SECRET_FILES"; then
      red "   SECRET FILE  $rel"
      hits=$((hits + 1))
    fi
  done < <(find "$dir" -type f 2>/dev/null)

  [ "$hits" -eq 0 ]
}

scan_for_coupling() { # scan_for_coupling <dir>
  local dir="$1" hits=0 f
  while IFS= read -r f; do
    if grep -qIE 'harpp|harpp-bridge|ikabud|bakeshop|daily-ledger|dc-cafe|applicationostest|/var/www/html' "$f" 2>/dev/null; then
      red "   COUPLING  ${f#"$dir"/}"
      grep -nIE 'harpp|ikabud|bakeshop|daily-ledger|dc-cafe|applicationostest|/var/www/html' "$f" | head -3 | sed 's/^/            /'
      hits=$((hits + 1))
    fi
  done < <(find "$dir" -type f 2>/dev/null)
  [ "$hits" -eq 0 ]
}

# ── --selftest: prove the scanner can fail ───────────────────────────────────────────────────────
if [ "$selftest" -eq 1 ]; then
  tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT
  pass=0; fail=0
  ck() { if [ "$2" = "$3" ]; then grn "   PASS  $1"; pass=$((pass+1));
         else red "   FAIL  $1 (got '$2', want '$3')"; fail=$((fail+1)); fi; }

  echo "== can the secret scanner actually fail? =="

  mkdir -p "$tmp/clean/tools"
  printf '#!/usr/bin/env bash\necho hello\n' > "$tmp/clean/tools/a.sh"
  if scan_for_secrets "$tmp/clean" > /dev/null 2>&1; then ck "a clean tree passes" "0" "0"
  else ck "a clean tree passes" "1" "0"; fi

  mkdir -p "$tmp/planted/tools"
  printf 'key = "sk-live-abcdefghijklmnopqrstuvwx"\n' > "$tmp/planted/tools/leak.sh"
  if scan_for_secrets "$tmp/planted" > /dev/null 2>&1; then ck "a planted API key is REFUSED" "pass" "refuse"
  else ck "a planted API key is REFUSED" "refuse" "refuse"; fi

  mkdir -p "$tmp/env/tools"
  printf 'DEEPSEEK_API_KEY=abcdefgh12345678\n' > "$tmp/env/tools/config.sh"
  if scan_for_secrets "$tmp/env" > /dev/null 2>&1; then ck "an env-var key assignment is REFUSED" "pass" "refuse"
  else ck "an env-var key assignment is REFUSED" "refuse" "refuse"; fi

  mkdir -p "$tmp/authdir"
  printf '{"deepseek":{"key":"x"}}\n' > "$tmp/authdir/auth.json"
  if scan_for_secrets "$tmp/authdir" > /dev/null 2>&1; then ck "a credential-shaped FILE is REFUSED" "pass" "refuse"
  else ck "a credential-shaped FILE is REFUSED" "refuse" "refuse"; fi

  mkdir -p "$tmp/jwt"
  printf 'token=eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxIn0.abc\n' > "$tmp/jwt/a.txt"
  if scan_for_secrets "$tmp/jwt" > /dev/null 2>&1; then ck "a JWT is REFUSED" "pass" "refuse"
  else ck "a JWT is REFUSED" "refuse" "refuse"; fi

  # A bare number or a short word must NOT trip it, or the scanner would cry wolf and be ignored.
  mkdir -p "$tmp/numbers/tools"
  printf 'pid=4291  count=402  t=20261007T164429  sk-short\n' > "$tmp/numbers/tools/b.sh"
  if scan_for_secrets "$tmp/numbers" > /dev/null 2>&1; then ck "bare numbers and short tokens do NOT trip it" "0" "0"
  else ck "bare numbers and short tokens do NOT trip it" "1" "0"; fi

  mkdir -p "$tmp/coupled/tools"
  printf '# built for ikabud harpp\n' > "$tmp/coupled/tools/c.sh"
  if scan_for_coupling "$tmp/coupled" > /dev/null 2>&1; then ck "origin-repo coupling is REFUSED" "pass" "refuse"
  else ck "origin-repo coupling is REFUSED" "refuse" "refuse"; fi

  echo
  echo "== build.sh --selftest: $pass passed, $fail failed =="
  [ "$fail" -eq 0 ] || exit 1
  exit 0
fi

# ── assemble ─────────────────────────────────────────────────────────────────────────────────────
dest="$out_root/lane-harness-$VERSION"
echo "== assembling lane-harness $VERSION =="
rm -rf "$dest"
mkdir -p "$dest/tools" "$dest/examples" "$dest/docs" "$dest/.vscode" "$dest/.devcontainer" "$dest/extras"

missing=0
for f in "${PAYLOAD_TOOLS[@]}"; do
  if [ -f "$REPO/tools/$f" ]; then
    cp "$REPO/tools/$f" "$dest/tools/$f"
    printf '   tools/%s\n' "$f"
  else
    red "   MISSING IN SOURCE: tools/$f"; missing=$((missing + 1))
  fi
done
[ "$missing" -eq 0 ] || { red "build refused: $missing payload file(s) absent"; exit 1; }

# Static package files.
cp "$PKG_DIR/VERSION"                "$dest/VERSION"
cp "$PKG_DIR/install.sh"             "$dest/install.sh"
cp "$PKG_DIR/uninstall.sh"           "$dest/uninstall.sh"
cp "$PKG_DIR/preflight.sh"           "$dest/preflight.sh"
# preflight must ALSO land inside tools/, because that is where .vscode/tasks.json invokes it from
# in an installed repository. Shipping it only at the archive root left the task pointing at a file
# that does not exist - found by installing the built package and reading the task list, not by
# reviewing the build script.
cp "$PKG_DIR/preflight.sh"           "$dest/tools/preflight.sh"
cp "$PKG_DIR/README.md"              "$dest/README.md"
cp "$PKG_DIR/SECURITY.md"            "$dest/SECURITY.md"
cp "$REPO/LICENSE-MIT"               "$dest/LICENSE"
cp "$PKG_DIR/gitignore.fragment"     "$dest/gitignore.fragment"
# gitattributes.fragment must be IN the archive: install.sh appends it to the target's
# .gitattributes, and a missing fragment silently downgraded to "no fragment shipped" - found by
# installing the built package into a scratch repo and reading the output.
cp "$PKG_DIR/gitattributes.fragment" "$dest/gitattributes.fragment"
cp "$PKG_DIR/examples/"*.sh          "$dest/examples/" 2>/dev/null || true
cp "$PKG_DIR/vscode/"*               "$dest/.vscode/" 2>/dev/null || true
cp "$PKG_DIR/devcontainer/"*         "$dest/.devcontainer/" 2>/dev/null || true
cp "$PKG_DIR/docs/"*.md              "$dest/docs/" 2>/dev/null || true
if [ -d "$PKG_DIR/extras/chatgpt-advisor" ]; then
  mkdir -p "$dest/extras/chatgpt-advisor"
  cp "$PKG_DIR/extras/chatgpt-advisor/README.md" "$dest/extras/chatgpt-advisor/" 2>/dev/null || true
  cp "$REPO/tools/harpp-bridge/chair_consult.py" "$dest/extras/chatgpt-advisor/" 2>/dev/null || true
  cp "$REPO/tools/harpp-bridge/chatgpt_page.js"  "$dest/extras/chatgpt-advisor/" 2>/dev/null || true
  cp "$REPO/tools/harpp-bridge/context_pack.py"  "$dest/extras/chatgpt-advisor/" 2>/dev/null || true
fi
chmod +x "$dest/tools/"*.sh "$dest/install.sh" "$dest/uninstall.sh" "$dest/preflight.sh" 2>/dev/null || true

# ── gates on the ASSEMBLED output ────────────────────────────────────────────────────────────────
echo
echo "== gate 1: secrets =="
if scan_for_secrets "$dest"; then grn "   no API keys, tokens or credential files found"; 
else red "   BUILD REFUSED: secrets in the package"; exit 1; fi

echo "== gate 2: origin-repo coupling =="
if scan_for_coupling "$dest"; then grn "   the payload is generic (no HARPP, no ikabud, no absolute paths)";
else red "   BUILD REFUSED: origin-repo coupling in the payload"; exit 1; fi

echo "== gate 3: the payload is auditable =="
{
  echo "# lane-harness $VERSION — sha256 of every shipped file."
  echo "# Verify with:  (cd lane-harness-$VERSION && sha256sum -c MANIFEST.sha256)"
  ( cd "$dest" && find . -type f ! -name MANIFEST.sha256 | LC_ALL=C sort | while IFS= read -r rel; do
      printf '%s  %s\n' "$(sha256_of "$rel")" "$rel"
    done )
} > "$dest/MANIFEST.sha256"
file_count=$(find "$dest" -type f | wc -l | tr -d ' ')
grn "   $file_count files, each hashed in MANIFEST.sha256"

# ── archives ─────────────────────────────────────────────────────────────────────────────────────
( cd "$out_root" && tar czf "lane-harness-$VERSION.tar.gz" "lane-harness-$VERSION" )
if command -v zip > /dev/null 2>&1; then
  ( cd "$out_root" && zip -qr "lane-harness-$VERSION.zip" "lane-harness-$VERSION" )
fi

echo
grn "== built: $out_root/lane-harness-$VERSION =="
ls -1 "$out_root" | sed 's/^/   /'
echo
echo "   Share the .tar.gz or .zip. Recipients start with docs/00-START-HERE.md."
