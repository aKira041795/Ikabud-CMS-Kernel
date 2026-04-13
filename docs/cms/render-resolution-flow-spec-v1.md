# Render Resolution Flow Spec v1

## Purpose

This document defines the code-level resolution flow from route or template boundary to:

- logical contract template
- render profile id
- resolved schema stack

It complements:

- `docs/cms/render-schema-spec-v1.md`
- `docs/cms/context-profiles-spec-v1.md`

The goal is to make the current v1 runtime deterministic and hard to misuse without redesigning the public render stack.

---

## Scope

Render Resolution Flow v1 covers:

- logical template resolution for profile and contract matching
- profile resolution order
- schema stack resolution order
- precedence between shell and route-specific schemas
- theme-aware render path handling for canonical contract matching

Render Resolution Flow v1 does not yet cover:

- admin profile resolution beyond reserved names
- module-specific custom resolvers outside the current public surfaces
- schema ownership enforcement beyond log-only guidance

---

## Key Decision

Profile and schema resolution must use a logical contract template key, not the final physical template path rendered by the template engine.

That means:

- theme override paths must not change contract matching
- canonical helper mappings may preserve a stable contract template key even when the rendered file lives under a theme override
- route or canonical-family meaning wins over filesystem detail

---

## Resolution Inputs

The resolution flow may inspect these inputs:

- requested template key
- canonical helper mappings for known CMS template families
- route metadata already present in context such as `public_route_kind`, `public_render_origin`, and `public_presentation_mode`
- matched kernel render contracts
- registered profile definitions and their shell schema stacks

The resolution flow must not depend on DiSyL execution.

---

## Resolution Order

### Step 1: Resolve the logical contract template

Determine the stable template key used for contract matching and profile resolution.

Rules:

- use the canonical template family key when a helper has already resolved one
- otherwise use the requested template key
- do not switch to a theme override path for contract or profile resolution once a stable logical key is known

### Step 2: Resolve the render profile

Determine whether the request belongs to an explicitly known profile family.

Rules:

- explicit canonical CMS family resolution wins first
- otherwise inspect matched contracts for one unique valid `profile_hint`
- if hints disagree or no trusted hint exists, emit no profile id rather than guessing

### Step 3: Resolve the schema stack

Build the final ordered schema stack.

Rules:

- append profile-owned shell schemas first
- append matched contract schema ids in matched contract order
- deduplicate by schema id while preserving first appearance
- later schemas in the final stack are more specific and take precedence on overlapping roots

---

## Reference Pseudocode

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

---

## Current v1 Mapping

### Canonical CMS entity renders

Resolution shape:

1. canonical helper identifies `entity.view` or `entity.list`
2. logical contract template stays canonical even if a public theme override renders the file
3. profile resolves to `cms_public`
4. schema stack becomes:
   - `kernel.shell@1`
   - `cms.public.entity.view@1`

or:

   - `kernel.shell@1`
   - `cms.public.entity.list@1`

### Ecommerce public renders

Resolution shape:

1. logical contract template stays on the `modules/ecommerce/public/*` key
2. matched contracts contribute one shell contract plus one route-specific contract
3. unique valid `profile_hint=commerce_public` resolves the profile
4. schema stack becomes:
   - `kernel.shell@1`
   - `ecommerce.public.shell@1`
   - one route-specific ecommerce schema

Examples:

- shop: `kernel.shell@1`, `ecommerce.public.shell@1`, `ecommerce.public.catalog@1`
- product: `kernel.shell@1`, `ecommerce.public.shell@1`, `ecommerce.public.product@1`
- cart: `kernel.shell@1`, `ecommerce.public.shell@1`, `ecommerce.public.cart@1`

---

## Precedence Rules

When multiple schemas describe the same root key:

- later schema in the resolved stack wins
- broader shell layers establish defaults first
- route-specific layers may overwrite shell defaults with more specific values

Example:

- `kernel.shell@1 < ecommerce.public.shell@1 < ecommerce.public.product@1`

This means product-specific roots or overrides win over commerce shell values, and commerce shell values win over generic kernel shell defaults.

---

## Guardrails

- `profile_hint` is a v1 bridge for narrow contract families, not a universal truth source
- explicit canonical-family resolution is stronger than `profile_hint`
- if a trusted profile cannot be resolved deterministically, emit no profile id
- physical theme override paths must not change matched contract ids or schema stack resolution

---

## Suggested Test Coverage

Add or preserve coverage for:

1. canonical CMS routes resolve the same profile and schema stack even when theme-aware template resolution changes the physical file path
2. ecommerce public routes resolve one shell schema plus one route-specific schema in stable order
3. overlapping root precedence follows final stack order
4. unknown or conflicting profile hints fall back to no profile id instead of guessing

---

## File-Level Starting Map

Runtime:

- `bootstrap.php`
- `kernel/App.php`
- `modules/cms/helpers/78-public-context.php`
- `modules/ecommerce/helpers/05-render-contracts.php`

Docs:

- `docs/cms/render-schema-spec-v1.md`
- `docs/cms/context-profiles-spec-v1.md`

Tests:

- `tests/render_context_contracts_test.php`
- `tests/cms_public_entity_contract_test.php`
