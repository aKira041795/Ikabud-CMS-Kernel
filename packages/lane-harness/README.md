# lane-harness

A governed way to hand implementation work to AI coding models from VS Code, where **the harness
verifies the outcome instead of trusting the model's own report**.

It is a small set of bash tools. No server, no daemon, no database, no telemetry, and **no API keys
of any kind are included**.

```
you (VS Code)
   │  write a brief + an acceptance command
   ▼
tools/lane.sh run <name> <lane-script> \
      --acceptance="<command that FAILS right now>" \
      --pass-looks-like="<what PASS looks like>"
   │  refuses to dispatch unless that command genuinely fails first
   ▼
tools/lane-model.sh          a model chain with budget-aware caps and fallback
   │  runs pi --print --model <model> ...
   ▼
the model edits the repo, runs tests, reports
   │
   ▼
tools/lane.sh re-runs the SAME acceptance command
   │
   ▼
VERIFIED  |  NOT VERIFIED  |  EXECUTION ONLY
```

## Why the acceptance gate exists

Handing work to a model and reading its summary is not verification: a model can report success
while having achieved nothing, and a model can report failure while having fixed the thing. This
harness was built after repeatedly paying for both mistakes, and its rules come from measurements:

- **A model's own "PASS" is not evidence.** A lane once returned `status: PASS` with its own 7/7
  self-written tests passing and still contained a silent behaviour regression.
- **The exit status decides success; log content only CLASSIFIES a failure.** A successful run whose
  own log contained the words "rate limit" was announced as `CRASHED on quota` by a call that
  returned 0.
- **A guard that cannot fail is worse than no guard**, because it is trusted. Every check here has a
  must-allow and a must-refuse direction, and the refusals are demonstrated.
- **The criterion is usually the defect.** Six separate times, a "failing test" turned out to be an
  unsatisfiable or mis-aimed acceptance criterion rather than broken code. The gate refuses a
  criterion that already passes, for exactly this reason.

The full reasoning, with the measurements, is in `docs/04-DOCTRINE.md`.

## What is in the package

| path | what |
|---|---|
| `tools/lane.sh` | dispatch, the pre-dispatch acceptance gate, the landing record, `status`/`list`/`pending`/`ack`, and a **29-case selftest** |
| `tools/lane-platform.sh` | the two OS primitives (pty, detach) with fallbacks that can be forced, so both branches are testable |
| `tools/lane-model.sh` | one model chain with budget-aware per-attempt caps and unavailability fallback |
| `tools/lane-watch.sh` | a one-shot watcher that EXITS on a landing, which is what makes the editor/agent notify you |
| `tools/model-chain.txt` | the ordered model chain — edit this for your providers |
| `tools/model-unavailable.patterns` | the one signature list shared by every consumer |
| `tools/*-selftest.sh`, `tools/harness-*-probe.sh` | the harness's own verification suite (see below) |
| `examples/lane-example.sh` | a short, copyable lane script |
| `.vscode/`, `.devcontainer/` | editor tasks and a container definition for Windows users |
| `docs/` | start here, Windows/WSL, VS Code integration, providers and keys, doctrine, troubleshooting |
| `extras/chatgpt-advisor/` | **optional** — consult your own ChatGPT subscription as a second opinion |

## Install

```bash
tar xzf lane-harness-0.1.0.tar.gz
cd lane-harness-0.1.0
bash preflight.sh --check-only          # see what your machine is missing, first
bash install.sh /path/to/your/repo      # copies tools/, adds .ai/ to .gitignore, writes .vscode/
```

**On Windows, read `WINDOWS-QUICKSTART.txt` first.** `install.sh` refuses to run from Git Bash
unless you pass `--allow-degraded`, because WSL2 is the supported path and a silent downgrade is the
thing this harness exists to prevent.

Then, in that repo:

```bash
bash tools/preflight.sh 2>/dev/null || true
bash tools/lane.sh selftest              # 29 passed, 0 failed
bash tools/lane-platform-selftest.sh     # 22 passed, 0 failed
```

If those two numbers come out, your install works — including the parts that only fail on your
machine's platform.

## What you must supply

1. **A model CLI.** The default is [`pi`](https://www.npmjs.com/package/@earendil-works/pi-coding-agent)
   (`pi --print --approve --model <model> <prompt>`). Anything with that shape works: set
   `LANE_MODEL_CMD` to your own command.
2. **Your own credentials, in your own machine's config.** This package ships none and expects none.
   See `docs/03-PROVIDERS-AND-KEYS.md`.
3. **An acceptance command per task.** That is the point, not a formality.

## Platform support

| platform | status | notes |
|---|---|---|
| **Linux** (x86_64/arm64) | **Full, verified** | the environment this was built and measured on |
| **WSL2 on Windows** | **Full — the recommended Windows path** | a real Linux kernel; VS Code's WSL extension puts the editor and the terminal inside it. See `docs/01-WINDOWS-AND-WSL.md` |
| **Git Bash / MSYS2 on Windows** | **Runs, degraded** | no pty, so a lane runs without a terminal. The fallback is tested (`LANE_PTY_MODE=none`) and the dispatch says so |
| **macOS** | **Runs, degraded** | needs `coreutils` for GNU `date`; no pty without `util-linux`. Untested here — see `docs/01` |

The pty and detach primitives have tested fallbacks rather than hard failures, because the original
failure mode was silent: a missing `script` failed *inside a background job*, so the error only ever
reached the lane's own log — the lane looked hung and was recorded `unverified`.

## Licence

MIT — see `LICENSE`.
