#!/usr/bin/env bash
#
# install.sh — install the lane harness into an existing repository.
#
# What it does, in order, and why:
#   1. refuses to install into the package itself;
#   2. runs the preflight, because an install on a machine missing `timeout` produces silent hangs;
#   3. copies tools/* — never overwriting a file that differs, unless --force. An existing
#      tools/lane.sh in the target may be a NEWER version than this package carries;
#   4. adds `.ai/` to .gitignore (the lane logs hold your prompts and the model's full output);
#   5. writes .vscode/tasks.json ONLY if absent, because that file is yours and merging JSON with
#      sed is how you destroy someone's tasks. If it exists, the tasks are written next to it as
#      tasks.lane-harness.snippet.json for you to paste;
#   6. records what it installed, so uninstall.sh can remove exactly that and nothing else.
#
# Deliberately NOT offered: a symlink/`--link` mode. lane.sh resolves its repository root from its
# own path (dirname $BASH_SOURCE), so a symlinked tools/ would make every lane operate on the
# PACKAGE's directory instead of your repo — a silent, destructive wrong-root bug.
#
# Usage:
#   bash install.sh /path/to/your/repo [--force] [--no-vscode] [--no-verify]
#
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$HERE"
[ -d "$SRC/tools" ] || { echo "install.sh: no tools/ next to this script — unzip the package first" >&2; exit 2; }

target=""; force=0; vscode=1; verify=1; allow_degraded=0
for arg in "$@"; do
  case "$arg" in
    --force)           force=1;;
    --no-vscode)       vscode=0;;
    --no-verify)       verify=0;;
    --allow-degraded)  allow_degraded=1;;
    -h|--help)         sed -n '2,26p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0;;
    -*)                echo "install.sh: unknown flag '$arg'" >&2; exit 2;;
    *)                 target="$arg";;
  esac
done
[ "${LANE_ALLOW_DEGRADED:-0}" = "1" ] && allow_degraded=1

[ -n "$target" ] || { echo "usage: bash install.sh /path/to/your/repo [--force] [--no-vscode]" >&2; exit 2; }
[ -d "$target" ] || { echo "install.sh: '$target' is not a directory" >&2; exit 2; }
target="$(cd "$target" && pwd)"

[ "$target" != "$SRC" ] || { echo "install.sh: refusing to install the package into itself" >&2; exit 2; }

# ── Windows: WSL2 is the SUPPORTED path, and this says so at the point of decision ──────────────
# Git Bash / MSYS2 / Cygwin can run the harness, but without a pty - so every lane starts with no
# terminal, and several model CLIs behave differently or refuse without one. That is a degradation,
# not a detail, and the whole point of this harness is not to let a real limitation pass silently.
platform_is_windows_posix() {
  case "$(uname -s 2>/dev/null)" in MINGW*|MSYS*|CYGWIN*) return 0;; esac
  return 1
}
if platform_is_windows_posix && [ "$allow_degraded" -eq 0 ]; then
  cat >&2 <<'WINDOWS_EOF'
REFUSING: this is bash from Windows (Git Bash / MSYS2 / Cygwin), not from WSL2.

The harness needs a pty and process-group detachment, which the Windows POSIX layer does not
provide. It will RUN here, but every lane is started WITHOUT a terminal, and some model CLIs
behave differently or refuse without one.

Use WSL2. The setup is one command in an Administrator PowerShell:

    wsl --install

then, INSIDE the Ubuntu shell it installs:

    sudo apt-get update && sudo apt-get install -y git
    mkdir -p ~/code && cd ~/code && git clone <your-repo> && cd <your-repo>
    bash /mnt/c/path/to/lane-harness-0.1.0/install.sh "$PWD"
    code .          # VS Code reopens attached to WSL (bottom-left says "WSL: Ubuntu")

Read WINDOWS-QUICKSTART.txt next to this script for the copy-paste version, and
 docs/01-WINDOWS-AND-WSL.md for the full walkthrough (including the CRLF trap that breaks bash
 scripts on a Windows checkout).

If you want the degraded Windows build anyway: re-run with --allow-degraded.
WINDOWS_EOF
  exit 2
fi
if platform_is_windows_posix; then
  echo "WARNING: installing into a Windows POSIX layer (Git Bash/MSYS) with --allow-degraded."
  echo "         Lanes will run WITHOUT a terminal. WSL2 is the supported path."
fi
if [ ! -d "$target/.git" ]; then
  echo "install.sh: WARNING: '$target' is not a git repository."
  echo "            The landing record reads 'git status' to attribute changed files, so without git"
  echo "            that field degrades to a whole-tree count it cannot trust. Continuing anyway."
fi

echo "== installing lane-harness into $target =="

if [ -f "$SRC/preflight.sh" ]; then
  bash "$SRC/preflight.sh" || {
    echo "install.sh: preflight reported a REQUIRED gap. Fix it and re-run (or run preflight.sh yourself)." >&2
    exit 1
  }
fi

mkdir -p "$target/tools"
installed="$target/.lane-harness-installed"
: > "$installed.tmp"

copied=0; skipped=0; replaced=0
for f in "$SRC"/tools/*; do
  [ -f "$f" ] || continue
  name="$(basename "$f")"
  dst="$target/tools/$name"
  if [ -f "$dst" ]; then
    if cmp -s "$f" "$dst"; then
      skipped=$((skipped + 1))
    elif [ "$force" -eq 1 ]; then
      cp "$f" "$dst"; replaced=$((replaced + 1))
    else
      echo "   KEPT    tools/$name (differs from the package — use --force to overwrite)"
      skipped=$((skipped + 1))
    fi
  else
    cp "$f" "$dst"; copied=$((copied + 1))
  fi
  chmod +x "$dst" 2>/dev/null || true
  printf 'tools/%s\n' "$name" >> "$installed.tmp"
done
# Examples are tracked in the manifest too, so uninstall.sh removes exactly what was installed
# instead of leaving them behind or, worse, guessing at a glob.
if [ -d "$SRC/examples" ]; then
  mkdir -p "$target/examples"
  for f in "$SRC"/examples/*.sh; do
    [ -f "$f" ] || continue
    b="$(basename "$f")"
    [ -f "$target/examples/$b" ] || cp "$f" "$target/examples/$b"
    printf 'examples/%s\n' "$b" >> "$installed.tmp"
  done
fi
echo "   tools:   $copied added, $replaced replaced, $skipped already present"

# ── .gitignore: the lane logs are yours, not the repository's ────────────────────────────────────
if [ -f "$target/.gitignore" ] && grep -qxE '\.ai/?' "$target/.gitignore"; then
  echo "   .gitignore already ignores .ai/"
else
  {
    echo ""
    echo "# lane-harness working state — lane logs hold your prompt and the model's full output."
    echo ".ai/"
  } >> "$target/.gitignore"
  echo "   .gitignore: added .ai/"
fi

mkdir -p "$target/.ai/runs"

# ── .gitattributes: keep the shell scripts LF ────────────────────────────────────────────────────
# A Windows checkout with core.autocrlf=true rewrites every .sh with CRLF, and bash then fails with
# "$'\r': command not found" - which reads as a broken harness. Pinning it here is the cheapest
# possible fix, and preflight.sh checks for it as well.
if [ -f "$target/.gitattributes" ] && grep -qE '^\*\.sh[[:space:]]+text[[:space:]]+eol=lf' "$target/.gitattributes"; then
  echo "   .gitattributes already pins *.sh to LF"
elif [ -f "$SRC/gitattributes.fragment" ]; then
  { echo ""; cat "$SRC/gitattributes.fragment"; } >> "$target/.gitattributes"
  echo "   .gitattributes: pinned *.sh to LF (a CRLF checkout breaks every script)"
else
  echo "   NOTE: no .gitattributes fragment shipped — on Windows run:"
  echo "         git config core.autocrlf false"
fi

# ── VS Code ──────────────────────────────────────────────────────────────────────────────────────
if [ "$vscode" -eq 1 ] && [ -d "$SRC/.vscode" ]; then
  mkdir -p "$target/.vscode"
  if [ -f "$target/.vscode/tasks.json" ]; then
    cp "$SRC/.vscode/tasks.json" "$target/.vscode/tasks.lane-harness.snippet.json"
    echo "   .vscode/tasks.json already exists — wrote tasks.lane-harness.snippet.json instead."
    echo "            Paste its \"tasks\" entries into your tasks.json (see docs/02-VSCODE-INTEGRATION.md)."
  else
    cp "$SRC/.vscode/tasks.json" "$target/.vscode/tasks.json"
    echo "   .vscode/tasks.json written"
  fi
  if [ -f "$target/.vscode/settings.json" ]; then
    cp "$SRC/.vscode/settings.fragment.json" "$target/.vscode/settings.lane-harness.snippet.json"
    echo "   .vscode/settings.json already exists — fragment written alongside it"
  elif [ -f "$SRC/.vscode/settings.fragment.json" ]; then
    cp "$SRC/.vscode/settings.fragment.json" "$target/.vscode/settings.json"
    echo "   .vscode/settings.json written"
  fi
  [ -f "$SRC/.vscode/extensions.json" ] && [ ! -f "$target/.vscode/extensions.json" ] \
    && cp "$SRC/.vscode/extensions.json" "$target/.vscode/extensions.json" \
    && echo "   .vscode/extensions.json written (recommends the WSL extension)"
fi

if [ -f "$SRC/gitignore.fragment" ]; then cp "$SRC/gitignore.fragment" "$target/.lane-harness-gitignore.fragment"; fi
if [ -d "$SRC/extras" ]; then
  cp -r "$SRC/extras" "$target/extras-lane-harness"
  echo "   extras/ copied to extras-lane-harness/ (optional ChatGPT advisor — see its README)"
fi

mv "$installed.tmp" "$installed"
printf '%s\n' ".lane-harness-installed" >> "$installed"
printf '%s\n' ".lane-harness-gitignore.fragment" >> "$installed"

# ── verify: the numbers, not a claim ─────────────────────────────────────────────────────────────
if [ "$verify" -eq 1 ]; then
  echo
  echo "== verifying the install (this is the point of the harness — do not skip it) =="
  ( cd "$target" && bash tools/lane-platform-selftest.sh 2>&1 | tail -2 )
  ( cd "$target" && bash tools/lane.sh selftest 2>&1 | tail -2 )
  ( cd "$target" && bash tools/lane-model-selftest.sh 2>&1 | tail -2 )
fi

echo
echo "== installed =="
echo "   next:  cd $target"
echo "          cat tools/model-chain.txt          # set YOUR models"
echo "          docs/02-VSCODE-INTEGRATION.md      # the dispatch loop in VS Code"
echo "          docs/03-PROVIDERS-AND-KEYS.md      # where YOUR credentials live (none ship here)"
