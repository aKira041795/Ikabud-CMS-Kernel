#!/usr/bin/env bash
#
# uninstall.sh — remove exactly what install.sh installed, and nothing else.
#
# The list comes from the manifest install.sh wrote (.lane-harness-installed), not from a guess. If
# the manifest is missing, this removes nothing and says so, because "remove everything matching
# tools/lane*" in someone else's repository is how you delete their work.
#
# .ai/ is deliberately LEFT ALONE: it is your lane history, which is evidence and possibly the only
# record of what a model did. Use --purge to delete it as well.
#
# Usage:
#   bash uninstall.sh /path/to/your/repo [--purge] [--dry-run]
#
set -u

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
target=""; purge=0; dry=0
for arg in "$@"; do
  case "$arg" in
    --purge)   purge=1;;
    --dry-run) dry=1;;
    -h|--help) sed -n '2,14p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'; exit 0;;
    -*)        echo "uninstall.sh: unknown flag '$arg'" >&2; exit 2;;
    *)         target="$arg";;
  esac
done
[ -n "$target" ] || { echo "usage: bash uninstall.sh /path/to/your/repo [--purge] [--dry-run]" >&2; exit 2; }
[ -d "$target" ] || { echo "uninstall.sh: '$target' is not a directory" >&2; exit 2; }
target="$(cd "$target" && pwd)"
manifest="$target/.lane-harness-installed"

run() { if [ "$dry" -eq 1 ]; then echo "   would: $*"; else "$@"; fi; }

if [ ! -f "$manifest" ]; then
  echo "uninstall.sh: no $manifest found."
  echo "   That file is the record of what this harness installed. Without it, guessing which files"
  echo "   are ours would risk deleting yours, so nothing was removed."
  exit 1
fi

echo "== removing lane-harness from $target (from its own manifest) =="
removed=0
while IFS= read -r rel; do
  [ -n "$rel" ] || continue
  case "$rel" in *..*|/*) echo "   SKIPPED suspicious entry: $rel"; continue;; esac
  if [ -e "$target/$rel" ]; then run rm -f "$target/$rel"; removed=$((removed + 1)); echo "   removed $rel"; fi
done < "$manifest"
run rm -f "$manifest"

# VS Code: only remove a file that is byte-identical to what we wrote, i.e. never touched by you.
for f in .vscode/tasks.json .vscode/settings.json .vscode/extensions.json; do
  [ -f "$target/$f" ] || continue
  # Compare against what we ACTUALLY wrote. settings.json is written FROM settings.fragment.json, so
  # comparing it against a same-named source could never match, and the message then claimed the user
  # had edited a file they had not touched - a message contradicting its own evidence, which is the
  # exact class this harness exists to remove. Say "cannot verify" rather than inventing an edit.
  src="$HERE/$f"
  [ "$f" = ".vscode/settings.json" ] && src="$HERE/.vscode/settings.fragment.json"
  if [ -f "$src" ] && cmp -s "$src" "$target/$f"; then
    run rm -f "$target/$f"; echo "   removed $f (untouched since install)"
  elif [ -f "$src" ]; then
    echo "   KEPT    $f (differs from what we wrote — treating it as yours)"
  else
    echo "   KEPT    $f (cannot verify it is unmodified — no matching source in the package)"
  fi
done
[ -d "$target/extras-lane-harness" ] && { run rm -rf "$target/extras-lane-harness"; echo "   removed extras-lane-harness/"; }

if [ "$purge" -eq 1 ]; then
  run rm -rf "$target/.ai"
  echo "   removed .ai/ (--purge)"
else
  echo "   KEPT    .ai/ — your lane history. Remove it yourself, or re-run with --purge."
fi

rmdir "$target/tools" 2>/dev/null && echo "   removed empty tools/"
rmdir "$target/examples" 2>/dev/null && echo "   removed empty examples/"
echo "== done: $removed file(s) ==$( [ "$dry" -eq 1 ] && echo ' (dry run — nothing was changed)')"
