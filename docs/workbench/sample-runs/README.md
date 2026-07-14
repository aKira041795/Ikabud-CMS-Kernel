# Workbench Sample Runs

This directory contains sanitized sample run artifacts demonstrating the end-to-end
pipeline:

```
observer evidence
→ browser run manifest
→ suite result
→ issue report
→ comprehension report
```

Each sample is a real run output with identifying information (hostnames, IPs, user
emails) removed. These serve as:
- **Reviewable pipeline proof** — reviewers can inspect the artifact bundle without
  needing local test output.
- **Fixture data** — for integration tests of the Workbench cockpit and reporter.
- **Onboarding reference** — new contributors can see what each stage produces.

## Structure

```
sample-runs/<run_id>/
├── README.md                    # This file
├── manifest.json                # Aggregate run metadata
├── <suite>--<project>.json      # Per-suite result with issues, gaps, evidence
├── issue-report.json            # Consolidated issue report (filtered + sorted)
├── evidence.json                # Observer evidence payload
└── comprehension-report.json    # Comprehension Engine diagnosis
```

## Adding a new sample

1. Run `npx playwright test` or the Workbench test suite.
2. Copy the latest run from `test_results/browser/runs/<run_id>/` into a new
   directory here.
3. Sanitize any sensitive data (URLs, user details, IPs, tokens).
4. Update this README's index if needed.
