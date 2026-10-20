#!/usr/bin/env bash
#
# tools/lane-policy.sh — runtime-enforced lane roles.
#
# Why this exists: "Sol is read-only", "the architect must not edit", and "no lane may
# push or merge" were conventions in prompts and instructions. A prompt is not a control.
# This file makes those rules executable:
#
#   role=architect|review|release-gate  ->  no edit/write tools
#   role=implement                      ->  edit/write allowed, but only inside --touches
#   all roles                           ->  git push/merge/rebase/history-rewrite blocked
#
# It also captures a per-file snapshot before the lane and compares it afterwards, so a
# read-only lane that edited a file is detected even when the file was already dirty.
#
# Source it, do not execute it:
#   . tools/lane-policy.sh
#   lane_policy_prepare review "path/to/reviewed/files"
#   ... run the lane ...
#   lane_policy_postcheck review "path/to/reviewed/files" "$RUNS/$name.policy.before"
#   lane_policy_cleanup
#
# `tools/lane-policy.sh selftest` proves both directions: allowed commands pass and
# forbidden ones are refused.

LANE_POLICY_VERSION="1"

lane_policy_roles() {
    printf '%s\n' architect implement review release-gate
}

lane_policy_validate() {
    local role="${1:-}"
    case "$role" in
        architect|implement|review|release-gate) return 0;;
        *) echo "lane-policy: invalid role '${role}' (expected: architect, implement, review, release-gate)" >&2; return 2;;
    esac
}

lane_policy_pi_flags() {
    local role="${1:-}"
    case "$role" in
        architect|review|release-gate)
            # Read-only at the tool registry: keep read/grep/glob/bash (git diff needs bash),
            # remove the mutating built-ins. The git guard below covers bash's git calls.
            printf '%s' "--exclude-tools edit,write"
            ;;
        implement)
            printf '%s' ""
            ;;
        *)
            return 2
            ;;
    esac
}

lane_policy_snapshot() {
    # Per-file content hashes for every path git reports. Portable enough for bash 4+;
    # `-z` keeps spaces and newlines in filenames intact.
    local out="${1:-}"
    [ -n "$out" ] || { echo "lane_policy_snapshot: output path required" >&2; return 2; }
    if ! git rev-parse --git-dir > /dev/null 2>&1; then
        printf '%s\n' "BASIS_UNAVAILABLE" > "$out"
        return 0
    fi
    : > "$out"
    while IFS= read -r -d '' entry; do
        local path hash
        path="${entry:3}"
        if [ -e "$path" ] && [ ! -d "$path" ]; then
            hash=$(git hash-object -- "$path" 2>/dev/null || echo "UNREADABLE")
        elif [ -d "$path" ]; then
            hash="DIR"
        else
            hash="DELETED"
        fi
        printf '%s\t%s\n' "$path" "$hash" >> "$out"
    done < <(git status --porcelain=v1 -z 2>/dev/null)
    sort -o "$out" "$out" 2>/dev/null || true
}

lane_policy_prepare() {
    local role="${1:-}" touches="${2:-}"
    lane_policy_validate "$role" || return 2
    # A second prepare in the same shell must not resolve `git` through the first guard:
    # that made the wrapper exec itself and hang forever. Remove the previous guard
    # before asking PATH for the real binary.
    if [ -n "${LANE_POLICY_DIR:-}" ]; then
        lane_policy_cleanup
    fi
    # Bash caches executable paths. Without this, `command -v git` can return a guard
    # directory that has already been removed, and the next git call fails or loops.
    hash -r 2>/dev/null || true
    LANE_POLICY_ROLE="$role"
    LANE_POLICY_TOUCHES="$touches"
    LANE_POLICY_PI_FLAGS="$(lane_policy_pi_flags "$role")"
    export LANE_POLICY_ROLE LANE_POLICY_TOUCHES LANE_POLICY_PI_FLAGS
    export GIT_TERMINAL_PROMPT=0

    LANE_POLICY_DIR="$(mktemp -d "${TMPDIR:-/tmp}/lane-policy-XXXXXX")" || {
        echo "lane-policy: could not create guard directory" >&2
        return 1
    }
    export LANE_POLICY_DIR
    LANE_REAL_GIT="$(command -v git 2>/dev/null || true)"
    export LANE_REAL_GIT

    if [ -n "$LANE_REAL_GIT" ]; then
        cat > "$LANE_POLICY_DIR/git" <<'GUARD'
#!/usr/bin/env python3
import os
import sys

real = os.environ.get("LANE_REAL_GIT") or "/usr/bin/git"
args = sys.argv[1:]

# Skip git global options so `git -C repo push` is still recognised.
i = 0
while i < len(args):
    a = args[i]
    if a in ("-C", "-c", "--git-dir", "--work-tree", "--namespace", "--exec-path", "--super-prefix") and i + 1 < len(args):
        i += 2
        continue
    if a.startswith("-"):
        i += 1
        continue
    break

sub = args[i] if i < len(args) else ""
blocked = sub in {"push", "merge", "rebase", "filter-branch", "filter-repo"}
if sub == "reset" and "--hard" in args:
    blocked = True
if sub == "clean" and any(a.startswith("-") and "f" in a for a in args):
    blocked = True
if sub == "remote" and any(a in {"add", "remove", "rm", "set-url", "rename"} for a in args):
    blocked = True
if sub in {"tag", "branch"} and any(a in {"-d", "-D", "--delete"} for a in args):
    blocked = True
if blocked:
    print("lane-policy: BLOCKED git " + " ".join(args), file=sys.stderr)
    sys.exit(97)
os.execv(real, [real] + args)
GUARD
        chmod +x "$LANE_POLICY_DIR/git"
        PATH="$LANE_POLICY_DIR:$PATH"
        export PATH
        hash -r 2>/dev/null || true
        echo "lane-policy: role=$role tools=$([ -n "$LANE_POLICY_PI_FLAGS" ] && echo "$LANE_POLICY_PI_FLAGS" || echo full) touches=${touches:-<none>} git-guard=on"
    else
        echo "lane-policy: role=$role (git not found; git guard unavailable)" >&2
    fi
    return 0
}

lane_policy_cleanup() {
    if [ -n "${LANE_POLICY_DIR:-}" ] && [ -d "${LANE_POLICY_DIR:-}" ]; then
        rm -rf "$LANE_POLICY_DIR"
    fi
    LANE_POLICY_DIR=""
    hash -r 2>/dev/null || true
}

lane_policy_touch_allowed() {
    local path="$1" touches="$2" pattern
    [ -z "$touches" ] && return 0
    IFS=',' read -r -a _lane_patterns <<< "$touches"
    for pattern in "${_lane_patterns[@]}"; do
        [ -n "$pattern" ] || continue
        # Trim surrounding whitespace.
        pattern="${pattern#"${pattern%%[![:space:]]*}"}"
        pattern="${pattern%"${pattern##*[![:space:]]}"}"
        if [[ "$path" == $pattern || "$path" == "$pattern"/* ]]; then
            return 0
        fi
    done
    return 1
}

lane_policy_postcheck() {
    local role="${1:-}" touches="${2:-}" before="${3:-}"
    lane_policy_validate "$role" || return 2
    if ! git rev-parse --git-dir > /dev/null 2>&1; then
        echo "lane-policy: postcheck skipped (not a git repository)"
        return 0
    fi
    [ -n "$before" ] && [ -f "$before" ] || {
        echo "lane-policy: postcheck skipped (no before snapshot)" >&2
        return 0
    }
    local after
    after="$(mktemp "${TMPDIR:-/tmp}/lane-policy-after-XXXXXX")"
    lane_policy_snapshot "$after"

    if grep -q '^BASIS_UNAVAILABLE$' "$before" 2>/dev/null; then
        rm -f "$after"
        return 0
    fi

    # A path whose hash changed appears in both files with different hashes, so compare
    # the whole sorted snapshot first; `comm -13` only catches additions.
    local added removed modified
    added="$(comm -13 "$before" "$after" 2>/dev/null | cut -f1 || true)"
    removed="$(comm -23 "$before" "$after" 2>/dev/null | cut -f1 || true)"
    modified=""
    if [ "$(wc -l < "$before")" -eq "$(wc -l < "$after")" ] && ! diff -q "$before" "$after" > /dev/null 2>&1; then
        modified="$(join -t $'\t' -j 1 "$before" "$after" 2>/dev/null | awk -F'\t' '$2 != $3 {print $1}' || true)"
    fi

    local violations=""
    local path
    if [ "$role" = "implement" ]; then
        if [ -n "$touches" ]; then
            while IFS= read -r path; do
                [ -n "$path" ] || continue
                if ! lane_policy_touch_allowed "$path" "$touches"; then
                    violations="${violations}${violations:+, }$path"
                fi
            done <<< "$(printf '%s\n%s\n%s\n' "$added" "$modified" "$removed" | sed '/^$/d' | sort -u)"
        fi
    else
        # Read-only roles: any new, removed, or modified path is a violation.
        violations="$(printf '%s\n%s\n%s\n' "$added" "$modified" "$removed" | sed '/^$/d' | sort -u | paste -sd ',' -)"
    fi

    rm -f "$after"
    if [ -n "$violations" ]; then
        echo "POLICY_VIOLATION role=$role touches=${touches:-<none>} paths=$violations" >&2
        return 97
    fi
    echo "lane-policy: postcheck OK role=$role touches=${touches:-<none>}"
    return 0
}

lane_policy_selftest() {
    local pass=0 fail=0
    ok() { echo "   PASS  $1"; pass=$((pass + 1)); }
    bad() { echo "   FAIL  $1"; fail=$((fail + 1)); }

    local tmp snapdir oldpwd rc
    tmp="$(mktemp -d "${TMPDIR:-/tmp}/lane-policy-selftest-XXXXXX")"
    snapdir="$(mktemp -d "${TMPDIR:-/tmp}/lane-policy-snap-XXXXXX")"
    oldpwd="$PWD"
    cd "$tmp" || { rm -rf "$tmp" "$snapdir"; return 1; }

    git init -q . || { cd "$oldpwd"; rm -rf "$tmp"; return 1; }
    git config user.email "lane-policy@example.invalid"
    git config user.name "lane-policy selftest"
    printf 'allowed\n' > allowed.txt
    printf 'outside\n' > outside.txt
    git add allowed.txt outside.txt
    git commit -qm init

    lane_policy_prepare implement "allowed.txt" > /dev/null || { cd "$oldpwd"; rm -rf "$tmp"; return 1; }
    git status > /dev/null && ok "git status is allowed" || bad "git status was blocked"

    git push > /dev/null 2>&1; rc=$?
    [ "$rc" -eq 97 ] && ok "git push is blocked (rc=97)" || bad "git push was not blocked (rc=$rc)"
    git merge other > /dev/null 2>&1; rc=$?
    [ "$rc" -eq 97 ] && ok "git merge is blocked (rc=97)" || bad "git merge was not blocked (rc=$rc)"
    git reset --hard > /dev/null 2>&1; rc=$?
    [ "$rc" -eq 97 ] && ok "git reset --hard is blocked (rc=97)" || bad "git reset --hard was not blocked (rc=$rc)"

    lane_policy_snapshot "$snapdir/before" > /dev/null
    printf 'changed\n' > allowed.txt
    lane_policy_postcheck implement "allowed.txt" "$snapdir/before" > /dev/null 2>&1
    [ $? -eq 0 ] && ok "implement role allows a touched path" || bad "implement role rejected a touched path"

    lane_policy_snapshot "$snapdir/before2" > /dev/null
    printf 'changed\n' > outside.txt
    lane_policy_postcheck implement "allowed.txt" "$snapdir/before2" > /dev/null 2>&1
    [ $? -eq 97 ] && ok "implement role rejects an untouched path" || bad "implement role accepted an untouched path"

    lane_policy_prepare review "" > /dev/null || { cd "$oldpwd"; rm -rf "$tmp" "$snapdir"; return 1; }
    lane_policy_snapshot "$snapdir/before3" > /dev/null
    printf 'sneaky\n' > allowed.txt
    lane_policy_postcheck review "" "$snapdir/before3" > /dev/null 2>&1
    [ $? -eq 97 ] && ok "review role rejects an edit" || bad "review role accepted an edit"

    lane_policy_cleanup
    cd "$oldpwd"
    rm -rf "$tmp" "$snapdir"
    echo "lane-policy selftest: $pass passed, $fail failed"
    [ "$fail" -eq 0 ]
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
    case "${1:-}" in
        selftest) lane_policy_selftest;;
        prepare) shift; lane_policy_prepare "$@";;
        postcheck) shift; lane_policy_postcheck "$@";;
        cleanup) lane_policy_cleanup;;
        validate) shift; lane_policy_validate "$@";;
        flags) shift; lane_policy_pi_flags "$@";;
        *)
            echo "usage: tools/lane-policy.sh <selftest|prepare|postcheck|cleanup|validate|flags> ..." >&2
            exit 2
            ;;
    esac
fi
