# Ikabud user-session crash recovery

## Purpose and status

This is a fail-closed, user-owned pilot for recording an Ikabud development session and, after a different-boot login, restoring its VS Code workspace/profile, exact supported chat session, and one integrated terminal running the verified global Pi harness. It records the active contract by path/hash and treats the file-backed Workbench `task.json.state` as the canonical lifecycle state.

The tooling does **not** configure firmware power recovery, desktop auto-login, authentication/keyring unlock, or the network. It does not install system services, mutate Git, parse the contract as lifecycle state, query a database, or modify application data. Exact restoration is unavailable unless all preflight capabilities and identity-probing adapters are supported.

The archived preflight for this host is currently `BLOCKED`: clean-stop delivery and firmware AC recovery are unverified; auto-login is configured for another account; the installed chat extension has no published exact-session API; and no integrated-terminal adapter is installed. Do not enable unattended recovery until a new preflight report is `READY` and the adapters are approved.

## Prerequisites and preflight

Required commands are Bash, Python 3, `flock`, `realpath`, `jq`, Git, and a separately installed global `pi`. Runtime prerequisites are:

- a graphical XDG-autostart session with proven synchronous clean-stop delivery;
- externally configured firmware AC recovery and desktop auto-login;
- an unlocked keyring/authentication state and, for chat reconnection, network access;
- VS Code workspace/profile support;
- a pinned chat extension with exact-session restore and identity probes;
- an integrated-terminal API capable of proving one terminal and its process identity;
- a verified global Pi executable and supported resume argv;
- a virtualenv, active `.ai/current-task.md`, and file-backed Workbench `task.json`.

Preflight invokes each approved adapter through a pinned file descriptor with `capabilities --json` and `preflight-probe --json`. Exact identity-field sets are required for workspace/profile, chat extension/version/session, and integrated-terminal/Pi identity; command-name regexes are never accepted as capability proof.

Run the non-mutating preflight before installation:

```bash
tools/session-resume/preflight.sh
```

It writes timestamped mode-`0600` JSON and Markdown reports below `${XDG_STATE_HOME:-$HOME/.local/state}/ikabud-resume/preflight/`. A `BLOCKED` result is terminal for the pilot: fix or explicitly provide the missing external capability, then rerun preflight. Generic chat panels, external terminals, and guessed APIs are not substitutes.

## Configuration and installation

Capture requires these exact identities:

```bash
export IKABUD_RESUME_CHAT_EXTENSION_ID='publisher.extension'
export IKABUD_RESUME_CHAT_EXTENSION_VERSION='1.2.3'
export IKABUD_RESUME_CHAT_SESSION_ID='exact-session-id'
export IKABUD_RESUME_WORKBENCH_TASK='/absolute/repository/path/to/task.json'
```

Optional explicit inputs are `IKABUD_RESUME_WORKSPACE`, `IKABUD_RESUME_CWD`, `IKABUD_RESUME_VENV`, `IKABUD_RESUME_VENV_PYTHON`, `IKABUD_RESUME_VSCODE_PROFILE`, `IKABUD_RESUME_PI_EXECUTABLE`, `IKABUD_RESUME_PI_VERSION`, `IKABUD_RESUME_PI_IDENTITY`, `IKABUD_RESUME_PI_ARGV`, and `IKABUD_RESUME_CONTRACT`. `IKABUD_RESUME_PI_ARGV` must be a JSON array beginning with the resolved Pi executable. Only exact-session/resume options accepted by `state.py` are allowed.

After preflight is `READY`, edit a private copy of the XDG template and replace `@REPO_ROOT@` with this repository's absolute realpath. Install it as the current user only:

```bash
repo_root="$(pwd -P)"
config_dir="${XDG_CONFIG_HOME:-$HOME/.config}/autostart"
mkdir -p -- "$config_dir"
sed "s|@REPO_ROOT@|$repo_root|g" \
  tools/session-resume/ikabud-resume.desktop.example \
  >"$config_dir/ikabud-resume.desktop"
chmod 0600 "$config_dir/ikabud-resume.desktop"
```

Inspect the generated file before login testing. Do not use `sudo`. The template runs `resume.sh` before any new arm, then arms if no replay occurred and runs `capture.sh`. No systemd user unit is supplied because preflight did not establish one as superior.

## Operation

Manual commands are useful for controlled adapter validation; the autostart chain is the normal operator path.

```bash
# Arm a new generation; fails if a marker already exists.
tools/session-resume/session-guard.sh arm

# Run the sole heartbeat writer (normally started by autostart).
tools/session-resume/capture.sh

# Gracefully stop/join capture, then remove the marker.
tools/session-resume/session-guard.sh disarm

# Inspect/recover an old marker before any arm on login.
tools/session-resume/resume.sh
```

`IKABUD_RESUME_CAPTURE_INTERVAL` may be `1` through `5` seconds. Production defaults to 5. `IKABUD_RESUME_DRY_RUN=1` validates recovery and writes acknowledgements without touching VS Code, chat, or Pi; it is intended for controlled tests, not proof of exact restoration. Resume exit `10` means replay completed and atomic rollover already armed the current boot. Exit `0` means no replay; any other nonzero status is fail-closed.

Graceful disarm verifies the capture PID, sends `TERM`, waits for capture's lock-protected PID-file cleanup, then takes the stable lock and unlinks the marker. A PID mismatch or join timeout retains the marker.

## Runtime state layout

The production root is `${XDG_STATE_HOME:-$HOME/.local/state}/ikabud-resume/` (mode `0700`). `IKABUD_RESUME_STATE_DIR` is a test override and should not be used for production installation.

```text
ikabud-resume/
├── stable.lock                         # stable flock inode, 0600
├── unclean.marker                      # atomic commit record, 0600
├── last-session.json                   # convenience projection, 0600
├── capture.pid                         # live writer handshake, 0600
├── snapshots/<generation>/<sequence>.json
├── acks/<generation>/intent.json
├── acks/<generation>/{vscode,chat,pi,complete}.json
├── acks/<generation>/failure.json      # present after a failed stage
└── logs/                               # reserved user-private evidence directory
```

Generation directories are `0700`; files are `0600`. Immutable snapshots use zero-padded monotonic sequence names. The marker—not `last-session.json`—selects the committed snapshot and records its digest, generation, sequence, boot ID, and heartbeat.

## Fail-closed implementation invariants

- State-root, generation-directory, marker, snapshot, and approved-path symlinks/path escapes are rejected.
- Publication writes and fsyncs an immutable snapshot, atomically renames the marker commit, then refreshes `last-session.json`. Recovery ignores an uncommitted newer snapshot/projection.
- Replay requires a marker from another boot, an exact generation/sequence pair, a valid digest/schema, and heartbeat timestamps within 45 seconds of each other. Wall-clock age after power-off is not a rejection condition.
- Capture updates only an existing same-generation/same-boot marker while holding `stable.lock`; it cannot recreate a disarmed marker.
- Resume holds the same lock through decision, staged acknowledgements, completion, and generation rollover. Rollover atomically replaces the marker and never intentionally creates a marker-absent window.
- Secret-like keys/values and private-key material are rejected before publication. Pi argv stays a JSON array and is never evaluated by a shell.
- Repository/workspace/cwd/contract/Workbench realpaths, critical hashes, Pi identity/hash, virtualenv Python, Git branch/HEAD, task ID, and canonical task state must still match. A Git mismatch reports `ARCHITECTURE_DECISION_REQUIRED` and does not mutate the worktree.
- Recovery intent has one UUID nonce. Atomic acknowledgements are generation-, nonce-, stage-, and snapshot-bound; conflicting replacement is rejected. Existing acknowledgements require a fresh adapter probe before they suppress launch.
- Network waiting is bounded to 60 seconds and local restore may continue offline. Exact chat probing uses bounded retry for at most 10 minutes and cannot acknowledge an unproven identity.

## Adapter interface

Non-dry recovery requires absolute, executable, non-symlink adapter paths whose resolved basenames are fixed:

```text
IKABUD_RESUME_VSCODE_ADAPTER      -> ikabud-resume-vscode-adapter
IKABUD_RESUME_CHAT_ADAPTER        -> ikabud-resume-chat-adapter
IKABUD_RESUME_PI_TERMINAL_ADAPTER -> ikabud-resume-pi-terminal-adapter
```

`lib.sh` pins the adapter and snapshot inodes, revalidates their configured names, and invokes them through inherited descriptors, without `eval`, with this argv contract (the snapshot argument may be a `/proc/<pid>/fd/<n>` absolute pinned path). It also exports the non-secret `IKABUD_RESUME_ADAPTER_STAGE` value (`vscode`, `chat`, or `pi`):

```text
<adapter> probe|restore --snapshot <ABSOLUTE_NON_SYMLINK_SNAPSHOT> --nonce <UUID>
```

`probe` exits zero only after proving the recorded identity. `restore` must be idempotent for the nonce and must first probe existing session/process intent so interruption between launch and acknowledgement cannot duplicate chat or Pi. The VS Code adapter proves workspace and profile; chat proves the exact session ID; Pi proves exactly one integrated terminal plus PID, executable/hash/argv, cwd, branch/HEAD, virtualenv Python, contract path/hash, and Workbench path/hash/task ID/state. Adapters never write acknowledgement files themselves; `resume.sh` writes them only after a successful probe.

## Tests

Run the self-contained suite from any repository working directory:

```bash
tools/session-resume/tests/run-tests.sh
```

The runner prints one result per case and exits nonzero on any failure. Every case gets a fresh `mktemp` state root through `IKABUD_RESUME_STATE_DIR`, valid synthetic boot UUIDs, a one-second capture interval, and dry-run recovery where applicable. Tests never use the real XDG state directory and never launch VS Code, chat, or Pi. They cover schema/secrets/permissions, safe argv and pinned adapters, lock/concurrent triggers, exact 45-second skew, crash commit selection and forced publication interruptions, root/generation/approved-path symlink substitution, immutable acknowledgement/intent tampering, PID start-time/argv identity and startup races, launch-before-ack idempotence, partial retry, durable branch-mismatch ADR evidence, wrong Pi identity/hash, bounded offline/chat behavior, adapter-backed identity probes, preflight interfaces, and atomic rollover.

## Deferred physical power-cut acceptance

Unit tests do not establish AC-power recovery or unattended GUI restoration. Perform this acceptance only after preflight is `READY`, adapters are pinned and independently approved, and a manual/physical or separately approved privileged procedure is authorized:

1. Archive the current preflight report and record firmware AC-recovery, current-user auto-login, clean-stop delivery, keyring/authentication readiness, and network target.
2. Through the existing file-backed Workbench control-plane API, persist a task whose `task.json.state` is `ARCHITECTURE_DECISION_REQUIRED`; record task/contract hashes. Do not use this tooling to edit it.
3. Arm and capture. Archive boot ID, generation/sequence marker, committed snapshot, workspace/profile, exact chat ID, Pi executable/hash/argv, `pwd`, branch/HEAD, virtualenv Python, contract/task paths and hashes, and process/terminal counts.
4. Cut power physically without cleanup. Restore power and let firmware recovery plus desktop auto-login trigger XDG autostart. Do not simulate success by calling generic GUI commands.
5. With the network target reachable, verify completion within 180 seconds: one workspace/profile, the same chat ID, exactly one integrated Pi terminal/process, all recorded environment identities, unchanged hashes, and nonce-bound acknowledgements.
6. Repeat three consecutive crash-resume cycles, retaining timestamped evidence for each. Then perform one graceful reboot and prove capture joined, the marker was removed, and no replay occurred.

The 10-minute chat retry is a fail-closed ceiling, not the 180-second acceptance target. Until this procedure passes, unattended physical recovery remains an external, deferred gap.
