<?php

declare(strict_types=1);

function tkCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('ticketing');
    if (!$ctx) {
        throw new \RuntimeException('Ticketing module context unavailable');
    }

    return $ctx;
}

function tkRender(string $template, array $context = []): string
{
    return tkCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function tkNormalizeListRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    $context = kernelApplyRenderContextShape($context, [
        'page_title' => 'Tickets',
        'tickets' => [],
        'stats' => [],
        'status_filter' => '',
        'priority_filter' => '',
        'category_filter' => '',
    ], ['page_title', 'tickets', 'stats', 'status_filter', 'priority_filter', 'category_filter'], $missingKeys, $typeMismatches);

    $context['stats'] = kernelApplyRenderContextShape($context['stats'], [
        'open' => 0,
        'in_progress' => 0,
        'resolved' => 0,
        'closed' => 0,
        'total' => 0,
    ], ['open', 'in_progress', 'resolved', 'closed', 'total'], $missingKeys, $typeMismatches, 'stats.');

    return $context;
}

function tkNormalizeCreateRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'New Ticket',
        'users' => [],
    ], ['page_title', 'users'], $missingKeys, $typeMismatches);
}

function tkNormalizeTicketDetailRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => '',
        'ticket' => [],
        'comments' => [],
        'attachments' => [],
        'users' => [],
    ], ['page_title', 'ticket', 'comments', 'attachments', 'users'], $missingKeys, $typeMismatches);
}

function tkNormalizePublicSubmitRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Submit a Maintenance Request',
        'captcha_question' => '',
        'captcha_token' => '',
        'base_url' => '',
    ], ['page_title', 'captcha_question', 'captcha_token', 'base_url'], $missingKeys, $typeMismatches);
}

function tkNormalizePublicSuccessRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Request Submitted',
        'ticket_no' => '',
        'base_url' => '',
    ], ['page_title', 'ticket_no', 'base_url'], $missingKeys, $typeMismatches);
}

function tkNormalizeSettingsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'page_title' => 'Ticketing - Settings',
        'settings' => [],
    ], ['page_title', 'settings'], $missingKeys, $typeMismatches);
}

function tkNormalizeCommentsRenderContext(array $context, string $template, array &$missingKeys = [], array &$typeMismatches = []): array
{
    return kernelApplyRenderContextShape($context, [
        'comments' => [],
    ], ['comments'], $missingKeys, $typeMismatches);
}

kernelRegisterRenderContextContract('ticketing.page.list', [
    'template' => 'modules/ticketing/list.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeListRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.create', [
    'template' => 'modules/ticketing/create.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeCreateRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.view', [
    'template' => 'modules/ticketing/view.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeTicketDetailRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.edit', [
    'template' => 'modules/ticketing/edit.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeTicketDetailRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.public-submit', [
    'template' => 'modules/ticketing/public-submit.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizePublicSubmitRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.public-success', [
    'template' => 'modules/ticketing/public-success.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizePublicSuccessRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.page.settings', [
    'template' => 'modules/ticketing/pages/settings.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeSettingsRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);

kernelRegisterRenderContextContract('ticketing.partial.comments', [
    'template' => 'modules/ticketing/partials/comments.disyl',
    'priority' => 20,
    'normalize' => 'tkNormalizeCommentsRenderContext',
    'log_event' => 'ticketing.render_context.contract_mismatch',
]);