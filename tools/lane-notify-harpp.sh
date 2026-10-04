#!/usr/bin/env bash
#
# tools/lane-notify-harpp.sh — post a LANE LANDING into the HARPP chat lane.
#
# WHY: the owner wants a report when a delegated process lands - "my way of knowing you have
# arrived at a completed task... even when I am away". tools/lane.sh already has the hook and
# already calls it on every landing; it just pointed at notify-send, which is a desktop toast
# and cannot leave the workstation. This makes the landing land in the one chat lane the owner
# asked to keep.
#
# WIRED IN BY tools/lane.sh notify_landing() as:
#     "$LANE_NOTIFY_CMD" -u <urgency> -a "lane" "lane <name>: <reason>" "<summary>"
# so this script is notify-send compatible on purpose.
#
# ENABLE (opt-in twice over - LANE_NOTIFY gates the call, and the target must resolve):
#     LANE_NOTIFY=1 LANE_NOTIFY_CMD="$PWD/tools/lane-notify-harpp.sh" \
#       bash tools/lane.sh run <name> <script> ...
#
# TARGET RESOLUTION, in order:
#   1. $LANE_NOTIFY_HARPP_CONVERSATION  - an explicit conversation id
#   2. the conversation titled $LANE_NOTIFY_HARPP_TITLE (default "Lane landings")
#   3. otherwise SKIP with a log line - never invent a target, never fail the lane.
# NOTE: the harness cannot create the conversation. POST /api/v1/harpp/conversations is
# owner-authenticated and the bridge exposes no create route, so the owner creates it once.
#
# FLOOD CONTROL - the reason the toast was switched off before:
#   - LANE_NOTIFY is already off by default, and tools/lane.sh selftest never sets it, so the
#     ~16 fixture landings per suite cannot reach this script.
#   - defence in depth: selftest fixture names (st1, st8, st9c, ...) are refused here anyway.
#   - the commit lock in lane.sh already guarantees ONE notification per landing.
#
# A report channel must never be able to break the thing it reports on: this script swallows
# every error, is bounded by a timeout, and ALWAYS exits 0.
#
set -u

RUNS="$(cd "$(dirname "$0")/.." && pwd)/.ai/runs"
LOG="$RUNS/lane-notify-harpp.log"
mkdir -p "$RUNS" 2>/dev/null || true

note() { printf '%s  %s\n' "$(date -Is 2>/dev/null || echo '-')" "$1" >> "$LOG" 2>/dev/null || true; }

# ---- notify-send compatible argument parsing -------------------------------------------
urgency="normal"
positional=()
while [ $# -gt 0 ]; do
  case "$1" in
    -u) urgency="${2:-normal}"; shift 2 ;;
    -a) shift 2 ;;
    --) shift; while [ $# -gt 0 ]; do positional+=("$1"); shift; done ;;
    -*) shift ;;
    *) positional+=("$1"); shift ;;
  esac
done
title="${positional[0]:-lane landing}"
body="${positional[1]:-}"

# "lane <name>: <reason>" -> <name>
lane_name="$title"
lane_name="${lane_name#lane }"
lane_name="${lane_name%%:*}"

# ---- gates (must-refuse) ----------------------------------------------------------------
if [ "${LANE_NOTIFY_HARPP:-1}" = "0" ]; then
  note "SKIP $lane_name: LANE_NOTIFY_HARPP=0"
  exit 0
fi
case "$lane_name" in
  st[0-9]*|"" )
    note "SKIP $lane_name: selftest fixture or unnamed lane (flood guard)"
    exit 0 ;;
esac

# ---- resolve + post ---------------------------------------------------------------------
CONV_ID="${LANE_NOTIFY_HARPP_CONVERSATION:-}"
CONV_TITLE="${LANE_NOTIFY_HARPP_TITLE:-Lane landings}"
DRY_RUN="${LANE_NOTIFY_HARPP_DRY_RUN:-0}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"

# stdout and stderr both go to the log: lane.sh discards this call's output (`> /dev/null 2>&1`),
# so the log file is the only surviving record that a landing was reported. The pipeline makes
# `$?` tee's exit status - which is deliberate: the python below reports its own failures, and
# a report channel must never be able to fail the lane it reports on.
LANE_NAME="$lane_name" LANE_BODY="$body" LANE_URGENCY="$urgency" \
CONV_ID="$CONV_ID" CONV_TITLE="$CONV_TITLE" DRY_RUN="$DRY_RUN" ROOT="$ROOT" \
timeout 30 python3 - <<'PY' 2>&1 | tee -a "$LOG"
import os, sys, json

sys.path.insert(0, os.path.join(os.environ["ROOT"], "tools", "harpp-bridge"))
import harpp_client as hc

name = os.environ.get("LANE_NAME", "")
body = os.environ.get("LANE_BODY", "")
urgency = os.environ.get("LANE_URGENCY", "normal").lower()
conv_id = (os.environ.get("CONV_ID") or "").strip()
conv_title = os.environ.get("CONV_TITLE") or "Lane landings"
dry = os.environ.get("DRY_RUN") == "1"

# failure reasons get the loud prefix, routine landings stay informational
loud = urgency in ("critical",)
message_type = "BLOCKED" if loud else "INFO"

if not conv_id:
    try:
        listing = hc.api("GET", "/api/v1/harpp/bridge/conversations")
    except Exception as exc:
        print(f"{name}: SKIP cannot list conversations: {type(exc).__name__}: {exc}")
        sys.exit(0)
    convs = ((listing or {}).get("data") or {}).get("conversations") or []
    match = [c for c in convs if str(c.get("title", "")).strip().lower() == conv_title.strip().lower()]
    if not match:
        print(f"{name}: SKIP no conversation titled {conv_title!r} "
              f"({len(convs)} conversations checked) - the owner must create it once; "
              f"the harness cannot")
        sys.exit(0)
    conv_id = str(match[0]["id"])

text = body.strip() or "no status line"
if dry:
    print(f"{name}: DRY-RUN would post to conversation {conv_id} as {message_type}: {text[:160]!r}")
    sys.exit(0)

try:
    hc.harpp_notify(conversation_id=int(conv_id), message_type=message_type,
                    title=f"Lane {name}", body=text)
except Exception as exc:
    print(f"{name}: POST FAILED (lane unaffected): {type(exc).__name__}: {exc}")
    sys.exit(0)

print(f"{name}: posted landing report to conversation {conv_id} as {message_type}")
PY
exit 0
