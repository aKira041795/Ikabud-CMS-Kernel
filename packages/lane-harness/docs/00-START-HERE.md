# Start here

Fifteen minutes from unzip to your first verified lane. Nothing here needs a server, an account with
this project, or an API key *in the package*.

## 0. What this is, in one paragraph

You write a **brief** (a bash file with a prompt) and an **acceptance command** — something that
fails right now and passes when the work is done. The harness refuses to dispatch unless that
command genuinely fails first, runs a model on the brief, then **re-runs the same command** and tells
you `VERIFIED`, `NOT VERIFIED` or `EXECUTION ONLY`. You get a verdict you can act on, instead of a
model's summary you have to take on faith.

## 1. Requirements

- **Linux, or WSL2 on Windows** (recommended), or a dev container. macOS and Git Bash work with
  degraded terminal capture — see `01-WINDOWS-AND-WSL.md`.
- `git`, `bash` ≥ 4, `timeout`, `mktemp`, `nohup` — all from coreutils/util-linux.
- A **model CLI**. The default is `pi`; anything shaped like
  `<cmd> --model <name> <prompt>` works via `LANE_MODEL_CMD`.
- Your own credentials for that CLI. **None ship with this package** — see `03-PROVIDERS-AND-KEYS.md`.

## 2. Install

**On Windows: open `WINDOWS-QUICKSTART.txt` at the archive root first.** It is the WSL2 setup as a
copy-paste block, and `install.sh` will refuse to run from Git Bash unless you pass
`--allow-degraded` — deliberately, because a silent downgrade is what this harness exists to stop.

```bash
tar xzf lane-harness-0.1.0.tar.gz
cd lane-harness-0.1.0
bash preflight.sh                     # what is this machine missing? run it first
bash install.sh /path/to/your/repo    # tools/, .gitignore, .gitattributes, .vscode/
```

`install.sh` never overwrites a file that differs, only if you pass `--force`. If you already have a
`.vscode/tasks.json`, it writes the harness tasks alongside it as a `.snippet.json` for you to paste
— it will not rewrite your editor config.

**Install it in the folder you actually open in VS Code.** The harness resolves its repository root
from its own location, so `<that folder>/tools/lane.sh` is where it must live.

## 3. Prove it works: two numbers

```bash
cd /path/to/your/repo
bash tools/lane-platform-selftest.sh    # expect: 22 passed, 0 failed
bash tools/lane.sh selftest             # expect: 29 passed, 0 failed
```

Do not proceed until those two numbers match. They are not a smoke test: they are the harness
demonstrating that it detects its own failure modes, in both directions, on your platform. The
platform suite includes the no-terminal path and a real lane carried end to end through it.

## 4. Point it at your models

```bash
cat tools/model-chain.txt
```

One model per line, in fallback order. Edit it for your providers. The harness falls through the list
when a model is unavailable, and divides a budget across the attempts so a hang cannot starve the
rest.

## 5. Your first lane, in VS Code

1. `Ctrl/Cmd+Shift+P` → **Tasks: Run Task** → **harness: preflight** — confirms what you have.
2. Copy the example: `cp examples/lane-example.sh tools/lane-my-task.sh`, then rewrite the prompt in
   it. Keep one **objective**, a **scope**, and the **acceptance command**.
3. Run **harness: dispatch a lane**. It asks for four things:
   - the lane name,
   - the lane script (`tools/lane-my-task.sh`),
   - **the acceptance command** — e.g. `bash tests/run.sh 2>&1 | grep -qx '  12/12 passed'`,
   - **what PASS looks like** — in words.
4. The task returns after ~90s saying `STILL RUNNING` with a re-arm instruction. That is by design,
   not a failure: no terminal wrapper can stay attached forever, so the runner records the landing
   by itself.
5. Run **harness: watch for the next landing**. It is one-shot: it exits the moment a lane lands,
   which is what makes VS Code tell you. If it times out instead, just run it again.
6. Read the verdict:

```
== my-task LANDED ==
   state:         landed
   reason:        report_present
   changed files: 2 (basis=delta, tree_dirty=2, dirty_before=0)
   acceptance:    PASS (log: .ai/runs/my-task.acceptance.log)
   VERDICT: VERIFIED
   EXECUTION: landed with a report - verify it, do not trust it
```

Two lines, two different questions. `VERDICT` is about **the objective** (did your acceptance
command pass?). `EXECUTION` is about **how it ran** (did the model finish, crash, run out of quota?).
Neither replaces the other.

## 6. The loop you will actually run

```
write the acceptance command  →  check it FAILS right now
        ↓
dispatch (VS Code task, background)      →  lane runs
        ↓
arm the watcher (VS Code task)           →  VS Code tells you when it lands
        ↓
read VERDICT + changed files             →  inspect the diff
        ↓
ACCEPT the work, or write a REPAIR lane whose acceptance is the SAME command
```

Keep one rule: **never weaken an acceptance command to make a lane pass.** If a lane says `BLOCKED`,
read its reason — in the experience this harness was built from, the blocked lane was right and the
criterion was the defect about as often as not.

## 7. Read next

| | |
|---|---|
| `01-WINDOWS-AND-WSL.md` | **Windows specifically** — WSL2 setup, the dev-container option, what Git Bash costs you, line endings |
| `02-VSCODE-INTEGRATION.md` | the tasks, the notification mechanism, multi-root workspaces, "any workspace?" |
| `03-PROVIDERS-AND-KEYS.md` | DeepSeek, Codex/ChatGPT subscription, the optional advisor — and where keys live |
| `04-DOCTRINE.md` | why the rules are what they are, with the measurements behind them |
| `05-TROUBLESHOOTING.md` | the failures you are most likely to hit, and their signatures |
| `SECURITY.md` | what runs, what is written, what leaves your machine |
