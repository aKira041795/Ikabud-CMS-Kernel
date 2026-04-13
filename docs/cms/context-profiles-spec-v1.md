# Context Profiles Spec v1

## Purpose

This document defines the first implementation-ready context profile model for the render pipeline.

It sits beside `docs/cms/render-schema-spec-v1.md`.

Resolution flow companion spec: `docs/cms/render-resolution-flow-spec-v1.md`

Render schemas define stable data shapes.
Context profiles define which schema family, enrichers, and route policies apply to a request before DiSyL renders.

The immediate goal is to make profile resolution explicit for the public render surfaces that already exist, without redesigning every render path in the system.

---

## Scope

Context Profiles v1 covers:

- profile naming
- profile resolution rules for the current public render paths
- profile-owned shell schema composition
- required runtime metadata fields
- logging and testing expectations

Context Profiles v1 does not yet cover:

- a universal profile system for every admin/module screen
- per-module custom profile registries
- profile-driven theme policy overrides beyond current route logic
- DiSyL syntax changes

---

## Key Decision

Profiles are resolved at the render boundary, not inside templates.

That means:

- route classification happens before DiSyL executes
- modules and kernel helpers decide the profile
- templates consume the resulting stable context and metadata

Profiles are operational runtime metadata. They are not template-selectable modes.

---

## Naming Rules

Profile ids use lowercase snake_case.

Approved v1 names:

- `cms_public`
- `commerce_public`
- `admin`
- `shell_only`
- `guidance_public`

Rules:

- a profile names an experience family, not a single template file
- a profile may own one or more shell schemas
- a profile may resolve to different route-specific schemas
- a profile id must be stable in docs, logs, tests, and runtime metadata

---

## Runtime Metadata Contract

Once a profile is resolved, the render context should be able to report:

```php
[
    'render_profile_id' => 'commerce_public',
    'render_schema_stack' => [
        'kernel.shell@1',
        'ecommerce.public.shell@1',
        'ecommerce.public.catalog@1',
    ],
]
```

Rules:

- `render_profile_id` is a single string or an empty string when no profile is resolved.
- `render_schema_stack` is ordered from broadest layer to most specific layer.
- profile-owned shell schemas appear before route-specific schemas.
- when multiple schemas in the resolved stack describe the same root key, the later schema takes precedence.
- the runtime may set these fields even when no mismatch occurs.

---

## Resolution Model

Context Profiles v1 uses deterministic resolution from the template boundary plus existing route metadata.

### Resolution order

1. Resolve a logical contract template key from canonical helper mappings or the requested template key.
2. Check whether that logical template belongs to a known canonical CMS template family.
3. Otherwise inspect matched kernel render-contract registrations for one unique valid `profile_hint`.
4. Fall back to no profile when the route family is outside the v1 scope.

### Foundation constraints

For the first implementation pass:

- canonical CMS entity view and entity list renders resolve through explicit CMS helpers
- ecommerce public renders resolve through profile hints attached to existing kernel contracts
- non-public module/admin screens do not need profile resolution yet unless they already have a clear shared shell contract
- `profile_hint` is advisory bridge metadata for v1 and must not override stronger canonical helper mappings or an explicit route-family resolver
- physical theme override paths must not change profile or schema resolution once a stable logical contract template key is known

## Code-Level Resolution Flow

The v1 runtime should stay simple and deterministic.

### Flow summary

1. Resolve a logical contract template key.
2. Resolve one profile id or none.
3. Resolve one ordered schema stack.
4. Apply precedence from left to right, with the later schema winning for overlapping roots.

### Reference pseudocode

```php
function resolveLogicalContractTemplate(string $template, array $context): string
{
  $logicalTemplate = trim((string)($context['logical_contract_template'] ?? ''));
  return $logicalTemplate !== '' ? $logicalTemplate : $template;
}

function resolveRenderProfileId(string $template, array $context): string
{
  $logicalTemplate = resolveLogicalContractTemplate($template, $context);

  if (isCmsCanonicalTemplateFamily($logicalTemplate)) {
    return 'cms_public';
  }

  $contracts = kernelMatchedRenderContextContracts($logicalTemplate);
  $validHints = [];

  foreach ($contracts as $contract) {
    $hint = trim((string)($contract['profile_hint'] ?? ''));
    if ($hint !== '' && profileRegistryHas($hint)) {
      $validHints[$hint] = true;
    }
  }

  if (count($validHints) === 1) {
    return array_key_first($validHints) ?: '';
  }

  return '';
}

function resolveRenderSchemaStack(string $template, array $context, string $profileId): array
{
  $logicalTemplate = resolveLogicalContractTemplate($template, $context);
  $contracts = kernelMatchedRenderContextContracts($logicalTemplate);
  $stack = profileShellSchemas($profileId);

  foreach ($contracts as $contract) {
    $schemaId = trim((string)($contract['schema_id'] ?? ''));
    if ($schemaId !== '' && !in_array($schemaId, $stack, true)) {
      $stack[] = $schemaId;
    }
  }

  return $stack;
}
```

### Operational notes

- canonical CMS mappings are stronger than contract hints
- `profile_hint` remains acceptable for v1 families such as `commerce_public` where contract registrations already map one-to-one to the profile
- if the runtime cannot derive one trusted profile, it should emit no profile id rather than guessing
- theme-aware rendering may change the physical file path, but not the logical template used for contract and profile resolution

---

## Initial Profiles

### `cms_public`

Use for:

- canonical CMS entity pages
- canonical CMS entity list pages
- CMS public renders that pass through the public theme shell and canonical entity contract layer

Current resolution rules:

- any canonical `entity.view.disyl` render resolves to `cms_public`
- any canonical `entity.list.disyl` render resolves to `cms_public`

Foundation schema stack:

- `kernel.shell@1`
- one of:
  - `cms.public.entity.view@1`
  - `cms.public.entity.list@1`

Primary runtime producers:

- `modules/cms/helpers/78-public-context.php`
- CMS public entity projection helpers
- CMS theme/customizer shell helpers

Route-kind expectations:

- `post`
- `page`
- entity-type specific kinds such as `product` when rendered through the canonical CMS entity template family

### `commerce_public`

Use for:

- storefront catalog routes
- product detail routes
- cart routes
- checkout routes
- order history/detail/confirmation routes

Current resolution rules:

- any render matched by `ecommerce.public.*` contracts with `profile_hint=commerce_public`

Foundation schema stack:

- `kernel.shell@1`
- `ecommerce.public.shell@1`
- one of:
  - `ecommerce.public.catalog@1`
  - `ecommerce.public.product@1`
  - `ecommerce.public.cart@1`
  - `ecommerce.public.checkout@1`
  - `ecommerce.public.orders@1`
  - `ecommerce.public.order.detail@1`
  - `ecommerce.public.order.confirmation@1`

Primary runtime producers:

- `modules/ecommerce/helpers/05-render-contracts.php`
- `modules/cms/helpers/78-public-context.php`

Route-kind expectations:

- `shop_index`
- `shop_category`
- `product_detail`
- `cart`
- `checkout`
- `my_orders`
- `order_detail`
- `order_confirmation`

### `admin`

Reserved for the next pass.

Reason:

- current admin render contracts are real, but they still need an inventory pass before one shared admin profile contract is declared as stable

### `shell_only`

Reserved for the next pass.

Reason:

- the current foundation work is centered on route-bearing public renders, not thin shell-only pages

### `guidance_public`

Reserved for the next pass.

Reason:

- no separate public guidance experience family is being formalized in the first implementation pass

---

## Composition Rules

### Rule 1

Profiles compose schemas; they do not replace schema ids.

### Rule 2

Profiles own shell-level composition.

For v1, both `cms_public` and `commerce_public` include `kernel.shell@1`.

### Rule 3

Route-specific schemas stay explicit.

For example, `commerce_public` does not collapse catalog and product pages into one generic schema. The profile stays the same while the route-specific schema changes.

### Rule 4

If the runtime cannot resolve a trusted profile, it should emit no profile id rather than guessing.

### Rule 5

Physical theme override paths must not change the resolved profile or schema stack once a stable logical contract template key exists.

### Rule 6

Profile resolution does not weaken schema enforcement. If a required root is missing from all trusted producers, Render Schema v1 should log the mismatch even when normalization supplies a fallback.

---

## Suggested Runtime Shape

The runtime should use a small explicit kernel profile registry in the first pass.

It only needs deterministic helpers that can answer:

- what profile applies to this template/context?
- which shell schema ids come from that registered profile?
- which route-specific schema ids came from matched contracts or canonical CMS helpers?

An acceptable v1 shape is:

```php
kernelRegisterRenderContextProfile('commerce_public', [
  'shell_schema_stack' => ['kernel.shell@1'],
]);

kernelRegisterRenderContextContract('ecommerce.public.catalog', [
  'schema_id' => 'ecommerce.public.catalog@1',
  'profile_hint' => 'commerce_public',
]);
```

combined with helper-level CMS canonical mappings for:

- `entity.view` -> `cms_public` + `cms.public.entity.view@1`
- `entity.list` -> `cms_public` + `cms.public.entity.list@1`

The concrete v1 flow is specified in `docs/cms/render-resolution-flow-spec-v1.md`.

---

## Logging Requirements

Mismatch and trace logs should move toward these fields:

- `template`
- `render_profile_id`
- `render_schema_stack`
- `contract`
- `public_render_origin`
- `public_route_kind`
- `public_presentation_mode`
- `missing_keys`
- `type_mismatches`

The first implementation may keep current event names such as:

- `cms.render_context.contract_mismatch`
- `ecommerce.render_context.contract_mismatch`

---

## Test Requirements

Context Profiles v1 should add or update coverage for:

1. `commerce_public` profile resolution through existing ecommerce contract registrations
2. stable schema stack ordering for ecommerce public routes
3. `cms_public` profile metadata on canonical entity view/list normalization
4. mismatch log payloads including profile/schema metadata
5. preservation of current contract ids and current render normalization behavior
6. resolution using the logical contract template instead of a physical theme override path

Likely starting tests:

- `tests/render_context_contracts_test.php`
- `tests/cms_public_entity_contract_test.php`
- `tests/cms_theme_test.php`

---

## File-Level Starting Map

Runtime:

- `bootstrap.php`
- `kernel/App.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/05-render-contracts.php`

Docs:

- `docs/cms/render-schema-context-profiles-plan.md`
- `docs/cms/render-schema-spec-v1.md`
- `docs/cms/render-resolution-flow-spec-v1.md`

---

## First Implementation Sequence

1. Register foundation render profiles in the kernel.
2. Add additive profile/schema metadata support to the kernel render-contract registry.
3. Annotate ecommerce public contracts with `schema_id` and `profile_hint`.
4. Add CMS canonical helpers that emit `cms_public` plus the canonical schema id.
5. Include profile/schema metadata in mismatch logging.
6. Freeze the names in tests.

That is sufficient for Context Profiles v1 Foundation without taking on broader admin or module profile work yet.
