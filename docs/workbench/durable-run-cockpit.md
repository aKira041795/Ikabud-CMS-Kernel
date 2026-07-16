# Durable run cockpit and intelligence

The Workbench cockpit consumes `RunRepository`, an indexed durable history keyed by run, module, commit, tenant, role, browser, environment, and outcome. Raw artifacts can expire under retention policy while their indexed summaries remain queryable. Contract/run comparison reports new, resolved, and persistent issue fingerprints.

`RunIntelligence` clusters recurrence, classifies flakes from observed history, requires owner/reason/expiry for quarantine, and builds an ordered causal timeline across interaction, HTTP, service, database, event, audit, render, screenshot, trace, log, and probe evidence. A release diagnosis is valid only when it links the failed contract, evidence, and remediation.

`RunExporter` provides versioned ARK JSON, JUnit XML, and SARIF 2.1.0. The Superadmin Workbench remains the presentation layer: its existing run-correlation view can consume these summaries and drill into retained raw artifacts without owning diagnostic truth.
