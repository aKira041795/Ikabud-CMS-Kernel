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

function tkEntityRow(array $ticket): array
{
    return [
        'id' => (int) ($ticket['id'] ?? 0),
        'ticket_no' => (string) ($ticket['ticket_no'] ?? ''),
        'subject' => (string) ($ticket['subject'] ?? ''),
        'status' => (string) ($ticket['status'] ?? ''),
        'priority' => (string) ($ticket['priority'] ?? ''),
        'category' => (string) ($ticket['category'] ?? ''),
        'source' => (string) ($ticket['source'] ?? ''),
        'created_at' => (string) ($ticket['created_at'] ?? ''),
        'updated_at' => (string) ($ticket['updated_at'] ?? ''),
        'created_by' => (int) ($ticket['created_by'] ?? 0),
        'assigned_to' => (int) ($ticket['assigned_to'] ?? 0),
        'creator_name' => (string) ($ticket['creator_name'] ?? ''),
        'assignee_name' => (string) ($ticket['assignee_name'] ?? ''),
    ];
}

function ticketing_cap_entity_list_ticket_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $limit = max(1, min(100, (int) ($data['limit'] ?? 25)));
    $status = trim((string) ($data['status'] ?? ''));
    $priority = trim((string) ($data['priority'] ?? ''));
    $category = trim((string) ($data['category'] ?? ''));

    $where = ['1=1'];
    $params = [];
    if ($status !== '') {
        $where[] = 't.status = :status';
        $params[':status'] = $status;
    }
    if ($priority !== '') {
        $where[] = 't.priority = :priority';
        $params[':priority'] = $priority;
    }
    if ($category !== '') {
        $where[] = 't.category = :category';
        $params[':category'] = $category;
    }

    $sql = 'SELECT t.*, c.full_name AS creator_name, a.full_name AS assignee_name '
        . 'FROM tickets t '
        . 'LEFT JOIN users c ON c.id = t.created_by '
        . 'LEFT JOIN users a ON a.id = t.assigned_to '
        . 'WHERE ' . implode(' AND ', $where) . ' '
        . 'ORDER BY t.created_at DESC LIMIT ' . $limit;
    $rows = tkCtx()->db()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC);
    $rows = is_array($rows) ? $rows : [];

    return [
        'rows' => array_values(array_map(static function (mixed $row): array {
            return tkEntityRow(is_array($row) ? $row : []);
        }, $rows)),
        'total' => count($rows),
    ];
}

function ticketing_cap_entity_get_ticket_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $data = is_array($payload) ? $payload : [];
    $id = (int) ($data['id'] ?? $data['entity_id'] ?? 0);
    $ticketNo = trim((string) ($data['ticket_no'] ?? ''));

    $where = '';
    $params = [];
    if ($id > 0) {
        $where = 't.id = :id';
        $params[':id'] = $id;
    } elseif ($ticketNo !== '') {
        $where = 't.ticket_no = :ticket_no';
        $params[':ticket_no'] = $ticketNo;
    } else {
        return [];
    }

    $sql = 'SELECT t.*, c.full_name AS creator_name, a.full_name AS assignee_name '
        . 'FROM tickets t '
        . 'LEFT JOIN users c ON c.id = t.created_by '
        . 'LEFT JOIN users a ON a.id = t.assigned_to '
        . 'WHERE ' . $where . ' LIMIT 1';
    $ticket = tkCtx()->db()->query($sql, $params)->fetch(\PDO::FETCH_ASSOC);

    return is_array($ticket) ? tkEntityRow($ticket) : [];
}

function ticketing_capability_handlers(): array
{
    return [
        'ticketing.create@1' => 'ticketing_cap_create_1',
        'entity.list.ticket@1' => 'ticketing_cap_entity_list_ticket_1',
        'entity.get.ticket@1' => 'ticketing_cap_entity_get_ticket_1',
    ];
}

function ticketing_cap_create_1(mixed $payload, string $resolvedCapabilityId = '', string $providerId = ''): array
{
    $input = is_array($payload) ? $payload : [];
    $subject = trim((string)($input['subject'] ?? ''));
    $createdBy = (int)($input['created_by'] ?? 0);
    if ($subject === '' || $createdBy < 1) {
        return ['ok' => false, 'error' => 'subject and created_by are required.'];
    }

    $priority = in_array(($input['priority'] ?? 'medium'), ['low', 'medium', 'high', 'urgent'], true)
        ? (string)$input['priority']
        : 'medium';
    $categories = ['plumbing', 'electrical', 'pest_control', 'common_area', 'security', 'other'];
    $category = in_array(($input['category'] ?? 'other'), $categories, true) ? (string)$input['category'] : 'other';
    $ticketNo = function_exists('tk_nextTicketNo') ? tk_nextTicketNo() : ('TKT-' . date('YmdHis') . '-' . bin2hex(random_bytes(2)));
    $db = tkCtx()->db();
    $stmt = $db->prepare(
        'INSERT INTO tickets (ticket_no, subject, description, priority, created_by, assigned_to, category, unit_no, source) '
        . 'VALUES (:no, :subject, :description, :priority, :created_by, :assigned_to, :category, :unit_no, :source)'
    );
    $stmt->execute([
        ':no' => $ticketNo,
        ':subject' => $subject,
        ':description' => trim((string)($input['description'] ?? '')) ?: null,
        ':priority' => $priority,
        ':created_by' => $createdBy,
        ':assigned_to' => !empty($input['assigned_to']) ? (int)$input['assigned_to'] : null,
        ':category' => $category,
        ':unit_no' => substr(trim((string)($input['unit_no'] ?? '')), 0, 40) ?: null,
        ':source' => trim((string)($input['source'] ?? 'capability')) ?: 'capability',
    ]);

    return ['ok' => true, 'ticket_id' => (int)$db->lastInsertId(), 'ticket_no' => $ticketNo];
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
