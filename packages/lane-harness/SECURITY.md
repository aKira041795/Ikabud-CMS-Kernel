# Security — what is shipped, what is not, and what this thing can do to you

## 1. No credentials are shipped

This package contains **no API keys, no tokens, no cookies, no passwords, and no credential files**.
That is enforced, not promised:

- `bash build.sh` runs a **secret scan on the assembled output** and refuses to produce a package on
  any hit. It matches key shapes (OpenAI, Anthropic, OpenRouter, Groq, Google, GitHub, Slack, JWTs,
  PEM private keys), env-style assignments (`DEEPSEEK_API_KEY=…`), and generic
  `api_key`/`token`/`secret`/`bearer` assignments.
- It separately **refuses credential-shaped FILENAMES** — `auth.json`, `.env*`, `credentials*`,
  `id_rsa*`, `*.pem`, `*.key`, `*.p12`, browser-profile directories — whatever they contain.
- `bash build.sh --selftest` **proves the scanner can fail**: it plants a live-shaped key, an
  env-var key, a credential file, and a JWT, and asserts each is refused; and it asserts that bare
  numbers and short tokens do **not** trip it, so the scanner will not be ignored as a cry-wolf.

A scanner nobody has watched fail is not a scanner.

## 2. Where your credentials actually live

The harness reads **none** of these. They are your machine's, they stay on your machine, and nothing
in this package opens them:

| what | where | who uses it |
|---|---|---|
| Model API keys (DeepSeek, Groq, OpenRouter, …) | `~/.pi/agent/auth.json`, mode `600` | the `pi` CLI, never this harness |
| Codex / ChatGPT-subscription OAuth token | `~/.pi/agent/auth.json` | the `pi` CLI |
| ChatGPT subscription **cookies** (for the optional advisor) | `~/.config/chair-consult/chatgpt-profile` (override with `CHAIR_CONSULT_PROFILE`) | Playwright, launched by the optional advisor only |

Set keys with your model CLI's own login flow. Never paste a key into a lane script, a contract, a
brief or a chat message — the harness writes the brief's **full text** into `.ai/runs/<lane>.log`.

## 3. What the harness writes — and why `.ai/` must stay out of git

`.ai/runs/` accumulates, per lane:

- `<lane>.log` — the model's **complete output**, including your prompt
- `<lane>.landed.json` — the landing record (status, exit code, acceptance result, changed paths)
- `<lane>.acceptance.log` — the output of your acceptance command
- `landings.jsonl`, `LANDINGS.log` — append-only history
- `<lane>.runner.sh` — a generated script

**These can contain anything you put in a brief, and anything the model printed** — file contents,
diffs, and whatever your tests echo. `install.sh` adds `.ai/` to `.gitignore`; do not undo that, and
do not attach `.ai/runs/*.log` to a public issue without reading it first.

## 4. This harness runs commands. It has no sandbox.

By design, a lane runs a model CLI that is free to edit files and execute shell commands in your
working tree, and the acceptance command you supply is executed by the harness. There is no
sandboxing, no allowlist, and no privilege separation.

Practical consequences, stated plainly:

- **Run it on work you can afford to lose.** Commit first; the harness never commits for you.
- **For untrusted or exploratory work, isolate**: a devcontainer (`.devcontainer/` is included), a
  throwaway clone, or a container with a scoped token.
- **Give the model credentials that are disposable.** A key the lane can read in its environment is
  a key that a prompt-injected model could exfiltrate.
- The model provider you call sees your prompt and your repository content. Treat that as data
  leaving your machine.

## 5. Desktop notifications are off by default

`notify-send` is invoked only with `LANE_NOTIFY=1` (or `--notify`). Silence is the default because
the alternative was one pop-up per landing, ~16 per self-test run.

## 6. Reporting a problem

If you find a credential in a build of this package, that is a bug of the highest severity: rotate
the credential at the provider first, then report it with the file path and the pattern that matched
— never paste the key itself.
