<?php
/**
 * Cms Akira Workflow Module — Helpers
 *
 * This file is auto-loaded when the module is enabled.
 * Scoped helper functions provide isolated access to module context,
 * database, input, and rendering. Register event listeners here too.
 *
 * @see docs/kernel/module-development-guide.md
 * @see docs/kernel/module-quickstart.md
 */

declare(strict_types=1);

// ── Scoped Context Helpers ───────────────────────────────────────

function cawCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('cms-akira-workflow');
    if (!$ctx) {
        throw new \RuntimeException('Cms Akira Workflow module context unavailable');
    }
    return $ctx;
}

function cawDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return cawCtx()->db();
}

function cawInput(?string $key = null, mixed $default = null): mixed
{
    return cawCtx()->input($key, $default);
}

function cawRender(string $template, array $context = []): string
{
    $resolved = str_starts_with($template, 'modules/cms-akira-workflow/')
        ? $template
        : 'modules/cms-akira-workflow/' . ltrim($template, '/');

    return cawCtx()->render($resolved, kernelPrepareRenderContext($resolved, $context));
}

function cms_akira_workflow_capability_handlers(): array
{
    return [
        'akira.workflow.evaluate@1' => 'caw_cap_akira_workflow_evaluate_1',
    ];
}

function caw_cap_akira_workflow_evaluate_1(mixed $payload, string $capabilityId = 'akira.workflow.evaluate@1', string $caller = 'unknown'): array
{
    if (!is_array($payload)) {
        return ['ok' => false, 'error' => 'payload must be an object'];
    }

    $status = trim((string)($payload['status'] ?? 'draft'));
    if ($status === '') {
        $status = 'draft';
    }

    $next = match ($status) {
        'draft' => ['review'],
        'review' => ['published', 'draft'],
        'published' => [],
        default => ['draft'],
    };

    return [
        'ok' => true,
        'data' => [
            'status' => $status,
            'next' => $next,
            'provider' => 'cms-akira-workflow',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-workflow');
