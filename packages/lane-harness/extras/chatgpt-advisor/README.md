# ChatGPT advisor (optional)

Consult **your own ChatGPT subscription** as a read-only second opinion, grounded in your repository,
*before* you write a brief.

This is an extra. The lane harness is complete and useful without it. Nothing else in the package
imports these three files.

## What it does

```
your query  →  a deterministic context pack built from YOUR repo  →  a prompt  →  ChatGPT (your
               (docs/, recent commits, git-grep hits, module manifests)        own session)
```

It answers with `path:line` citations, and it is told to say what evidence is missing rather than
invent it. The transcript is saved under `.ai/consult/` so you can point an agent at it.

## What it needs

| | |
|---|---|
| Node + Playwright | `npm i -D playwright && npx playwright install chromium` |
| Python 3 | stdlib only — no pip packages |
| A ChatGPT account | the **subscription** you already have; a one-time interactive login |

**No API key is used.** It drives your logged-in ChatGPT session in a browser profile — cookies, not
a token. Nothing is billed per token, and nothing here needs an `OPENAI_API_KEY`. (Pointing this at a
metered API model instead is not supported on purpose: the credits are the wrong kind of thing to
spend on a second opinion, and an exhausted balance produces a drafting attempt with no text.)

## Setup

```bash
cd extras/chatgpt-advisor
npm i -D playwright
npx playwright install chromium

python3 chair_consult.py --login          # opens a browser: sign in, then close it
```

The session is stored in:

```
~/.config/chair-consult/chatgpt-profile
```

Override with `CHAIR_CONSULT_PROFILE=/path/to/profile`. **Never copy that directory anywhere, commit
it, or share it** — it is a live session, and it is the one file here that is genuinely sensitive.
It is not an API key, so nothing in the harness's secret scan needs to catch it: keep it out of
packages by hand.

## Use

```bash
# one-shot: grounded prompt written to .ai/consult/<slug>.md, then answered
python3 chair_consult.py --query "should the import wizard stream rows or batch them?"

# compose the prompt only — inspect what would be sent, spend nothing
python3 chair_consult.py --query "..." --dry-run

# continue the same discussion: prior turns are carried in, their fact blocks stripped
python3 chair_consult.py --session import-wizard --query "what about a 2GB file?"

# machine-readable, for an agent to consume
python3 chair_consult.py --query "..." --json
```

Then reference the saved transcript in the brief you hand to a lane:

```
## ADVISOR INPUT
Read .ai/consult/import-wizard.md. Where it disagrees with this brief, say so in your report.
```

## Scope limits, stated honestly

- Its context pack reads `docs/**/*.md`, `.ai/chair/ledger.md`, `modules/*/module.json`, recent commit
  subjects and `git grep` hits. In a repository without those, it simply finds less — no
  repository-specific code, and nothing hard-coded to one project.
- It is a **read-only advisor**. A passing mention of ChatGPT in prose launches nothing; it runs only
  when you invoke it.
- Browser automation against a consumer web UI can break when that UI changes. If it stops working,
  the lane harness is unaffected — this is an extra, not a dependency.
