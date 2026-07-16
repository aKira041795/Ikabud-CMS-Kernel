# Component and visual scenario governance

The versioned component catalog governs empty, loading, populated, validation, error, unauthorized, and degraded states for every ARK Workbench primitive. Required variants include desktop, tablet, mobile, light/dark theme, localization/long text, and standard/dense data.

`ComponentScenarioGovernance` validates catalog completeness, joins axe accessibility results to structural/screenshot comparisons, creates immutable approval artifacts for intentional baseline changes, and reports affected modules from their Workbench contracts. Critical or serious accessibility findings block release; changed screenshots cannot pass without explicit approval.

Execution tiers:

- Pull request: Chromium, critical states, structural and accessibility checks.
- Release: Chromium, Firefox, and WebKit across required viewports.
- Nightly: full state, theme, locale, and data-shape matrix.

Baselines are content-addressed. Approval records retain previous hash, approved hash, approver, reason, and timestamp. Updating a screenshot file alone never changes release eligibility.
