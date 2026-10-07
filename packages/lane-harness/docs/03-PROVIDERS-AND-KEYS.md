# Providers and keys

## The rule

**This package contains no API keys, tokens, cookies or passwords, and it does not want any.**
Credentials belong to the model CLI on your machine, in your machine's config, and the harness never
reads them. `build.sh` refuses to produce a package containing anything key-shaped (and
`build.sh --selftest` proves that scanner can fail), and `SECURITY.md` lists what is checked.

Everything below is **setup you do once, yourself**, on your own machine.

## 1. The model CLI

The default is [`pi`](https://www.npmjs.com/package/@earendil-works/pi-coding-agent):

```bash
npm install -g --prefix ~/.npm-global @earendil-works/pi-coding-agent
export PATH="$HOME/.npm-global/bin:$PATH"
pi --version
```

The harness only needs a command shaped like:

```
<cmd> --print --approve --model <model-name> <prompt>
```

To use something else, set `LANE_MODEL_CMD` — for example a wrapper around another CLI:

```bash
export LANE_MODEL_CMD="my-model-cli --non-interactive --approve-all"
# then lane_model_run will invoke:  my-model-cli --non-interactive --approve-all --model X "<prompt>"
```

## 2. Providers, and how each one authenticates

| provider | what it is | how you authenticate | notes |
|---|---|---|---|
| **DeepSeek** | metered API, cheap | API key in `~/.pi/agent/auth.json` (mode `600`), or `DEEPSEEK_API_KEY` | good as the high-volume fallback |
| **OpenAI Codex / ChatGPT subscription** | subscription, not metered per token | OAuth with your ChatGPT account — interactive, via `pi`'s own `/login` | subscription models are the *fixed-cost* route |
| Groq | metered, fast, low cost | API key | watch the per-day token ceiling |
| OpenRouter | metered multi-model | API key | many models behind one key |
| **ChatGPT subscription (browser)** | the *optional* advisor only | a browser profile with your ChatGPT session — **cookies, not a key** | see §4 |

Check what your CLI already has:

```bash
pi auth check --provider deepseek --json        # -> {"status":"ready", ...}
pi auth check --provider openai-codex --json    # -> {"status":"ready","authType":"oauth"}
```

Keys live in `~/.pi/agent/auth.json`. **Treat that file as a secret**: `chmod 600`, never in a repo,
never in a screenshot, never pasted into a chat. The second provider list is in
`~/.pi/agent/models.json`, which is configuration, not a secret — but it is worth keeping out of
version control too, because it often names internal endpoints.

## 3. Configure the chain

`tools/model-chain.txt`, one model per line, in **fallback order**. The harness moves to the next
line when a model is unavailable, and divides a budget across the attempts so a hang cannot starve
the rest.

```
openai-codex/gpt-5.6-sol      # subscription — fixed cost, strongest
deepseek-v4-flash             # metered — cheap, high volume
openai-codex/gpt-5.6-terra    # a third provider, so one exhausted provider cannot stop the work
```

Ordering logic worth keeping:

- **Put a model from a different provider last.** The failure this prevents is real: with a two-model
  chain of the same two providers, both becoming unavailable ended the work completely.
- **A subscription model first is cheaper than a metered one** — until the subscription's cap runs
  out, at which point the metered fallback is what keeps you moving. If your cap is tight, put the
  metered model first and the subscription second.
- **Do not put your slowest model first on an open-ended task.** A hang produces no exit, so the chain
  cannot advance past it. Keep deliverables bounded (one checkable artefact), and the per-attempt cap
  does the rest.

## 4. The optional ChatGPT advisor

`extras/chatgpt-advisor/` lets you consult your own ChatGPT **subscription** as a second opinion,
grounded in your repository, before you write a brief. It is optional and independent of the lanes.

- It uses **your ChatGPT session in a browser profile** — cookies, not an API key. Nothing is billed
  per token.
- It needs **Node + Playwright**: `npm i -D playwright && npx playwright install chromium`.
- It needs a one-time interactive login: run its `login` subcommand, sign in, then close the window.
- The profile lives at `~/.config/chair-consult/chatgpt-profile` (override with
  `CHAIR_CONSULT_PROFILE`). **Never copy that directory anywhere** — it is a live session.
- Its context pack reads `docs/**/*.md`, `.ai/chair/ledger.md` and `modules/*/module.json` if they
  exist, and degrades to nothing if they do not. No repository-specific code.

This is a convenience, not a dependency. The lane harness is complete without it.

## 5. Test a provider cheaply before you trust it

A lane that dies on a bad key wastes a dispatch. Two cheap checks:

```bash
# one-shot, a few tokens
pi --print --model deepseek-v4-flash "Reply with exactly: OK"

# the chain logic, with NO tokens at all — uses a stub in place of the model CLI
bash tools/lane-model-selftest.sh        # expect: 19 passed, 0 failed

# does a full lane work end to end?  never touches a real model
bash tools/lane-platform-selftest.sh     # expect: 22 passed, 0 failed
```

The self-tests never spend tokens: `LANE_MODEL_CMD` points at a stub. Use them before blaming your
keys for anything.

## 6. What you must never do

- **Never put a credential in a lane script, a brief, a contract or a prompt.** The harness writes the
  brief's full text into `.ai/runs/<lane>.log`, and the model's full output too.
- **Never commit `.ai/`.** `install.sh` adds it to `.gitignore`; `SECURITY.md` §3 explains what is in
  there. Read a log before attaching it to a public issue.
- **Never give a lane a credential you are not willing to rotate.** A model edit is code execution;
  anything in the lane's environment is reachable from it.

## 7. If a key is exposed

Rotate it at the provider first, then work out how it travelled — the three usual routes are a lane
log, a committed config file, and a pasted prompt. `grep -rIn 'sk-' .ai/ .vscode/ tools/` finds the
obvious cases locally; then check `git log -p` for anything committed.
