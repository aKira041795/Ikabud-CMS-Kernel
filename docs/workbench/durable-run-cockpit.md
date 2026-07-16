# Durable run cockpit and intelligence

The Workbench cockpit consumes `RunRepository`, an indexed durable history keyed by run, module, commit, tenant, role, browser, environment, and outcome. Raw artifacts can expire under retention policy while their indexed summaries remain queryable. Contract/run comparison reports new, resolved, and persistent issue fingerprints.

`RunIntelligence` clusters recurrence, classifies flakes from observed history, requires owner/reason/expiry for quarantine, and builds an ordered causal timeline across interaction, HTTP, service, database, event, audit, render, screenshot, trace, log, and probe evidence. A release diagnosis is valid only when it links the failed contract, evidence, and remediation.

`RunExporter` provides versioned ARK JSON, JUnit XML, and SARIF 2.1.0. The Superadmin Workbench remains the presentation layer: its existing run-correlation view can consume these summaries and drill into retained raw artifacts without owning diagnostic truth.

## Concurrent writer safety

`RunRepository` serializes every index mutation through `index.lock`. A writer acquires an exclusive `flock`, reloads the latest index while holding that lock, applies its mutation, and publishes the new index with an atomic rename. Raw-artifact expiry uses the same critical section. This prevents parallel CI workers and browser shards from overwriting one another's run summaries.

Run artifacts and indexes are written to a unique temporary file in the destination directory before rename. A failed write or rename raises an error; it is never reported as a persisted run. The lock file is durable coordination state and must not be deleted while Workbench writers are active.

## Contract-run correlation

Contract runs live at `storage/workbench/runs/<WB_RUN_ID>.json`. Superadmin correlates that artifact with Reporter, Analyst, Comprehension, Scenario, and Pattern Intelligence artifacts carrying the same canonical run ID. A contract-only run remains visible as `ark-contract`; when other engine artifacts arrive it becomes an `ark-hybrid` view without losing contract execution details or timeout evidence.
