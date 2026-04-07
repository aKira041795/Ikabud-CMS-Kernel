<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────
// Ecommerce Module — Admin Email Templates (handlers/52-admin-email-templates.php)
// ─────────────────────────────────────────────────────────────────────────

function ecAdminEmailTemplates(): void
{
    $user = ecRequireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_verify();

        try {
            $input = ecInput();
            ecPersistEmailTemplates(is_array($input) ? $input : []);
            $_SESSION['ec_message'] = ['type' => 'success', 'text' => 'Email templates saved.'];
        } catch (RuntimeException $e) {
            $_SESSION['ec_message'] = ['type' => 'error', 'text' => $e->getMessage()];
        } catch (Throwable $e) {
            write_log('ecAdminEmailTemplates save failed: ' . $e->getMessage(), 'error', ['module' => 'ecommerce']);
            $_SESSION['ec_message'] = ['type' => 'error', 'text' => 'Failed to save email templates.'];
        }

        header('Location: /ecommerce/admin/email-templates');
        exit;
    }

    $ctx = ecAdminContext($user, 'email_templates', [
        'page_title' => 'Ecommerce — Email Templates',
        'message' => $_SESSION['ec_message'] ?? null,
        'email_templates' => ecEmailTemplates(),
    ]);
    unset($_SESSION['ec_message']);

    ecRender('modules/ecommerce/admin/email-templates.disyl', $ctx);

    if (function_exists('releaseSessionAfterRender')) {
        releaseSessionAfterRender();
    }
}