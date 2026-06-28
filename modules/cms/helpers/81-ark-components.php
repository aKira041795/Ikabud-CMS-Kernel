<?php
/**
 * ARK Theme — DiSyL Component Registration (Phase 7)
 *
 * Registers ARK-specific custom DiSyL components that extend the kernel's
 * governed component vocabulary. Each component demonstrates how themes can
 * extend DiSyL with custom rendering logic while keeping business logic in
 * handlers and presentation in templates.
 *
 * Per ARK doctrine: "Theme presents. Modules provide. DiSyL declares."
 * These components live in the CMS module (not the theme directory) because
 * themes are presentation-only — PHP registration belongs to the module layer.
 *
 * Loaded via cms_helpers autoload or explicit require in CMS bootstrap.
 *
 * @package Ikabud\Modules\CMS
 */

declare(strict_types=1);

// ── Guard: only register if TemplateEngine is available ──────────────────
if (!function_exists('app') || !app()->has('templateEngine')) {
    return;
}

$engine = app()->templateEngine();

// ── ark_card_grid — Responsive card grid layout ──────────────────────────
// Usage: {ark_card_grid columns="3" gap="md"}
//         ... {ark_card} items ...
//       {/ark_card_grid}
$engine->registerComponent('ark_card_grid', function (array $attrs, string $body): string {
    $columns = max(1, min(6, (int)($attrs['columns'] ?? 3)));
    $gap = match ($attrs['gap'] ?? 'md') {
        'sm' => '0.75rem',
        'md' => '1.25rem',
        'lg' => '2rem',
        default => '1.25rem',
    };

    return sprintf(
        '<div class="ark-card-grid" style="grid-template-columns:repeat(%d,1fr);gap:%s;">%s</div>',
        $columns,
        $gap,
        $body
    );
});

// ── ark_hero — Hero section with optional background ─────────────────────
// Usage: {ark_hero background="image_url" overlay="dark" height="500px"}
//         <h1>Headline</h1>
//         <p>Subheadline</p>
//       {/ark_hero}
$engine->registerComponent('ark_hero', function (array $attrs, string $body): string {
    $background = $attrs['background'] ?? '';
    $overlay = $attrs['overlay'] ?? 'none';
    $height = $attrs['height'] ?? '400px';
    $align = $attrs['align'] ?? 'center';

    $bgStyle = '';
    if ($background !== '' && filter_var($background, FILTER_VALIDATE_URL)) {
        $bgStyle = sprintf(
            'background-image:url(%s);background-size:cover;background-position:center;',
            htmlspecialchars($background, ENT_QUOTES, 'UTF-8')
        );
    } elseif ($background !== '') {
        $bgStyle = sprintf('background:%s;', htmlspecialchars($background, ENT_QUOTES, 'UTF-8'));
    }

    $overlayHtml = '';
    if ($overlay === 'dark') {
        $overlayHtml = '<div style="position:absolute;inset:0;background:rgba(0,0,0,0.45);"></div>';
    } elseif ($overlay === 'light') {
        $overlayHtml = '<div style="position:absolute;inset:0;background:rgba(255,255,255,0.7);"></div>';
    }

    $alignStyles = match ($align) {
        'left' => 'text-align:left;align-items:flex-start;',
        'right' => 'text-align:right;align-items:flex-end;',
        default => 'text-align:center;align-items:center;',
    };

    return sprintf(
        '<section class="ark-hero" style="position:relative;display:flex;justify-content:center;%smin-height:%s;overflow:hidden;border-radius:var(--radius-lg,1rem);margin-bottom:var(--spacing-lg,2rem);%s">%s<div style="position:relative;z-index:1;padding:var(--spacing-xl,3rem) var(--spacing-md,1.25rem);max-width:var(--layout-max-width,1280px);width:100%%;display:flex;flex-direction:column;%s">%s</div></section>',
        $alignStyles,
        htmlspecialchars($height, ENT_QUOTES, 'UTF-8'),
        $bgStyle,
        $overlayHtml,
        $alignStyles,
        $body
    );
});

// ── ark_stats — Statistics/metrics grid row ──────────────────────────────
// Usage: {ark_stats columns="4"}
//         {ark_stat value="125K" label="Users" /}
//         {ark_stat value="99.9%" label="Uptime" /}
//       {/ark_stats}
$engine->registerComponent('ark_stats', function (array $attrs, string $body): string {
    $columns = max(1, min(6, (int)($attrs['columns'] ?? 3)));

    return sprintf(
        '<div class="ark-stats" style="display:grid;grid-template-columns:repeat(%d,1fr);gap:var(--spacing-md,1.25rem);margin:var(--spacing-lg,2rem) 0;">%s</div>',
        $columns,
        $body
    );
});

// ── ark_stat — Single stat item (used inside ark_stats) ──────────────────
// Usage: {ark_stat value="125K" label="Active Users" icon="users" /}
$engine->registerComponent('ark_stat', function (array $attrs, string $body): string {
    $value = htmlspecialchars((string)($attrs['value'] ?? ''), ENT_QUOTES, 'UTF-8');
    $label = htmlspecialchars((string)($attrs['label'] ?? ''), ENT_QUOTES, 'UTF-8');
    $icon = htmlspecialchars((string)($attrs['icon'] ?? ''), ENT_QUOTES, 'UTF-8');

    $iconHtml = '';
    if ($icon !== '') {
        $iconHtml = sprintf(
            '<div style="font-size:1.5rem;margin-bottom:0.35rem;color:var(--color-primary);">%s</div>',
            $icon
        );
    }

    return sprintf(
        '<div class="ark-stat" style="text-align:center;padding:var(--spacing-md,1.25rem);background:var(--color-surface,#fff);border:1px solid var(--color-border,#e2e8f0);border-radius:var(--radius-md,0.75rem);">%s<div style="font-size:clamp(1.5rem,4vw,2.5rem);font-weight:800;color:var(--color-text);line-height:1.1;">%s</div><div style="font-size:0.85rem;color:var(--color-text-secondary);margin-top:0.25rem;">%s</div></div>',
        $iconHtml,
        $value,
        $label
    );
});

// ── ark_section_heading — Unified section heading with optional link ─────
// Usage: {ark_section_heading title="Latest Articles" link_url="/blog" link_label="View all" /}
$engine->registerComponent('ark_section_heading', function (array $attrs, string $body): string {
    $title = htmlspecialchars((string)($attrs['title'] ?? ''), ENT_QUOTES, 'UTF-8');
    $linkUrl = htmlspecialchars((string)($attrs['link_url'] ?? ''), ENT_QUOTES, 'UTF-8');
    $linkLabel = htmlspecialchars((string)($attrs['link_label'] ?? 'View all'), ENT_QUOTES, 'UTF-8');

    $linkHtml = '';
    if ($linkUrl !== '') {
        $linkHtml = sprintf(
            '<a href="%s" style="color:var(--color-primary);font-weight:500;font-size:0.875rem;text-decoration:none;">%s &rarr;</a>',
            $linkUrl,
            $linkLabel
        );
    }

    return sprintf(
        '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:var(--spacing-md,1.25rem);"><h2 style="font-size:1.5rem;font-weight:700;margin:0;color:var(--color-text);">%s</h2>%s</div>',
        $title,
        $linkHtml
    );
});
