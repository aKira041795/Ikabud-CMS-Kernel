<?php

declare(strict_types=1);

function cmsAdminAiAutomation(array $params = []): void
{
    $user = cmsRequireCap('ai.automation.manage');

    $plans = cmsAiAutomationListPlans(['limit' => 100]);
    $runs = cmsAiAutomationListRuns(['limit' => 50]);
    $searchSettings = function_exists('cmsAiAutomationSearchGroundingSettings')
        ? cmsAiAutomationSearchGroundingSettings()
        : ['search_grounding_provider' => '', 'search_grounding_api_key' => '', 'search_grounding_max_results' => 5];
    $searchConfig = [
        'configured' => ($searchSettings['search_grounding_provider'] ?? '') !== '' && ($searchSettings['search_grounding_api_key'] ?? '') !== '',
        'provider' => (string)($searchSettings['search_grounding_provider'] ?? ''),
        'max_results' => (int)($searchSettings['search_grounding_max_results'] ?? 5),
    ];

    echo cmsRender('modules/cms/admin/ai-automation.disyl', array_merge(cmsAdminContext($user, 'ai_automation', [
        ['label' => 'AI Automation', 'url' => ''],
    ]), [
        'page_title' => 'AI Content Automation',
        'plans_json' => json_encode($plans, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'runs_json' => json_encode($runs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'cadences_json' => json_encode(cmsAiAutomationAllowedCadences(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'visual_modes_json' => json_encode(cmsAiAutomationAllowedVisualModes(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'search_config_json' => json_encode($searchConfig, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]));
}

function cmsApiAiPlanList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('ai.automation.manage');

    echo json_encode([
        'ok' => true,
        'plans' => cmsAiAutomationListPlans([
            'limit' => cmsInput('limit', 50),
        ]),
    ]);
    exit;
}

function cmsApiAiPlanGet(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('ai.automation.manage');

    $id = (int)($params['id'] ?? 0);
    $plan = cmsAiAutomationGetPlan($id);
    if ($plan === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Plan not found']);
        exit;
    }

    echo json_encode(['ok' => true, 'plan' => $plan]);
    exit;
}

function cmsApiAiRunList(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('ai.automation.manage');

    $planId = (int)(cmsInput('plan_id', 0) ?? 0);

    echo json_encode([
        'ok' => true,
        'runs' => cmsAiAutomationListRuns([
            'limit' => cmsInput('limit', 50),
            'plan_id' => $planId > 0 ? $planId : null,
        ]),
    ]);
    exit;
}

function cmsApiAiPlanCreate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.automation.manage');
    app()->csrfEnforce();

    $result = cmsAiAutomationSavePlan(cmsInput(), null, $user);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['ok' => true, 'plan' => $result['plan'] ?? null]);
    exit;
}

function cmsApiAiPlanUpdate(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.automation.manage');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    $result = cmsAiAutomationSavePlan(cmsInput(), $id, $user);
    if (empty($result['ok'])) {
        http_response_code(($result['error'] ?? '') === 'Plan not found' ? 404 : 422);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['ok' => true, 'plan' => $result['plan'] ?? null]);
    exit;
}

function cmsApiAiPlanToggle(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.automation.manage');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    $result = cmsAiAutomationTogglePlan($id, $user);
    if (empty($result['ok'])) {
        http_response_code(($result['error'] ?? '') === 'Plan not found' ? 404 : 422);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['ok' => true, 'plan' => $result['plan'] ?? null]);
    exit;
}

function cmsApiAiPlanRunNow(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('ai.automation.manage');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    $plan = cmsAiAutomationGetPlan($id);
    if ($plan === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Plan not found']);
        exit;
    }

    $result = cmsAiAutomationExecutePlan($plan);
    if (empty($result['ok'])) {
        http_response_code(422);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['ok' => true] + $result);
    exit;
}

function cmsApiAiPlanDelete(array $params = []): void
{
    header('Content-Type: application/json');
    cmsRequireCap('ai.automation.manage');
    app()->csrfEnforce();

    $id = (int)($params['id'] ?? 0);
    $result = cmsAiAutomationDeletePlan($id);
    if (empty($result['ok'])) {
        http_response_code(($result['error'] ?? '') === 'Plan not found' ? 404 : 422);
        echo json_encode($result);
        exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

function cmsApiAiContentRefine(array $params = []): void
{
    header('Content-Type: application/json');
    $user = cmsRequireCap('ai.refine');
    app()->csrfEnforce();

    $contentId = (int)($params['id'] ?? 0);
    $feedback = trim((string)(cmsInput('feedback', '') ?? ''));
    $content = cmsAiAutomationLoadContentRecord($contentId);
    if ($content === null) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Content not found']);
        exit;
    }
    if (!cmsCanEditContent($user, $content)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Permission denied']);
        exit;
    }

    $result = cmsAiAutomationRefineContent($contentId, $feedback, $user);
    if (empty($result['ok'])) {
        http_response_code(($result['error'] ?? '') === 'Content not found' ? 404 : 422);
        echo json_encode($result);
        exit;
    }

    echo json_encode($result);
    exit;
}