<?php

declare(strict_types=1);

function moodleIntegrationTemplateKey(string $relativePath): string
{
    return 'modules/moodle-integration/' . ltrim($relativePath, '/');
}

function moodleIntegrationCtx(): \Ikabud\Kernel\Contracts\ModuleContext
{
    $ctx = module('moodle-integration');
    if (!$ctx) {
        throw new \RuntimeException('Moodle Integration module context unavailable');
    }

    return $ctx;
}

function moodleIntegrationDb(): \Ikabud\Kernel\Contracts\ModuleDB
{
    return moodleIntegrationCtx()->db();
}

function moodleIntegrationInput(): array
{
    $input = moodleIntegrationCtx()->input();
    return is_array($input) ? $input : [];
}

// ── Capability Handlers ─────────────────────────────────────────────────

function moodle_integration_capability_handlers(): array
{
    return [
        'moodle.sso.validate@1' => 'moodle_integration_cap_sso_validate_1',
    ];
}

/**
 * Capability handler: moodle.sso.validate@1
 *
 * Validates an inbound SSO token from the Moodle-side plugin.
 * Atomically consumes the token (consume-once enforcement) and returns
 * the authenticated user + learning resource context.
 *
 * Expected payload: {token: string}
 * Returns: {ok: bool, user_id?: int, learning_resource_id?: int, tenant_id?: int, error?: string}
 *
 * @param mixed $payload
 * @return array
 */
function moodle_integration_cap_sso_validate_1(mixed $payload, string $capabilityId = '', string $providerId = ''): array
{
    $input = is_array($payload) ? $payload : [];
    $token = trim((string)($input['token'] ?? ''));
    if ($token === '') {
        return ['ok' => false, 'error' => 'SSO token is required'];
    }

    $tenantId = function_exists('moodleIntegrationCurrentTenantId')
        ? moodleIntegrationCurrentTenantId()
        : 0;
    if ($tenantId <= 0) {
        return ['ok' => false, 'error' => 'Tenant context unavailable'];
    }

    try {
        // Use the SSOService to validate the token (JWT structure, HMAC signature,
        // expiry, and atomic consume-once enforcement)
        if (!class_exists('\\MoodleIntegration\\Services\\SSOService')) {
            require_once __DIR__ . '/services/SSOService.php';
        }

        $ssoService = new \MoodleIntegration\Services\SSOService($tenantId);
        $result = $ssoService->validateInboundToken($token, $tenantId);

        if ($result === null) {
            return ['ok' => false, 'error' => 'Invalid, expired, or already consumed SSO token'];
        }

        return array_merge(['ok' => true], $result);
    } catch (\Throwable $e) {
        if (function_exists('write_log')) {
            write_log('moodle-integration cap: SSO validation failed: ' . $e->getMessage(), 'error');
        }
        return ['ok' => false, 'error' => 'SSO token validation failed'];
    }
}

function moodleIntegrationRender(string $relativePath, array $context = []): string
{
    $template = moodleIntegrationTemplateKey($relativePath);
    return moodleIntegrationCtx()->render($template, kernelPrepareRenderContext($template, $context));
}

function moodleIntegrationRenderStyledBlock(string $relativePath, array $context = []): string
{
    static $stylesInjected = false;

    $html = moodleIntegrationRender($relativePath, $context);
    if ($stylesInjected) {
        return $html;
    }

    $stylesInjected = true;
    return moodleIntegrationPublicThemeStyles() . $html;
}

function moodleIntegrationWithCmsContext(callable $callback): mixed
{
    if (function_exists('moduleWithContext')) {
        return moduleWithContext('cms', static function () use ($callback): mixed {
            return $callback();
        });
    }

    return $callback();
}

function moodleIntegrationPublicThemeStyles(): string
{
    return <<<'HTML'
<style>
.moodle-page{display:grid;gap:1.5rem;}
.moodle-page__intro,.moodle-panel--hero{position:relative;overflow:hidden;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 80%, white);border-radius:1.75rem;background:linear-gradient(135deg,color-mix(in srgb, var(--color-primary,#2563eb) 10%, white),color-mix(in srgb, var(--color-light-bg,#f8fafc) 88%, white));box-shadow:0 18px 44px rgba(15,23,42,.07);}
.moodle-page__intro::after,.moodle-panel--hero::after{content:"";position:absolute;inset:auto -4rem -5rem auto;width:12rem;height:12rem;border-radius:999px;background:color-mix(in srgb, var(--color-primary,#2563eb) 12%, transparent);filter:blur(2px);pointer-events:none;}
.moodle-page__intro-body{position:relative;z-index:1;padding:1.5rem;display:grid;gap:.9rem;}
.moodle-page__eyebrow{font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;font-weight:700;color:var(--color-primary,#2563eb);}
.moodle-page__lead{max-width:56rem;color:var(--color-text-muted,#64748b);font-size:1rem;line-height:1.7;margin:0;}
.moodle-page__meta{display:flex;flex-wrap:wrap;gap:.75rem;}
.moodle-page__pill,.moodle-stat-pill{display:inline-flex;align-items:center;gap:.45rem;padding:.45rem .8rem;border-radius:999px;background:rgba(255,255,255,.7);border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 75%, white);font-size:.78rem;font-weight:600;color:var(--color-text,#0f172a);backdrop-filter:blur(8px);}
.moodle-page__notice{padding:1rem 1.125rem;border:1px solid color-mix(in srgb, var(--color-warning,#d97706) 28%, white);border-radius:1rem;background:color-mix(in srgb, var(--color-warning,#d97706) 10%, white);color:var(--color-text,#1f2937);}
.moodle-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:1.25rem;}
.moodle-card,.moodle-panel,.moodle-progress-card{background:var(--color-surface,#fff);border:1px solid var(--color-border,#e5e7eb);border-radius:1.25rem;box-shadow:0 10px 30px rgba(15,23,42,.06);overflow:hidden;}
.moodle-card{display:flex;flex-direction:column;height:100%;}
.moodle-card__media{aspect-ratio:16/9;background:linear-gradient(135deg,color-mix(in srgb, var(--color-primary,#2563eb) 14%, white),color-mix(in srgb, var(--color-primary,#2563eb) 4%, white));overflow:hidden;}
.moodle-card__media img{display:block;width:100%;height:100%;object-fit:cover;}
.moodle-card__body,.moodle-panel__body,.moodle-progress-card__body{padding:1.25rem;}
.moodle-kicker{font-size:.78rem;letter-spacing:.12em;text-transform:uppercase;font-weight:700;color:var(--color-primary,#2563eb);margin-bottom:.5rem;}
.moodle-card__title,.moodle-panel__title,.moodle-progress-card__title{margin:0;font-size:1.35rem;line-height:1.3;color:var(--color-text,#111827);}
.moodle-card__summary,.moodle-panel__summary,.moodle-progress-card__meta{margin-top:.75rem;color:var(--color-text-muted,#64748b);line-height:1.7;}
.moodle-card__summary{display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}
.moodle-card__footer{display:flex;align-items:center;justify-content:space-between;gap:.75rem;margin-top:auto;padding:1rem 1.25rem 1.2rem;border-top:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 70%, white);background:color-mix(in srgb, var(--color-light-bg,#f8fafc) 55%, white);}
.moodle-card__footer-copy{font-size:.82rem;color:var(--color-text-muted,#64748b);}
.moodle-actions{display:flex;flex-wrap:wrap;gap:.75rem;margin-top:1rem;}
.moodle-button{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.78rem 1.1rem;border-radius:999px;border:1px solid var(--color-border,#d1d5db);font-weight:600;text-decoration:none;color:var(--color-text,#111827);background:var(--color-surface,#fff);transition:background-color .15s ease,border-color .15s ease,color .15s ease,transform .15s ease;}
.moodle-button:hover{border-color:var(--color-primary,#2563eb);color:var(--color-primary,#2563eb);transform:translateY(-1px);}
.moodle-button--primary{background:var(--color-primary,#2563eb);border-color:var(--color-primary,#2563eb);color:#fff;}
.moodle-button--primary:hover{background:color-mix(in srgb, var(--color-primary,#2563eb) 88%, black);border-color:color-mix(in srgb, var(--color-primary,#2563eb) 88%, black);color:#fff;}
.moodle-empty{padding:2rem;border:1px dashed var(--color-border,#d1d5db);border-radius:1.25rem;text-align:center;color:var(--color-text-muted,#64748b);background:color-mix(in srgb, var(--color-surface,#fff) 92%, var(--color-primary,#2563eb) 8%);}
.moodle-detail{display:grid;gap:1.5rem;}
.moodle-detail__grid{display:grid;gap:1.25rem;grid-template-columns:minmax(0,1.25fr) minmax(280px,.9fr);align-items:start;}
.moodle-detail__hero{aspect-ratio:16/8;background:linear-gradient(135deg,color-mix(in srgb, var(--color-primary,#2563eb) 18%, white),color-mix(in srgb, var(--color-primary,#2563eb) 3%, white));overflow:hidden;}
.moodle-detail__hero img{display:block;width:100%;height:100%;object-fit:cover;}
.moodle-detail__rail{display:grid;gap:1rem;}
.moodle-detail__aside{padding:1.25rem;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 80%, white);border-radius:1.25rem;background:color-mix(in srgb, var(--color-light-bg,#f8fafc) 58%, white);}
.moodle-detail__aside-title{margin:0 0 .65rem;font-size:.95rem;font-weight:700;color:var(--color-text,#111827);}
.moodle-detail__aside-copy{margin:0;color:var(--color-text-muted,#64748b);line-height:1.65;font-size:.92rem;}
.moodle-progress-list{display:grid;gap:1rem;}
.moodle-progress-card{display:grid;gap:0;}
.moodle-progress-card__header{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;}
.moodle-progress-bar{height:.7rem;background:color-mix(in srgb, var(--color-border,#e5e7eb) 70%, white);border-radius:999px;overflow:hidden;margin-top:.9rem;}
.moodle-progress-bar__value{display:block;height:100%;border-radius:999px;background:linear-gradient(90deg,var(--color-primary,#2563eb),color-mix(in srgb, var(--color-primary,#2563eb) 70%, white));}
.moodle-stat{font-size:.92rem;color:var(--color-text-muted,#64748b);}
.moodle-progress-card__footer{display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:.75rem;margin-top:1rem;padding-top:1rem;border-top:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 70%, white);}
.moodle-panel--warning{border-color:color-mix(in srgb, var(--color-warning,#d97706) 30%, white);background:color-mix(in srgb, var(--color-warning,#d97706) 8%, white);}
.moodle-dashboard,.moodle-progress-board{display:grid;gap:1.25rem;}
.moodle-dashboard__header,.moodle-progress-board__header{display:flex;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;gap:1rem;}
.moodle-dashboard__grid{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(280px,.85fr);gap:1rem;align-items:start;}
.moodle-dashboard__main,.moodle-dashboard__rail{display:grid;gap:1rem;align-content:start;}
.moodle-auth-card{position:relative;overflow:hidden;display:grid;gap:1rem;padding:1.35rem;border:1px solid color-mix(in srgb, var(--color-primary,#2563eb) 22%, white);border-radius:1.4rem;background:linear-gradient(135deg,color-mix(in srgb, var(--color-primary,#2563eb) 12%, white),color-mix(in srgb, #0f172a 6%, white));}
.moodle-auth-card::after{content:"";position:absolute;right:-2rem;top:-2rem;width:8rem;height:8rem;border-radius:999px;background:color-mix(in srgb, var(--color-primary,#2563eb) 14%, transparent);}
.moodle-auth-card__content,.moodle-auth-card__actions{position:relative;z-index:1;}
.moodle-auth-card__actions{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;}
.moodle-summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.9rem;flex:1 1 320px;}
.moodle-summary-card{padding:1rem 1.05rem;border-radius:1.15rem;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 72%, white);background:color-mix(in srgb, var(--color-light-bg,#f8fafc) 70%, white);}
.moodle-summary-card__label{font-size:.74rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:var(--color-text-muted,#64748b);}
.moodle-summary-card__value{margin-top:.35rem;font-size:1.65rem;line-height:1;font-weight:800;color:var(--color-text,#0f172a);}
.moodle-summary-card__note{margin-top:.35rem;font-size:.84rem;color:var(--color-text-muted,#64748b);}
.moodle-status-banner{padding:1rem 1.1rem;border:1px solid color-mix(in srgb, var(--color-primary,#2563eb) 24%, white);border-radius:1.2rem;background:color-mix(in srgb, var(--color-primary,#2563eb) 10%, white);color:color-mix(in srgb, var(--color-primary,#2563eb) 55%, #0f172a);}
.moodle-status-banner__actions{margin-top:.9rem;display:flex;flex-wrap:wrap;gap:.75rem;}
.moodle-status-banner--ready{border-color:color-mix(in srgb, #059669 28%, white);background:color-mix(in srgb, #059669 10%, white);color:#065f46;}
.moodle-status-banner--failed{border-color:color-mix(in srgb, #d97706 30%, white);background:color-mix(in srgb, #d97706 12%, white);color:#92400e;}
.moodle-empty--soft{padding:1.25rem 1.35rem;border-style:solid;text-align:left;}
.moodle-course-stack{display:grid;gap:1rem;}
.moodle-course-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:1rem;padding:1.15rem 1.2rem;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 76%, white);border-radius:1.2rem;background:var(--color-surface,#fff);box-shadow:0 10px 28px rgba(15,23,42,.05);}
.moodle-course-row--accent{border-color:color-mix(in srgb, var(--color-primary,#2563eb) 24%, white);background:linear-gradient(135deg,color-mix(in srgb, var(--color-primary,#2563eb) 8%, white),var(--color-surface,#fff));}
.moodle-course-row__main{min-width:0;}
.moodle-course-row__top{display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;justify-content:space-between;}
.moodle-course-row__title{margin:0;font-size:1.08rem;font-weight:700;color:var(--color-text,#0f172a);}
.moodle-course-row__meta{margin-top:.35rem;display:flex;flex-wrap:wrap;gap:.5rem;font-size:.84rem;color:var(--color-text-muted,#64748b);}
.moodle-course-row__aside{display:grid;gap:.65rem;align-content:start;justify-items:end;min-width:120px;}
.moodle-course-row__percent{font-size:1.25rem;font-weight:800;color:var(--color-text,#0f172a);}
.moodle-badge{display:inline-flex;align-items:center;border-radius:999px;padding:.38rem .72rem;background:color-mix(in srgb, var(--color-light-bg,#f8fafc) 78%, white);border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 76%, white);font-size:.76rem;font-weight:700;color:var(--color-text,#0f172a);}
.moodle-badge--success{background:color-mix(in srgb, #059669 10%, white);border-color:color-mix(in srgb, #059669 20%, white);color:#065f46;}
.moodle-badge--progress{background:color-mix(in srgb, var(--color-primary,#2563eb) 11%, white);border-color:color-mix(in srgb, var(--color-primary,#2563eb) 20%, white);color:color-mix(in srgb, var(--color-primary,#2563eb) 68%, #0f172a);}
.moodle-badge--idle{background:color-mix(in srgb, #64748b 10%, white);border-color:color-mix(in srgb, #64748b 14%, white);color:#475569;}
.moodle-progress-board__grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;}
.moodle-progress-metric{padding:1rem 1.1rem;border-radius:1.15rem;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 76%, white);background:linear-gradient(180deg,color-mix(in srgb, var(--color-light-bg,#f8fafc) 78%, white),white);}
.moodle-progress-metric__value{margin-top:.45rem;font-size:1.85rem;font-weight:800;color:var(--color-text,#0f172a);}
.moodle-progress-metric__label{font-size:.78rem;letter-spacing:.14em;text-transform:uppercase;font-weight:700;color:var(--color-text-muted,#64748b);}
.moodle-rail-card{padding:1.1rem 1.15rem;border:1px solid color-mix(in srgb, var(--color-border,#e5e7eb) 78%, white);border-radius:1.2rem;background:linear-gradient(180deg,color-mix(in srgb, var(--color-light-bg,#f8fafc) 74%, white),white);box-shadow:0 10px 26px rgba(15,23,42,.05);}
.moodle-rail-card__title{margin:0;font-size:1rem;font-weight:800;color:var(--color-text,#0f172a);}
.moodle-rail-card__copy{margin-top:.55rem;color:var(--color-text-muted,#64748b);line-height:1.65;font-size:.92rem;}
.moodle-step-list{display:grid;gap:.8rem;margin-top:.85rem;}
.moodle-step-item{display:grid;grid-template-columns:auto 1fr;gap:.75rem;align-items:start;}
.moodle-step-item__index{display:inline-flex;align-items:center;justify-content:center;width:1.8rem;height:1.8rem;border-radius:999px;background:color-mix(in srgb, var(--color-primary,#2563eb) 12%, white);border:1px solid color-mix(in srgb, var(--color-primary,#2563eb) 24%, white);font-size:.8rem;font-weight:800;color:color-mix(in srgb, var(--color-primary,#2563eb) 70%, #0f172a);}
.moodle-step-item__title{font-size:.92rem;font-weight:700;color:var(--color-text,#0f172a);}
.moodle-step-item__copy{margin-top:.15rem;color:var(--color-text-muted,#64748b);font-size:.85rem;line-height:1.55;}
.moodle-quick-links{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.9rem;}
@media (max-width: 960px){
    .moodle-detail__grid{grid-template-columns:1fr;}
    .moodle-dashboard__grid{grid-template-columns:1fr;}
    .moodle-course-row{grid-template-columns:1fr;}
    .moodle-course-row__aside{justify-items:start;min-width:0;}
}
@media (max-width: 640px){
    .moodle-page__intro-body{padding:1.1rem;}
    .moodle-card__body,.moodle-panel__body,.moodle-progress-card__body{padding:1rem;}
    .moodle-actions{flex-direction:column;align-items:stretch;}
    .moodle-button{width:100%;}
    .moodle-card__footer,.moodle-progress-card__footer{padding-left:1rem;padding-right:1rem;}
    .moodle-auth-card__actions{align-items:stretch;}
}
</style>
HTML;
}

function moodleIntegrationRenderPublicPage(string $relativePath, array $context = [], array $options = []): string
{
    $fragmentContext = array_merge($context, [
        'theme_styles' => moodleIntegrationPublicThemeStyles(),
    ]);
    $renderedHtml = moodleIntegrationRender($relativePath, $fragmentContext);

    if (!function_exists('cmsPublicCanonicalRenderEntityView')) {
        $cmsPublicHandlerPath = BASE_PATH . '/modules/cms/handlers/90-public.php';
        if (is_file($cmsPublicHandlerPath)) {
            require_once $cmsPublicHandlerPath;
        }
    }

    if (!function_exists('cmsPublicCanonicalRenderEntityView')) {
        return '<main class="cms-public-shell px-4 py-8">' . $renderedHtml . '</main>';
    }

    $requestPath = moodleIntegrationCurrentRequestPath();
    $pageTitle = trim((string)($context['page_title'] ?? $options['header_title'] ?? 'Learning'));
    $entity = [
        'id' => 0,
        'type' => 'page',
        'title' => $pageTitle,
        'slug' => trim((string)basename($requestPath), '/'),
        'url' => moodleIntegrationPath($requestPath),
        'status' => 'published',
        'published_at' => date('Y-m-d H:i:s'),
        'meta' => [],
    ];

    return moodleIntegrationWithCmsContext(static function () use ($entity, $options, $pageTitle, $renderedHtml): string {
        return cmsPublicCanonicalRenderEntityView($entity, [
            'content_type' => 'page',
            'meta' => [],
            'rendered_html' => $renderedHtml,
            'builder_enabled' => true,
            'builder_page_settings' => [
                'container_class' => 'cms-public-shell px-4 py-8',
            ],
            'force_hide_customized_sidebar' => true,
            'entity_view_context' => array_merge([
                'show_header' => true,
                'header_title' => $pageTitle,
                'show_meta' => false,
                'show_media' => false,
                'show_summary' => false,
                'show_taxonomies' => false,
                'show_back_link' => false,
                'bypass_shell' => true,
            ], is_array($options['entity_view_context'] ?? null) ? $options['entity_view_context'] : []),
            'public_render_origin' => 'cms',
            'public_route_kind' => (string)($options['public_route_kind'] ?? 'page'),
            'public_presentation_mode' => 'canonical',
        ]);
    });
}

function moodleIntegrationBaseUrl(): string
{
    return rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
}

function moodleIntegrationPath(string $path = ''): string
{
    return moodleIntegrationBaseUrl() . '/' . ltrim($path, '/');
}

function moodleIntegrationCurrentRequestPath(): string
{
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    $path = rtrim((string)$path, '/');
    return $path !== '' ? $path : '/';
}

function moodleIntegrationCurrentQueryParams(): array
{
    $query = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '');
    if ($query === '') {
        return [];
    }

    parse_str($query, $params);
    return is_array($params) ? $params : [];
}

function moodleIntegrationCanonicalPublicPath(string $path = ''): string
{
    $path = '/' . ltrim($path, '/');
    return '/cms' . ($path === '/' ? '' : $path);
}

function moodleIntegrationRedirectToCanonicalPublicPath(string $canonicalPath): void
{
    $canonicalPath = '/' . ltrim($canonicalPath, '/');
    if (moodleIntegrationCurrentRequestPath() === $canonicalPath) {
        return;
    }

    $query = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '');
    $location = moodleIntegrationPath($canonicalPath);
    if ($query !== '') {
        $location .= '?' . $query;
    }

    header('Location: ' . $location, true, 302);
    exit;
}

function moodleIntegrationSettingsDefaults(): array
{
    static $defaults = null;
    if ($defaults !== null) {
        return $defaults;
    }

    $defaults = [];
    $manifest = discoverModules()['moodle-integration'] ?? [];
    $fields = is_array($manifest['settings_fields'] ?? null) ? $manifest['settings_fields'] : [];
    foreach ($fields as $field) {
        if (!is_array($field)) {
            continue;
        }

        $key = trim((string)($field['key'] ?? ''));
        if ($key === '' || !array_key_exists('default', $field)) {
            continue;
        }

        $defaults[$key] = (string)$field['default'];
    }

    return $defaults;
}

function moodleIntegrationGetSettings(): array
{
    $saved = getModuleSettings('moodle-integration');
    return array_merge(moodleIntegrationSettingsDefaults(), is_array($saved) ? $saved : []);
}

function moodleIntegrationGetSettingsForTenant(int $tenantId = 0): array
{
    $tenantId = $tenantId > 0 ? $tenantId : moodleIntegrationCurrentTenantId();
    if ($tenantId > 0 && function_exists('getModuleSettingsForTenant')) {
        $saved = getModuleSettingsForTenant('moodle-integration', $tenantId);
        $merged = array_merge(moodleIntegrationSettingsDefaults(), is_array($saved) ? $saved : []);
    } else {
        $merged = moodleIntegrationGetSettings();
    }

    // Transparently decrypt secret fields stored as AES-256-GCM envelopes.
    foreach (['api_token', 'sso_secret'] as $secretKey) {
        if (isset($merged[$secretKey]) && $merged[$secretKey] !== '') {
            $merged[$secretKey] = moodleIntegrationDecryptSettingValue((string)$merged[$secretKey]);
        }
    }

    return $merged;
}

/**
 * Encrypt a secret setting value with the app encryption key.
 * Returns a JSON string containing the AES-256-GCM envelope; plain text is stored only
 * when no encryption key is available (fail-open with a warning log entry).
 */
function moodleIntegrationEncryptSettingValue(string $plaintext): string
{
    if ($plaintext === '') {
        return '';
    }

    try {
        $crypto = new \Ikabud\Kernel\Crypto();
        $envelope = $crypto->encryptString($plaintext);
        return json_encode(array_merge(['enc' => 1], $envelope), JSON_UNESCAPED_SLASHES);
    } catch (\Throwable $e) {
        // In production, missing or broken encryption key is a hard error — credentials must not be stored in plaintext.
        // In development/test environments, fail-open with a logged warning so local setups work without a key.
        $appEnv = 'development';
        try {
            $appEnv = trim((string)(function_exists('app') && method_exists(app(), 'config') ? (app()->config('env') ?? 'development') : ($_ENV['APP_ENV'] ?? 'development')));
        } catch (\Throwable $_) {}

        if ($appEnv === 'production') {
            throw new \RuntimeException('moodle-integration: APP_ENCRYPTION_KEY is missing or invalid — refusing to store secret in plaintext in production. Configure APP_ENCRYPTION_KEY. Original error: ' . $e->getMessage());
        }

        write_log('moodle-integration: could not encrypt setting value — ' . $e->getMessage() . '; storing plaintext (non-production only)', 'warning');
        return $plaintext;
    }
}

/**
 * Decrypt a setting value that may be an AES-256-GCM envelope produced by
 * moodleIntegrationEncryptSettingValue(). Returns the value as-is when it is
 * not an envelope (backward compatibility with existing plaintext values).
 */
function moodleIntegrationDecryptSettingValue(string $raw): string
{
    if ($raw === '') {
        return '';
    }

    $parsed = json_decode($raw, true);
    if (!is_array($parsed) || ($parsed['enc'] ?? 0) !== 1 || empty($parsed['ciphertext'])) {
        return $raw; // not an encrypted envelope — plaintext passthrough
    }

    try {
        $crypto = new \Ikabud\Kernel\Crypto();
        return $crypto->decryptString(
            (string)$parsed['ciphertext'],
            (string)$parsed['iv'],
            (string)$parsed['tag'],
            isset($parsed['key_id']) ? (string)$parsed['key_id'] : null
        );
    } catch (\Throwable $e) {
        write_log('moodle-integration: could not decrypt setting value — ' . $e->getMessage(), 'error');
        return '';
    }
}

function moodleIntegrationNormalizeEnrollmentMode(string $mode): string
{
    $mode = trim(strtolower($mode));
    if (in_array($mode, ['manual_review', 'auto_enroll', 'paid_then_auto'], true)) {
        return $mode;
    }

    return 'manual_review';
}

function moodleIntegrationSharedTenantCategoryId(?int $tenantId = null, ?array $settings = null): int
{
    $tenantId = ($tenantId ?? 0) > 0 ? (int)$tenantId : moodleIntegrationCurrentTenantId();
    $settings = is_array($settings) ? $settings : moodleIntegrationGetSettingsForTenant($tenantId);
    if (($settings['tenant_mode'] ?? 'per_instance') !== 'shared') {
        return 0;
    }

    $mapRaw = trim((string)($settings['shared_category_map_json'] ?? ''));
    if ($mapRaw === '') {
        return 0;
    }

    $map = json_decode($mapRaw, true);
    if (!is_array($map)) {
        return 0;
    }

    return isset($map[(string)$tenantId]) ? (int)$map[(string)$tenantId] : 0;
}

function moodleIntegrationCourseBelongsToTenant(array $course, ?int $tenantId = null, ?array $settings = null): bool
{
    $tenantId = ($tenantId ?? 0) > 0 ? (int)$tenantId : moodleIntegrationCurrentTenantId();
    $settings = is_array($settings) ? $settings : moodleIntegrationGetSettingsForTenant($tenantId);
    if (($settings['tenant_mode'] ?? 'per_instance') !== 'shared') {
        return true;
    }

    $expectedCategoryId = moodleIntegrationSharedTenantCategoryId($tenantId, $settings);
    if ($expectedCategoryId <= 0) {
        return false;
    }

    $courseCategoryId = (int)($course['moodle_category_id'] ?? $course['categoryid'] ?? $course['category_id'] ?? 0);
    return $courseCategoryId > 0 && $courseCategoryId === $expectedCategoryId;
}

function moodleIntegrationCategoryKey(string $value): string
{
    $value = strtolower(trim($value));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

function moodleIntegrationEnsureLearningResourceForTenant(int $tenantId, string $provider, string $providerId, string $title, array $metadata = []): int
{
    if ($tenantId <= 0 || $provider === '' || $providerId === '') {
        return 0;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return 0;
    }

    $stmt = $db->prepare(
        'INSERT INTO learning_resources (tenant_id, provider, provider_id, title, metadata_json, created_at, updated_at)
         VALUES (:tenant_id, :provider, :provider_id, :title, :metadata_json, NOW(), NOW())
         ON DUPLICATE KEY UPDATE title = VALUES(title), metadata_json = VALUES(metadata_json), status = \'active\', updated_at = NOW()'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':provider' => $provider,
        ':provider_id' => $providerId,
        ':title' => $title !== '' ? $title : 'Learning Resource',
        ':metadata_json' => $metadata !== [] ? json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null,
    ]);

    $lookup = $db->prepare('SELECT id FROM learning_resources WHERE tenant_id = :tenant_id AND provider = :provider AND provider_id = :provider_id LIMIT 1');
    $lookup->execute([
        ':tenant_id' => $tenantId,
        ':provider' => $provider,
        ':provider_id' => $providerId,
    ]);

    return (int)($lookup->fetchColumn() ?: 0);
}

function moodleIntegrationLearningResourceIdByMoodleCourseId(int $moodleCourseId, ?int $tenantId = null): int
{
    if ($moodleCourseId <= 0) {
        return 0;
    }

    $tenantId = ($tenantId ?? 0) > 0 ? (int)$tenantId : moodleIntegrationCurrentTenantId();
    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return 0;
    }

    $stmt = $db->prepare('SELECT resource_id FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND moodle_course_id = :moodle_course_id LIMIT 1');
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':moodle_course_id' => $moodleCourseId,
    ]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function moodleIntegrationRecordSsoTokenForTenant(int $tenantId, int $userId, int $learningResourceId, string $token, int $ttlSeconds = 60): bool
{
    if ($tenantId <= 0 || $userId <= 0 || $token === '') {
        return false;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return false;
    }

    $expiresAt = date('Y-m-d H:i:s', time() + max(30, min($ttlSeconds, 300)));
    $db->prepare('DELETE FROM moodle_sso_tokens WHERE tenant_id = :tenant_id AND (used_at IS NOT NULL OR expires_at < NOW())')->execute([
        ':tenant_id' => $tenantId,
    ]);

    $stmt = $db->prepare(
        'INSERT INTO moodle_sso_tokens (tenant_id, user_id, learning_resource_id, token_hash, expires_at, created_at)
         VALUES (:tenant_id, :user_id, :learning_resource_id, :token_hash, :expires_at, NOW())'
    );

    return $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':learning_resource_id' => $learningResourceId > 0 ? $learningResourceId : null,
        ':token_hash' => hash('sha256', $token),
        ':expires_at' => $expiresAt,
    ]);
}

function moodleIntegrationConsumeSsoTokenForTenant(int $tenantId, string $token): ?array
{
    if ($tenantId <= 0 || $token === '') {
        return null;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT * FROM moodle_sso_tokens
         WHERE tenant_id = :tenant_id AND token_hash = :token_hash AND used_at IS NULL AND expires_at >= NOW()
         LIMIT 1'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':token_hash' => hash('sha256', $token),
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }

    $update = $db->prepare('UPDATE moodle_sso_tokens SET used_at = NOW() WHERE tenant_id = :tenant_id AND id = :id AND used_at IS NULL');
    $update->execute([
        ':tenant_id' => $tenantId,
        ':id' => (int)$row['id'],
    ]);
    if ($update->rowCount() < 1) {
        return null;
    }

    return $row;
}

/**
 * Return the parsed capabilities array for a registered learning provider slug.
 * Returns an empty array if the provider row is not found or its capabilities_json is invalid.
 */
function moodleIntegrationGetProviderCapabilities(string $slug): array
{
    if ($slug === '') {
        return [];
    }

    $tenantId = moodleIntegrationCurrentTenantId();
    $db = $tenantId > 0 ? moodleIntegrationTenantDb($tenantId) : null;
    if (!$db instanceof \PDO) {
        return [];
    }

    try {
        $stmt = $db->prepare('SELECT capabilities_json FROM learning_providers WHERE slug = :slug AND is_active = 1 LIMIT 1');
        $stmt->execute([':slug' => $slug]);
        $json = $stmt->fetchColumn();
        if ($json === false || $json === null) {
            return [];
        }
        $decoded = json_decode((string)$json, true);
        return is_array($decoded) ? $decoded : [];
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * Return true if the named capability is truthy for a provider.
 * Capability keys match the capabilities_json keys (e.g. 'supports_sso').
 */
function moodleIntegrationProviderSupports(string $slug, string $capability): bool
{
    $caps = moodleIntegrationGetProviderCapabilities($slug);
    return !empty($caps[$capability]);
}

/**
 * Mark a learning resource as inactive (soft-delete from public views).
 * Historical progress and enrollment rows keep their FK reference intact.
 */
function moodleIntegrationDeactivateLearningResource(int $tenantId, int $resourceId): void
{
    if ($tenantId <= 0 || $resourceId <= 0) {
        return;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return;
    }

    $stmt = $db->prepare('UPDATE learning_resources SET status = \'inactive\', updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND status != \'inactive\'');
    $stmt->execute([':id' => $resourceId, ':tenant_id' => $tenantId]);
}

/**
 * Restore a previously inactive learning resource to active status.
 */
function moodleIntegrationActivateLearningResource(int $tenantId, int $resourceId): void
{
    if ($tenantId <= 0 || $resourceId <= 0) {
        return;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return;
    }

    $stmt = $db->prepare('UPDATE learning_resources SET status = \'active\', updated_at = NOW() WHERE id = :id AND tenant_id = :tenant_id AND status != \'active\'');
    $stmt->execute([':id' => $resourceId, ':tenant_id' => $tenantId]);
}

/**
 * Increment the outbound Moodle API call counter for the current minute window.
 * Returns false when the counter exceeds $maxPerMinute, signalling the caller to abort the call.
 * On DB error the function allows the call (fail-open) to avoid blocking legitimate syncs.
 */
function moodleIntegrationCheckAndRecordOutboundRequest(int $tenantId, int $maxPerMinute): bool
{
    if ($tenantId <= 0 || $maxPerMinute <= 0) {
        return true;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return true;
    }

    $windowStart = date('Y-m-d H:i:00');

    try {
        $stmt = $db->prepare(
            'INSERT INTO moodle_rate_limit (tenant_id, window_start, request_count, created_at, updated_at)
             VALUES (:tenant_id, :window_start, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE request_count = request_count + 1, updated_at = NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':window_start' => $windowStart]);

        $check = $db->prepare('SELECT request_count FROM moodle_rate_limit WHERE tenant_id = :tenant_id AND window_start = :window_start LIMIT 1');
        $check->execute([':tenant_id' => $tenantId, ':window_start' => $windowStart]);
        $count = (int)($check->fetchColumn() ?: 1);

        return $count <= $maxPerMinute;
    } catch (\Throwable $e) {
        return true;
    }
}

function moodleIntegrationIsConfigured(): bool
{
    $settings = moodleIntegrationGetSettings();
    return trim((string)($settings['moodle_url'] ?? '')) !== '';
}

function moodleIntegrationCurrentTenantId(): int
{
    if (function_exists('tenant_id')) {
        return (int)tenant_id();
    }

    if (function_exists('app') && method_exists(app(), 'tenant') && app()->tenant()->current() !== null) {
        return (int)app()->tenant()->current();
    }

    return 0;
}

function moodleIntegrationRequireUser(): array
{
    $user = app()->user();
    if (!is_array($user) || empty($user['id'])) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Authentication required']);
        exit;
    }

    return $user;
}

function moodleIntegrationRequirePageUser(?string $redirectPath = null): array
{
    $user = app()->user();
    if (is_array($user) && !empty($user['id'])) {
        return $user;
    }

    $redirectPath = $redirectPath !== null && $redirectPath !== ''
        ? $redirectPath
        : moodleIntegrationCurrentRequestPath();

    $query = (string)(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '');
    if ($query !== '') {
        $redirectPath .= (str_contains($redirectPath, '?') ? '&' : '?') . $query;
    }

    header('Location: ' . moodleIntegrationPath('/cms/login?redirect=' . urlencode($redirectPath)), true, 302);
    exit;
}

function moodleIntegrationCurrentUser(): ?array
{
    $user = app()->user();
    return is_array($user) && !empty($user['id']) ? $user : null;
}

function moodleIntegrationLearnerHomePath(): string
{
    return '/cms/page/my-learning';
}

function moodleIntegrationServiceRoutingEnabled(): bool
{
    $tenantId = moodleIntegrationCurrentTenantId();
    if ($tenantId > 0 && function_exists('isModuleEnabledForTenant')) {
        return isModuleEnabledForTenant('moodle-integration', $tenantId);
    }

    if (function_exists('getEnabledModules')) {
        $modules = getEnabledModules();
        return isset($modules['moodle-integration']);
    }

    return false;
}

function moodleIntegrationUserHasLearnerSignal(int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }

    $db = moodleIntegrationDb();
    $tenantId = moodleIntegrationCurrentTenantId();

    try {
        if ($tenantId > 0) {
            $progressStmt = $db->prepare(
                'SELECT COUNT(*) FROM moodle_user_progress WHERE tenant_id = :tenant_id AND user_id = :user_id'
            );
            $progressStmt->execute([
                ':tenant_id' => $tenantId,
                ':user_id' => $userId,
            ]);
        } else {
            $progressStmt = $db->prepare(
                'SELECT COUNT(*) FROM moodle_user_progress WHERE user_id = :user_id'
            );
            $progressStmt->execute([
                ':user_id' => $userId,
            ]);
        }
        if ((int)$progressStmt->fetchColumn() > 0) {
            return true;
        }

        if ($tenantId > 0) {
            $queueStmt = $db->prepare(
                'SELECT COUNT(*) FROM moodle_sync_queue WHERE tenant_id = :tenant_id AND type = :type AND payload_json LIKE :user_fragment'
            );
            $queueStmt->execute([
                ':tenant_id' => $tenantId,
                ':type' => 'enrollment',
                ':user_fragment' => '%"user_id":' . $userId . '%',
            ]);
        } else {
            $queueStmt = $db->prepare(
                'SELECT COUNT(*) FROM moodle_sync_queue WHERE type = :type AND payload_json LIKE :user_fragment'
            );
            $queueStmt->execute([
                ':type' => 'enrollment',
                ':user_fragment' => '%"user_id":' . $userId . '%',
            ]);
        }
        return (int)$queueStmt->fetchColumn() > 0;
    } catch (\Throwable $e) {
        return false;
    }
}

function moodleIntegrationResolveUserServiceContext(array $user): ?array
{
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0 || !moodleIntegrationServiceRoutingEnabled()) {
        return null;
    }

    $role = trim((string)($user['role'] ?? ''));
    if ($role !== '' && function_exists('cmsIsLearnerRole') && !cmsIsLearnerRole($role)) {
        return null;
    }

    $primaryService = function_exists('cmsDetectPrimaryUserService') ? cmsDetectPrimaryUserService($userId) : null;
    if ($primaryService !== null && $primaryService !== 'elearning') {
        return null;
    }

    if ($primaryService === 'elearning' || moodleIntegrationUserHasLearnerSignal($userId)) {
        return [
            'service' => 'elearning',
            'url' => moodleIntegrationLearnerHomePath(),
            'label' => 'My Learning',
            'source' => $primaryService === 'elearning' ? 'binding' : 'moodle_activity',
        ];
    }

    return null;
}

function moodleIntegrationLoginUrl(string $redirectPath): string
{
    return moodleIntegrationPath('/cms/login?redirect=' . urlencode($redirectPath));
}

function moodleIntegrationShortcodeAttrs(string $raw): array
{
    $attrs = [];
    if ($raw === '') {
        return $attrs;
    }

    if (preg_match_all('/([a-zA-Z_][a-zA-Z0-9_-]*)\s*=\s*(["\'])(.*?)\2/', $raw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $key = strtolower((string)($match[1] ?? ''));
            $value = html_entity_decode((string)($match[3] ?? ''), ENT_QUOTES, 'UTF-8');
            if ($key !== '') {
                $attrs[$key] = $value;
            }
        }
    }

    return $attrs;
}

function moodleIntegrationShortcodeValue(array $attrs, string $key, string $default = ''): string
{
    $value = trim((string)($attrs[$key] ?? $default));
    return $value !== '' ? $value : $default;
}

function moodleIntegrationRenderShortcode(string $tag, array $attrs = []): string
{
    $tag = strtolower(trim($tag));

    if (in_array($tag, ['moodle-courses', 'moodle_course_list'], true)) {
        return moodleIntegrationRenderCourseListBlock([
            'title' => moodleIntegrationShortcodeValue($attrs, 'title', 'Available Courses'),
            'limit' => (int)($attrs['limit'] ?? 6),
            'category' => moodleIntegrationShortcodeValue($attrs, 'category', ''),
            'category_id' => (int)($attrs['category_id'] ?? 0),
        ]);
    }

    if (in_array($tag, ['moodle-course-detail', 'moodle_course_detail'], true)) {
        return moodleIntegrationRenderCourseDetailBlock([
            'course_id' => (int)($attrs['course_id'] ?? $attrs['id'] ?? 0),
        ]);
    }

    if (in_array($tag, ['moodle-my-courses', 'moodle_my_courses'], true)) {
        return moodleIntegrationRenderMyCoursesBlock([
            'title' => moodleIntegrationShortcodeValue($attrs, 'title', 'My Courses'),
            'status' => moodleIntegrationShortcodeValue($attrs, 'status', ''),
        ]);
    }

    if (in_array($tag, ['moodle-progress', 'moodle_progress_dashboard'], true)) {
        return moodleIntegrationRenderProgressDashboardBlock([
            'title' => moodleIntegrationShortcodeValue($attrs, 'title', 'Learning Progress'),
        ]);
    }

    return '';
}

function moodleIntegrationResolveCmsDb(?int $tenantId = null): ?\PDO
{
    if ($tenantId !== null && $tenantId > 0 && function_exists('app') && method_exists(app(), 'dbForTenant')) {
        $db = app()->dbForTenant($tenantId);
        if ($db instanceof \PDO) {
            return $db;
        }
    }

    if (function_exists('cmsDb')) {
        $db = cmsDb();
        if ($db instanceof \PDO) {
            return $db;
        }
    }

    if (function_exists('app')) {
        try {
            $db = app()->db();
            if ($db instanceof \PDO) {
                return $db;
            }
        } catch (\Throwable $e) {
        }
    }

    return null;
}

function moodleIntegrationResolveCmsAuthorId(\PDO $db): int
{
    $user = moodleIntegrationCurrentUser();
    if (is_array($user) && !empty($user['id'])) {
        return (int)$user['id'];
    }

    try {
        $stmt = $db->query('SELECT id FROM cms_users ORDER BY id ASC LIMIT 1');
        $authorId = (int)($stmt ? $stmt->fetchColumn() : 0);
        if ($authorId > 0) {
            return $authorId;
        }
    } catch (\Throwable $e) {
    }

    return 0;
}

function moodleIntegrationAssignUserService(int $userId, string $service, bool $isPrimary = true, array $options = []): bool
{
    if ($userId <= 0 || $service === '' || !function_exists('cmsAssignUserService')) {
        return false;
    }

    try {
        return (bool)moodleIntegrationWithCmsContext(static function () use ($userId, $service, $isPrimary, $options): bool {
            return (bool)cmsAssignUserService($userId, $service, $isPrimary, $options);
        });
    } catch (\Throwable $e) {
        return false;
    }
}

function moodleIntegrationCmsPageSpecs(): array
{
    return [
        [
            'title' => 'Learning Center',
            'slug' => 'learning-center',
            'body' => "<p>Browse available learning paths, open course details, and start the enrollment flow that hands off into Moodle.</p>\n[moodle-courses title=\"Available Courses\" limit=\"9\"]",
            'excerpt' => 'Editable CMS landing page for the Moodle course catalog.',
            'menu_label' => 'Learning',
            'legacy_markers' => [
                'This site is connected to our Moodle training environment.',
                '<h2>Moodle Learning Center</h2>',
                'Use this page as the editable landing page for the Moodle integration.',
            ],
        ],
        [
            'title' => 'My Learning',
            'slug' => 'my-learning',
            'body' => "<p>Use this dashboard to follow your learner flow: queued enrollments, courses ready to launch, and progress already synced back from Moodle.</p>\n[moodle-my-courses title=\"My Learning\"]\n[moodle-progress title=\"Progress Dashboard\"]",
            'excerpt' => 'Learner dashboard for enrollment status, launches, and synced Moodle progress.',
            'menu_label' => '',
            'legacy_markers' => [
                'Show each signed-in learner their synced Moodle progress from the local cache.',
            ],
        ],
    ];
}

function moodleIntegrationShouldUpgradeLegacyPage(string $body, array $spec): bool
{
    $markers = is_array($spec['legacy_markers'] ?? null) ? $spec['legacy_markers'] : [];
    foreach ($markers as $marker) {
        if ($marker !== '' && str_contains($body, (string)$marker)) {
            return true;
        }
    }

    if (str_contains($body, '[moodle-') || str_contains($body, '[moodle_')) {
        return false;
    }

    return false;
}

function moodleIntegrationManagedPageBuilderSettings(): array
{
    return [
        'container_class' => 'cms-public-shell px-4 py-8',
    ];
}

function moodleIntegrationUpsertCmsContentMeta(\PDO $db, int $contentId, string $key, string $value): bool
{
    if ($contentId <= 0 || $key === '') {
        return false;
    }

    $selectStmt = $db->prepare('SELECT id, meta_value FROM cms_content_meta WHERE content_id = :content_id AND meta_key = :meta_key ORDER BY id ASC LIMIT 1');
    $selectStmt->execute([
        ':content_id' => $contentId,
        ':meta_key' => $key,
    ]);
    $existing = $selectStmt->fetch(\PDO::FETCH_ASSOC);
    if (is_array($existing) && !empty($existing['id'])) {
        if ((string)($existing['meta_value'] ?? '') === $value) {
            return false;
        }

        $updateStmt = $db->prepare('UPDATE cms_content_meta SET meta_value = :meta_value WHERE id = :id');
        $updateStmt->execute([
            ':meta_value' => $value,
            ':id' => (int)$existing['id'],
        ]);
        return true;
    }

    $insertStmt = $db->prepare('INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:content_id, :meta_key, :meta_value)');
    $insertStmt->execute([
        ':content_id' => $contentId,
        ':meta_key' => $key,
        ':meta_value' => $value,
    ]);
    return true;
}

function moodleIntegrationEnsureManagedPagePresentation(\PDO $db, int $contentId): bool
{
    if ($contentId <= 0) {
        return false;
    }

    $builderSettingsJson = json_encode(moodleIntegrationManagedPageBuilderSettings(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($builderSettingsJson) || $builderSettingsJson === '') {
        $builderSettingsJson = '{"container_class":"cms-public-shell px-4 py-8"}';
    }

    $changed = false;
    $changed = moodleIntegrationUpsertCmsContentMeta($db, $contentId, '_builder_enabled', '1') || $changed;
    $changed = moodleIntegrationUpsertCmsContentMeta($db, $contentId, '_builder_page_settings', $builderSettingsJson) || $changed;
    $changed = moodleIntegrationUpsertCmsContentMeta($db, $contentId, 'builder_show_sidebar_override', '0') || $changed;

    return $changed;
}

function moodleIntegrationEnsureCmsMenuItem(\PDO $db, string $label, string $url): bool
{
    if ($label === '' || $url === '') {
        return false;
    }

    try {
        $menuId = 0;

        $menuStmt = $db->prepare("SELECT menu_id FROM cms_menu_locations WHERE slug IN ('primary', 'header') AND menu_id IS NOT NULL ORDER BY FIELD(slug, 'primary', 'header'), id ASC LIMIT 1");
        $menuStmt->execute();
        $menuId = (int)($menuStmt->fetchColumn() ?: 0);

        if ($menuId <= 0) {
            $menuStmt = $db->query("SELECT id FROM cms_menus WHERE location IN ('primary', 'header') ORDER BY FIELD(location, 'primary', 'header'), id ASC LIMIT 1");
            $menuId = (int)($menuStmt ? $menuStmt->fetchColumn() : 0);
        }

        if ($menuId <= 0) {
            return false;
        }

        $existingStmt = $db->prepare('SELECT id FROM cms_menu_items WHERE menu_id = :menu_id AND url = :url LIMIT 1');
        $existingStmt->execute([
            ':menu_id' => $menuId,
            ':url' => $url,
        ]);
        if ((int)$existingStmt->fetchColumn() > 0) {
            return false;
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) FROM cms_menu_items WHERE menu_id = :menu_id');
        $sortStmt->execute([':menu_id' => $menuId]);
        $sortOrder = (int)$sortStmt->fetchColumn() + 10;

        $insertStmt = $db->prepare(
            'INSERT INTO cms_menu_items (menu_id, parent_id, label, url, link_type, link_ref, target, css_class, sort_order) VALUES (:menu_id, NULL, :label, :url, :link_type, NULL, :target, NULL, :sort_order)'
        );
        $insertStmt->execute([
            ':menu_id' => $menuId,
            ':label' => $label,
            ':url' => $url,
            ':link_type' => 'custom',
            ':target' => '_self',
            ':sort_order' => $sortOrder,
        ]);
        return true;
    } catch (\Throwable $e) {
        return false;
    }
}

function moodleIntegrationEnsureCmsPagesExist(?int $tenantId = null): array
{
    return moodleIntegrationWithCmsContext(static function () use ($tenantId): array {
        $db = moodleIntegrationResolveCmsDb($tenantId);
        if (!$db instanceof \PDO || !function_exists('cmsEnsureUniqueSlug')) {
            return ['pages_created' => [], 'pages_updated' => []];
        }

        $authorId = moodleIntegrationResolveCmsAuthorId($db);
        $created = [];
        $updated = [];
        $menuChanged = false;
        foreach (moodleIntegrationCmsPageSpecs() as $spec) {
            $slug = trim((string)($spec['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $existingStmt = $db->prepare("SELECT id, slug, body FROM cms_content WHERE type = 'page' AND slug = :slug AND deleted_at IS NULL LIMIT 1");
            $existingStmt->execute([':slug' => $slug]);
            $existing = $existingStmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($existing) && !empty($existing['id'])) {
                $pageChanged = moodleIntegrationEnsureManagedPagePresentation($db, (int)$existing['id']);
                if (!empty($spec['menu_label'])) {
                    $menuChanged = moodleIntegrationEnsureCmsMenuItem($db, (string)$spec['menu_label'], '/cms/page/' . (string)($existing['slug'] ?? $slug)) || $menuChanged;
                }
                if (moodleIntegrationShouldUpgradeLegacyPage((string)($existing['body'] ?? ''), $spec)) {
                    $updateStmt = $db->prepare('UPDATE cms_content SET body = :body, excerpt = :excerpt, updated_at = NOW() WHERE id = :id');
                    $updateStmt->execute([
                        ':body' => (string)($spec['body'] ?? ''),
                        ':excerpt' => (string)($spec['excerpt'] ?? ''),
                        ':id' => (int)$existing['id'],
                    ]);
                    $pageChanged = true;
                }
                if ($pageChanged) {
                    $updated[] = [
                        'title' => (string)($spec['title'] ?? 'Untitled'),
                        'slug' => (string)($existing['slug'] ?? $slug),
                    ];
                }
                continue;
            }

            if ($authorId <= 0) {
                continue;
            }

            $uniqueSlug = cmsEnsureUniqueSlug($slug, 'page');
            $stmt = $db->prepare(
                "INSERT INTO cms_content (uuid, title, slug, body, excerpt, type, status, author_id, featured_image_id, published_at, created_at)
                 VALUES (:uuid, :title, :slug, :body, :excerpt, 'page', 'published', :author_id, NULL, :published_at, NOW())"
            );
            $stmt->execute([
                ':uuid' => function_exists('cmsUuid') ? cmsUuid() : bin2hex(random_bytes(16)),
                ':title' => (string)($spec['title'] ?? 'Untitled'),
                ':slug' => $uniqueSlug,
                ':body' => (string)($spec['body'] ?? ''),
                ':excerpt' => (string)($spec['excerpt'] ?? ''),
                ':author_id' => $authorId,
                ':published_at' => date('Y-m-d H:i:s'),
            ]);

            $contentId = (int)$db->lastInsertId();
            moodleIntegrationEnsureManagedPagePresentation($db, $contentId);

            $created[] = [
                'title' => (string)($spec['title'] ?? 'Untitled'),
                'slug' => $uniqueSlug,
            ];

            if (!empty($spec['menu_label'])) {
                $menuChanged = moodleIntegrationEnsureCmsMenuItem($db, (string)$spec['menu_label'], '/cms/page/' . $uniqueSlug) || $menuChanged;
            }
        }

        if ($created !== [] || $updated !== [] || $menuChanged) {
            if (function_exists('cmsCacheFlushAll')) {
                cmsCacheFlushAll();
            } elseif (function_exists('pageCacheInvalidateModule')) {
                pageCacheInvalidateModule('cms');
            }
        }

        return [
            'pages_created' => $created,
            'pages_updated' => $updated,
        ];
    });
}

function moodleIntegrationRunCmsInstallSetup(array $context = []): array
{
    $tenantId = isset($context['tenant_id']) ? (int)$context['tenant_id'] : null;
    return moodleIntegrationEnsureCmsPagesExist($tenantId);
}

function moodleIntegrationManagedCmsPages(): array
{
    return moodleIntegrationWithCmsContext(static function (): array {
        $db = moodleIntegrationResolveCmsDb(moodleIntegrationCurrentTenantId());
        if (!$db instanceof \PDO) {
            return [];
        }

        $pages = [];
        foreach (moodleIntegrationCmsPageSpecs() as $spec) {
            $slug = trim((string)($spec['slug'] ?? ''));
            if ($slug === '') {
                continue;
            }

            $stmt = $db->prepare("SELECT id, title, slug, status FROM cms_content WHERE type = 'page' AND slug = :slug AND deleted_at IS NULL LIMIT 1");
            $stmt->execute([':slug' => $slug]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            $pages[] = [
                'title' => (string)($spec['title'] ?? 'Untitled'),
                'slug' => $slug,
                'exists' => is_array($row) && !empty($row['id']),
                'status' => (string)($row['status'] ?? 'missing'),
                'view_url' => '/cms/page/' . (string)($row['slug'] ?? $slug),
                'edit_url' => !empty($row['id']) ? '/cms/admin/content/edit/' . (int)$row['id'] : '',
                'description' => (string)($spec['excerpt'] ?? ''),
            ];
        }

        return $pages;
    });
}

function moodleIntegrationAdminShortcodes(): array
{
    return [
        [
            'label' => 'Course Catalog',
            'shortcode' => '[moodle-courses title="Available Courses" limit="9"]',
            'description' => 'Renders cached Moodle course cards for public CMS pages.',
        ],
        [
            'label' => 'Single Course Card',
            'shortcode' => '[moodle-course-detail course_id="123"]',
            'description' => 'Renders one cached Moodle course by Moodle course ID.',
        ],
        [
            'label' => 'My Courses',
            'shortcode' => '[moodle-my-courses title="My Learning"]',
            'description' => 'Shows the signed-in user their synced Moodle courses.',
        ],
        [
            'label' => 'Progress Dashboard',
            'shortcode' => '[moodle-progress title="Progress Dashboard"]',
            'description' => 'Shows the signed-in user progress bars from the local sync cache.',
        ],
    ];
}

/**
 * Return sync freshness data for admin dashboards and staleness checks.
 * Reads last_full_sync_at and last_progress_sync_at from moodle_sync_metrics.
 * Both columns are only advanced on successful sync completions, so they give
 * a reliable "how old is our data?" answer independent of retry/failure counts.
 */
function moodleIntegrationSyncFreshnessForTenant(int $tenantId = 0): array
{
    $tenantId = $tenantId > 0 ? $tenantId : moodleIntegrationCurrentTenantId();
    $settings = moodleIntegrationGetSettingsForTenant($tenantId);
    $threshold = max(1, (int)($settings['staleness_threshold_minutes'] ?? 60));

    $result = [
        'last_full_sync_at' => null,
        'last_progress_sync_at' => null,
        'minutes_since_full_sync' => null,
        'minutes_since_progress_sync' => null,
        'courses_stale' => false,
        'progress_stale' => false,
        'staleness_threshold_minutes' => $threshold,
    ];

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return $result;
    }

    try {
        $stmt = $db->prepare(
            'SELECT sync_type, last_full_sync_at, last_progress_sync_at
             FROM moodle_sync_metrics
             WHERE tenant_id = :tenant_id AND sync_type IN (\'courses\', \'progress_refresh\')
             LIMIT 10'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        foreach ($rows as $row) {
            $type = (string)($row['sync_type'] ?? '');
            if ($type === 'courses' && $row['last_full_sync_at'] !== null) {
                $result['last_full_sync_at'] = $row['last_full_sync_at'];
                $ts = strtotime((string)$row['last_full_sync_at']);
                if ($ts !== false) {
                    $result['minutes_since_full_sync'] = (int)floor((time() - $ts) / 60);
                    $result['courses_stale'] = $result['minutes_since_full_sync'] > $threshold;
                }
            }
            if ($type === 'progress_refresh' && $row['last_progress_sync_at'] !== null) {
                $result['last_progress_sync_at'] = $row['last_progress_sync_at'];
                $ts = strtotime((string)$row['last_progress_sync_at']);
                if ($ts !== false) {
                    $result['minutes_since_progress_sync'] = (int)floor((time() - $ts) / 60);
                    $result['progress_stale'] = $result['minutes_since_progress_sync'] > $threshold;
                }
            }
        }
    } catch (\Throwable $e) {
        // Non-fatal; dashboard degrades gracefully
    }

    return $result;
}

/**
 * Dispatch a targeted progress reconciliation sync for a single learner + resource
 * if their cached progress exceeds the staleness threshold.
 *
 * This enforces the reconciliation contract at user-interaction time:
 *   webhook = fast path (may miss events under network failure)
 *   scheduled sync + targeted refresh = authoritative reconciliation layer
 *
 * The page renders immediately from cache; fresh data arrives after the worker runs.
 * Idempotency key is scoped to a 1-minute window so rapid page reloads won't flood the queue.
 *
 * Returns true if a sync was dispatched (data was stale), false otherwise.
 */
function moodleIntegrationMaybeDispatchUserProgressSync(int $tenantId, int $userId, int $learningResourceId, int $thresholdMinutes = 60): bool
{
    if ($thresholdMinutes <= 0 || !moodleIntegrationIsConfigured() || $tenantId <= 0 || $userId <= 0 || $learningResourceId <= 0) {
        return false;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return false;
    }

    try {
        // Resolve the Moodle course ID for this resource.
        $cacheStmt = $db->prepare(
            'SELECT id AS cache_id, moodle_course_id
             FROM moodle_courses_cache
             WHERE tenant_id = :tenant_id AND resource_id = :resource_id
             LIMIT 1'
        );
        $cacheStmt->execute([':tenant_id' => $tenantId, ':resource_id' => $learningResourceId]);
        $cacheRow = $cacheStmt->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($cacheRow) || empty($cacheRow['moodle_course_id'])) {
            return false;
        }

        $moodleCourseId = (int)$cacheRow['moodle_course_id'];

        // Check last_synced for this user + resource.
        $progressStmt = $db->prepare(
            'SELECT last_synced
             FROM moodle_user_progress
             WHERE tenant_id = :tenant_id AND user_id = :user_id AND learning_resource_id = :resource_id
             LIMIT 1'
        );
        $progressStmt->execute([':tenant_id' => $tenantId, ':user_id' => $userId, ':resource_id' => $learningResourceId]);
        $lastSynced = $progressStmt->fetchColumn();

        $isStale = ($lastSynced === false || $lastSynced === null);
        if (!$isStale) {
            $lastSyncedTs = strtotime((string)$lastSynced);
            $isStale = $lastSyncedTs !== false && (time() - $lastSyncedTs) > ($thresholdMinutes * 60);
        }

        if (!$isStale) {
            return false;
        }

        // Use a 1-minute idempotency window to suppress duplicate dispatches from rapid page loads.
        $minuteWindow = (int)floor(time() / 60);
        $idempotencyKey = "targeted-progress:{$tenantId}:{$userId}:{$learningResourceId}:{$minuteWindow}";

        moodleIntegrationQueueTableInsertForTenant($tenantId, 'sync_progress', [
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'moodle_course_id' => $moodleCourseId,
            'learning_resource_id' => $learningResourceId,
            'action' => 'targeted_refresh',
            'source' => 'staleness_check',
        ], 'pending', $idempotencyKey);

        \write_log("moodle-integration: targeted reconciliation queued for user {$userId} resource {$learningResourceId} tenant {$tenantId} (last_synced={$lastSynced})", 'info');
        return true;
    } catch (\Throwable $e) {
        \write_log('moodle-integration: failed to dispatch targeted progress sync — ' . $e->getMessage(), 'warning');
        return false;
    }
}

/**
 * Dispatch targeted reconciliation syncs for all of a user's progress rows that
 * exceed the staleness threshold. Called on /my-courses to keep the dashboard fresh
 * without waiting for the next scheduled batch run.
 *
 * Caps dispatches at 10 rows per call to avoid flooding the queue for learners with
 * many courses. Rows are prioritised by oldest last_synced first.
 *
 * Returns the count of syncs dispatched.
 */
function moodleIntegrationMaybeDispatchStaleProgressForUser(int $tenantId, int $userId, int $thresholdMinutes = 60): int
{
    if ($thresholdMinutes <= 0 || !moodleIntegrationIsConfigured() || $tenantId <= 0 || $userId <= 0) {
        return 0;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return 0;
    }

    try {
        $stmt = $db->prepare(
            'SELECT p.learning_resource_id, p.last_synced, c.moodle_course_id
             FROM moodle_user_progress p
             JOIN moodle_courses_cache c ON c.id = p.course_cache_id AND c.tenant_id = p.tenant_id
             WHERE p.tenant_id = :tenant_id
               AND p.user_id = :user_id
               AND p.learning_resource_id IS NOT NULL
               AND (p.last_synced IS NULL OR p.last_synced < NOW() - INTERVAL :threshold_minutes MINUTE)
             ORDER BY p.last_synced ASC
             LIMIT 10'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':user_id' => $userId,
            ':threshold_minutes' => $thresholdMinutes,
        ]);
        $staleRows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $dispatched = 0;
        $minuteWindow = (int)floor(time() / 60);

        foreach ($staleRows as $row) {
            $resourceId = (int)($row['learning_resource_id'] ?? 0);
            $moodleCourseId = (int)($row['moodle_course_id'] ?? 0);
            if ($resourceId <= 0 || $moodleCourseId <= 0) {
                continue;
            }

            $idempotencyKey = "targeted-progress:{$tenantId}:{$userId}:{$resourceId}:{$minuteWindow}";
            moodleIntegrationQueueTableInsertForTenant($tenantId, 'sync_progress', [
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'moodle_course_id' => $moodleCourseId,
                'learning_resource_id' => $resourceId,
                'action' => 'targeted_refresh',
                'source' => 'staleness_check',
            ], 'pending', $idempotencyKey);

            $dispatched++;
        }

        if ($dispatched > 0) {
            \write_log("moodle-integration: targeted reconciliation queued {$dispatched} stale rows for user {$userId} tenant {$tenantId}", 'info');
        }

        return $dispatched;
    } catch (\Throwable $e) {
        \write_log('moodle-integration: failed to dispatch stale progress syncs — ' . $e->getMessage(), 'warning');
        return 0;
    }
}

function moodleIntegrationTenantDb(int $tenantId = 0): ?\PDO
{
    $tenantId = $tenantId > 0 ? $tenantId : moodleIntegrationCurrentTenantId();
    if ($tenantId > 0 && function_exists('app') && method_exists(app(), 'dbForTenant')) {
        $tenantDb = app()->dbForTenant($tenantId);
        if ($tenantDb instanceof \PDO) {
            return $tenantDb;
        }
    }

    if (function_exists('app') && method_exists(app(), 'db')) {
        $db = app()->db();
        if ($db instanceof \PDO) {
            return $db;
        }
    }

    return null;
}

function moodleIntegrationQueueTableInsertForTenant(int $tenantId, string $type, array $payload, string $status = 'pending', string $idempotencyKey = ''): int
{
    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return 0;
    }

    $idempotencyKeyValue = $idempotencyKey !== '' ? $idempotencyKey : null;

    // Use ON DUPLICATE KEY to return the existing queue row's ID when the same
    // idempotency_key is submitted again (retry / double-submit guard).
    $stmt = $db->prepare(
        'INSERT INTO moodle_sync_queue (tenant_id, type, idempotency_key, payload_json, status, retries, available_at, created_at, updated_at)
         VALUES (:tenant_id, :type, :idempotency_key, :payload_json, :status, 0, NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id), updated_at = updated_at'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':type' => $type,
        ':idempotency_key' => $idempotencyKeyValue,
        ':payload_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ':status' => $status,
    ]);

    return (int)$db->lastInsertId();
}

function moodleIntegrationQueueTableInsert(string $type, array $payload, string $status = 'pending', string $idempotencyKey = ''): int
{
    return moodleIntegrationQueueTableInsertForTenant(moodleIntegrationCurrentTenantId(), $type, $payload, $status, $idempotencyKey);
}

function moodleIntegrationEnrollmentRequestHydrateRow(array $row): array
{
    $row['course_title'] = trim((string)($row['course_title'] ?? $row['title'] ?? 'Course'));
    $row['learner_name'] = trim((string)($row['learner_name'] ?? $row['display_name'] ?? $row['username'] ?? 'Learner'));
    $row['learner_email'] = trim((string)($row['learner_email'] ?? $row['email'] ?? ''));
    $row['reviewer_name'] = trim((string)($row['reviewer_name'] ?? $row['reviewer_username'] ?? ''));
    $row['review_notes'] = trim((string)($row['review_notes'] ?? ''));
    $row['enrollment_mode'] = moodleIntegrationNormalizeEnrollmentMode((string)($row['enrollment_mode'] ?? 'manual_review'));
    return $row;
}

function moodleIntegrationEnrollmentRequestById(int $requestId, ?int $tenantId = null): ?array
{
    if ($requestId <= 0) {
        return null;
    }

    $db = moodleIntegrationTenantDb($tenantId ?? moodleIntegrationCurrentTenantId());
    if (!$db instanceof \PDO) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT r.*, c.title AS course_title,
                COALESCE(NULLIF(u.display_name, ""), u.username, CAST(r.user_id AS CHAR)) AS learner_name,
                u.email AS learner_email,
                COALESCE(NULLIF(reviewer.display_name, ""), reviewer.username, "") AS reviewer_name,
                reviewer.username AS reviewer_username
         FROM moodle_enrollment_requests r
         LEFT JOIN moodle_courses_cache c ON c.tenant_id = r.tenant_id AND c.moodle_course_id = r.moodle_course_id
         LEFT JOIN cms_users u ON u.id = r.user_id
         LEFT JOIN cms_users reviewer ON reviewer.id = r.reviewed_by_user_id
         WHERE r.tenant_id = :tenant_id AND r.id = :id
         LIMIT 1'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId ?? moodleIntegrationCurrentTenantId(),
        ':id' => $requestId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? moodleIntegrationEnrollmentRequestHydrateRow($row) : null;
}

function moodleIntegrationEnrollmentRequestRow(int $userId, int $moodleCourseId, ?int $tenantId = null): ?array
{
    if ($userId <= 0 || $moodleCourseId <= 0) {
        return null;
    }

    $tenantId = $tenantId ?? moodleIntegrationCurrentTenantId();
    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return null;
    }

    $stmt = $db->prepare(
        'SELECT r.*, c.title AS course_title,
                COALESCE(NULLIF(u.display_name, ""), u.username, CAST(r.user_id AS CHAR)) AS learner_name,
                u.email AS learner_email,
                COALESCE(NULLIF(reviewer.display_name, ""), reviewer.username, "") AS reviewer_name,
                reviewer.username AS reviewer_username
         FROM moodle_enrollment_requests r
         LEFT JOIN moodle_courses_cache c ON c.tenant_id = r.tenant_id AND c.moodle_course_id = r.moodle_course_id
         LEFT JOIN cms_users u ON u.id = r.user_id
         LEFT JOIN cms_users reviewer ON reviewer.id = r.reviewed_by_user_id
         WHERE r.tenant_id = :tenant_id AND r.user_id = :user_id AND r.moodle_course_id = :moodle_course_id
         LIMIT 1'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':moodle_course_id' => $moodleCourseId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    return is_array($row) ? moodleIntegrationEnrollmentRequestHydrateRow($row) : null;
}

function moodleIntegrationSaveEnrollmentRequestForTenant(int $tenantId, int $userId, int $moodleCourseId, string $status, array $options = []): ?array
{
    if ($tenantId <= 0 || $userId <= 0 || $moodleCourseId <= 0 || $status === '') {
        return null;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return null;
    }

    $existing = moodleIntegrationEnrollmentRequestRow($userId, $moodleCourseId, $tenantId);
    $reviewNotes = array_key_exists('review_notes', $options)
        ? trim((string)$options['review_notes'])
        : trim((string)($existing['review_notes'] ?? ''));
    $requestedBySource = trim((string)($options['requested_by_source'] ?? ($existing['requested_by_source'] ?? 'cms')));
    $reviewedByUserId = isset($options['reviewed_by_user_id']) ? (int)$options['reviewed_by_user_id'] : (int)($existing['reviewed_by_user_id'] ?? 0);
    $reviewedAt = array_key_exists('reviewed_at', $options)
        ? ($options['reviewed_at'] === null ? null : (string)$options['reviewed_at'])
        : ($existing['reviewed_at'] ?? null);
    $syncQueueId = isset($options['sync_queue_id']) ? (int)$options['sync_queue_id'] : (int)($existing['sync_queue_id'] ?? 0);
    $learningResourceId = isset($options['learning_resource_id'])
        ? (int)$options['learning_resource_id']
        : (int)($existing['learning_resource_id'] ?? moodleIntegrationLearningResourceIdByMoodleCourseId($moodleCourseId, $tenantId));
    $enrollmentMode = moodleIntegrationNormalizeEnrollmentMode((string)($options['enrollment_mode'] ?? ($existing['enrollment_mode'] ?? moodleIntegrationGetSettingsForTenant($tenantId)['enrollment_mode'] ?? 'manual_review')));

    if ($status === 'pending_review') {
        $reviewedByUserId = 0;
        $reviewedAt = null;
        $syncQueueId = 0;
    }

    if ($existing !== null) {
        $stmt = $db->prepare(
            'UPDATE moodle_enrollment_requests
             SET status = :status,
                 review_notes = :review_notes,
                 enrollment_mode = :enrollment_mode,
                 requested_by_source = :requested_by_source,
                 learning_resource_id = :learning_resource_id,
                 reviewed_by_user_id = :reviewed_by_user_id,
                 reviewed_at = :reviewed_at,
                 sync_queue_id = :sync_queue_id,
                 updated_at = NOW()
             WHERE tenant_id = :tenant_id AND id = :id'
        );
        $stmt->execute([
            ':status' => $status,
            ':review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
            ':enrollment_mode' => $enrollmentMode,
            ':requested_by_source' => $requestedBySource !== '' ? $requestedBySource : 'cms',
            ':learning_resource_id' => $learningResourceId > 0 ? $learningResourceId : null,
            ':reviewed_by_user_id' => $reviewedByUserId > 0 ? $reviewedByUserId : null,
            ':reviewed_at' => $reviewedAt,
            ':sync_queue_id' => $syncQueueId > 0 ? $syncQueueId : null,
            ':tenant_id' => $tenantId,
            ':id' => (int)$existing['id'],
        ]);

        return moodleIntegrationEnrollmentRequestById((int)$existing['id'], $tenantId);
    }

    $stmt = $db->prepare(
        'INSERT INTO moodle_enrollment_requests (
            tenant_id, user_id, learning_resource_id, moodle_course_id, status, enrollment_mode, review_notes, requested_by_source,
            reviewed_by_user_id, sync_queue_id, requested_at, reviewed_at, created_at, updated_at
         ) VALUES (
            :tenant_id, :user_id, :learning_resource_id, :moodle_course_id, :status, :enrollment_mode, :review_notes, :requested_by_source,
            :reviewed_by_user_id, :sync_queue_id, NOW(), :reviewed_at, NOW(), NOW()
         )'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':learning_resource_id' => $learningResourceId > 0 ? $learningResourceId : null,
        ':moodle_course_id' => $moodleCourseId,
        ':status' => $status,
        ':enrollment_mode' => $enrollmentMode,
        ':review_notes' => $reviewNotes !== '' ? $reviewNotes : null,
        ':requested_by_source' => $requestedBySource !== '' ? $requestedBySource : 'cms',
        ':reviewed_by_user_id' => $reviewedByUserId > 0 ? $reviewedByUserId : null,
        ':sync_queue_id' => $syncQueueId > 0 ? $syncQueueId : null,
        ':reviewed_at' => $reviewedAt,
    ]);

    return moodleIntegrationEnrollmentRequestById((int)$db->lastInsertId(), $tenantId);
}

function moodleIntegrationUserEnrollmentRequests(int $userId, array $statuses = [], int $limit = 20): array
{
    if ($userId <= 0) {
        return [];
    }

    $db = moodleIntegrationTenantDb(moodleIntegrationCurrentTenantId());
    if (!$db instanceof \PDO) {
        return [];
    }

    $sql = 'SELECT r.*, c.title AS course_title
            FROM moodle_enrollment_requests r
            LEFT JOIN moodle_courses_cache c ON c.tenant_id = r.tenant_id AND c.moodle_course_id = r.moodle_course_id
            WHERE r.tenant_id = :tenant_id AND r.user_id = :user_id';
    $params = [
        ':tenant_id' => moodleIntegrationCurrentTenantId(),
        ':user_id' => $userId,
    ];
    if ($statuses !== []) {
        $placeholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params[$key] = (string)$status;
        }
        $sql .= ' AND r.status IN (' . implode(', ', $placeholders) . ')';
    }
    $sql .= ' ORDER BY COALESCE(r.reviewed_at, r.requested_at) DESC, r.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return array_map('moodleIntegrationEnrollmentRequestHydrateRow', $rows);
}

function moodleIntegrationAdminEnrollmentRequests(array $statuses = [], int $limit = 20): array
{
    $db = moodleIntegrationTenantDb(moodleIntegrationCurrentTenantId());
    if (!$db instanceof \PDO) {
        return [];
    }

    $sql = 'SELECT r.*, c.title AS course_title,
                   COALESCE(NULLIF(u.display_name, ""), u.username, CAST(r.user_id AS CHAR)) AS learner_name,
                   u.email AS learner_email,
                   COALESCE(NULLIF(reviewer.display_name, ""), reviewer.username, "") AS reviewer_name,
                   reviewer.username AS reviewer_username
            FROM moodle_enrollment_requests r
            LEFT JOIN moodle_courses_cache c ON c.tenant_id = r.tenant_id AND c.moodle_course_id = r.moodle_course_id
            LEFT JOIN cms_users u ON u.id = r.user_id
            LEFT JOIN cms_users reviewer ON reviewer.id = r.reviewed_by_user_id
            WHERE r.tenant_id = :tenant_id';
    $params = [':tenant_id' => moodleIntegrationCurrentTenantId()];
    if ($statuses !== []) {
        $placeholders = [];
        foreach (array_values($statuses) as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params[$key] = (string)$status;
        }
        $sql .= ' AND r.status IN (' . implode(', ', $placeholders) . ')';
    }
    $sql .= ' ORDER BY CASE WHEN r.status = "pending_review" THEN 0 ELSE 1 END, COALESCE(r.reviewed_at, r.requested_at) DESC, r.id DESC LIMIT ' . max(1, $limit);

    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return array_map('moodleIntegrationEnrollmentRequestHydrateRow', $rows);
}

function moodleIntegrationEnrollmentRequestStatusSummary(): array
{
    $db = moodleIntegrationTenantDb(moodleIntegrationCurrentTenantId());
    if (!$db instanceof \PDO) {
        return ['pending_review' => 0, 'pending_payment' => 0, 'approved' => 0, 'rejected' => 0, 'revoked' => 0];
    }

    $stmt = $db->prepare('SELECT status, COUNT(*) AS count_rows FROM moodle_enrollment_requests WHERE tenant_id = :tenant_id GROUP BY status');
    $stmt->execute([':tenant_id' => moodleIntegrationCurrentTenantId()]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $summary = ['pending_review' => 0, 'pending_payment' => 0, 'approved' => 0, 'rejected' => 0, 'revoked' => 0];
    foreach ($rows as $row) {
        $status = (string)($row['status'] ?? 'pending_review');
        if (!array_key_exists($status, $summary)) {
            $summary[$status] = 0;
        }
        $summary[$status] = (int)($row['count_rows'] ?? 0);
    }

    return $summary;
}

function moodleIntegrationDeleteUserProgressForCourse(int $tenantId, int $userId, int $moodleCourseId): void
{
    if ($tenantId <= 0 || $userId <= 0 || $moodleCourseId <= 0) {
        return;
    }

    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return;
    }

    $stmt = $db->prepare(
        'DELETE p FROM moodle_user_progress p
         JOIN moodle_courses_cache c ON c.id = p.course_cache_id AND c.tenant_id = p.tenant_id
         WHERE p.tenant_id = :tenant_id AND p.user_id = :user_id AND c.moodle_course_id = :moodle_course_id'
    );
    $stmt->execute([
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
        ':moodle_course_id' => $moodleCourseId,
    ]);
}

function moodleIntegrationAdminPageContext(array $user, string $pageTitle, array $extra = []): array
{
    $base = function_exists('cmsAdminContext')
        ? cmsAdminContext($user, 'moodle_integration', [['label' => $pageTitle]])
        : ['current_page' => 'moodle_integration', 'breadcrumbs' => [['label' => $pageTitle]], 'ext_nav_items' => []];

    return array_merge($base, ['page_title' => $pageTitle], $extra);
}

if (function_exists('app') && method_exists(app(), 'hooks')) {
    app()->hooks()->on('cms.admin.nav_items', static function (array $items): array {
        if (function_exists('moduleIsActive') && !moduleIsActive('moodle-integration')) {
            return $items;
        }

        $items[] = [
            'label' => 'Moodle',
            'url' => moodleIntegrationPath('/admin/moodle-integration'),
            'icon' => 'book-open',
            'active_key' => 'moodle_integration',
        ];

        return $items;
    }, 20);

    app()->hooks()->on('cms.editor.block_types', static function (array $blocks): array {
        $blocks[] = [
            'type' => 'moodle_course_list',
            'label' => 'Moodle Course List',
            'icon' => 'book-open',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Heading', 'placeholder' => 'Available Courses'],
                ['key' => 'limit', 'type' => 'number', 'label' => 'Max Items', 'placeholder' => '6'],
            ],
        ];
        $blocks[] = [
            'type' => 'moodle_course_detail',
            'label' => 'Moodle Course Detail',
            'icon' => 'file-text',
            'fields' => [
                ['key' => 'course_id', 'type' => 'text', 'label' => 'Moodle Course ID', 'placeholder' => '123'],
            ],
        ];
        $blocks[] = [
            'type' => 'moodle_my_courses',
            'label' => 'My Moodle Courses',
            'icon' => 'user',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Heading', 'placeholder' => 'My Courses'],
            ],
        ];
        $blocks[] = [
            'type' => 'moodle_progress_dashboard',
            'label' => 'Moodle Progress Dashboard',
            'icon' => 'bar-chart',
            'fields' => [
                ['key' => 'title', 'type' => 'text', 'label' => 'Heading', 'placeholder' => 'Learning Progress'],
            ],
        ];

        return $blocks;
    }, 10);

    app()->hooks()->on('cms.builder.renderers', static function (array $map): array {
        $map['moodle_course_list'] = 'moodleIntegrationRenderCourseListBlock';
        $map['moodle_course_detail'] = 'moodleIntegrationRenderCourseDetailBlock';
        $map['moodle_my_courses'] = 'moodleIntegrationRenderMyCoursesBlock';
        $map['moodle_progress_dashboard'] = 'moodleIntegrationRenderProgressDashboardBlock';
        return $map;
    }, 10);

    app()->hooks()->on('cms.public.render_content', static function (string $html, array $content): string {
        if ($html === '' || stripos($html, '[moodle') === false) {
            return $html;
        }

        $pattern = '/<p>\s*\[(moodle(?:-|_)[a-z0-9_-]+)([^\]]*)\]\s*<\/p>|\[(moodle(?:-|_)[a-z0-9_-]+)([^\]]*)\]/i';

        return preg_replace_callback($pattern, static function (array $matches): string {
            $tag = strtolower((string)($matches[1] !== '' ? $matches[1] : ($matches[3] ?? '')));
            $attrString = trim((string)($matches[2] !== '' ? $matches[2] : ($matches[4] ?? '')));
            return moodleIntegrationRenderShortcode($tag, moodleIntegrationShortcodeAttrs($attrString));
        }, $html) ?? $html;
    }, 10);
}

function moodleIntegrationRenderCourseListBlock(array $block = []): string
{
    $courses = moodleIntegrationCachedCourses((int)($block['limit'] ?? 6), [
        'category' => trim((string)($block['category'] ?? '')),
        'category_id' => (int)($block['category_id'] ?? 0),
    ]);
    return moodleIntegrationRenderStyledBlock('blocks/course-list.disyl', [
        'block_title' => trim((string)($block['title'] ?? 'Available Courses')),
        'courses' => $courses,
    ]);
}

function moodleIntegrationRenderCourseDetailBlock(array $block = []): string
{
    $course = null;
    $courseId = (int)($block['course_id'] ?? 0);
    if ($courseId > 0) {
        $course = moodleIntegrationCachedCourseByMoodleId($courseId);
    }

    return moodleIntegrationRenderStyledBlock('blocks/course-detail.disyl', [
        'course' => $course,
    ]);
}

function moodleIntegrationRenderMyCoursesBlock(array $block = []): string
{
    $user = app()->user();
    $isAuthenticated = is_array($user) && !empty($user['id']);
    $statusFilter = array_values(array_filter(array_map('trim', explode(',', (string)($block['status'] ?? '')))));
    $courses = $isAuthenticated ? moodleIntegrationUserProgressRows((int)$user['id'], ['statuses' => $statusFilter]) : [];
    $requests = $isAuthenticated
        ? moodleIntegrationUserEnrollmentRequests((int)$user['id'], ['pending_review', 'pending_payment', 'rejected', 'revoked'], 10)
        : [];
    $courseCount = count($courses);
    $completedCount = 0;
    $activeCount = 0;
    $pendingReviewCount = 0;
    $totalProgress = 0.0;
    foreach ($courses as $course) {
        $status = trim((string)($course['status'] ?? ''));
        $progressPercent = (float)($course['progress_percent'] ?? 0);
        $totalProgress += $progressPercent;
        if ($status === 'completed') {
            $completedCount++;
        }
        if ($status === 'in_progress' || ($status !== 'completed' && $progressPercent > 0)) {
            $activeCount++;
        }
    }
    foreach ($requests as $request) {
        if ((string)($request['status'] ?? '') === 'pending_review') {
            $pendingReviewCount++;
        }
    }
    $redirectPath = moodleIntegrationCurrentRequestPath();
    $query = moodleIntegrationCurrentQueryParams();
    $queuedCourseId = (int)($query['course_id'] ?? 0);
    $showQueuedNotice = ((string)($query['queued'] ?? '')) === '1'
        || ((string)($query['requested'] ?? '')) === '1'
        || ((string)($query['launch_blocked'] ?? '')) === '1';
    $queuedCourse = $queuedCourseId > 0 ? moodleIntegrationCachedCourseByMoodleId($queuedCourseId) : null;

    return moodleIntegrationRenderStyledBlock('blocks/my-courses.disyl', [
        'block_title' => trim((string)($block['title'] ?? 'My Courses')),
        'courses' => $courses,
        'course_count' => $courseCount,
        'completed_count' => $completedCount,
        'active_count' => $activeCount,
        'pending_review_count' => $pendingReviewCount,
        'enrollment_requests' => $requests,
        'average_progress' => $courseCount > 0 ? round($totalProgress / $courseCount, 1) : 0,
        'current_flow_state' => !$isAuthenticated
            ? 'sign_in_required'
            : ($pendingReviewCount > 0 ? 'awaiting_review' : ($showQueuedNotice ? 'waiting_for_sync' : ($courseCount > 0 ? 'ready_to_learn' : 'choose_next_course'))),
        'is_authenticated' => $isAuthenticated,
        'authenticated_user_name' => $isAuthenticated ? trim((string)($user['name'] ?? $user['display_name'] ?? $user['username'] ?? 'Learner')) : '',
        'authenticated_user_email' => $isAuthenticated ? trim((string)($user['email'] ?? '')) : '',
        'login_url' => moodleIntegrationLoginUrl($redirectPath),
        'logout_url' => moodleIntegrationPath('/auth/logout'),
        'learning_center_url' => moodleIntegrationPath('/cms/page/learning-center'),
        'show_queued_notice' => $showQueuedNotice,
        'queued_course_id' => $queuedCourseId,
        'queued_course' => $queuedCourse,
        'queued_status_url' => $queuedCourseId > 0 ? moodleIntegrationPath('/api/v1/moodle-integration/status/' . $queuedCourseId) : '',
        'queued_launch_url' => $queuedCourseId > 0 ? moodleIntegrationPath('/cms/course/' . $queuedCourseId . '/launch') : '',
    ]);
}

function moodleIntegrationRenderProgressDashboardBlock(array $block = []): string
{
    $user = app()->user();
    $courses = is_array($user) && !empty($user['id']) ? moodleIntegrationUserProgressRows((int)$user['id']) : [];
    $completedCount = 0;
    $averageProgress = 0.0;
    foreach ($courses as $course) {
        $averageProgress += (float)($course['progress_percent'] ?? 0);
        if (trim((string)($course['status'] ?? '')) === 'completed') {
            $completedCount++;
        }
    }
    $redirectPath = moodleIntegrationCurrentRequestPath();
    $query = moodleIntegrationCurrentQueryParams();
    $queuedCourseId = (int)($query['course_id'] ?? 0);

    return moodleIntegrationRenderStyledBlock('blocks/progress-dashboard.disyl', [
        'block_title' => trim((string)($block['title'] ?? 'Learning Progress')),
        'courses' => $courses,
        'course_count' => count($courses),
        'completed_count' => $completedCount,
        'average_progress' => count($courses) > 0 ? round($averageProgress / count($courses), 1) : 0,
        'is_authenticated' => is_array($user) && !empty($user['id']),
        'login_url' => moodleIntegrationLoginUrl($redirectPath),
        'queued_course_id' => $queuedCourseId,
    ]);
}

function moodleIntegrationCachedCourses(int $limit = 20): array
{
    $tenantId = moodleIntegrationCurrentTenantId();
    $db = moodleIntegrationDb();
    return moodleIntegrationCachedCoursesWithFilters($limit, [], $tenantId, $db);
}

function moodleIntegrationCachedCoursesWithFilters(int $limit = 20, array $filters = [], ?int $tenantId = null, mixed $db = null): array
{
    $tenantId = ($tenantId ?? 0) > 0 ? (int)$tenantId : moodleIntegrationCurrentTenantId();
    $db = $db instanceof \PDO ? $db : moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return [];
    }

    $sql = 'SELECT id, resource_id, moodle_course_id, moodle_category_id, moodle_category_key, title, summary, image, updated_at
            FROM moodle_courses_cache
            WHERE tenant_id = :tenant_id';
    $params = [':tenant_id' => $tenantId];

    $sharedCategoryId = moodleIntegrationSharedTenantCategoryId($tenantId);
    if ($sharedCategoryId > 0) {
        $sql .= ' AND moodle_category_id = :shared_category_id';
        $params[':shared_category_id'] = $sharedCategoryId;
    } elseif ((moodleIntegrationGetSettingsForTenant($tenantId)['tenant_mode'] ?? 'per_instance') === 'shared') {
        return [];
    }

    $categoryId = (int)($filters['category_id'] ?? 0);
    $categoryKey = moodleIntegrationCategoryKey((string)($filters['category'] ?? ''));
    if ($categoryId > 0) {
        $sql .= ' AND moodle_category_id = :category_id';
        $params[':category_id'] = $categoryId;
    } elseif ($categoryKey !== '') {
        $sql .= ' AND moodle_category_key = :category_key';
        $params[':category_key'] = $categoryKey;
    }

    $sql .= ' ORDER BY updated_at DESC, id DESC LIMIT ' . max(1, $limit);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function moodleIntegrationCachedCourseByMoodleId(int $moodleCourseId): ?array
{
    $db = moodleIntegrationDb();
    $stmt = $db->prepare('SELECT id, resource_id, moodle_course_id, moodle_category_id, moodle_category_key, title, summary, image, updated_at FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND moodle_course_id = :moodle_course_id LIMIT 1');
    $stmt->execute([
        ':tenant_id' => moodleIntegrationCurrentTenantId(),
        ':moodle_course_id' => $moodleCourseId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!is_array($row) || !moodleIntegrationCourseBelongsToTenant($row)) {
        return null;
    }

    return $row;
}

function moodleIntegrationCachedCourseByResourceId(int $resourceId): ?array
{
    if ($resourceId <= 0) {
        return null;
    }

    $db = moodleIntegrationDb();
    $stmt = $db->prepare('SELECT id, resource_id, moodle_course_id, moodle_category_id, moodle_category_key, title, summary, image, updated_at FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND resource_id = :resource_id LIMIT 1');
    $stmt->execute([
        ':tenant_id' => moodleIntegrationCurrentTenantId(),
        ':resource_id' => $resourceId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!is_array($row) || !moodleIntegrationCourseBelongsToTenant($row)) {
        return null;
    }

    return $row;
}

function moodleIntegrationUserProgressRows(int $userId, array $filters = []): array
{
    $db = moodleIntegrationDb();
    $tenantId = moodleIntegrationCurrentTenantId();
    $sql = 'SELECT p.course_cache_id, p.progress_percent, p.grade, p.status, p.last_synced,
                   c.resource_id, c.title, c.summary, c.image, c.moodle_course_id, c.moodle_category_id, c.moodle_category_key
            FROM moodle_user_progress p
            LEFT JOIN moodle_courses_cache c ON c.id = p.course_cache_id AND c.tenant_id = p.tenant_id
            WHERE p.tenant_id = :tenant_id AND p.user_id = :user_id';
    $params = [
        ':tenant_id' => $tenantId,
        ':user_id' => $userId,
    ];
    $sharedCategoryId = moodleIntegrationSharedTenantCategoryId($tenantId);
    if ($sharedCategoryId > 0) {
        $sql .= ' AND c.moodle_category_id = :shared_category_id';
        $params[':shared_category_id'] = $sharedCategoryId;
    } elseif ((moodleIntegrationGetSettingsForTenant($tenantId)['tenant_mode'] ?? 'per_instance') === 'shared') {
        return [];
    }

    $statuses = array_values(array_filter(array_map('trim', (array)($filters['statuses'] ?? []))));
    if ($statuses !== []) {
        $placeholders = [];
        foreach ($statuses as $index => $status) {
            $key = ':status_' . $index;
            $placeholders[] = $key;
            $params[$key] = $status;
        }
        $sql .= ' AND p.status IN (' . implode(', ', $placeholders) . ')';
    }

    $sql .= ' ORDER BY p.last_synced DESC, p.id DESC';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

    return is_array($rows) ? $rows : [];
}

function moodleIntegrationUserCourseProgressRow(int $userId, int $moodleCourseId): ?array
{
    if ($userId <= 0 || $moodleCourseId <= 0) {
        return null;
    }

    $db = moodleIntegrationDb();
    $stmt = $db->prepare(
        'SELECT p.course_cache_id, p.progress_percent, p.grade, p.status, p.last_synced, c.resource_id, c.title, c.summary, c.image, c.moodle_course_id, c.moodle_category_id, c.moodle_category_key
         FROM moodle_user_progress p
         JOIN moodle_courses_cache c ON c.id = p.course_cache_id AND c.tenant_id = p.tenant_id
         WHERE p.tenant_id = :tenant_id AND p.user_id = :user_id AND c.moodle_course_id = :moodle_course_id
         LIMIT 1'
    );
    $stmt->execute([
        ':tenant_id' => moodleIntegrationCurrentTenantId(),
        ':user_id' => $userId,
        ':moodle_course_id' => $moodleCourseId,
    ]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);

    if (!is_array($row) || !moodleIntegrationCourseBelongsToTenant($row)) {
        return null;
    }

    return $row;
}

function moodleIntegrationLatestEnrollmentQueueRow(int $userId, int $moodleCourseId): ?array
{
    if ($userId <= 0 || $moodleCourseId <= 0) {
        return null;
    }

    $db = moodleIntegrationDb();
    $stmt = $db->prepare(
        'SELECT id, type, status, last_error, processed_at, updated_at, payload_json
         FROM moodle_sync_queue
         WHERE tenant_id = :tenant_id AND type = :type
         ORDER BY id DESC
         LIMIT 25'
    );
    $stmt->execute([
        ':tenant_id' => moodleIntegrationCurrentTenantId(),
        ':type' => 'enrollment',
    ]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    foreach ($rows as $row) {
        $payload = json_decode((string)($row['payload_json'] ?? ''), true);
        if (!is_array($payload)) {
            continue;
        }
        if ((int)($payload['user_id'] ?? 0) !== $userId) {
            continue;
        }
        if ((int)($payload['moodle_course_id'] ?? 0) !== $moodleCourseId) {
            continue;
        }

        $row['payload'] = $payload;
        return $row;
    }

    return null;
}

function moodleIntegrationCourseStatusPayload(int $userId, int $moodleCourseId): array
{
    $course = moodleIntegrationCachedCourseByMoodleId($moodleCourseId);
    $request = moodleIntegrationEnrollmentRequestRow($userId, $moodleCourseId);
    $queue = moodleIntegrationLatestEnrollmentQueueRow($userId, $moodleCourseId);
    $progress = moodleIntegrationUserCourseProgressRow($userId, $moodleCourseId);

    $state = 'idle';
    $message = 'No enrollment request found yet.';
    $readyToLaunch = false;
    $requestStatus = trim((string)($request['status'] ?? ''));

    if ($requestStatus === 'pending_review') {
        $state = 'pending_review';
        $message = 'Enrollment request submitted. Waiting for eligibility review.';
    } elseif ($requestStatus === 'pending_payment') {
        $state = 'pending_payment';
        $message = 'Enrollment is waiting for payment confirmation before Moodle sync can begin.';
    } elseif ($requestStatus === 'rejected') {
        $state = 'rejected';
        $message = trim((string)($request['review_notes'] ?? 'Enrollment request was not approved.'));
    } elseif ($requestStatus === 'revoked') {
        $state = 'revoked';
        $message = trim((string)($request['review_notes'] ?? 'Enrollment access was revoked after review.'));
    } elseif ($queue !== null) {
        $queueStatus = trim((string)($queue['status'] ?? 'pending'));
        if ($queueStatus === 'failed') {
            $state = 'failed';
            $message = trim((string)($queue['last_error'] ?? 'Enrollment sync failed.'));
        } elseif ($queueStatus === 'processing') {
            $state = 'syncing';
            $message = 'Enrollment is syncing into Moodle now.';
        } else {
            $state = 'queued';
            $message = 'Enrollment is queued and waiting for Moodle sync.';
        }
    }

    if ($progress !== null) {
        $state = 'ready';
        $message = 'Enrollment is ready. You can launch this course in Moodle now.';
        $readyToLaunch = true;
    }

    return [
        'ok' => true,
        'course_id' => $moodleCourseId,
        'course_title' => (string)($progress['title'] ?? $course['title'] ?? 'Course'),
        'state' => $state,
        'message' => $message,
        'ready_to_launch' => $readyToLaunch,
        'launch_url' => moodleIntegrationPath('/cms/course/' . $moodleCourseId . '/launch'),
        'request' => $request === null ? null : [
            'id' => (int)($request['id'] ?? 0),
            'status' => (string)($request['status'] ?? ''),
            'review_notes' => (string)($request['review_notes'] ?? ''),
            'requested_at' => (string)($request['requested_at'] ?? ''),
            'reviewed_at' => (string)($request['reviewed_at'] ?? ''),
        ],
        'queue' => $queue === null ? null : [
            'id' => (int)($queue['id'] ?? 0),
            'status' => (string)($queue['status'] ?? ''),
            'processed_at' => (string)($queue['processed_at'] ?? ''),
            'updated_at' => (string)($queue['updated_at'] ?? ''),
            'last_error' => (string)($queue['last_error'] ?? ''),
        ],
        'progress' => $progress === null ? null : [
            'progress_percent' => (float)($progress['progress_percent'] ?? 0),
            'status' => (string)($progress['status'] ?? 'not_started'),
            'last_synced' => (string)($progress['last_synced'] ?? ''),
        ],
    ];
}

/**
 * Resolve the canonical moodle_course_id from a learning_resource_id.
 * Used by resource-ID-keyed route handlers so they don't need to know
 * the provider-level ID.
 */
function moodleIntegrationMoodleCourseIdByResourceId(int $resourceId, ?int $tenantId = null): int
{
    if ($resourceId <= 0) {
        return 0;
    }

    $tenantId = ($tenantId ?? 0) > 0 ? (int)$tenantId : moodleIntegrationCurrentTenantId();
    $db = moodleIntegrationTenantDb($tenantId);
    if (!$db instanceof \PDO) {
        return 0;
    }

    $stmt = $db->prepare('SELECT moodle_course_id FROM moodle_courses_cache WHERE tenant_id = :tenant_id AND resource_id = :resource_id LIMIT 1');
    $stmt->execute([':tenant_id' => $tenantId, ':resource_id' => $resourceId]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Access-state lookup by canonical learning_resource_id.
 * Resolves the provider-level moodle_course_id internally so callers
 * never need to pass it.
 */
function moodleIntegrationLearnerCourseAccessStateByResourceId(int $userId, int $resourceId): array
{
    $moodleCourseId = moodleIntegrationMoodleCourseIdByResourceId($resourceId);
    if ($moodleCourseId <= 0) {
        return [
            'launch_ready' => false, 'review_pending' => false, 'payment_pending' => false,
            'request_rejected' => false, 'request_revoked' => false, 'queue_pending' => false,
            'queue_failed' => false, 'can_queue_enrollment' => false, 'can_submit_request' => false,
            'message' => 'Course not found.',
            'progress' => null, 'request' => null, 'queue' => null,
        ];
    }

    return moodleIntegrationLearnerCourseAccessState($userId, $moodleCourseId);
}

function moodleIntegrationLearnerCourseAccessState(int $userId, int $moodleCourseId): array
{
    $progress = moodleIntegrationUserCourseProgressRow($userId, $moodleCourseId);
    $request = moodleIntegrationEnrollmentRequestRow($userId, $moodleCourseId);
    $queue = moodleIntegrationLatestEnrollmentQueueRow($userId, $moodleCourseId);

    $requestStatus = trim((string)($request['status'] ?? ''));
    $queueStatus = trim((string)($queue['status'] ?? ''));
    $launchReady = $progress !== null;
    $reviewPending = $requestStatus === 'pending_review';
    $paymentPending = $requestStatus === 'pending_payment';
    $requestRejected = $requestStatus === 'rejected';
    $requestRevoked = $requestStatus === 'revoked';
    $queueFailed = $requestStatus === 'approved' && $queueStatus === 'failed';
    $queuePending = $requestStatus === 'approved' && $queue !== null && !$queueFailed && !$launchReady;
    $canQueueEnrollment = !$launchReady && (!$request || $requestRejected || $requestRevoked || $queueFailed);

    $message = 'Submit this course for review before launching in Moodle.';
    if ($launchReady) {
        $message = 'Enrollment is ready. You can launch this course in Moodle now.';
    } elseif ($reviewPending) {
        $message = 'Enrollment request submitted. Waiting for eligibility review.';
    } elseif ($paymentPending) {
        $message = 'Enrollment is waiting for payment confirmation before Moodle sync can begin.';
    } elseif ($requestRejected) {
        $message = trim((string)($request['review_notes'] ?? 'Enrollment request was not approved.'));
    } elseif ($requestRevoked) {
        $message = trim((string)($request['review_notes'] ?? 'Enrollment access was revoked after review.'));
    } elseif ($queuePending) {
        $message = $queueStatus === 'processing'
            ? 'Enrollment is syncing into Moodle now.'
            : 'Enrollment is queued and waiting for Moodle sync.';
    } elseif ($queueFailed) {
        $message = trim((string)($queue['last_error'] ?? 'Enrollment sync failed. Queue it again to retry.'));
    }

    return [
        'launch_ready' => $launchReady,
        'review_pending' => $reviewPending,
        'payment_pending' => $paymentPending,
        'request_rejected' => $requestRejected,
        'request_revoked' => $requestRevoked,
        'queue_pending' => $queuePending,
        'queue_failed' => $queueFailed,
        'can_queue_enrollment' => $canQueueEnrollment,
        'can_submit_request' => $canQueueEnrollment,
        'message' => $message,
        'progress' => $progress,
        'request' => $request,
        'queue' => $queue,
    ];
}

app()->hooks()->on('kernel.user_service_context', static function (?array $context, array $user): ?array {
    if (is_array($context)) {
        return $context;
    }

    return moodleIntegrationResolveUserServiceContext($user);
}, 20);