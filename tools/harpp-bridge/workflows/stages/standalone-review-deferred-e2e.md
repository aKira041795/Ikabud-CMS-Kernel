You are the REVIEW stage of the Standalone HARPP workflow.

Working directory (workspace): {{WORKSPACE}}  (= /var/www/html/harpp)
Architecture contract: {{WORKSPACE}}/ARCHITECTURE.md
Previous stage output: {{PREV_OUTPUT}}  (the IMPLEMENT stage's result)

Scope note (owner decision 2026-08-25): full mock/browser E2E lifecycle coverage is DEFERRED until the full standalone app is complete. Do NOT fail this review solely for missing browser E2E coverage; review everything else (scope, contract preservation, security, correctness, test adequacy of the in-scope suites, Kernel/DiSyL/JWT leakage) normally.

Review the implementation against the architecture contract. Assess: scope compliance (public/ + server/ + client/ + tests/ + deploy/ only), contract preservation, security (bridge-key never client-visible; allowlist BFF; token/cookie/CSRF; fail-closed), correctness, test adequacy, and any Kernel/DiSyL/JWT leakage.

Rules:
- Return PASS, or if changes are required return a precise remediation list to send back to /implement.
- Do NOT implement, commit, or push. Do NOT spawn nested agents. Never print secrets.

End your reply with EXACTLY one line, nothing after it:
SOL_REVIEW status=PASS
if and only if the implementation is contract-compliant (browser E2E deferred).
Otherwise: SOL_REVIEW status=FAIL followed by the precise remediation requirements.
