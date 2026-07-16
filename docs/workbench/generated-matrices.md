# Generated quality matrices

`MatrixPlanner` turns declared actor, tenant, capability-profile, browser, viewport, and environment dimensions into a deterministic plan. Mandatory critical combinations are selected first. A stable greedy pairwise pass then maximizes coverage within the risk budget; every omitted combination carries a reason.

Every selected identity receives navigation, direct-route, action, API, entity-access, export, log, and error-page isolation checks. Cross-tenant observations are always critical. The plan digest is derived solely from normalized dimensions, selected combinations, and mandatory rules, so recorded inputs reproduce the same plan.

Risk budgets may reduce redundant combinations but may never omit a mandatory critical combination. Release reporting uses the plan's measured pair and critical coverage rather than estimating coverage from test files.
