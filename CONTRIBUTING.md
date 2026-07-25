# Contributing to Ikabud

Thank you for your interest in contributing to Ikabud. This document outlines how to participate, what contributions are welcome, and what to expect from the review process.

---

## Quick links

- **Code of Conduct** — [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)
- **Security disclosures** — [SECURITY.md](SECURITY.md)
- **Technical contributor guide** — [docs/kernel/contributor-workflows.md](docs/kernel/contributor-workflows.md)
- **Licensing** — [LICENSING.md](LICENSING.md)
- **Project philosophy** — [docs/PHILOSOPHY.md](docs/PHILOSOPHY.md)

---

## What contributions are wanted

### Community Edition (MIT)

All changes to Community Edition components are welcome:

- Bug fixes
- Test improvements and coverage additions
- Documentation improvements (typos, clarifications, translations)
- DiSyL engine improvements (grammar, parser, components)
- Community module enhancements
- New community modules (anti-spam, contact-form, media, search, etc.)
- Performance optimizations
- Tooling and CLI improvements

### Enterprise Edition (Commercial)

Changes to Enterprise Edition components are accepted through a **standard Contributor License Agreement (CLA)** process. This applies to:

- Kernel orchestration (`kernel/App.php`, `kernel/Capabilities/`, `kernel/EntityContext/`, etc.)
- Enterprise modules (CMS, ecommerce, guidance, WMS, bakeshop, etc.)
- Infrastructure (`public/index.php`, `bootstrap.php`, `src/helpers/module-manager.php`)

Contact **noah2.omamalin@gmail.com** to start the CLA process for Enterprise contributions.

### What is not currently open for contribution

- Architectural rewrites without prior discussion
- Changes that break stable contracts (see [kernel-stable-contracts.md](docs/kernel/kernel-stable-contracts.md))
- Features that bypass kernel governance (tenant isolation, capability dispatch, module boundaries)

---

## How to contribute

### 1. Choose or open an issue

- Look for issues labeled `good first issue` or `help wanted`
- For significant changes, open an issue first to discuss the approach before writing code
- For security issues, follow the [security disclosure policy](SECURITY.md) — do not file a public issue

### 2. Set up your environment

Follow the technical setup in [docs/kernel/contributor-workflows.md](docs/kernel/contributor-workflows.md):

- PHP 8.2+, MySQL 5.7+, Composer
- Configure `.env` for app and database access
- Run `composer install`

### 3. Make your changes

- Follow the [reading order](docs/kernel/contributor-workflows.md#reading-order-for-new-contributors) if you are new to the codebase
- Keep changes focused on one concern per pull request
- Add or update tests for your change
- Run `php -l` on touched PHP files before committing

### 4. Test

Run the relevant test suite and check both logs:

```bash
composer test
# or for focused tests:
php tests/request_dispatch_integration_test.php
```

Always check:

- `storage/logs/app.log`
- `storage/logs/error.log`

### 5. Submit a pull request

- Use a descriptive title and reference the related issue
- Include a summary of what the change does and why
- Note any breaking changes or migration considerations
- Mark if the change touches Community or Enterprise components

---

## Review expectations

- **Initial review** typically within 5 business days
- **Reviewers** are the project maintainer and designated reviewers
- **Merge criteria**: passing tests, no log errors, review approval, CLA signed for Enterprise changes
- **Response time** for follow-up review rounds: 2–3 business days

---

## Coding conventions

- PHP: PSR-12, typed properties and return types where possible
- SQL: MySQL 5.7 compatibility (see [database-profiles.md](docs/kernel/database-profiles.md))
- DiSyL: templates use `{tag}` syntax, snake_case variable names
- Tests: plain PHP integration-style tests that bootstrap the app directly
- Git: descriptive commit messages, no `WIP` commits on shared branches

---

## Branch and commit conventions

- **Main branch**: `master` — always deployable
- **Feature branches**: `feature/<short-description>`
- **Bugfix branches**: `fix/<short-description>`
- Commit messages: present tense, imperative mood ("Add input validation", not "Added input validation")

---

## Communication

- **Issues**: GitHub Issues for bug reports, feature requests, and questions
- **Security**: See [SECURITY.md](SECURITY.md) for private reporting
- **Contact**: noah2.omamalin@gmail.com for licensing and enterprise inquiries

---

## Contributor License Agreement (CLA)

Enterprise Edition contributions require a signed CLA. The CLA:

- Grants the project permission to use your contribution
- Does **not** transfer copyright ownership
- Allows you to reuse your own contribution
- Is a one-time signing, not per-contribution

Contact **noah2.omamalin@gmail.com** to request the CLA.

---

## Becoming a maintainer

Maintainers are contributors who have demonstrated:

- Consistent, high-quality contributions over time
- Understanding of the architecture and governance model
- Reliable review participation
- Alignment with the project's philosophy

There is no fixed timeline. Express interest by reaching out to the project maintainer after several accepted contributions.

---

## Where discussions happen

- **GitHub Issues** — bug reports, feature requests, design discussions
- **Pull requests** — code review and implementation discussion
- **Direct contact** — noah2.omamalin@gmail.com for governance, licensing, and security matters

---

## Related documents

- [docs/kernel/contributor-workflows.md](docs/kernel/contributor-workflows.md) — technical setup, testing, refactor workflows
- [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md) — community behavior expectations
- [SECURITY.md](SECURITY.md) — vulnerability reporting
- [docs/PHILOSOPHY.md](docs/PHILOSOPHY.md) — project principles and design rationale
- [docs/TERMINOLOGY.md](docs/TERMINOLOGY.md) — canonical terminology
- [LICENSING.md](LICENSING.md) — component-to-license boundary
