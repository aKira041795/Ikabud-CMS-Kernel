# VS Code integration

The harness is a bash tool, not an extension. VS Code is the driver: tasks launch it, the integrated
terminal shows it, and the task-completion signal is how you learn a lane landed.

## Does it work in any VS Code workspace?

**Yes, with three real requirements and one honest degradation.**

| requirement | why | if missing |
|---|---|---|
| a POSIX shell (**WSL2** on Windows) | the harness is bash; and it needs a pty + process groups | runs on Git Bash with **no terminal for the lane** (tested fallback, disclosed at dispatch) |
| **git** in the workspace | the landing record attributes changed files from `git status` | lanes still run, record and verify — the record says `basis=unavailable` and `--require-clean` **refuses** rather than silently passing |
| `tools/` at the folder you **open** | the harness resolves its repo root from its own path (`dirname $BASH_SOURCE`) | nothing runs; install it in the folder you open |

What does **not** matter: language, framework, build system, or that it is a "code" repo at all. The
lane is just bash that calls a model CLI, and the acceptance command is whatever you already use —
`npm test`, `pytest -q`, `cargo test`, `php tests/run.php`, a curl against a staging URL, a grep for
a row in a database dump.

**Multi-root workspaces are per-repo.** The harness is installed *into a repository*, and every task
runs with `cwd` set to a workspace folder. In a multi-root workspace VS Code's `${workspaceFolder}`
resolves to the **first** folder, which is not necessarily the repo you installed into. Two options:

- point a task at a named folder: `"cwd": "${workspaceFolder:my-repo}"`, or
- add a second set of tasks per repo, or
- open the repo as its own window (simplest, and what the harness assumes).

Everything below assumes single-root: one repo, one harness.

## The three ways to run it

**1. Tasks (recommended).** `install.sh` writes `.vscode/tasks.json`. `Ctrl/Cmd+Shift+P` → *Tasks:
Run Task*:

| task | what it does |
|---|---|
| `harness: preflight` | reports required/optional gaps and which pty+detach mode you will run in |
| `harness: all checks` | preflight + the three self-tests. Run this first on any machine |
| `harness: dispatch a lane` | prompts for name, script, acceptance command, PASS wording |
| `harness: watch for the next landing` | one-shot watcher; exits the moment a lane lands |
| `harness: landings (pending)` | landings the agent/you have not been told about yet |
| `harness: landings (recent)` | recent landings with the acceptance verdict |
| `harness: status of a lane` | the full record for one lane |

**2. The integrated terminal.** The same commands, verbatim:

```bash
bash tools/lane.sh run my-task tools/lane-my-task.sh \
  --acceptance="bash tests/run.sh 2>&1 | grep -qx '  12/12 passed'" \
  --pass-looks-like="12/12 passed and exit status 0"
```

**3. An AI agent in VS Code.** This is what the harness was built to be driven by. The agent writes
the brief, chooses the criterion, dispatches, and — crucially — **reads the verdict instead of the
model's summary**. Point it at `04-DOCTRINE.md` so it follows the rules rather than reinventing them.

## Why the notification works the way it does — read this before changing the tasks

A task only tells you it finished **if it finishes**. Two facts follow:

- `lane.sh run` deliberately hands the wait back after ~90s and exits `3` with a re-arm instruction,
  rather than blocking for an hour and being killed by a terminal cap with no signal at all.
- `lane-watch.sh` is a **one-shot** watcher: it exits on the next landing. A watcher that looped
  forever could never notify, which is the flaw every earlier version of this had.

So the loop is: **dispatch → the task ends → arm the watcher → the watcher ends when a lane lands →
VS Code surfaces it.** If the watcher times out with "no landing yet", that is a heartbeat, not a
failure: run it again.

The generated `.vscode/tasks.json` marks these two tasks `"isBackground": true` and gives them a
problem matcher with `beginsPattern`/`endsPattern`, so VS Code knows when they are running and when
they have ended. Without that, VS Code would either show a permanent spinner or treat an unfinished
task as done. If you rewrite the tasks, keep the matcher:

```json
"problemMatcher": [{
  "owner": "lane-harness-dispatch",
  "pattern": [{ "regexp": "^(.*)$", "message": 1 }],
  "background": {
    "activeOnStart": true,
    "beginsPattern": "^== dispatching ",
    "endsPattern": "^(== .* (LANDED|STILL RUNNING) ==|REFUSING TO DISPATCH)"
  }
}]
```

### If you are not getting told a lane landed

1. `bash tools/lane.sh pending` — if it lists landings, the **recording** works and only the
   **notification** is missing. The runner records itself, so nothing is ever lost.
2. `bash tools/lane.sh ack` after you have read them.
3. Prefer the watcher in its own terminal panel that stays open. A watcher in a panel you close is a
   watcher that cannot tell you anything.

## Recommended settings

`install.sh` writes `.vscode/settings.json` (or a `settings.lane-harness.snippet.json` beside yours):

```json
{
  "files.watcherExclude": { "**/.ai/runs/**": true },
  "search.exclude":       { "**/.ai/runs/**": true }
}
```

The harness writes a marker, a log, a generated runner and an acceptance log **per lane**, plus an
append-only journal. Under the file watcher that is constant churn and constant git refreshes, and the
contents are noise in search. Nothing else is changed — a harness that rewrites your editor settings
is not a guest.

Task-completion notifications are a VS Code setting; search the Settings UI for **"notify"** and pick
what you want (`task...notify...`). The harness does not set it for you, because notification
behaviour is a personal preference and the harness should not be silently changing how your editor
behaves.

## Recommended extensions

`install.sh` writes `.vscode/extensions.json` recommending:

- **WSL** (`ms-vscode-remote.remote-wsl`) — on Windows this is the integration that makes full mode
  possible: the editor *and* the terminal run inside WSL2.
- **Dev Containers** — the alternative if you prefer a container over WSL.
- **ShellCheck** — the harness is bash; this catches the bug class it is most exposed to.
- **Copilot** / **Copilot Chat** — how the briefs get written. The harness verifies their output; it
  does not replace them.

## Keyboard shortcuts (optional)

`Ctrl/Cmd+Shift+P` → *Preferences: Open Keyboard Shortcuts (JSON)*:

```json
[
  { "key": "ctrl+alt+h", "command": "workbench.action.tasks.runTask", "args": "harness: watch for the next landing" },
  { "key": "ctrl+alt+l", "command": "workbench.action.tasks.runTask", "args": "harness: landings (pending)" }
]
```
