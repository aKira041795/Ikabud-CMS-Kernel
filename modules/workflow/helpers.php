<?php
/**
 * Workflow Module — Compatibility Shell
 *
 * STATUS: LEGACY COMPATIBILITY ONLY (v2)
 *
 * Workflow is a kernel primitive. Authoritative runtime behavior lives in:
 *   - kernel/WorkflowRuntime.php  (runtime)
 *   - kernel/App.php              (capability registration)
 *   - database/migrations/006_kernel_workflow_tables.sql (schema)
 *
 * This file exists only to preserve backward compatibility for code that
 * calls these helper functions directly. New code should use:
 *   - app()->cap()->call('workflow.state.get@1', ...)
 *   - app()->cap()->call('workflow.transition@1', ...)
 *   - app()->workflow()->allowedActions(...)
 *
 * REMOVAL CRITERIA (Stage 3):
 *   - No supported code paths call these functions directly
 *   - Tests cover kernel-owned workflow behavior directly
 *   - No installer or admin flow expects module-owned workflow identity
 *   - Documentation points only to kernel ownership
 *
 * @package Ikabud\Modules\Workflow
 * @deprecated Use kernel capability calls instead
 */

declare(strict_types=1);

function workflow_capability_handlers(): array
{
    return [];
}

function workflowEnsureCmsContentWorkflow(): void
{
    app()->workflow()->ensureCmsContentWorkflow();
}

function workflowGetDefinition(string $workflowKey, string $module, string $entityType): ?array
{
    return app()->workflow()->getDefinition($workflowKey, $module, $entityType);
}

function workflowAllowedActions(array $definition, string $state, ?string $role): array
{
    return app()->workflow()->allowedActions($definition, $state, $role);
}

function workflowGetOrCreateInstance(string $workflowKey, string $module, string $entityType, string $entityId, string $defaultState): ?array
{
    return app()->workflow()->getOrCreateInstance($workflowKey, $module, $entityType, $entityId, $defaultState);
}
