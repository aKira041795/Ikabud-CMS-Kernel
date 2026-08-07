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

    $entityId = (string)($payload['id'] ?? $payload['entity_id'] ?? '');

    // Delegate to the kernel workflow authority (workflow.state.get@1) when a
    // real CMS content entity id is available. This provider is the boundary
    // for the CMS content workflow, so it invokes the contract under the
    // canonical CMS caller identity (matching cmsApiContentWorkflowState).
    //
    // The call runs under the CMS module context: the kernel workflow
    // definition for cms.content is seeded/owned by the CMS module, and the
    // module-scoped DB routing (KernelPDO::setActiveModule) makes it invisible
    // while the cms-akira-workflow provider context is active.
    if ($entityId !== '') {
        try {
            $pushedCtx = function_exists('modulePushContext') ? modulePushContext('cms') : null;
            try {
                $res = app()->cap()->call('workflow.state.get@1', [
                    'workflow_key' => 'cms.content',
                    'module' => 'cms',
                    'entity_type' => 'cms_content',
                    'entity_id' => $entityId,
                ], ['caller_module' => 'cms']);
            } finally {
                if ($pushedCtx !== null && function_exists('modulePopContext')) {
                    modulePopContext();
                }
            }

            $wf = is_array($res['workflow'] ?? null) ? $res['workflow'] : null;
            if (is_array($wf)) {
                $actions = is_array($wf['allowed_actions'] ?? null) ? $wf['allowed_actions'] : [];
                $next = array_values(array_filter(array_map(
                    static fn ($a) => is_array($a) ? (string)($a['action'] ?? '') : '',
                    $actions
                ), static fn ($s) => $s !== ''));

                return [
                    'ok' => true,
                    'data' => [
                        'status' => (string)($wf['state'] ?? $status),
                        'next' => $next,
                        'workflow' => $wf,
                        'provider' => 'cms-akira-workflow',
                        'resolved_from' => 'kernel',
                    ],
                ];
            }
        } catch (Throwable $e) {
            // fall through to fallback
        }
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
            'resolved_from' => 'fallback',
        ],
    ];
}

// ── Event Listeners ──────────────────────────────────────────────
// Register inter-module event listeners here. Examples:
//
// app()->events()->listen('order.placed', function (array $payload, string $event) {
//     // React to events from other modules
// }, 10, 'cms-akira-workflow');
