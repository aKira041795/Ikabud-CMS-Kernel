# Render Schema, Context Profiles, and DiSyL Evolution Plan

## Purpose

This document turns the recent render-contract stabilization work into a concrete next-step plan.

The goal is not to make DiSyL "more powerful" in the abstract. The goal is to make rendering:

- deterministic
- inspectable
- versioned
- hard to bypass
- safe for multi-module and multi-tenant growth

This plan separates:

1. kernel/runtime work
2. render-contract/schema work
3. context-profile work
4. observability work
5. actual DiSyL language/tooling work

That separation matters because most of the next value is in the render pipeline, not in adding more template syntax.

---

## Core Direction

The platform should continue moving from:

- modules controlling render behavior ad hoc

toward:

- kernel-owned render orchestration
- named render schemas
- profile-driven context composition
- DiSyL as a declarative presentation language over validated data

The governing rule is:

- nothing renders without passing through the kernel render boundary

---

## Current State

The repo already has the foundation for this direction:

- shared render-context contract registration and normalization in `bootstrap.php`
- final render-context shaping in `kernel/App.php`
- CMS canonical public context assembly in `modules/cms/helpers/78-public-context.php`
- documented canonical entity-view contract in `docs/cms/entity-view-block-schema.md`
- wrapper-based contract preparation across multiple modules
- regression coverage for render-context contracts and theme-sensitive rendering

What is still missing is:

- a formal named render schema spec
- a first-class context profile model
- a render trace / inspection surface
- DiSyL linting that knows about contracts
- stronger rules for components and template inputs

---

## Definitions

### Render Schema

A versioned, named definition of the stable data shape available at a render boundary.

Examples:

- `kernel.shell@1`
- `cms.public.page@1`
- `cms.public.entity.view@1`
- `cms.public.entity.list@1`
- `ecommerce.public.catalog@1`
- `ecommerce.public.product@1`
- `admin.page@1`

A schema defines:

- required roots
- optional roots
- type expectations
- defaults
- deprecations
- ownership notes

### Context Profile

A named experience-level context composition that determines which schemas, enrichers, and policies apply to a request.

Examples:

- `cms_public`
- `commerce_public`
- `guidance_public`
- `admin`
- `shell_only`

A profile defines:

- base schema stack
- route classification rules
- context enrichment order
- behavior expectations
- observability labels

### Render Contract

The runtime normalization and validation step applied before DiSyL executes.

A render contract is the operational enforcement layer.
A render schema is the documented, versioned specification behind it.

---

## Design Constraints

These constraints should remain in force.

1. DiSyL remains declarative.
2. Business rules stay in kernel/module code, not templates.
3. Templates must not rely on undeclared or accidental context keys.
4. Module code must not bypass kernel render preparation.
5. Public render behavior must stay deterministic across theme and route variations.
6. Debug tooling must be cheap in production and rich in development.

---

## Non-Goals

This plan does not aim to:

- turn DiSyL into a reactive frontend framework
- create one universal schema for every render surface
- allow arbitrary module-defined template state outside declared contracts
- move business decision logic into templates
- replace current CMS canonical entity contracts with theme-specific forks

---

## Target Architecture

The desired pipeline is:

```text
Request
  -> Route Classification
  -> Context Profile Resolution
  -> Schema Stack Resolution
  -> Context Enrichment
  -> Contract Normalization
  -> Contract Validation
  -> Render Trace Capture
  -> DiSyL Render
```

Where:

- route classification picks the experience family
- profile resolution determines which schema family applies
- schema stack resolution merges shell + route-specific schemas
- enrichment fills the context from kernel and modules
- normalization guarantees minimum shape
- validation enforces required data and logs or fails in strict mode
- DiSyL renders over stable, documented inputs

---

## Workstreams

## 1. Render Schema Spec v1

### Objective

Define named, versioned schemas for the current render boundaries that already exist in practice.

### First schema set

Foundation pass should wire the render surfaces that already have stable runtime contracts:

- `kernel.shell@1`
- `cms.public.entity.view@1`
- `cms.public.entity.list@1`
- `ecommerce.public.shell@1`
- `ecommerce.public.catalog@1`
- `ecommerce.public.product@1`
- `ecommerce.public.cart@1`
- `ecommerce.public.checkout@1`
- `ecommerce.public.orders@1`
- `ecommerce.public.order.detail@1`
- `ecommerce.public.order.confirmation@1`

Reserve these names for the next pass after inventory work:

- `cms.public.page@1`
- `admin.page@1`

### Deliverables

1. A new schema spec document under `docs/`
2. A registry shape for schemas in the kernel
3. Schema metadata added to existing render contract registration
4. A clear deprecation story for renamed keys

### Required contents per schema

Each schema definition should include:

- schema id
- version
- description
- root keys
- required keys
- optional keys
- type expectations
- default semantics
- deprecated keys
- producer notes
- consumer notes

### Suggested implementation shape

Extend the current render contract registry so contracts can declare metadata like:

```php
[
    'id' => 'ecommerce.public.catalog',
    'schema_id' => 'ecommerce.public.catalog@1',
    'profile_hint' => 'commerce_public',
    'template' => 'modules/ecommerce/public/shop.disyl',
    'required' => [...],
    'normalize' => callable,
]
```

### Acceptance criteria

- every canonical CMS and ecommerce public render path maps to a named schema
- the schema names appear in logs and diagnostics
- docs and runtime names match exactly
- entity-view and entity-list schemas are documented without ambiguity

---

## 2. Context Profiles v1

### Objective

Stop treating every render as one global context shape.

### Initial profile set

Create these first:

- `shell_only`
- `cms_public`
- `commerce_public`
- `guidance_public`
- `admin`

### Responsibilities of a profile

Each profile should define:

- which schema family can apply
- which enrichers run
- which route kinds belong to it
- which modules are expected to contribute data
- whether strict validation is on by default

### Composition rules

Profiles should compose instead of duplicating everything.

Example:

- `commerce_public`
  - includes `kernel.shell@1`
  - includes CMS public shell/context rules
  - adds storefront route metadata
  - adds commerce-specific schema branches

### Suggested implementation points

Likely files to touch:

- `bootstrap.php`
- `kernel/App.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/00-init.php`

### Acceptance criteria

- every public render can report its resolved profile
- profile resolution is deterministic from route/context inputs
- route-scoped theme and storefront behavior are described by profiles instead of ad hoc branching

---

## 3. Render Observability

### Objective

Make it possible to answer:

- why did this page render this way?
- which schema/profile was used?
- which defaults were applied?
- which keys were missing?

### Minimum output

A render trace should capture:

- request id
- template key
- resolved profile
- matched schema ids
- normalization actions
- missing required keys
- strict-mode result
- final route kind
- theme source

### First implementation

Start with structured logs and a dev-only dump payload.

Possible modes:

- log-only in production
- response header or HTML comment in development
- optional admin/debug panel later

### Debug panel target

A later dev panel can show:

- matched schema stack
- final context roots
- validation failures/warnings
- template candidate selection
- theme/profile resolution notes

### Acceptance criteria

- a developer can identify the active profile and schema from one request
- missing contract data is visible without reverse-engineering templates
- theme/template selection can be traced without instrumenting templates manually

---

## 4. DiSyL Tooling Evolution

### Objective

Improve DiSyL as a platform language without turning it into a business-logic engine.

### Prioritized language-adjacent features

#### 4.1 Contract-aware linting

The linter should be able to warn when templates read keys not declared by the active schema.

Examples:

- unknown root variable
- deprecated key usage
- impossible branch on always-missing key

#### 4.2 Component prop schemas

Components should be able to declare:

- required props
- optional props
- default props
- accepted primitive types

This should improve composition and reviewability.

#### 4.3 Dev-time unresolved binding diagnostics

In development only, unresolved required bindings should be easier to spot.

This should happen through linting, traces, or debug output, not by crashing production renders unnecessarily.

### Explicit non-goal

Do not add complex new control-flow or stateful runtime behavior to DiSyL until schema/profile enforcement is stable.

### Acceptance criteria

- DiSyL lint can reason about schema-defined keys
- components can describe inputs explicitly
- developers get better feedback before runtime template drift reaches production

---

## Recommended Sequence

## Phase 1. Render Schema Spec v1

### Why first

Because schema drift is the core long-term risk.

### Tasks

1. Draft the schema document for current canonical render surfaces.
2. Add schema ids to the existing contract registry.
3. Update logging so schema ids appear in mismatch output.
4. Lock down naming conventions.

### Output

- spec doc
- runtime ids
- tests that assert schema/profile naming remains stable

---

## Phase 2. Context Profiles v1

### Why second

Because profiles give structure to schema selection and keep the schema set from becoming one giant global surface.

### Tasks

1. Define profile registry format.
2. Resolve profile from route/context.
3. Bind schemas to profiles.
4. Document profile composition rules.

### Output

- profile registry
- profile resolution helpers
- profile-aware tests for CMS and ecommerce routes

---

## Phase 3. Render Trace v1

### Why third

Because once schemas and profiles exist, trace output becomes meaningful.

### Tasks

1. Add structured trace capture.
2. Add opt-in development surfacing.
3. Add test coverage for trace metadata emission.

### Output

- trace logs
- request-linked diagnostics
- optional development-only debug output

---

## Phase 4. DiSyL Contract-aware Linting

### Why fourth

Because the linter should consume stable schemas and profiles, not lead them.

### Tasks

1. Define schema manifest format usable by lint.
2. Map templates to schema ids.
3. Warn on undeclared keys and deprecated roots.
4. Add component prop schema support if feasible in the same pass.

### Output

- linter improvements
- template contract checks in CI

---

## File-Level Starting Map

### Likely runtime files

- `bootstrap.php`
- `kernel/App.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/00-init.php`
- `kernel/DiSyL/TemplateEngine.php`
- DiSyL linter/CLI files

### Likely docs

- `docs/cms/disyl-implementation-spec.md`
- `docs/kernel/module-development-guide.md`
- `docs/cms/entity-view-block-schema.md`
- new schema/profile spec docs

### Likely tests

- render contract regression tests
- CMS theme/public context tests
- ecommerce public render tests
- DiSyL lint tests

---

## Proposed Initial Milestone

The first milestone should be intentionally narrow.

### Milestone name

Render Schema v1 Foundation

### Scope

- name and document current schema ids
- add profile ids for current public families
- surface schema/profile ids in logs
- no major new DiSyL syntax

### Done means

- canonical CMS entity renders declare named schemas
- canonical ecommerce public renders declare named schemas
- every foundation public render can report its profile
- current tests continue to pass
- new docs explain how module authors hook into schemas instead of inventing ad hoc context

---

## Risks

### Risk 1. Over-generalizing too early

Trying to design every profile and schema family at once will slow progress and create abstract complexity.

### Mitigation

Start with the render surfaces that already have stable behavior.

### Risk 2. Re-creating global context sprawl under new names

If profiles are too loose, they become a relabeled version of the current problem.

### Mitigation

Keep each profile narrow and route-driven.

### Risk 3. Adding too much language surface too soon

If DiSyL grows before schemas and profiles are stable, complexity will move into templates again.

### Mitigation

Sequence kernel/schema work before language expansion.

---

## Immediate Next Step

Implement the first follow-up spec:

- `Render Schema Spec v1`

Drafted here:

- `docs/cms/render-schema-spec-v1.md`
- `docs/cms/context-profiles-spec-v1.md`

That work should define the named schemas, profile names, and registry format without yet expanding DiSyL syntax.

Once that is documented and wired into runtime metadata, the next pass should implement `Context Profiles v1`.

---

## Decision Summary

These are approved directions:

- explicit render schemas
- context profiles
- render observability
- contract-aware DiSyL linting
- component prop schemas

These are deferred or rejected directions:

- large new DiSyL control-flow features
- business logic in templates
- one universal render schema for every surface
- ad hoc module bypasses around the kernel render boundary
