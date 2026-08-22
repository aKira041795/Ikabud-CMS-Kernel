#!/usr/bin/env bash
# pi-arch-review.sh — Architecture peer review via the Pi execution harness.
#
# Runs DeepSeek Pro + Codex Sol (ChatGPT Pro subscription) as independent
# peer reviewers/architects on a task contract, then saves each review.
#
# Usage:
#   bash tools/pi-arch-review.sh [task-file] [outdir]
#
#   task-file   Path to the task contract (default: .ai/current-task.md)
#   outdir      Where to write results  (default: test_results)
#
# Outputs:
#   <outdir>/arch-dspro.txt      DeepSeek Pro review
#   <outdir>/arch-codexsol.txt   Codex Sol review
#   <outdir>/arch-dspro.jsonl    raw Pi JSONL (trace)
#   <outdir>/arch-codexsol.jsonl raw Pi JSONL (trace)
set -u
# Repo-agnostic: resolve the repository root from this script's own location.
REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO_ROOT"

TASK_FILE="${1:-.ai/current-task.md}"
OUTDIR="${2:-test_results}"
[ -f "$TASK_FILE" ] || { echo "task file not found: $TASK_FILE" >&2; exit 2; }
mkdir -p "$OUTDIR"

PROMPT="$(sed "s|{{TASK_FILE}}|$TASK_FILE|g" tools/arch-review-prompt.txt)"

run_one() {
  local model="$1" name="$2"
  echo "=== $name ($model) ==="
  if timeout "${MODEL_TIMEOUT:-600}" pi --model "$model" --mode json --print "$PROMPT" > "$OUTDIR/arch-$name.jsonl" 2>&1; then
    echo "    ($name completed)"
  else
    echo "    ($name timed out/errored after ${MODEL_TIMEOUT:-600}s — using partial output)"
  fi
  python3 - "$OUTDIR" "$name" <<'PYEOF'
import json, sys
outdir, name = sys.argv[1], sys.argv[2]
parts = []
try:
    for line in open(f"{outdir}/arch-{name}.jsonl"):
        line = line.strip()
        if not line:
            continue
        o = json.loads(line)
        if o.get("type") == "message_update":
            ae = o.get("assistantMessageEvent", {})
            if ae.get("type") == "text_delta":
                parts.append(ae.get("delta", ""))
except Exception as e:
    print("parse error:", e)
text = "".join(parts).strip()
open(f"{outdir}/arch-{name}.txt", "w").write(text)
print(f"{name}: final_text_len={len(text)}")
PYEOF
}

run_one "deepseek-v4-pro" "dspro"
run_one "openai-codex/gpt-5.6-sol" "codexsol"
echo "=== DONE: reviews in $OUTDIR/arch-{dspro,codexsol}.txt ==="
