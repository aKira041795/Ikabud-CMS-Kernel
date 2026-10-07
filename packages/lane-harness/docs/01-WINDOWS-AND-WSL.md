# Windows — and what to do about it

**Short answer: use WSL2.** It is one command, VS Code supports it natively, and it is the only
Windows option that gives full behaviour. Everything else on Windows works with a documented
degradation.

## Why WSL2 rather than Git Bash

The harness needs two things from the operating system:

| primitive | what it is for | on Git Bash / MSYS2 |
|---|---|---|
| `script -qec` (util-linux) | gives the lane a **terminal**, because several model CLIs behave differently or refuse without a TTY | absent or a different `script` — lanes run **without a terminal** |
| `setsid` | starts the watchdog and the wrapper in a **new session**, so they outlive the editor's terminal | absent — falls back to `nohup` (ignores SIGHUP only) |

Both have **tested fallbacks**, so Git Bash runs. But the original failure mode is worth knowing,
because it is why the fallbacks exist: a missing `script` failed *inside a background job*, so the
error only ever reached the lane's own log — the lane looked hung and was recorded `unverified`.

WSL2 is a real Linux kernel. Zero degradation, and it is what the harness is verified on.

## Set up WSL2 (once, ~10 minutes)

In an **Administrator PowerShell**:

```powershell
wsl --install
```

Reboot if it asks. Then, in the Ubuntu shell it installs:

```bash
sudo apt-get update && sudo apt-get install -y git curl
# Node (for the `pi` model CLI, if that is what you use)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt-get install -y nodejs
```

### Keep your code in the Linux filesystem, not in /mnt/c

```bash
mkdir -p ~/code && cd ~/code
git clone <your-repo> && cd <your-repo>
```

Two reasons, and the second is the one that bites:

1. `/mnt/c` is a translation layer; file operations are several times slower and file watching is
   unreliable.
2. **Line endings.** A Windows checkout with `core.autocrlf=true` gives every `.sh` a trailing CR,
   and bash then fails with:

```
tools/lane.sh: line 95: $'\r': command not found
```

   which reads as a broken harness. `install.sh` appends `*.sh text eol=lf` to `.gitattributes`, and
   `preflight.sh` checks for stray carriage returns and tells you the fix. If you cloned before
   installing:

```bash
git config core.autocrlf false
printf '*.sh text eol=lf\n' >> .gitattributes
sed -i 's/\r$//' tools/*.sh        # or: sudo apt-get install -y dos2unix && dos2unix tools/*.sh
```

### Wire it into VS Code

Install the **WSL** extension (`ms-vscode-remote.remote-wsl`) in VS Code on Windows, then from the
Ubuntu shell:

```bash
cd ~/code/<your-repo>
code .
```

VS Code reopens attached to WSL. The window says **WSL: Ubuntu** in the bottom-left, the integrated
terminal is a Linux shell, and tasks run there. That is the whole trick: your editor stays on
Windows, the harness sees Linux.

```bash
bash preflight.sh --check-only      # from the unpacked package
bash install.sh .
bash tools/lane-platform-selftest.sh   # expect 22 passed, 0 failed
bash tools/lane.sh selftest            # expect 29 passed, 0 failed
```

The platform suite reports `pty mode: util-linux` and `detach: setsid` on WSL2. If it says
`pty: NONE`, you are not running inside WSL — check the bottom-left corner of the VS Code window.

## Option B — Dev Containers (no WSL setup)

If you would rather not enable WSL, a container is also a real Linux. The package ships
`.devcontainer/devcontainer.json`.

1. Install **Dev Containers** (`ms-vscode-remote.remote-containers`).
2. Open your repo folder in VS Code → `Ctrl/Cmd+Shift+P` → **Dev Containers: Reopen in Container**.
3. The container installs `util-linux` and `php-cli`, and the integrated terminal is inside it.

Trade-off: your repo is bind-mounted from Windows, so file operations are slower than WSL2, and the
same **line-ending** rule applies inside the container. For a long-lived setup, WSL2 is faster.

## Option C — Git Bash or MSYS2 (runs, degraded)

It works, and the harness will tell you what you are getting rather than hiding it:

```
$ bash tools/lane.sh run my-task tools/lane-my-task.sh --acceptance=... --pass-looks-like=...
== dispatching my-task ==
   ...
   pty: NONE - no util-linux 'script' here, so the lane runs WITHOUT a terminal.
        That is a degradation, not a failure. On Windows use WSL2 for full mode.
   detach: nohup
```

What you lose: the lane has no TTY. Output still lands in the log, the runner still records the exit
status, and the acceptance verdict is unaffected — **the part that matters still works**. What can
differ is a model CLI's own behaviour (progress rendering, colour, and CLIs that refuse a non-TTY).

If `script` *is* present but is not util-linux, the harness detects that by content
(`script --version`) and still chooses the no-pty path — a BSD `script` that exists would otherwise
select the pty branch and kill every lane inside a background job.

The best response is honest, not clever. What each primitive means and what it should be replaced
with is **named in the code** (`tools/lane-platform.sh`), so it can be ported deliberately rather
than discovered the hard way. The fallbacks that *are* portable are already implemented.

## macOS

Runs with the same two degradations, and two extra notes:

- `script -qec` is util-linux; the BSD `script` takes different flags. Install it with
  `brew install util-linux` and put it on `PATH`:
  `export PATH="$(brew --prefix util-linux)/bin:$PATH"` — then `bash tools/lane-platform-selftest.sh`
  should report `pty mode: util-linux`.
- Some timestamp fields use GNU `date`. `iso()` already falls back to a POSIX format, and the
  log-mtime field degrades to empty rather than failing. `brew install coreutils` removes the
  difference entirely.

## The support matrix, stated plainly

| platform | pty | detach | status |
|---|---|---|---|
| Linux | `script -qec` | `setsid` | **full — built and measured here** |
| **WSL2 on Windows** | `script -qec` | `setsid` | **full — the recommended Windows path** |
| Dev container | `script -qec` | `setsid` | full |
| Git Bash / MSYS2 | none | `nohup` | runs, degraded, disclosed at dispatch |
| macOS | none, unless util-linux | `nohup` | runs, degraded; not verified on real hardware here |

`bash tools/lane-platform-selftest.sh` proves **both** pty branches, four detach/pty combinations, and
carries a real lane from dispatch to a recorded landing with **no pty**. So the Windows-degraded path
is not a claim — it is executed, on your machine, by that command.
