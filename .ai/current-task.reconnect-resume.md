task: User-space Ikabud development-session crash-recovery pilot for Ubuntu

objective: >
  Implement a fail-closed, unattended recovery path that, after firmware reboot and desktop auto-login,
  restores the recorded VS Code workspace, exact supported chat-agent session, and one global Pi harness
  terminal with its repository, cwd, virtualenv, Git branch, argv, and active .ai contract. Demonstrate that
  the file-backed Workbench state DevelopmentLifecycle::ARCHITECTURE_DECISION_REQUIRED survives power loss
  and resumes effectively exactly once. Session-resume reliability is the release gate.

scope:
  allowed:
    - Add versioned scripts, schemas, tests, templates, and operator documentation only under
      `tools/session-resume/`; implementation begins with a non-mutating compatibility preflight.
    - Provide `capture.sh`, `session-guard.sh`, `resume.sh`, an XDG desktop-autostart template, and, only when
      preflight proves it superior, a systemd user-unit template.
    - Install user-owned configuration under `${XDG_CONFIG_HOME:-$HOME/.config}` and machine-local 0700 state
      under `${XDG_STATE_HOME:-$HOME/.local/state}/ikabud-resume/`.
    - Store `last-session.json`, `unclean.marker`, immutable generation/sequence snapshots, a stable lock,
      generation-scoped acknowledgements, commit metadata, and structured logs outside Git.
    - Snapshot schema/adaptor versions, generation/sequence, boot ID, timestamps, approved realpaths,
      workspace/profile, exact chat session identity, cwd, branch/HEAD, venv, resolved global Pi executable
      and argv, active `.ai/current-task.md` path/hash, and Workbench `task.json` path/hash/task ID/state.
    - Treat Workbench `task.json.state`, validated against `DevelopmentLifecycle::STATES`, as the only canonical
      lifecycle state. Treat `.ai/current-task.md` (a fenced YAML block plus Markdown) as the active contract,
      captured by path/hash but never parsed as the canonical state source.
  prohibited:
    - Root, sudo, firmware/display-manager/bootloader changes, system-wide systemd, `/etc` changes, or claims
      that user-space tooling establishes AC-power recovery or desktop auto-login.
    - Application, Kernel, module, route, capability, schema, migration, tenant/control database, or MySQL
      changes; no database access, cross-module access, or cross-tenant state. Any future application exposure
      requires a versioned capability contract before routes.
    - MySQL 8 assumptions; Ikabud remains MySQL 5.7 compatible and untouched.
    - Treating repository executable `ikabud` as Pi; it is the PHP Kernel CLI. Only a separately resolved and
      verified global `pi` executable may be replayed.
    - Git checkout/reset/clean/stash, branch or worktree mutation, unrelated process termination, GUI-coordinate
      automation, arbitrary shell replay, `eval`, or replacing a newer generation.
    - Persisting credentials, tokens, secrets, secret-bearing prompts, transcripts, extension storage, or
      machine-local runtime state in Git.
    - Claiming exact chat restoration from opening a generic panel or using an unverified extension command.
    - Parsing `.ai/current-task.md` as canonical lifecycle state; it is captured by path/hash only and never
      parsed.

constraints:
  - Preflight must record desktop/session type, graphical trigger and clean-stop behavior, firmware AC recovery,
    auto-login, network target, `code` profile/workspace behavior, chat extension ID/version/exact-session API,
    integrated-terminal API, global Pi realpath/identity/resume syntax, venv, and Workbench artifact resolver.
    These environment capabilities are currently UNVERIFIED; any missing mandatory capability produces a
    durable `BLOCKED` report and stops implementation without approximate fallback.
  - Persist argv as a JSON array; execute without `eval` through an explicit executable/option allowlist.
    Reject secret-bearing data before publication.
  - State files are 0600. Writes use same-filesystem temporary files, schema validation, file fsync, atomic
    rename, and parent-directory fsync where supported.
  - `session-guard.sh` exclusively arms and disarms `unclean.marker`. `capture.sh` may update its heartbeat only
    while the same-generation marker exists and must never create or re-arm an absent marker. Graceful disarm
    must stop and join capture, acquire the stable lock, unlink the marker, and fsync the directory.
  - On every login (clean or crash), the XDG autostart entry first launches `resume.sh` under the stable lock,
    reading any pre-existing `unclean.marker` read-only and deciding replay by boot ID before any arming occurs.
    `session-guard.sh` and `capture.sh` must not arm or write until `resume.sh` has consumed or declined the
    prior marker. After that decision, the autostart entry launches `session-guard.sh` to arm a fresh generation
    marker and starts `capture.sh` for the new session, so a clean login is never left unprotected and a crash
    marker is never overwritten before inspection.
  - Capture polls at most every 5 seconds, persists state changes within 10 seconds, and commits heartbeat state
    at least every 15 seconds. One capture iteration is the sole heartbeat writer.
  - Cross-file rename is not falsely treated as atomic. Each iteration writes an immutable sequence snapshot,
    fsyncs it, then atomically publishes `unclean.marker` as the commit record containing its generation,
    sequence, digest, boot ID, and heartbeat. `last-session.json` is atomically refreshed afterward. Recovery
    follows only a marker-referenced, digest-valid immutable snapshot; an interrupted publication uses the
    prior committed sequence. Permitted heartbeat skew is at most 45 seconds.
  - Automatic recovery requires a marker from a different boot ID and a matching generation/sequence snapshot.
    Same-boot, malformed, stale-pair, missing, symlink-swapped, path-escaped, or digest-invalid state fails
    closed. Current wall-clock age is not a rejection criterion after power-off.
  - Approved repository, workspace, cwd, venv, Pi, contract, and Workbench paths must resolve beneath recorded
    realpaths. Critical hashes and executable identity must match.
  - Branch mismatch records `ARCHITECTURE_DECISION_REQUIRED`, preserves the dirty worktree, and performs no
    checkout or mutation.
  - Initial network waiting is bounded to 60 seconds. Local restoration may continue offline; exact chat
    reconnection uses bounded backoff for at most 10 minutes and cannot complete the generation without a
    supported identity probe.
  - Resume holds the stable lock and proceeds idempotently: validate; wait for network; restore/acknowledge VS
    Code; restore/acknowledge exact chat; launch/acknowledge one integrated Pi terminal; write completion;
    create the resumed generation's initial snapshot and marker; atomically rename the new marker over the old
    marker. There must be no marker-absent re-arm window.
  - Acknowledgements are atomic `acks/<generation>/{vscode,chat,pi,complete}.json` files bound to a nonce.
    Adapters acknowledge only after identity probes. Retries must probe existing nonce/session/process intent
    before launching, so interruption between launch and acknowledgement cannot duplicate chat or Pi.
  - Pi acknowledgement verifies PID, executable/argv, cwd, branch, venv Python, contract path/hash, Workbench
    task path/hash/state, and one integrated terminal. Chat acknowledgement requires the exact recorded session
    ID; VS Code acknowledgement requires the recorded workspace/profile.

acceptance:
  - `last-session.json` and its committed immutable snapshot are schema-valid, atomic, permission-restricted,
    secret-free, generation-bound, and sufficient to restore every recorded identity and environment field.
  - Graceful logout/shutdown removes the marker only after the capture writer has stopped. Abrupt termination
    retains a recoverable marker. Capture cannot recreate a disarmed marker.
  - Clean login performs no replay. Crash login performs one effective replay despite concurrent triggers,
    interrupted stages, or restart between completion and generation rollover.
  - A clean login arms a fresh generation marker and starts capture before returning control to the user.
  - Recovery restores the recorded workspace, exact supported chat session, and one integrated terminal running
    the verified global Pi harness—not `php ikabud`—with the expected cwd, branch, venv, argv, contract hash,
    and canonical `ARCHITECTURE_DECISION_REQUIRED` state.
  - Partial failure retains recoverability, records the failed stage, and retries without duplicating completed
    chat or Pi sessions. The old generation is consumed only by an atomic marker replacement after all
    acknowledgements validate.
  - Invalid/tampered state, branch mismatch, missing venv, wrong Pi identity, unsupported exact chat restore,
    command tampering, or path/symlink substitution fails closed without modifying Git, application files, or
    application data.
  e2e_acceptance:
    - Operator preconditions are a manual/physical or separately approved privileged power cut, preconfigured
      firmware AC recovery, desktop auto-login, unlocked required keyring/authentication, and reachable network.
      User-space tooling verifies and records these conditions but does not establish or execute them.
    - From the autostart timestamp in a graphical session where the network target is reachable, exact restoration
      and completion acknowledgement must occur within 180 seconds. The 180-second budget applies only under the
      reachable-network precondition; the 10-minute chat backoff is a fail-closed ceiling, not a target.
    - Persist an active Workbench task whose `task.json.state` is `ARCHITECTURE_DECISION_REQUIRED` via the
      existing file-backed Workbench control-plane API (`DevelopmentTaskRepository` → `task.json`). The "no
      database access / no application changes" prohibition constrains the tooling and tests, not persisting
      this pre-existing Workbench artifact for the scenario. Cut power without cleanup; restore power; and prove
      one workspace, the same chat session, one Pi process, expected cwd/branch/venv/argv, and unchanged
      contract/task hashes.
    - Pass three consecutive crash-resume cycles, then prove one graceful reboot produces no replay.

verification:
  - Archive preflight results before implementation. Unsupported firmware recovery, auto-login, graphical
    trigger, clean-stop delivery, exact chat-session API, terminal API, or global Pi resume/probe behavior is a
    terminal `BLOCKED` result for this pilot.
  - Unit-test schemas, permissions, secret rejection, safe argv, locking, boot/generation/sequence matching,
    45-second skew, crash-consistent commit selection, path/symlink rejection, bounded retries,
    acknowledgements, disarm coordination, and atomic generation replacement.
  - Integration-test decision-state capture, graceful stop, forced kill at every commit/swap boundary,
    stale/corrupt state, offline startup, concurrent triggers, launch-before-ack interruption, partial adapter
    failures, dirty-worktree branch mismatch, wrong Pi identity, and exact restoration.
  - Retain timestamped e2e evidence: boot IDs, autostart/completion times, logs, snapshots, markers,
    acknowledgements, process counts, workspace/profile, exact chat ID, `pwd`, Python executable, Git branch,
    Pi executable/argv, and `.ai`/Workbench paths and hashes.
  - Confirm all verification performs no database query or migration and changes no application route,
    capability, module, tenant/control state, Git branch, or worktree content outside `tools/session-resume/`.

risk:
  - Firmware AC recovery and desktop auto-login are privileged external prerequisites.
  - VS Code, chat, Pi, or terminal APIs may not support stable exact restoration or identity probes; versions
    must be pinned and unsupported environments blocked.
  - Desktop shutdown callbacks, keyring unlock, authentication, and graphical-session timing may prevent
    unattended recovery; conservative crash classification and durable evidence are required.
  - Replaying a harness command can duplicate external effects; only verified, secret-free, allowlisted commands
    with adapter-level identity/idempotency probes are eligible.
  - POSIX does not provide atomic multi-file replacement; immutable sequence records plus an atomically published
    marker commit record are required to eliminate torn-pair ambiguity.
  - `BLOCKED_DECISION_REQUIRED` is a misnomer for this codebase; the canonical state is
    `ARCHITECTURE_DECISION_REQUIRED` (present in `DevelopmentLifecycle::STATES`). Recorded here to avoid
    downstream confusion.

status: READY_FOR_IMPLEMENTATION