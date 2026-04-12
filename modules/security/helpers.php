<?php
/**
 * Security Module — Helpers
 *
 * Core security functions + hook registration for global security enforcement.
 * Split helpers are auto-loaded from helpers/ directory.
 */

declare(strict_types=1);

// Auto-load split helper files.
foreach (glob(__DIR__ . '/helpers/*.php') as $_secHelperFile) {
    require_once $_secHelperFile;
}
unset($_secHelperFile);

// ── Module Context ────────────────────────────────────────────────────────

function securityCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module();
    if (!$ctx) {
        throw new \RuntimeException('Security module context unavailable');
    }
    return $ctx;
}

function securityDb(): \PDO
{
    return securityCtx()->db();
}

function securityGetSettings(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $defaults = securityDefaultSettings();
    $stored = readTenantModuleSettings('security');
    $cache = array_merge($defaults, $stored);
    return $cache;
}

function securityDefaultSettings(): array
{
    return [
        'enabled'                       => '1',
        'file_integrity_enabled'        => '1',
        'file_integrity_paths'          => '["kernel","src","modules","public/index.php","bootstrap.php","config"]',
        'admin_ip_allowlist_enabled'     => '0',
        'audit_log_enabled'             => '1',
        'audit_log_retention_days'      => '90',
        'auto_escalation_enabled'       => '1',
        'auto_escalation_threshold'     => '10',
        'auto_escalation_window_minutes' => '60',
    ];
}

function securityIsEnabled(): bool
{
    $settings = securityGetSettings();
    return ($settings['enabled'] ?? '0') === '1';
}

// ── Render Helper ─────────────────────────────────────────────────────────

function securityRender(string $template, array $context = []): string
{
    $ctx = securityCtx();
    $user = $ctx->user();
    $base = [
        'current_user' => $user,
        'module_id'    => 'security',
    ];
    return $ctx->render($template, array_merge($base, $context));
}

// ── Hook Registration ────────────────────────────────────────────────────

/**
 * Register security module hooks.
 * Called during module bootstrap (from module-manager after helpers are loaded).
 */
function securityRegisterHooks(): void
{
    if (!function_exists('app') || !app()->hooks()) {
        return;
    }

    $hooks = app()->hooks();

    // Admin IP allowlist enforcement — highest priority (before route matching).
    $hooks->on('kernel.request.before_dispatch', function (array $context) {
        return securityEnforceIpAllowlist($context);
    }, -5000);

    // Auto-escalation listener — check after anti-spam events.
    $hooks->on('kernel.event.antispam.blocked', function (array $payload) {
        securityHandleAntiSpamEvent($payload);
    }, 100);

    $hooks->on('kernel.event.antispam.honeypot.triggered', function (array $payload) {
        securityHandleAntiSpamEvent($payload);
    }, 100);
}

// ── Capability Handlers ──────────────────────────────────────────────────

function security_capability_handlers(): array
{
    return [
        'security.audit@1' => function (array $input): array {
            $eventType = $input['event_type'] ?? 'unknown';
            $severity  = $input['severity'] ?? 'info';
            $detail    = $input['detail'] ?? [];

            securityAuditLog($eventType, $severity, $detail);

            return ['ok' => true, 'logged' => true];
        },
    ];
}
