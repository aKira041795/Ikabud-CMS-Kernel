<?php

declare(strict_types=1);

function ecAdminWebhooks(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();
        $input = ecInput();
        $action = trim((string)($input['action'] ?? 'save'));
        $webhookId = isset($input['webhook_id']) ? (int)$input['webhook_id'] : 0;

        try {
            if ($action === 'delete') {
                ecOutboundWebhookDelete($webhookId);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Webhook deleted.'];
            } elseif ($action === 'test') {
                $result = ecOutboundWebhookSendTest($webhookId);
                $_SESSION['ec_message'] = !empty($result['ok'])
                    ? ['type' => 'success', 'text' => 'Test delivery completed.']
                    : ['type' => 'error', 'text' => 'Test delivery failed: ' . (string)($result['response_body'] ?? $result['error'] ?? 'unknown error')];
            } else {
                $savedId = ecOutboundWebhookSave([
                    'name' => $input['name'] ?? '',
                    'target_url' => $input['target_url'] ?? '',
                    'signing_secret' => $input['signing_secret'] ?? '',
                    'event_patterns' => $input['event_patterns'] ?? '',
                    'is_active' => !empty($input['is_active']),
                ], $webhookId > 0 ? $webhookId : null);
                $_SESSION['ec_message'] = ['type' => 'success', 'text' => $webhookId > 0 ? 'Webhook updated.' : 'Webhook created.'];
                header('Location: /ecommerce/admin/webhooks?edit=' . $savedId);
                exit;
            }
        } catch (\Throwable $e) {
            $_SESSION['ec_message'] = ['type' => 'error', 'text' => $e->getMessage()];
        }

        header('Location: /ecommerce/admin/webhooks');
        exit;
    }

    $editId = max(0, (int)(ecInput()['edit'] ?? 0));
    $editWebhook = $editId > 0 ? ecOutboundWebhookGet($editId) : null;
    if (!is_array($editWebhook)) {
        $editWebhook = [
            'id' => 0,
            'name' => '',
            'target_url' => '',
            'signing_secret' => ecOutboundWebhookGenerateSecret(),
            'event_patterns' => ['ecommerce.order.created', 'ecommerce.order.paid', 'ecommerce.order.refunded'],
            'event_patterns_text' => "ecommerce.order.created\necommerce.order.paid\necommerce.order.refunded",
            'is_active' => true,
        ];
    }

    $ctx = ecAdminContext($user, 'webhooks', [
        'page_title' => 'Ecommerce — Webhooks',
        'webhooks' => ecOutboundWebhookList(false),
        'recent_deliveries' => ecOutboundWebhookRecentDeliveries(25),
        'edit_webhook' => $editWebhook,
        'message' => $_SESSION['ec_message'] ?? null,
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/webhooks.disyl', $ctx);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}