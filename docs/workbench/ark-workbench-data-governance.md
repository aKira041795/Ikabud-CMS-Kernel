# ARK Workbench Data Governance

Workbench evidence is internal diagnostic data. Secrets, credentials, authorization headers, cookies, CSRF tokens, raw session identifiers, and unrestricted request bodies must never be sent to an AI provider or retained in issue memory.

## Retention

- Raw run artifacts: 30 days by default.
- Normalized observations and issue occurrences: 180 days.
- Verified cases: retained until superseded or explicitly purged.
- Dismissed, flaky, and environment-only findings: 90 days, retaining aggregate counters afterward.
- Provider prompts/responses: disabled by default; when enabled for audit, retain 14 days after redaction.

## Redaction

All AI-bound data passes through the Workbench redactor. Values classified `secret` are removed, `sensitive` values are hashed or replaced, and source snippets are allowlisted by repository-relative path. Tenant and entity identifiers are pseudonymized unless a local provider is selected and policy explicitly permits them.

## Governance

- Only kernel superadmins may configure AI policy, promote cases, or purge Workbench knowledge.
- AI suggestions cannot execute code, issue SQL, alter tests, or mutate graph truth.
- Every AI result records provider, model, prompt/schema version, evidence IDs, latency, and fallback state.
- Verified human or regression-test outcomes outrank AI inference and historical probability.
- Export and purge operations must be auditable and scoped by module, issue, run, and retention class.
