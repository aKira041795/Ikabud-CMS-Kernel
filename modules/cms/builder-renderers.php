<?php

/**
 * CMS Page Builder — Per-Widget Render Functions
 *
 * Each function renders a single widget type. Signature:
 *   function(array $props, array $style, array $attrs, string $children, array $node, array $context): string
 *
 * Loaded by helpers.php and dispatched via cmsBuilderWidgetRenderers().
 */

if (function_exists('cmsBuilderWidgetRenderers')) {
    return; // Already loaded — prevent "Cannot redeclare" fatal
}

// ─── Dispatch Registry ──────────────────────────────────────────────────

/**
 * Returns the widget-type → render-function map.
 * Extensible: modules can hook 'cms.builder.renderers' to add/override entries.
 */
function cmsBuilderWidgetRenderers(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [
        'document'        => 'cmsRenderWidget_document',
        'section'         => 'cmsRenderWidget_layout',
        'container'       => 'cmsRenderWidget_layout',
        'layout_container' => 'cmsRenderWidget_layout',
        'row'             => 'cmsRenderWidget_layout',
        'column'          => 'cmsRenderWidget_layout',
        'heading'         => 'cmsRenderWidget_heading',
        'text'            => 'cmsRenderWidget_text',
        'image'           => 'cmsRenderWidget_image',
        'button'          => 'cmsRenderWidget_button',
        'spacer'          => 'cmsRenderWidget_spacer',
        'divider'         => 'cmsRenderWidget_divider',
        'video'           => 'cmsRenderWidget_video',
        'icon'            => 'cmsRenderWidget_icon',
        'icon_box'        => 'cmsRenderWidget_icon_box',
        'tabs'            => 'cmsRenderWidget_tabs',
        'accordion'       => 'cmsRenderWidget_accordion',
        'social_icons'    => 'cmsRenderWidget_social_icons',
        'list'            => 'cmsRenderWidget_list',
        'counter'         => 'cmsRenderWidget_counter',
        'progress'        => 'cmsRenderWidget_progress',
        'testimonial'     => 'cmsRenderWidget_testimonial',
        'slideshow'       => 'cmsRenderWidget_slideshow',
        'form'            => 'cmsRenderWidget_form',
        'gallery'         => 'cmsRenderWidget_gallery',
        'map'             => 'cmsRenderWidget_map',
        'table'           => 'cmsRenderWidget_table',
        'alert'           => 'cmsRenderWidget_alert',
        'anchor'          => 'cmsRenderWidget_anchor',
        'posts_grid'      => 'cmsRenderWidget_posts_grid',
        'products_grid'   => 'cmsRenderWidget_products_grid',
        'team_grid'       => 'cmsRenderWidget_team_grid',
        'entity_view'     => 'cmsRenderWidget_entity_view',
        'entity_list'     => 'cmsRenderWidget_entity_list',
        'pricing_table'   => 'cmsRenderWidget_pricing_table',
        'countdown'       => 'cmsRenderWidget_countdown',
        'star_rating'     => 'cmsRenderWidget_star_rating',
        'call_to_action'  => 'cmsRenderWidget_call_to_action',
        'flip_box'        => 'cmsRenderWidget_flip_box',
        'image_box'       => 'cmsRenderWidget_image_box',
        'logo_grid'       => 'cmsRenderWidget_logo_grid',
        'blockquote'      => 'cmsRenderWidget_blockquote',
        'toggle'          => 'cmsRenderWidget_toggle',
        'search_box'      => 'cmsRenderWidget_search_box',
        'nav_menu'        => 'cmsRenderWidget_nav_menu',
        'recent_posts'    => 'cmsRenderWidget_recent_posts',
        'social_links'    => 'cmsRenderWidget_social_links_widget',
        'contact_info'    => 'cmsRenderWidget_contact_info',
        'categories'      => 'cmsRenderWidget_categories',
        'tag_cloud'       => 'cmsRenderWidget_tag_cloud',
        'archives'        => 'cmsRenderWidget_archives',
        'opening_hours'   => 'cmsRenderWidget_opening_hours',
        'badge'           => 'cmsRenderWidget_badge',
        'stat_card'       => 'cmsRenderWidget_stat_card',
        'contact_card'    => 'cmsRenderWidget_contact_card',
        'breadcrumbs'     => 'cmsRenderWidget_breadcrumbs',
        'code_block'      => 'cmsRenderWidget_code_block',
        'audio'           => 'cmsRenderWidget_audio',
        'html_embed'      => 'cmsRenderWidget_html_embed',
    ];

    // Allow modules to extend/override widget renderers via kernel Hooks (filter).
    // app()->hooks()->on('cms.builder.renderers', function(array $map): array { $map['my_type'] = 'myRenderFn'; return $map; }, 10);
    $map = app()->hooks()->filter('cms.builder.renderers', $map);

    return $map;
}

// ─── Widget Render Functions ─────────────────────────────────────────────

/**
 * Apply full-width breakout styles: expand to 100vw and bleed past the column.
 * Mirrors the React NodeRenderer wrapperStyle full-width override.
 */
function cmsBuilderApplyFullWidth(array &$style): void
{
    unset($style['width'], $style['margin'], $style['marginLeft'], $style['marginRight']);
    $style['width']      = '100vw';
    $style['marginLeft'] = 'calc(-50vw + 50%)';
    $style['alignSelf']  = 'flex-start';
    $style['overflow']   = 'hidden';
}

function cmsRenderWidget_document(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    return $children;
}

function cmsRenderWidget_layout(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $type = (string)($node['type'] ?? 'div');
    $tag = $type === 'section' ? 'section' : 'div';
    $rawStyle = isset($node['style']) && is_array($node['style']) ? $node['style'] : [];

    // Container is used in two different roles:
    // 1) constrained page-width wrapper (top-level inside a section)
    // 2) generic flex/grid layout cell inside presets / other flex containers
    // Apply wrapper defaults only when the node is not explicitly acting as
    // a layout container/item AND is not a flex/grid child of another container.
    // In the React builder these containers live inside a wrapper <div> that
    // absorbs flex-child sizing; the PHP output has no wrapper so we must
    // detect the parent context and skip constrained-width defaults when
    // the container is already a flex/grid item of its parent.
    if ($type === 'container') {
        $isLayoutItem = !empty($rawStyle['flex']) || !empty($rawStyle['flexBasis']) || !empty($rawStyle['order']) || !empty($rawStyle['alignSelf']);
        $isExplicitLayout = in_array((string)($rawStyle['display'] ?? ''), ['flex', 'grid'], true);
        $hasExplicitConstraint = array_key_exists('maxWidth', $rawStyle) || array_key_exists('margin', $rawStyle);
        // Check if the parent is a flex/grid container — if so this container
        // is a flex/grid item and should NOT get page-width wrapper defaults.
        $parentDisplay = (string)($context['_parent_display'] ?? '');
        $parentType = (string)($context['_parent_type'] ?? '');
        $isFlexChild = in_array($parentDisplay, ['flex', 'grid'], true);
        // Exception: direct child of a section is still a page-width wrapper
        // (sections contain a single centered container by convention).
        $isDirectSectionChild = $parentType === 'section';

        if (!$isLayoutItem && !$isExplicitLayout && !$hasExplicitConstraint && (!$isFlexChild || $isDirectSectionChild)) {
            $style = ['maxWidth' => '1200px', 'margin' => '0 auto', 'padding' => '0 24px'] + $style;
        }
    }

    // Mirror React ColumnRenderer: inject flex:1 when no explicit flex or width is set,
    // so columns evenly share space inside their parent row — matching the builder preview.
    if ($type === 'column') {
        $hasExplicitSize = !empty($style['flex']) || !empty($style['width']);
        if (!$hasExplicitSize) {
            $style = ['flex' => '1'] + $style;
        }
    }

    // Flag layout containers with responsive mobile collapse rules so CSS can target only
    // preset/custom layout containers instead of every generic container on the site.
    if (in_array($type, ['container', 'layout_container'], true)) {
        $mobile = isset($rawStyle['mobile']) && is_array($rawStyle['mobile']) ? $rawStyle['mobile'] : [];
        if (($style['display'] ?? null) === 'flex' || ($style['display'] ?? null) === 'grid') {
            $attrs['data-layout-display'] = (string)$style['display'];
        }
        // Auto-set data-mobile-layout for flex-row containers (so CSS auto-stacks on mobile)
        // even when the user hasn't set explicit mobile overrides.
        $isFlexRow = (($style['display'] ?? '') === 'flex' || (!isset($style['display']) && (isset($style['flexDirection']) || isset($style['gap'])))) &&
                     in_array($style['flexDirection'] ?? 'row', ['row', 'row-reverse'], true);
        $hasExplicitMobile = !empty($mobile['flexDirection']) || !empty($mobile['gridTemplateColumns']) || !empty($mobile['flex']);
        if ($isFlexRow || $hasExplicitMobile) {
            $attrs['data-mobile-layout'] = '1';
        }
    }

    return '<' . $tag . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . $children . '</' . $tag . '>';
}

function cmsRenderWidget_heading(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $tag = cmsBuilderNormalizeHeadingTag($props['level'] ?? 'h2');
    return '<' . $tag . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . cmsBuilderNodeContent($props) . '</' . $tag . '>';
}

function cmsRenderWidget_text(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . cmsBuilderNodeContent($props) . '</div>';
}

function cmsRenderWidget_image(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $src = (string)($props['src'] ?? $props['url'] ?? '');
    if ($src === '') {
        return '';
    }
    $alt = (string)($props['alt'] ?? '');
    $caption = (string)($props['caption'] ?? '');
    $linkUrl = (string)($props['linkUrl'] ?? '');
    $linkTarget = (string)($props['linkTarget'] ?? '');

    // Extract image-specific CSS props that belong on <img>, not <figure>
    $imgStyle = [];
    $imgOnlyProps = ['objectFit', 'objectPosition'];
    foreach ($imgOnlyProps as $ip) {
        if (isset($style[$ip])) {
            $imgStyle[$ip] = $style[$ip];
            unset($style[$ip]);
        }
    }
    // width/height go on the <img> too so the image itself sizes correctly,
    // but keep them on <figure> as well for wrapper sizing
    if (isset($style['width'])) {
        $imgStyle['width'] = '100%';
    }
    if (isset($style['height']) && $style['height'] !== 'auto') {
        $imgStyle['height'] = '100%';
    }

    // Full-width breakout: expand figure to 100vw and ensure img fills it
    if (!empty($props['fullWidth'])) {
        cmsBuilderApplyFullWidth($style);
        $imgStyle['width'] = '100%';
    }

    $imgStyleStr = cmsBuilderStyleAttr($imgStyle);
    $imgTag = '<img src="' . cmsBuilderEsc($src) . '" alt="' . cmsBuilderEsc($alt) . '" loading="lazy"' . $imgStyleStr . '>';

    // Wrap in link if linkUrl provided
    if ($linkUrl !== '') {
        $targetAttr = $linkTarget === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';
        $imgTag = '<a href="' . cmsBuilderEsc($linkUrl) . '"' . $targetAttr . '>' . $imgTag . '</a>';
    }

    $html = '<figure' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>'
        . $imgTag;
    if ($caption !== '') {
        $html .= '<figcaption>' . cmsBuilderEsc($caption) . '</figcaption>';
    }
    return $html . '</figure>';
}

function cmsRenderWidget_button(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $href = (string)($props['href'] ?? $props['url'] ?? $props['buttonUrl'] ?? '#');
    $label = cmsBuilderNodeContent($props);
    if ($label === '') {
        $label = (string)($props['buttonText'] ?? $props['text'] ?? 'Click here');
    }
    $target = (string)($props['target'] ?? '');
    $variant = (string)($props['variant'] ?? 'primary');
    $size = (string)($props['size'] ?? 'medium');
    $classes = trim(($attrs['class'] ?? '') . ' cms-builder-button cms-builder-button--' . $variant . ' cms-builder-button--' . $size);
    return '<a' . cmsBuilderAttrString(array_merge($attrs, [
        'href' => $href,
        'class' => $classes,
        'target' => $target === '_blank' ? '_blank' : null,
        'rel' => $target === '_blank' ? 'noopener noreferrer' : null,
    ])) . cmsBuilderStyleAttr($style) . '>' . cmsBuilderEsc($label) . '</a>';
}

function cmsRenderWidget_spacer(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    if (!isset($style['height']) && !empty($props['height'])) {
        $style['height'] = (string)$props['height'];
    }
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '></div>';
}

function cmsRenderWidget_divider(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $dividerStyle = (string)($props['dividerStyle'] ?? 'solid');
    $thickness = (string)($props['thickness'] ?? '');
    $color = (string)($props['color'] ?? '');
    $width = (string)($props['width'] ?? '100%');
    $label = trim((string)($props['label'] ?? ''));

    // Text divider: render as flex row with line-text-line
    if ($label !== '') {
        $lineColor = $color !== '' ? $color : '#e5e7eb';
        $lineThickness = $thickness !== '' ? $thickness : '1px';
        $wrapStyle = array_merge(['display' => 'flex', 'alignItems' => 'center', 'gap' => '12px', 'width' => $width, 'margin' => '0 auto'], $style);
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapStyle) . '>'
            . '<span style="flex:1;height:' . cmsBuilderEsc($lineThickness) . ';background:' . cmsBuilderEsc($lineColor) . '"></span>'
            . '<span style="font-size:13px;color:#6b7280;white-space:nowrap">' . cmsBuilderEsc($label) . '</span>'
            . '<span style="flex:1;height:' . cmsBuilderEsc($lineThickness) . ';background:' . cmsBuilderEsc($lineColor) . '"></span>'
            . '</div>';
    }

    // Map panel props into style equivalents (panel props override defaults but user style wins)
    $base = ['border' => 'none', 'width' => $width, 'display' => 'block', 'margin' => '0 auto'];
    if ($thickness !== '') {
        $base['height'] = $thickness;
    } else {
        $base['height'] = '1px';
    }
    if ($color !== '') {
        $base['backgroundColor'] = $color;
    } else {
        $base['backgroundColor'] = '#e5e7eb';
    }
    // Apply border-style for non-solid dividers (dashed, dotted, double)
    if ($dividerStyle !== 'solid' && $dividerStyle !== '') {
        $base['borderTopStyle'] = $dividerStyle;
        $base['borderTopWidth'] = $thickness !== '' ? $thickness : '1px';
        $base['borderTopColor'] = $color !== '' ? $color : '#e5e7eb';
        $base['height'] = 'auto';
        unset($base['backgroundColor']);
    }
    $style = array_merge($base, $style);
    return '<hr' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>';
}

function cmsRenderWidget_video(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $src = (string)($props['src'] ?? $props['url'] ?? '');
    if ($src === '') {
        return '';
    }
    if (!empty($props['fullWidth'])) {
        cmsBuilderApplyFullWidth($style);
    }
    if (preg_match('/youtube\.com\/watch|youtu\.be\/|vimeo\.com\//', $src)) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . cmsGenerateEmbedHtml($src, cmsDetectEmbedProvider($src)) . '</div>';
    }
    $poster = (string)($props['poster'] ?? '');
    return '<video' . cmsBuilderAttrString(array_merge($attrs, [
        'src' => $src,
        'poster' => $poster !== '' ? $poster : null,
        'controls' => !empty($props['controls']) ? 'controls' : null,
        'autoplay' => !empty($props['autoplay']) ? 'autoplay' : null,
        'loop' => !empty($props['loop']) ? 'loop' : null,
        'muted' => !empty($props['muted']) ? 'muted' : null,
        'playsinline' => 'playsinline',
    ])) . cmsBuilderStyleAttr($style) . '></video>';
}

function cmsRenderWidget_icon(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $iconName = (string)($props['icon'] ?? 'Star');
    $size     = max(12, (int)($props['size'] ?? 24));
    $color    = trim((string)($props['color'] ?? 'currentColor'));
    if ($color === '') {
        $color = 'currentColor';
    }
    if (!isset($style['color']) && $color !== 'currentColor') {
        $style['color'] = $color;
    }
    $svgPath = cmsBuilderIconSvgPath($iconName);
    $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24"'
         . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"'
         . ' aria-hidden="true">' . $svgPath . '</svg>';
    return '<span' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'inline-flex', 'alignItems' => 'center', 'justifyContent' => 'center'], $style)) . '>' . $svg . '</span>';
}

function cmsBuilderIconSvgPath(string $icon): string
{
    return match ($icon) {
        'Heart'      => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
        'Check'      => '<polyline points="20 6 9 17 4 12"/>',
        'Zap'        => '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
        'Shield'     => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
        'Clock'      => '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
        'Globe'      => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10A15.3 15.3 0 0 1 12 2z"/>',
        'Mail'       => '<path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/>',
        'Phone'      => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.69 12a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.6 1.22h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L7.91 8.91a16 16 0 0 0 5.55 5.55l.91-.91a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 21 16.92z"/>',
        'Lock'       => '<rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'Rocket'     => '<path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="m12 15-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/>',
        'Lightbulb'  => '<line x1="9" y1="18" x2="15" y2="18"/><line x1="10" y1="22" x2="14" y2="22"/><path d="M15.09 14c.18-.98.65-1.74 1.41-2.5A4.65 4.65 0 0 0 18 8 6 6 0 0 0 6 8c0 1 .23 2.23 1.5 3.5A4.61 4.61 0 0 1 8.91 14"/>',
        'ArrowRight' => '<line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/>',
        'MapPin'     => '<path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>',
        default      => '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
    };
}

function cmsRenderWidget_icon_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $icon        = (string)($props['icon']        ?? 'Star');
    $title       = cmsBuilderEsc((string)($props['title']       ?? ''));
    $description = cmsBuilderEsc((string)($props['description'] ?? ''));
    $layout      = in_array((string)($props['layout'] ?? ''), ['left', 'right', 'top'], true) ? (string)$props['layout'] : 'top';
    $linkUrl     = trim((string)($props['linkUrl']  ?? ''));
    $linkText    = trim((string)($props['linkText'] ?? 'Learn more'));

    $iconSvg  = '<svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . cmsBuilderIconSvgPath($icon) . '</svg>';
    $iconWrap = '<div style="display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;border-radius:12px;background:#f1f5f9;color:#0f172a;flex-shrink:0">' . $iconSvg . '</div>';

    $ctaHtml = '';
    if ($linkUrl !== '') {
        $ctaHtml = '<div style="margin-top:8px"><a href="' . cmsBuilderEsc($linkUrl) . '" style="font-size:13px;color:#2563eb;text-decoration:none;font-weight:500">' . cmsBuilderEsc($linkText) . ' &rarr;</a></div>';
    }

    $textBlock = '<div>';
    if ($title !== '')       { $textBlock .= '<h3 style="margin:0 0 6px;font-size:15px;font-weight:600;color:#0f172a;line-height:1.4">' . $title . '</h3>'; }
    if ($description !== '') { $textBlock .= '<p style="margin:0;font-size:14px;line-height:1.6;color:#475569">' . $description . '</p>'; }
    $textBlock .= $ctaHtml . '</div>';

    $isInline = in_array($layout, ['left', 'right'], true);
    if ($isInline) {
        $flexDir  = $layout === 'right' ? 'row-reverse' : 'row';
        $innerHtml = '<div style="display:flex;flex-direction:' . $flexDir . ';align-items:flex-start;gap:16px">' . $iconWrap . $textBlock . '</div>';
    } else {
        $innerHtml = '<div style="display:flex;flex-direction:column;align-items:center;text-align:center;gap:12px">' . $iconWrap . $textBlock . '</div>';
    }

    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . $innerHtml . '</div>';
}

function cmsRenderWidget_tabs(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $tabs = cmsBuilderNormalizeItems($props['tabs'] ?? [], 'tabs');
    $activeTab = (string)($props['activeTab'] ?? ($tabs[0]['id'] ?? ''));
    $tabStyle = (string)($props['tabStyle'] ?? 'underline');
    $tabAlign = (string)($props['tabAlign'] ?? 'left');
    $justifyContent = match ($tabAlign) {
        'center' => 'center',
        'right' => 'flex-end',
        default => 'flex-start',
    };
    $nav = '';
    $panels = '';
    foreach ($tabs as $tab) {
        if (!is_array($tab)) {
            continue;
        }
        $tabId = cmsBuilderEsc((string)($tab['id'] ?? ''));
        $isActive = (string)($tab['id'] ?? '') === $activeTab;
        $activeCls = $isActive ? ' active' : '';
        $nav .= '<button type="button" class="cms-builder-tab-btn cms-builder-tab-btn--' . $tabStyle . $activeCls . '" data-tab="' . $tabId . '">' . cmsBuilderEsc((string)($tab['label'] ?? 'Tab')) . '</button>';
        $panels .= '<div class="cms-builder-tab-panel' . $activeCls . '" data-tab="' . $tabId . '">' . cmsBuilderEsc((string)($tab['content'] ?? '')) . '</div>';
    }
    // Style variants
    if ($tabStyle === 'pills') {
        $btnCss = '.cms-builder-tab-btn--pills{padding:8px 18px;border:none;border-radius:999px;background:transparent;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280}'
            . '.cms-builder-tab-btn--pills.active{background:#2563EB;color:#fff}';
        $navBorderCss = 'display:flex;gap:6px;margin-bottom:16px;justify-content:' . $justifyContent;
    } elseif ($tabStyle === 'boxed') {
        $btnCss = '.cms-builder-tab-btn--boxed{padding:10px 20px;border:1px solid #e5e7eb;border-bottom:none;background:#f9fafb;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border-radius:6px 6px 0 0}'
            . '.cms-builder-tab-btn--boxed.active{background:#fff;color:#2563EB;border-color:#2563EB}';
        $navBorderCss = 'display:flex;gap:4px;border-bottom:1px solid #e5e7eb;margin-bottom:16px;justify-content:' . $justifyContent;
    } else {
        // Default: underline
        $btnCss = '.cms-builder-tab-btn--underline{padding:10px 20px;border:none;background:transparent;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border-bottom:2px solid transparent}'
            . '.cms-builder-tab-btn--underline.active{color:#2563EB;border-bottom-color:#2563EB}';
        $navBorderCss = 'display:flex;border-bottom:1px solid #e5e7eb;margin-bottom:16px;justify-content:' . $justifyContent;
    }
    $panelCss = '<style>.cms-builder-tab-panel{display:none}.cms-builder-tab-panel.active{display:block}' . $btnCss . '</style>';
    return $panelCss . '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><div style="' . $navBorderCss . '">' . $nav . '</div><div>' . $panels . '</div></div>';
}

function cmsRenderWidget_accordion(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $items = cmsBuilderNormalizeItems($props['items'] ?? [], 'accordion');
    $allowMultiple = ($props['allowMultiple'] ?? false) !== false;
    // When allowMultiple is false, use a shared name attribute on <details> (HTML native exclusive accordion)
    $exclusiveName = !$allowMultiple ? 'accordion-' . cmsBuilderEsc((string)($node['id'] ?? 'a')) : '';
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $nameAttr = $exclusiveName !== '' ? ' name="' . $exclusiveName . '"' : '';
        $html .= '<details' . $nameAttr . (!empty($item['isOpen']) ? ' open' : '') . '>'
            . '<summary>' . cmsBuilderEsc((string)($item['title'] ?? 'Item')) . '</summary>'
            . '<div>' . cmsBuilderEsc((string)($item['content'] ?? '')) . '</div></details>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_social_icons(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $icons = isset($props['icons']) && is_array($props['icons']) ? $props['icons'] : [];
    $size = (int)($props['size'] ?? 24);
    $iconStyle = (string)($props['style'] ?? 'filled');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'gap' => '12px', 'alignItems' => 'center', 'flexWrap' => 'wrap'], $style)) . '>';
    foreach ($icons as $ico) {
        if (!is_array($ico)) continue;
        $platform = cmsBuilderEsc((string)($ico['platform'] ?? 'link'));
        $url = cmsBuilderEsc((string)($ico['url'] ?? '#'));
        $boxSize = $size + 16;
        if ($iconStyle === 'outline') {
            $itemStyle = 'display:inline-flex;align-items:center;justify-content:center;width:' . $boxSize . 'px;height:' . $boxSize . 'px;border-radius:50%;background-color:transparent;border:2px solid #374151;color:#374151;text-decoration:none;font-size:12px;font-weight:600';
        } elseif ($iconStyle === 'minimal') {
            $itemStyle = 'display:inline-flex;align-items:center;justify-content:center;color:#374151;text-decoration:none;font-size:13px;font-weight:500;padding:4px 6px';
        } else {
            $itemStyle = 'display:inline-flex;align-items:center;justify-content:center;width:' . $boxSize . 'px;height:' . $boxSize . 'px;border-radius:50%;background-color:#f3f4f6;color:#374151;text-decoration:none;font-size:12px;font-weight:600';
        }
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="cms-builder-social-icon" data-platform="' . $platform . '" style="' . $itemStyle . '">' . $platform . '</a>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_list(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $listItems = cmsBuilderNormalizeItems($props['items'] ?? []);
    $listType = (string)($props['listType'] ?? 'bullet');
    $icon = (string)($props['icon'] ?? 'Check');
    $tag = $listType === 'number' ? 'ol' : 'ul';
    $isCheck = $listType === 'check';
    $listStyle = $isCheck ? ['listStyle' => 'none', 'paddingLeft' => '0'] : ['paddingLeft' => '1.5em'];
    $iconMap = ['Check' => '&#x2713;', 'CheckCircle' => '&#x25C9;', 'Star' => '&#x2605;', 'ArrowRight' => '&#x2192;', 'Dash' => '&mdash;'];
    $iconChar = $iconMap[$icon] ?? '&#x2713;';
    $html = '<' . $tag . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'flexDirection' => 'column', 'gap' => '8px'], $listStyle, $style)) . '>';
    foreach ($listItems as $li) {
        $prefix = $isCheck ? '<span style="color:#22c55e;margin-right:8px">' . $iconChar . '</span>' : '';
        $html .= '<li' . ($isCheck ? ' style="display:flex;align-items:center"' : '') . '>' . $prefix . cmsBuilderEsc((string)$li) . '</li>';
    }
    return $html . '</' . $tag . '>';
}

function cmsRenderWidget_counter(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $end = cmsBuilderEsc((string)($props['endValue'] ?? '100'));
    $start = cmsBuilderEsc((string)($props['startValue'] ?? '0'));
    $prefix = cmsBuilderEsc((string)($props['prefix'] ?? ''));
    $suffix = cmsBuilderEsc((string)($props['suffix'] ?? ''));
    $title = cmsBuilderEsc((string)($props['title'] ?? ''));
    $duration = cmsBuilderEsc((string)($props['duration'] ?? '2000'));
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['textAlign' => 'center', 'padding' => '24px'], $style)) . '>'
        . '<div class="cms-builder-counter-value" data-target="' . $end . '" data-duration="' . $duration . '" data-prefix="' . $prefix . '" data-suffix="' . $suffix . '" style="font-size:48px;font-weight:700;line-height:1">' . $prefix . $start . $suffix . '</div>'
        . ($title !== '' ? '<div style="font-size:14px;color:#6b7280;margin-top:8px">' . $title . '</div>' : '')
        . '</div>';
}

function cmsRenderWidget_progress(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $val = max(0, min(100, (int)($props['value'] ?? 75)));
    $max = max(1, (int)($props['max'] ?? 100));
    $pct = round($val / $max * 100);
    $label = cmsBuilderEsc((string)($props['label'] ?? 'Progress'));
    $color = cmsBuilderEsc((string)($props['color'] ?? '#3B82F6'));
    $showVal = ($props['showValue'] ?? true) !== false;
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['width' => '100%'], $style)) . '>'
        . '<div style="display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px"><span>' . $label . '</span>' . ($showVal ? '<span>' . $pct . '%</span>' : '') . '</div>'
        . '<div style="width:100%;height:8px;background-color:#e5e7eb;border-radius:4px;overflow:hidden"><div style="width:' . $pct . '%;height:100%;background-color:' . $color . ';border-radius:4px"></div></div>'
        . '</div>';
}

function cmsRenderWidget_testimonial(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $quote = cmsBuilderEsc((string)($props['quote'] ?? ''));
    $author = cmsBuilderEsc((string)($props['author'] ?? ''));
    $role = cmsBuilderEsc((string)($props['role'] ?? ''));
    $avatar = (string)($props['avatar'] ?? '');
    $rating = (int)($props['rating'] ?? 0);
    $stars = '';
    if ($rating > 0) {
        $stars = '<div style="color:#fbbf24;margin-bottom:12px">' . str_repeat('★', min($rating, 5)) . str_repeat('☆', max(0, 5 - $rating)) . '</div>';
    }
    $avatarHtml = $avatar !== '' ? '<img src="' . cmsBuilderEsc($avatar) . '" alt="' . $author . '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;margin-right:12px" loading="lazy">' : '';
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['padding' => '24px', 'backgroundColor' => '#f9fafb', 'borderRadius' => '8px'], $style)) . '>'
        . $stars
        . '<blockquote style="font-size:16px;line-height:1.6;color:#374151;margin:0 0 16px 0;font-style:italic">' . $quote . '</blockquote>'
        . '<div style="display:flex;align-items:center">' . $avatarHtml . '<div><div style="font-weight:600;font-size:14px;color:#1f2937">' . $author . '</div>' . ($role !== '' ? '<div style="font-size:13px;color:#6b7280">' . $role . '</div>' : '') . '</div></div>'
        . '</div>';
}

function cmsRenderWidget_slideshow(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $slides        = cmsBuilderNormalizeItems($props['slides'] ?? [], 'slides');
    $height        = cmsBuilderEsc((string)($props['height'] ?? '500px'));
    $interval      = (int)($props['interval'] ?? 5000);
    $showArrows    = ($props['showArrows'] ?? true) !== false;
    $showDots      = ($props['showDots'] ?? true) !== false;
    $autoplay      = !empty($props['autoplay']);
    $animationStyle = cmsBuilderEsc((string)($props['animationStyle'] ?? 'slide'));
    $fullWidth     = !empty($props['fullWidth']);
    $showCaption   = ($props['showCaption'] ?? true) !== false;

    // Playback extras
    $loop         = ($props['loop'] ?? true) !== false;
    $pauseOnHover = ($props['pauseOnHover'] ?? true) !== false;
    $keyboardNav  = ($props['keyboardNav'] ?? true) !== false;

    // Animation extras
    $transitionSpeed  = max(100, (int)($props['transitionSpeed'] ?? 600));
    $transitionEasing = cmsBuilderEsc((string)($props['transitionEasing'] ?? 'ease-in-out'));
    $imageObjectFit   = cmsBuilderEsc((string)($props['imageObjectFit'] ?? 'cover'));

    // Caption props
    $captionAlign    = cmsBuilderEsc((string)($props['captionAlign'] ?? 'center'));
    $captionPosition = (string)($props['captionPosition'] ?? 'bottom');
    $captionColor    = cmsBuilderEsc((string)($props['captionColor'] ?? '#ffffff'));
    $captionTitleSize = cmsBuilderEsc((string)($props['captionTitleSize'] ?? '32px'));
    $captionDescSize  = cmsBuilderEsc((string)($props['captionDescSize'] ?? '18px'));
    $captionBgProp   = (string)($props['captionBg'] ?? 'auto');
    $captionWidth    = (string)($props['captionWidth'] ?? 'full');
    $captionMaxWidth = cmsBuilderEsc((string)($props['captionMaxWidth'] ?? ''));

    // Arrow / dot styles
    $arrowStyleProp = (string)($props['arrowStyle'] ?? 'rounded');
    $dotStyleProp   = (string)($props['dotStyle'] ?? 'dots');

    // Button styles
    $btnColor        = cmsBuilderEsc((string)($props['btnColor'] ?? '#2563EB'));
    $btnTextColor    = cmsBuilderEsc((string)($props['btnTextColor'] ?? '#ffffff'));
    $btnBorderRadius = cmsBuilderEsc((string)($props['btnBorderRadius'] ?? '6px'));
    $btnSize         = (string)($props['btnSize'] ?? 'md');
    $btn2Style       = (string)($props['btn2Style'] ?? 'outline');
    $btn2Color       = cmsBuilderEsc((string)($props['btn2Color'] ?? '#ffffff'));

    $btnPaddingMap = ['sm' => '7px 16px', 'md' => '10px 20px', 'lg' => '13px 28px', 'xl' => '16px 36px'];
    $btnFontMap    = ['sm' => '12px', 'md' => '14px', 'lg' => '16px', 'xl' => '18px'];
    $btnPad  = $btnPaddingMap[$btnSize] ?? '10px 20px';
    $btnFont = $btnFontMap[$btnSize] ?? '14px';

    // Caption inner max-width
    if ($captionMaxWidth !== '') {
        $captionInnerMaxWidth = $captionMaxWidth;
    } elseif ($captionWidth === 'narrow') {
        $captionInnerMaxWidth = '800px';
    } elseif ($captionWidth === 'boxed') {
        $captionInnerMaxWidth = '1200px';
    } else {
        $captionInnerMaxWidth = '';
    }

    // Caption background
    if ($captionBgProp === '' || $captionBgProp === 'auto') {
        $bgGrad = $captionPosition === 'center' ? 'rgba(0,0,0,0.4)' : 'linear-gradient(transparent,rgba(0,0,0,0.6))';
    } else {
        $bgGrad = cmsBuilderEsc($captionBgProp);
    }

    // Caption position CSS
    $posStyle = 'position:absolute;left:0;right:0;padding:24px;z-index:2;';
    if ($captionPosition === 'top') {
        $posStyle .= 'top:0;';
    } elseif ($captionPosition === 'center') {
        $posStyle .= 'top:50%;transform:translateY(-50%);';
    } else {
        $posStyle .= 'bottom:0;';
    }

    // Button row alignment
    $btnJustify = $captionAlign === 'right' ? 'flex-end' : ($captionAlign === 'center' ? 'center' : 'flex-start');

    // Transition speed in seconds string
    $transSpeedSec   = round($transitionSpeed / 1000, 2) . 's';
    $trackTransition = 'transform ' . $transSpeedSec . ' ' . $transitionEasing;
    $stackTransition = 'opacity ' . $transSpeedSec . ' ' . $transitionEasing;

    // Full-width support
    $wrapStyle = ['position' => 'relative', 'overflow' => 'hidden'];
    if ($fullWidth) {
        unset($style['width'], $style['margin'], $style['marginLeft'], $style['marginRight']);
        $wrapStyle['width']      = '100vw';
        $wrapStyle['marginLeft'] = 'calc(-50vw + 50%)';
        $wrapStyle['alignSelf']  = 'flex-start';
    }

    // Data attributes
    $dataAttrs = ' data-interval="' . $interval . '"'
        . ' data-autoplay="' . ($autoplay ? 'true' : 'false') . '"'
        . ' data-animation="' . $animationStyle . '"'
        . ' data-loop="' . ($loop ? 'true' : 'false') . '"'
        . ' data-pause-hover="' . ($pauseOnHover ? 'true' : 'false') . '"'
        . ' data-keyboard="' . ($keyboardNav ? 'true' : 'false') . '"'
        . ' data-transition-speed="' . $transitionSpeed . '"'
        . ' data-transition-easing="' . $transitionEasing . '"';

    $html = '<div' . cmsBuilderAttrString($attrs) . $dataAttrs . cmsBuilderStyleAttr(array_merge($style, $wrapStyle)) . '>';

    $useSlideTrack = in_array($animationStyle, ['slide', 'carousel', 'coverflow']);
    if ($useSlideTrack) {
        $html .= '<div class="cms-builder-slide-track" style="display:flex;transition:' . $trackTransition . ';height:' . $height . '">';
    }

    foreach ($slides as $idx => $slide) {
        if (!is_array($slide)) continue;
        $img       = cmsBuilderEsc((string)($slide['image'] ?? ''));
        $sBgColor  = cmsBuilderEsc((string)($slide['bgColor'] ?? '#1e293b'));
        $sTitle    = cmsBuilderEsc((string)($slide['title'] ?? ''));
        $sDesc     = cmsBuilderEsc((string)($slide['description'] ?? ''));
        $sLink     = cmsBuilderEsc((string)($slide['link'] ?? ''));
        $sCtaText  = cmsBuilderEsc((string)($slide['ctaText'] ?? ''));
        $sCta2Text = cmsBuilderEsc((string)($slide['cta2Text'] ?? ''));
        $sCta2Link = cmsBuilderEsc((string)($slide['cta2Link'] ?? ''));

        if ($useSlideTrack) {
            $html .= '<div class="cms-builder-slide" style="min-width:100%;height:100%;position:relative;flex-shrink:0;background-color:' . $sBgColor . '">';
        } else {
            $stackStyle = $idx === 0
                ? 'position:relative;width:100%;height:' . $height . ';overflow:hidden;background-color:' . $sBgColor
                : 'position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;overflow:hidden;transition:' . $stackTransition . ';background-color:' . $sBgColor;
            $html .= '<div class="cms-builder-slide" style="' . $stackStyle . '">';
        }

        $imgClass = $animationStyle === 'kenburns' ? ' class="cms-kb-img"' : '';
        if ($img !== '') {
            $html .= '<img' . $imgClass . ' src="' . $img . '" alt="' . $sTitle . '" style="width:100%;height:100%;object-fit:' . $imageObjectFit . '" loading="lazy">';
        }

        // Caption overlay
        if ($showCaption) {
            $hasCaption = $sTitle !== '' || $sDesc !== '' || $sCtaText !== '' || $sCta2Text !== '';
            if ($hasCaption) {
                $html .= '<div style="' . $posStyle . 'background:' . $bgGrad . ';color:' . $captionColor . ';text-align:' . $captionAlign . ';text-shadow:0 2px 4px rgba(0,0,0,0.5)">';
                // Optional inner max-width container
                if ($captionInnerMaxWidth !== '') {
                    $html .= '<div style="max-width:' . $captionInnerMaxWidth . ';margin:0 auto">';
                }
                $html .= ($sTitle !== '' ? '<h3 style="margin:0 0 8px 0;font-size:' . $captionTitleSize . ';font-weight:700;line-height:1.2">' . $sTitle . '</h3>' : '');
                $html .= ($sDesc !== '' ? '<p style="margin:0;font-size:' . $captionDescSize . ';opacity:0.9;line-height:1.5">' . $sDesc . '</p>' : '');

                // CTA button row
                if ($sCtaText !== '' || $sCta2Text !== '') {
                    $html .= '<div style="margin-top:16px;display:flex;gap:8px;justify-content:' . $btnJustify . ';flex-wrap:wrap">';

                    // Primary button
                    if ($sCtaText !== '' && $sLink !== '') {
                        $html .= '<a href="' . $sLink . '" style="display:inline-block;padding:' . $btnPad . ';background:' . $btnColor . ';color:' . $btnTextColor . ';border-radius:' . $btnBorderRadius . ';text-decoration:none;font-size:' . $btnFont . ';font-weight:500;text-shadow:none">' . $sCtaText . '</a>';
                    }

                    // Secondary button
                    if ($sCta2Text !== '' && $sCta2Link !== '') {
                        if ($btn2Style === 'ghost') {
                            $btn2Css = 'background:transparent;color:' . $btn2Color . ';border:none';
                        } elseif ($btn2Style === 'solid') {
                            $btn2Css = 'background:' . $btnColor . ';color:' . $btnTextColor . ';border:none';
                        } elseif ($btn2Style === 'inverted') {
                            $btn2Css = 'background:#ffffff;color:#1e293b;border:none';
                        } else {
                            // outline (default)
                            $btn2Css = 'background:transparent;color:' . $btn2Color . ';border:2px solid ' . $btn2Color;
                        }
                        $html .= '<a href="' . $sCta2Link . '" style="display:inline-block;padding:' . $btnPad . ';border-radius:' . $btnBorderRadius . ';text-decoration:none;font-size:' . $btnFont . ';font-weight:500;text-shadow:none;' . $btn2Css . '">' . $sCta2Text . '</a>';
                    }
                    $html .= '</div>';
                }

                if ($captionInnerMaxWidth !== '') {
                    $html .= '</div>';
                }
                $html .= '</div>';
            }
        }

        $html .= '</div>';
    }

    if ($useSlideTrack) {
        $html .= '</div>';
    }

    // Arrow navigation
    if ($showArrows && count($slides) > 1) {
        if ($arrowStyleProp === 'dark') {
            $arrowBase = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:rgba(0,0,0,0.65);color:#fff;border:none;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.4);font-size:22px;line-height:1';
            $arrowInset = '12px';
        } elseif ($arrowStyleProp === 'square') {
            $arrowBase = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:rgba(255,255,255,0.92);color:#1e293b;border:none;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:4px;box-shadow:0 2px 8px rgba(0,0,0,0.2);font-size:22px;line-height:1';
            $arrowInset = '12px';
        } elseif ($arrowStyleProp === 'minimal') {
            $arrowBase = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:transparent;color:#fff;border:none;cursor:pointer;width:32px;height:32px;display:flex;align-items:center;justify-content:center;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.6));font-size:28px;line-height:1';
            $arrowInset = '12px';
        } elseif ($arrowStyleProp === 'overlap') {
            $arrowBase = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:rgba(255,255,255,0.92);color:#1e293b;border:none;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:50%;box-shadow:0 4px 12px rgba(0,0,0,0.15);font-size:22px;line-height:1';
            $arrowInset = '-24px';
        } else {
            // rounded (default)
            $arrowBase = 'position:absolute;top:50%;transform:translateY(-50%);z-index:10;background:rgba(255,255,255,0.92);color:#1e293b;border:none;cursor:pointer;width:44px;height:44px;display:flex;align-items:center;justify-content:center;border-radius:50%;box-shadow:0 2px 8px rgba(0,0,0,0.2);font-size:22px;line-height:1';
            $arrowInset = '12px';
        }
        $html .= '<button type="button" class="cms-builder-slide-prev" style="' . $arrowBase . ';left:' . $arrowInset . '" aria-label="Previous slide">&#8249;</button>';
        $html .= '<button type="button" class="cms-builder-slide-next" style="' . $arrowBase . ';right:' . $arrowInset . '" aria-label="Next slide">&#8250;</button>';
    }

    // Dot/indicator navigation
    if ($showDots && count($slides) > 1) {
        $dotGap = ($dotStyleProp === 'bars' || $dotStyleProp === 'lines') ? '4px' : '8px';
        $html .= '<div style="position:absolute;bottom:16px;left:50%;transform:translateX(-50%);display:flex;gap:' . $dotGap . ';z-index:10;align-items:center">';
        foreach ($slides as $di => $ds) {
            $active = $di === 0;
            if ($dotStyleProp === 'bars') {
                $w   = $active ? '28px' : '10px';
                $css = 'width:' . $w . ';height:6px;border-radius:3px;background:#fff;border:none;cursor:pointer;padding:0;opacity:' . ($active ? '1' : '0.5') . ';transition:all 0.3s';
            } elseif ($dotStyleProp === 'lines') {
                $css = 'width:24px;height:3px;border-radius:2px;background:#fff;border:none;cursor:pointer;padding:0;opacity:' . ($active ? '1' : '0.4') . ';transition:all 0.3s';
            } elseif ($dotStyleProp === 'numbers') {
                $bg  = $active ? '#fff' : 'transparent';
                $col = $active ? '#0f172a' : '#fff';
                $bdr = $active ? '2px solid #fff' : '2px solid rgba(255,255,255,0.4)';
                $css = 'min-width:28px;height:28px;border-radius:50%;background:' . $bg . ';color:' . $col . ';border:' . $bdr . ';cursor:pointer;padding:0;font-size:11px;font-weight:600;display:flex;align-items:center;justify-content:center';
                $html .= '<button type="button" class="cms-builder-slide-dot" style="' . $css . '" aria-label="Go to slide ' . ($di + 1) . '">' . ($di + 1) . '</button>';
                continue;
            } else {
                // dots (default)
                $scale = $active ? 'scale(1.3)' : 'scale(1)';
                $css   = 'width:10px;height:10px;border-radius:50%;background:#fff;border:none;cursor:pointer;padding:0;opacity:' . ($active ? '1' : '0.5') . ';transform:' . $scale . ';transition:all 0.3s';
            }
            $html .= '<button type="button" class="cms-builder-slide-dot" style="' . $css . '" aria-label="Go to slide ' . ($di + 1) . '"></button>';
        }
        $html .= '</div>';
    }

    return $html . '</div>';
}

function cmsRenderWidget_form(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $fields = isset($props['fields']) && is_array($props['fields']) ? $props['fields'] : [];
    $submitText = cmsBuilderEsc((string)($props['submitText'] ?? 'Submit'));
    $html = '<form' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['width' => '100%', 'maxWidth' => '500px'], $style)) . ' method="post" onsubmit="return false">';
    foreach ($fields as $field) {
        if (!is_array($field)) continue;
        $fLabel = cmsBuilderEsc((string)($field['label'] ?? ''));
        $fType = (string)($field['type'] ?? 'text');
        $fPlaceholder = cmsBuilderEsc((string)($field['placeholder'] ?? ''));
        $fRequired = !empty($field['required']);
        $fId = cmsBuilderEsc((string)($field['id'] ?? ''));
        $html .= '<div style="margin-bottom:16px">';
        if ($fLabel !== '') {
            $html .= '<label style="display:block;margin-bottom:4px;font-size:14px;font-weight:500;color:#374151">' . $fLabel . ($fRequired ? ' <span style="color:#EF4444">*</span>' : '') . '</label>';
        }
        if ($fType === 'textarea') {
            $html .= '<textarea name="' . $fId . '" placeholder="' . $fPlaceholder . '" rows="4" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;resize:vertical"' . ($fRequired ? ' required' : '') . '></textarea>';
        } else {
            $html .= '<input type="' . cmsBuilderEsc($fType) . '" name="' . $fId . '" placeholder="' . $fPlaceholder . '" style="width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px"' . ($fRequired ? ' required' : '') . '>';
        }
        $html .= '</div>';
    }
    $successMessage = cmsBuilderEsc((string)($props['successMessage'] ?? 'Thank you! Your submission has been received.'));
    $html .= '<button type="submit" class="cms-builder-button" style="padding:12px 24px;background-color:#2563EB;color:#fff;border:none;border-radius:8px;font-weight:500;font-size:14px;cursor:pointer">' . $submitText . '</button>';
    $html .= '<div class="cms-form-success" style="display:none;padding:16px;background:#f0fdf4;border:1px solid #22c55e;border-radius:8px;color:#166534;margin-top:16px;font-size:14px" data-success-message="' . $successMessage . '">' . $successMessage . '</div>';
    return $html . '</form>';
}

function cmsRenderWidget_gallery(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $images = cmsBuilderNormalizeItems($props['images'] ?? [], 'images');
    $cols = max(1, (int)($props['columns'] ?? 3));
    $gapPx = (int)($props['gap'] ?? 16);
    $lightbox = ($props['lightbox'] ?? false) !== false;
    $aspectRatio = (string)($props['aspectRatio'] ?? '');
    $layout = (string)($props['layout'] ?? 'grid');
    $imageSize = (string)($props['imageSize'] ?? 'cover');
    if (!empty($props['fullWidth'])) {
        cmsBuilderApplyFullWidth($style);
    }
    // Masonry layout uses CSS columns instead of grid
    $isMasonry = $layout === 'masonry';
    if ($isMasonry) {
        $wrapStyle = array_merge(['columnCount' => (string)$cols, 'columnGap' => $gapPx . 'px', 'width' => '100%'], $style);
    } else {
        $wrapStyle = array_merge(['display' => 'grid', 'gridTemplateColumns' => 'repeat(' . $cols . ', 1fr)', 'gap' => $gapPx . 'px', 'width' => '100%'], $style);
    }
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapStyle) . '>';
    // Aspect ratio → height styling for images
    $arStyle = '';
    if ($aspectRatio !== '' && $aspectRatio !== 'auto') {
        $arMap = ['1:1' => '100%', '4:3' => '75%', '3:2' => '66.67%', '16:9' => '56.25%', '21:9' => '42.86%'];
        $pbVal = $arMap[$aspectRatio] ?? '';
        if ($pbVal !== '') {
            $arStyle = 'padding-bottom:' . $pbVal . ';height:0;';
        }
    }
    foreach ($images as $img) {
        if (!is_array($img)) continue;
        $gSrc = cmsBuilderEsc((string)($img['src'] ?? ''));
        $gAlt = cmsBuilderEsc((string)($img['alt'] ?? ''));
        $gCaption = cmsBuilderEsc((string)($img['caption'] ?? ''));
        if ($gSrc === '') continue;
        $figStyle = 'margin:0;overflow:hidden;border-radius:8px;' . ($isMasonry ? 'break-inside:avoid;margin-bottom:' . $gapPx . 'px;' : '');
        $imgCss = 'width:100%;display:block;object-fit:' . cmsBuilderEsc($imageSize) . ';';
        if ($arStyle !== '') {
            $figStyle .= 'position:relative;' . $arStyle;
            $imgCss .= 'position:absolute;top:0;left:0;width:100%;height:100%;';
        } else {
            $imgCss .= 'height:auto;';
        }
        $imgTag = '<img src="' . $gSrc . '" alt="' . $gAlt . '" style="' . $imgCss . '" loading="lazy">';
        if ($lightbox) {
            $imgTag = '<a href="' . $gSrc . '" class="cms-gallery-lightbox" data-lightbox="gallery">' . $imgTag . '</a>';
        }
        $html .= '<figure style="' . $figStyle . '">' . $imgTag;
        if ($gCaption !== '') {
            $html .= '<figcaption style="padding:8px 0;font-size:13px;color:#6b7280;text-align:center">' . $gCaption . '</figcaption>';
        }
        $html .= '</figure>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_map(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $embedUrl = (string)($props['embedUrl'] ?? '');
    $address = (string)($props['address'] ?? '');
    $mapHeight = cmsBuilderEsc((string)($style['height'] ?? $props['height'] ?? '400px'));
    if (!empty($props['fullWidth'])) {
        cmsBuilderApplyFullWidth($style);
    }
    if ($embedUrl !== '') {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><iframe src="' . cmsBuilderEsc($embedUrl) . '" width="100%" height="' . $mapHeight . '" style="border:0;border-radius:8px" loading="lazy" allowfullscreen></iframe></div>';
    }
    // If address is provided and no embedUrl or lat/lng, generate OSM search URL
    if ($address !== '' && empty($props['latitude'])) {
        $osmUrl = 'https://www.openstreetmap.org/export/embed.html?query=' . urlencode($address);
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><iframe src="' . cmsBuilderEsc($osmUrl) . '" width="100%" height="' . $mapHeight . '" style="border:0;border-radius:8px" loading="lazy"></iframe></div>';
    }
    $lat = cmsBuilderEsc((string)($props['latitude'] ?? '14.5995'));
    $lng = cmsBuilderEsc((string)($props['longitude'] ?? '120.9842'));
    $zoom = (int)($props['zoom'] ?? 14);
    $osmUrl = 'https://www.openstreetmap.org/export/embed.html?bbox=' . ($lng - 0.01) . ',' . ($lat - 0.01) . ',' . ($lng + 0.01) . ',' . ($lat + 0.01) . '&layer=mapnik&marker=' . $lat . ',' . $lng;
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><iframe src="' . cmsBuilderEsc($osmUrl) . '" width="100%" height="' . $mapHeight . '" style="border:0;border-radius:8px" loading="lazy"></iframe></div>';
}

function cmsRenderWidget_table(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $headers = isset($props['headers']) && is_array($props['headers']) ? $props['headers'] : [];
    $rows = isset($props['rows']) && is_array($props['rows']) ? $props['rows'] : [];
    $striped = ($props['striped'] ?? false) !== false;
    $bordered = ($props['bordered'] ?? false) !== false;
    $borderCss = $bordered ? 'border:1px solid #e5e7eb;' : '';
    $cellCss = 'padding:12px 16px;text-align:left;' . ($bordered ? 'border:1px solid #e5e7eb;' : 'border-bottom:1px solid #e5e7eb;');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['width' => '100%', 'overflowX' => 'auto'], $style)) . '>';
    $html .= '<table style="width:100%;border-collapse:collapse;font-size:14px;color:#374151;' . $borderCss . '">';
    if (!empty($headers)) {
        $html .= '<thead><tr>';
        foreach ($headers as $h) {
            $html .= '<th style="' . $cellCss . 'font-weight:600;background-color:#f9fafb">' . cmsBuilderEsc((string)$h) . '</th>';
        }
        $html .= '</tr></thead>';
    }
    $html .= '<tbody>';
    foreach ($rows as $ri => $row) {
        if (!is_array($row)) continue;
        $bg = $striped && $ri % 2 === 1 ? 'background-color:#f9fafb;' : '';
        $html .= '<tr style="' . $bg . '">';
        foreach ($row as $cell) {
            $html .= '<td style="' . $cellCss . '">' . cmsBuilderEsc((string)$cell) . '</td>';
        }
        $html .= '</tr>';
    }
    $html .= '</tbody></table></div>';
    return $html;
}

function cmsRenderWidget_alert(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $alertTitle = trim((string)($props['title'] ?? ''));
    $alertContent = cmsBuilderEsc((string)($props['content'] ?? 'This is an alert message.'));
    $alertType = (string)($props['alertType'] ?? 'info');
    $alertColors = [
        'info'    => ['bg' => '#EFF6FF', 'border' => '#3B82F6', 'text' => '#1E40AF'],
        'success' => ['bg' => '#F0FDF4', 'border' => '#22C55E', 'text' => '#166534'],
        'warning' => ['bg' => '#FFFBEB', 'border' => '#F59E0B', 'text' => '#92400E'],
        'error'   => ['bg' => '#FEF2F2', 'border' => '#EF4444', 'text' => '#991B1B'],
    ];
    $ac = $alertColors[$alertType] ?? $alertColors['info'];
    $dismissible = !empty($props['dismissible']);
    $dismissBtn = $dismissible ? '<button type="button" class="cms-builder-alert-dismiss" style="position:absolute;top:12px;right:12px;background:none;border:none;cursor:pointer;font-size:18px;line-height:1;color:' . $ac['text'] . ';opacity:0.6" aria-label="Dismiss">&times;</button>' : '';
    $baseStyle = [
        'width' => '100%', 'padding' => '16px 20px', 'backgroundColor' => $ac['bg'],
        'borderLeft' => '4px solid ' . $ac['border'], 'borderRadius' => '6px', 'color' => $ac['text'],
        'fontSize' => '14px', 'lineHeight' => '1.5',
    ];
    if ($dismissible) {
        $baseStyle['position'] = 'relative';
        $baseStyle['paddingRight'] = '40px';
    }
    $body = '';
    if ($alertTitle !== '') {
        $body .= '<strong style="display:block;font-size:15px;margin-bottom:4px">' . cmsBuilderEsc($alertTitle) . '</strong>';
    }
    $body .= $alertContent;
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge($baseStyle, $style)) . '>' . $dismissBtn . $body . '</div>';
}

function cmsRenderWidget_anchor(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $anchorId = cmsBuilderEsc((string)($props['anchorId'] ?? 'anchor'));
    return '<div id="' . $anchorId . '"' . cmsBuilderAttrString($attrs) . ' style="display:block;height:0;visibility:hidden"></div>';
}

function cmsBuilderGridStyle(array $style, string $templateColumns, string $gap = '24px'): array
{
    unset($style['display'], $style['gridTemplateColumns'], $style['gridTemplateRows'], $style['gridAutoFlow'], $style['gap'], $style['rowGap'], $style['columnGap']);

    return array_merge([
        'display' => 'grid',
        'gridTemplateColumns' => $templateColumns,
        'gap' => $gap,
        'width' => '100%',
    ], $style);
}

function cmsBuilderNormalizeIntList(mixed $value): array
{
    $items = [];
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value) && trim($value) !== '') {
        $items = preg_split('/\s*,\s*/', trim($value)) ?: [];
    }

    return array_values(array_unique(array_filter(array_map('intval', $items), static fn (int $id): bool => $id > 0)));
}

function cmsBuilderFetchPosts(array $options = []): array
{
    $postType = trim((string)($options['type'] ?? 'post')) ?: 'post';
    $limit = max(1, min(100, (int)($options['limit'] ?? 5)));
    $categoryIds = cmsBuilderNormalizeIntList($options['category_ids'] ?? []);
    $postIds = cmsBuilderNormalizeIntList($options['post_ids'] ?? []);
    $sourceMode = trim((string)($options['source_mode'] ?? ''));
    $includeAuthor = !empty($options['include_author']);
    $includeFeaturedImage = !empty($options['include_featured_image']);
    $orderByInput = (string)($options['order_by'] ?? 'date');
    $order = strtolower((string)($options['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $logKey = trim((string)($options['log_key'] ?? ''));

    if (!in_array($sourceMode, ['latest', 'posts', 'categories'], true)) {
        $sourceMode = $postIds !== [] ? 'posts' : ($categoryIds !== [] ? 'categories' : 'latest');
    }
    if ($sourceMode === 'posts' && $postIds === []) {
        return [];
    }
    if ($sourceMode === 'categories' && $categoryIds === []) {
        return [];
    }

    try {
        $db = cmsDb();
        $params = [':type' => $postType];
        $select = [
            'c.id',
            'c.title',
            'c.slug',
            'c.excerpt',
            'c.published_at',
            'c.created_at',
            'COALESCE(c.published_at, c.created_at) AS sort_date',
        ];
        $joins = '';

        if ($includeAuthor) {
            $joins .= ' LEFT JOIN cms_users u ON u.id = c.author_id';
            $select[] = 'u.display_name AS author_name';
        }
        if ($includeFeaturedImage) {
            $joins .= ' LEFT JOIN cms_media m ON m.id = c.featured_image_id';
            $select[] = 'm.file_path AS featured_image';
        }

        $sql = 'SELECT ' . implode(', ', $select) . ' FROM cms_content c' . $joins . ' ';
        $sql .= "WHERE c.deleted_at IS NULL AND c.type = :type AND " . cmsPublicVisibilitySql('c') . ' ';

        if ($sourceMode === 'posts') {
            $placeholders = [];
            foreach ($postIds as $index => $postId) {
                $placeholder = ':post_' . $index;
                $placeholders[] = $placeholder;
                $params[$placeholder] = $postId;
            }
            $sql .= 'AND c.id IN (' . implode(', ', $placeholders) . ') ';
            $orderSql = 'FIELD(c.id, ' . implode(', ', $postIds) . ')';
        } else {
            if ($sourceMode === 'categories' && $categoryIds !== []) {
                $placeholders = [];
                foreach ($categoryIds as $index => $categoryId) {
                    $placeholder = ':category_' . $index;
                    $placeholders[] = $placeholder;
                    $params[$placeholder] = $categoryId;
                }
                $sql .= 'AND EXISTS (SELECT 1 FROM cms_content_categories cc WHERE cc.content_id = c.id AND cc.category_id IN (' . implode(', ', $placeholders) . ')) ';
            }

            $orderSql = match ($orderByInput) {
                'title' => 'c.title ' . $order . ', c.id DESC',
                'random' => 'RAND()',
                default => 'sort_date ' . $order . ', c.id DESC',
            };
        }

        $sql .= 'ORDER BY ' . $orderSql;
        $sql .= ' LIMIT ' . $limit;

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        if ($logKey !== '') {
            write_log($logKey, 'error', [
                'message' => $e->getMessage(),
                'type' => $postType,
                'post_ids' => $postIds,
                'category_ids' => $categoryIds,
            ]);
        }
        return [];
    }
}

function cmsBuilderFetchCategorySummary(array $options = []): array
{
    $module = (string)($options['module'] ?? 'post');
    $count = max(1, min(50, (int)($options['count'] ?? 8)));
    $orderBy = (string)($options['order_by'] ?? 'name');
    $showEmpty = !empty($options['show_empty']);
    $taxonomy = $module === 'product' ? 'product' : 'default';
    $contentType = $module === 'product' ? 'product' : 'post';
    $orderSql = $orderBy === 'count' ? 'post_count DESC, c.name ASC' : 'c.name ASC';
    $havingSql = $showEmpty ? '' : 'HAVING post_count > 0';

    try {
        $db = cmsDb();
        $stmt = $db->prepare(
            "SELECT c.name, c.slug, COUNT(p.id) AS post_count
             FROM cms_categories c
             LEFT JOIN cms_content_categories cc ON cc.category_id = c.id
             LEFT JOIN cms_content p ON p.id = cc.content_id
               AND p.type = :content_type AND p.deleted_at IS NULL AND " . cmsPublicVisibilitySql('p') . "
             WHERE c.taxonomy = :taxonomy
             GROUP BY c.id, c.name, c.slug
             {$havingSql}
             ORDER BY {$orderSql}
             LIMIT :n"
        );
        $stmt->bindValue(':taxonomy', $taxonomy, \PDO::PARAM_STR);
        $stmt->bindValue(':content_type', $contentType, \PDO::PARAM_STR);
        $stmt->bindValue(':n', $count, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function cmsBuilderFetchTagSummary(array $options = []): array
{
    $count = max(1, min(60, (int)($options['count'] ?? 16)));
    $orderBy = (string)($options['order_by'] ?? 'count');
    $orderSql = $orderBy === 'name' ? 't.name ASC, post_count DESC' : 'post_count DESC, t.name ASC';

    try {
        $stmt = cmsDb()->prepare(
            "SELECT t.id, t.name, t.slug, COUNT(p.id) AS post_count
             FROM cms_tags t
             LEFT JOIN cms_content_tags ct ON ct.tag_id = t.id
             LEFT JOIN cms_content p ON p.id = ct.content_id
               AND p.type = 'post' AND p.deleted_at IS NULL AND " . cmsPublicVisibilitySql('p') . "
             GROUP BY t.id, t.name, t.slug
             ORDER BY {$orderSql}
             LIMIT :n"
        );
        $stmt->bindValue(':n', $count, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function cmsBuilderFetchArchiveSummary(array $options = []): array
{
    $count = max(1, min(36, (int)($options['count'] ?? 6)));
    $orderBy = (string)($options['order_by'] ?? 'date_desc');
    $orderSql = $orderBy === 'date_asc' ? 'ym ASC' : 'ym DESC';

    try {
        $stmt = cmsDb()->prepare(
            "SELECT DATE_FORMAT(c.published_at, '%Y-%m') AS ym,
                    DATE_FORMAT(c.published_at, '%M %Y') AS label,
                    COUNT(*) AS post_count
             FROM cms_content c
             WHERE c.type = 'post' AND c.deleted_at IS NULL AND " . cmsPublicVisibilitySql('c') . "
             GROUP BY DATE_FORMAT(c.published_at, '%Y-%m'), DATE_FORMAT(c.published_at, '%M %Y')
             ORDER BY {$orderSql}
             LIMIT :n"
        );
        $stmt->bindValue(':n', $count, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
}

function cmsBuilderSocialLinksData(): array
{
    $settings = function_exists('readCmsSettings') ? readCmsSettings() : [];
    return function_exists('cmsPublicSocialLinks') ? cmsPublicSocialLinks($settings) : [];
}

function cmsRenderWidget_posts_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $postCount = max(1, min(12, (int)($props['postCount'] ?? 3)));
    $gridCols = max(1, min(6, (int)($props['gridColumns'] ?? 3)));
    $showFeaturedImage = ($props['showFeaturedImage'] ?? true) !== false;
    $showDate = ($props['showDate'] ?? true) !== false;
    $showAuthor = ($props['showAuthor'] ?? false) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $showReadMore = ($props['showReadMore'] ?? true) !== false;
    $excerptLen = max(20, (int)($props['excerptLength'] ?? 120));
    $postType = (string)($props['postType'] ?? 'post');
    $orderBy = (string)($props['orderBy'] ?? 'date');
    $order = strtolower((string)($props['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $readMoreText = trim((string)($props['readMoreText'] ?? 'Read More')) ?: 'Read More';
    $posts = cmsBuilderFetchPosts([
        'type' => $postType,
        'limit' => $postCount,
        'source_mode' => (string)($props['sourceMode'] ?? ''),
        'category_ids' => $props['categoryIds'] ?? [],
        'post_ids' => $props['postIds'] ?? [],
        'order_by' => $orderBy,
        'order' => strtolower($order),
        'include_author' => $showAuthor,
        'include_featured_image' => $showFeaturedImage,
        'log_key' => 'cms.builder.posts_grid.query_error',
    ]);
    if (empty($posts)) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center">No posts found.</p></div>';
    }
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(cmsBuilderGridStyle($style, 'repeat(' . $gridCols . ', 1fr)')) . '>';
    foreach ($posts as $p) {
        $pTitle = cmsBuilderEsc((string)($p['title'] ?? 'Untitled'));
        $pExcerpt = cmsBuilderEsc(mb_strimwidth((string)($p['excerpt'] ?? ''), 0, $excerptLen, '...'));
        $pDate = !empty($p['published_at']) ? date('M j, Y', strtotime((string)$p['published_at'])) : '';
        $authorName = trim((string)($p['author_name'] ?? ''));
        $pUrl = cmsBuilderEntityPermalink($postType, (string)($p['slug'] ?? ''));
        $imageUrl = !empty($p['featured_image']) && function_exists('cmsResolveUploadUrl') ? cmsResolveUploadUrl((string)$p['featured_image']) : '';
        $html .= '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;flex-direction:column">';
        if ($showFeaturedImage && $imageUrl !== '') {
            $html .= '<a href="' . cmsBuilderEsc($pUrl) . '"><img src="' . cmsBuilderEsc($imageUrl) . '" alt="' . $pTitle . '" loading="lazy" style="display:block;width:100%;aspect-ratio:16/9;object-fit:cover"></a>';
        }
        $html .= '<div style="padding:20px;flex:1"><h3 style="margin:0 0 8px;font-size:18px;font-weight:600"><a href="' . cmsBuilderEsc($pUrl) . '" style="color:#1f2937;text-decoration:none">' . $pTitle . '</a></h3>';
        if ($showDate && $pDate !== '') {
            $html .= '<div style="font-size:12px;color:#9ca3af;margin-bottom:8px">' . cmsBuilderEsc($pDate) . '</div>';
        }
        if ($showAuthor && $authorName !== '') {
            $html .= '<div style="font-size:12px;color:#94a3b8;margin-bottom:8px">By ' . cmsBuilderEsc($authorName) . '</div>';
        }
        if ($showExcerpt && $pExcerpt !== '') {
            $html .= '<p style="font-size:14px;color:#6b7280;line-height:1.5;margin:0">' . $pExcerpt . '</p>';
        }
        $html .= '</div>';
        if ($showReadMore) {
            $html .= '<div style="padding:12px 20px;border-top:1px solid #f3f4f6"><a href="' . cmsBuilderEsc($pUrl) . '" style="font-size:13px;color:#3B82F6;text-decoration:none;font-weight:500">' . cmsBuilderEsc($readMoreText) . ' &rarr;</a></div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
}

function cmsBuilderEntityPermalink(string $type, string $slug): string
{
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $type = trim($type);
    $slug = trim($slug);

    if ($slug === '') {
        return $baseUrl !== '' ? $baseUrl : '#';
    }

    if ($type === 'product' && function_exists('ecProductGetBySlug')) {
        return $baseUrl . '/ecommerce/shop/' . rawurlencode($slug);
    }

    if (function_exists('cmsContentPermalink')) {
        return cmsContentPermalink(['type' => $type, 'slug' => $slug]);
    }

    if ($type === 'page') {
        return $baseUrl . '/cms/page/' . rawurlencode($slug);
    }

    if ($type === 'post') {
        return $baseUrl . '/cms/blog/' . rawurlencode($slug);
    }

    return $baseUrl . '/cms/' . rawurlencode($type) . '/' . rawurlencode($slug);
}

function cmsRenderWidget_products_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    if (!function_exists('ecProductList')) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">Ecommerce module not active.</p></div>';
    }

    $itemCount   = max(1, min(50, (int)($props['itemCount']   ?? 6)));
    $gridCols    = max(1, min(6,  (int)($props['gridColumns'] ?? 3)));
    $showImage   = ($props['showImage']   ?? true) !== false;
    $showTitle   = ($props['showTitle']   ?? true) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $showMeta    = ($props['showMeta']    ?? true) !== false;
    $showAction  = ($props['showAction']  ?? true) !== false;
    $excerptLen  = max(20, (int)($props['excerptLength'] ?? 120));
    $orderBy     = match ((string)($props['orderBy'] ?? 'date')) {
        'title' => 'title',
        'price' => 'price',
        'random' => 'random',
        'updated_at' => 'updated_at',
        default => 'created_at',
    };
    $order       = strtolower((string)($props['order'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $actionText  = trim((string)($props['actionText'] ?? 'View Product'));
    $emptyMessage = cmsBuilderEsc((string)($props['emptyMessage'] ?? 'No products found.'));

    // Resolve category filter — supports array or empty (= all products).
    $categoryIds = [];
    if (!empty($props['categoryIds']) && is_array($props['categoryIds'])) {
        $categoryIds = array_values(array_unique(array_map('intval', $props['categoryIds'])));
    }

    $result = ecProductList([
        'category_ids' => $categoryIds,
        'limit'        => $itemCount,
        'offset'       => 0,
        'order_by'     => $orderBy,
        'order'        => $order,
        'status'       => 'published',
    ]);
    $products = $result['items'] ?? [];

    if (empty($products)) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMessage . '</p></div>';
    }

    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(cmsBuilderGridStyle($style, 'repeat(' . $gridCols . ', 1fr)')) . '>';

    foreach ($products as $p) {
        $pTitle    = cmsBuilderEsc((string)($p['title'] ?? 'Untitled'));
        $pSlug     = cmsBuilderEsc((string)($p['slug']  ?? ''));
        $pExcerpt  = cmsBuilderEsc(mb_strimwidth((string)($p['excerpt'] ?? ''), 0, $excerptLen, '...'));
        $pUrl      = cmsBuilderEntityPermalink('product', (string)($p['slug'] ?? ''));
        $imgUrl    = (string)($p['primary_image_url'] ?? $p['featured_image_url'] ?? '');
        $pricing   = is_array($p['pricing'] ?? null) ? $p['pricing'] : [];
        $priceText = trim((string)($pricing['formatted'] ?? ''));
        if ($priceText === '' && isset($pricing['active_price'])) {
            $priceText = number_format((float)$pricing['active_price'], 2);
        }

        $html .= '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;flex-direction:column">';

        if ($showImage) {
            if ($imgUrl !== '') {
                $html .= '<a href="' . cmsBuilderEsc($pUrl) . '"><img src="' . cmsBuilderEsc($imgUrl) . '" alt="' . $pTitle . '" loading="lazy" style="display:block;width:100%;aspect-ratio:4/3;object-fit:cover"></a>';
            } else {
                $html .= '<a href="' . cmsBuilderEsc($pUrl) . '"><div style="width:100%;aspect-ratio:4/3;background:#f3f4f6;display:flex;align-items:center;justify-content:center"><span style="font-size:48px">&#128247;</span></div></a>';
            }
        }

        $html .= '<div style="padding:16px;flex:1;display:flex;flex-direction:column;gap:8px">';
        if ($showTitle) {
            $html .= '<h3 style="margin:0;font-size:16px;font-weight:600"><a href="' . cmsBuilderEsc($pUrl) . '" style="color:#1f2937;text-decoration:none">' . $pTitle . '</a></h3>';
        }
        if ($showExcerpt && $pExcerpt !== '') {
            $html .= '<p style="font-size:13px;color:#6b7280;line-height:1.5;margin:0">' . $pExcerpt . '</p>';
        }
        if ($showMeta && $priceText !== '') {
            $html .= '<div style="font-size:18px;font-weight:700;color:#111827;margin-top:auto">' . cmsBuilderEsc($priceText) . '</div>';
        }
        $html .= '</div>';

        if ($showAction) {
            $html .= '<div style="padding:12px 16px;border-top:1px solid #f3f4f6"><a href="' . cmsBuilderEsc($pUrl) . '" style="display:block;text-align:center;background:#0f172a;color:#fff;font-size:13px;font-weight:500;padding:8px 16px;border-radius:6px;text-decoration:none">' . cmsBuilderEsc($actionText) . '</a></div>';
        }

        $html .= '</div>';
    }

    return $html . '</div>';
}

function cmsBuilderResolveTeamContentType(array $props = []): string
{
    $requestedType = trim((string)($props['teamType'] ?? ''));
    if ($requestedType !== '') {
        return $requestedType;
    }

    static $cachedType = null;
    if ($cachedType !== null) {
        return $cachedType;
    }

    try {
        $rows = cmsDb()->query(
            "SELECT slug, label FROM cms_content_types WHERE is_active = 1 ORDER BY sort_order ASC, slug ASC"
        )->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $rows = [];
    }

    if ($rows === []) {
        $cachedType = '';
        return $cachedType;
    }

    foreach (['team_member', 'team', 'staff_member', 'staff'] as $candidate) {
        foreach ($rows as $row) {
            if ((string)($row['slug'] ?? '') === $candidate) {
                $cachedType = $candidate;
                return $cachedType;
            }
        }
    }

    foreach ($rows as $row) {
        $slug = strtolower(trim((string)($row['slug'] ?? '')));
        $label = strtolower(trim((string)($row['label'] ?? '')));
        if ($slug !== '' && (str_contains($slug, 'team') || str_contains($slug, 'staff') || str_contains($label, 'team') || str_contains($label, 'staff'))) {
            $cachedType = (string)$row['slug'];
            return $cachedType;
        }
    }

    $cachedType = '';
    return $cachedType;
}

function cmsRenderWidget_team_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $teamType = cmsBuilderResolveTeamContentType($props);
    $itemCount = max(1, min(24, (int)($props['itemCount'] ?? 4)));
    $gridCols = max(1, min(6, (int)($props['gridColumns'] ?? 4)));
    $showImage = ($props['showImage'] ?? true) !== false;
    $showTitle = ($props['showTitle'] ?? true) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $showAction = ($props['showAction'] ?? true) !== false;
    $excerptLen = max(20, (int)($props['excerptLength'] ?? 100));
    $orderBy = match ((string)($props['orderBy'] ?? 'name')) {
        'role' => 'c.excerpt',
        'date' => 'COALESCE(c.published_at, c.created_at)',
        'random' => 'RAND()',
        default => 'c.title',
    };
    $order = strtolower((string)($props['order'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';
    $emptyMessage = cmsBuilderEsc((string)($props['emptyMessage'] ?? 'No team members found.'));
    $departmentIds = [];
    if (!empty($props['departmentIds']) && is_array($props['departmentIds'])) {
        $departmentIds = array_values(array_unique(array_filter(array_map('intval', $props['departmentIds']), static fn (int $id): bool => $id > 0)));
    }

    if ($teamType === '') {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMessage . '</p></div>';
    }

    $params = [':type' => $teamType];
    $sql = 'SELECT DISTINCT c.id, c.title, c.slug, c.excerpt, c.published_at, c.created_at, c.featured_image_id, m.file_path AS featured_image '
        . 'FROM cms_content c '
        . 'LEFT JOIN cms_media m ON m.id = c.featured_image_id ';

    if ($departmentIds !== []) {
        $sql .= 'INNER JOIN cms_content_categories cc ON cc.content_id = c.id ';
    }

    $sql .= 'WHERE c.deleted_at IS NULL AND c.type = :type AND ' . cmsPublicVisibilitySql('c') . ' ';

    if ($departmentIds !== []) {
        $placeholders = [];
        foreach ($departmentIds as $index => $departmentId) {
            $placeholder = ':department_' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $departmentId;
        }
        $sql .= 'AND cc.category_id IN (' . implode(', ', $placeholders) . ') ';
    }

    $sql .= 'ORDER BY ' . $orderBy;
    if ($orderBy !== 'RAND()') {
        $sql .= ' ' . $order;
    }
    $sql .= ' LIMIT ' . $itemCount;

    try {
        $teamMembers = cmsDb()->query($sql, $params)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $teamMembers = [];
    }

    if ($teamMembers === []) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMessage . '</p></div>';
    }

    $wrapperStyle = cmsBuilderGridStyle($style, 'repeat(' . $gridCols . ', 1fr)');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapperStyle) . '>';

    foreach ($teamMembers as $member) {
        $title = cmsBuilderEsc((string)($member['title'] ?? 'Untitled'));
        $role = cmsBuilderEsc(mb_strimwidth((string)($member['excerpt'] ?? ''), 0, $excerptLen, '...'));
        $memberUrl = cmsBuilderEntityPermalink($teamType, (string)($member['slug'] ?? ''));
        $imageUrl = '';
        if (!empty($member['featured_image'])) {
            $imageUrl = function_exists('cmsResolveUploadUrl') ? cmsResolveUploadUrl((string)$member['featured_image']) : (string)$member['featured_image'];
        }

        $html .= '<article style="background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;padding:20px;display:flex;flex-direction:column;align-items:center;gap:12px;text-align:center;box-shadow:0 10px 30px rgba(15,23,42,0.06)">';
        if ($showImage) {
            if ($imageUrl !== '') {
                $html .= '<a href="' . cmsBuilderEsc($memberUrl) . '" style="display:block;width:88px;height:88px;border-radius:999px;overflow:hidden;background:#e2e8f0"><img src="' . cmsBuilderEsc($imageUrl) . '" alt="' . $title . '" loading="lazy" style="display:block;width:100%;height:100%;object-fit:cover"></a>';
            } else {
                $html .= '<a href="' . cmsBuilderEsc($memberUrl) . '" style="display:flex;width:88px;height:88px;border-radius:999px;overflow:hidden;background:#e2e8f0;align-items:center;justify-content:center;color:#94a3b8;font-size:32px;text-decoration:none">&#128100;</a>';
            }
        }
        if ($showTitle) {
            $html .= '<h3 style="margin:0;font-size:18px;line-height:1.35;color:#0f172a"><a href="' . cmsBuilderEsc($memberUrl) . '" style="color:inherit;text-decoration:none">' . $title . '</a></h3>';
        }
        if ($showExcerpt && $role !== '') {
            $html .= '<p style="margin:0;font-size:14px;line-height:1.6;color:#64748b">' . $role . '</p>';
        }
        if ($showAction) {
            $html .= '<a href="' . cmsBuilderEsc($memberUrl) . '" style="margin-top:auto;display:inline-flex;align-items:center;justify-content:center;padding:10px 16px;border-radius:10px;background:#0f172a;color:#ffffff;text-decoration:none;font-size:13px;font-weight:600">View Profile</a>';
        }
        $html .= '</article>';
    }

    return $html . '</div>';
}

function cmsRenderWidget_placeholder_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $emptyMsg = cmsBuilderEsc((string)($props['emptyMessage'] ?? 'No items found.'));
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMsg . '</p></div>';
}

function cmsBuilderEntityFeaturedImageUrl(array $entity): string
{
    if (!empty($entity['featured_image_url'])) {
        return (string)$entity['featured_image_url'];
    }

    $featuredImageId = (int)($entity['featured_image_id'] ?? 0);
    if ($featuredImageId <= 0) {
        return '';
    }

    try {
        $path = cmsDb()->query('SELECT file_path FROM cms_media WHERE id = ? LIMIT 1', [$featuredImageId])->fetchColumn();
    } catch (\Throwable $e) {
        $path = false;
    }

    if (!is_string($path) || $path === '' || !function_exists('cmsResolveUploadUrl')) {
        return '';
    }

    return cmsResolveUploadUrl($path);
}

function cmsBuilderEntityViewContext(array $context): array
{
    $entity = isset($context['entity']) && is_array($context['entity']) ? $context['entity'] : $context;
    $entity = array_merge($entity, cmsEntityRenderProjection($entity, [
        'fallback_capability_data' => ['inquiry', 'lessons_index', 'media_gallery'],
    ]));

    $entity['featured_image_url'] = cmsBuilderEntityFeaturedImageUrl($entity);

    return $entity;
}

function cmsRenderWidget_entity_view(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $rootContext = $context;
    $entity = cmsBuilderEntityViewContext($context);
    $showFeaturedImage = ($props['showFeaturedImage'] ?? true) !== false;
    $showTitle = ($props['showTitle'] ?? true) !== false;
    $showMeta = ($props['showMeta'] ?? true) !== false;
    $showTypeLabel = ($props['showTypeLabel'] ?? true) !== false;
    $showAuthor = ($props['showAuthor'] ?? true) !== false;
    $showDate = ($props['showDate'] ?? true) !== false;
    $showPricing = ($props['showPricing'] ?? true) !== false;
    $showInventory = ($props['showInventory'] ?? true) !== false;
    $showSku = ($props['showSku'] ?? true) !== false;
    $showProgress = ($props['showProgress'] ?? true) !== false;
    $showLessons = ($props['showLessons'] ?? true) !== false;
    $showActions = ($props['showActions'] ?? true) !== false;
    $showBody = ($props['showBody'] ?? true) !== false;

    $title = cmsBuilderEsc((string)($entity['title'] ?? 'Current Entity'));
    $excerpt = trim((string)($entity['excerpt'] ?? ''));
    $body = trim((string)($entity['body'] ?? ''));
    $postHtml = trim((string)($rootContext['post_html'] ?? ''));
    $contentHtml = $postHtml !== '' ? $postHtml : ($body !== '' ? nl2br(cmsBuilderEsc($body)) : ($excerpt !== '' ? '<p>' . cmsBuilderEsc($excerpt) . '</p>' : ''));
    $publishedAt = !empty($entity['published_at']) ? date('M j, Y', strtotime((string)$entity['published_at'])) : '';
    $pricing = is_array($entity['capability_data']['pricing'] ?? null) ? $entity['capability_data']['pricing'] : [];
    $inventory = is_array($entity['capability_data']['inventory'] ?? null) ? $entity['capability_data']['inventory'] : [];
    $progress = is_array($entity['capability_data']['progress_tracking'] ?? null) ? $entity['capability_data']['progress_tracking'] : [];
    $lessons = is_array($entity['capability_data']['lessons_index'] ?? null) ? $entity['capability_data']['lessons_index'] : [];
    $gallery = is_array($entity['capability_data']['media_gallery'] ?? null) ? $entity['capability_data']['media_gallery'] : [];
    $inquiry = is_array($entity['capability_data']['inquiry'] ?? null) ? $entity['capability_data']['inquiry'] : [];
    $capabilities = is_array($entity['capabilities'] ?? null) ? $entity['capabilities'] : [];
    $typeLabel = trim((string)($entity['content_type_label'] ?? $entity['type'] ?? ''));
    $authorName = trim((string)($entity['author_name'] ?? ''));
    $entityType = trim((string)($entity['type'] ?? '')) ?: 'post';
    $entitySlug = trim((string)($entity['slug'] ?? ''));
    $entityUrl = cmsBuilderEntityPermalink($entityType, $entitySlug);
    $pricingText = trim((string)($pricing['formatted'] ?? ''));
    if ($pricingText === '' && isset($pricing['active_price'])) {
        $currency = trim((string)($pricing['currency'] ?? 'USD'));
        $pricingText = $currency . ' ' . number_format((float)$pricing['active_price'], 2);
    }

    $html = '<article' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'flexDirection' => 'column', 'gap' => '24px', 'width' => '100%'], $style)) . '>';
    if ($showFeaturedImage) {
        $galleryItems = is_array($gallery['items'] ?? null) ? array_slice($gallery['items'], 0, 3) : [];
        if ($galleryItems !== []) {
            $html .= '<div style="display:grid;grid-template-columns:2fr 1fr;gap:12px">';
            foreach ($galleryItems as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $candidate = trim((string)($item['url'] ?? $item['src'] ?? $item['thumb'] ?? ''));
                if ($candidate === '') {
                    continue;
                }
                $imageUrl = preg_match('#^(https?:)?//#i', $candidate) === 1 || str_starts_with($candidate, '/')
                    ? $candidate
                    : (function_exists('cmsResolveUploadUrl') ? cmsResolveUploadUrl($candidate) : $candidate);
                $cellStyle = $index === 0
                    ? 'overflow:hidden;border-radius:18px;background:#e2e8f0;grid-row:1 / span 2'
                    : 'overflow:hidden;border-radius:18px;background:#e2e8f0';
                $html .= '<div style="' . $cellStyle . '"><img src="' . cmsBuilderEsc($imageUrl) . '" alt="' . $title . '" loading="lazy" style="display:block;width:100%;height:100%;object-fit:cover"></div>';
            }
            $html .= '</div>';
        } elseif (!empty($entity['featured_image_url'])) {
            $html .= '<div style="overflow:hidden;border-radius:18px;background:#e2e8f0"><img src="' . cmsBuilderEsc((string)$entity['featured_image_url']) . '" alt="' . $title . '" loading="lazy" style="display:block;width:100%;height:auto"></div>';
        }
    }

    $html .= '<div style="display:flex;flex-direction:column;gap:12px">';
    if ($showTitle) {
        $html .= '<h2 style="margin:0;font-size:32px;line-height:1.2;color:#0f172a">' . $title . '</h2>';
    }
    if ($showMeta) {
        $metaParts = [];
        if ($showTypeLabel && $typeLabel !== '') {
            $metaParts[] = '<span style="padding:6px 10px;border-radius:999px;background:#e0f2fe;color:#0369a1;font-weight:600">' . cmsBuilderEsc($typeLabel) . '</span>';
        }
        if ($showDate && $publishedAt !== '') {
            $metaParts[] = '<span>' . cmsBuilderEsc($publishedAt) . '</span>';
        }
        if ($showAuthor && $authorName !== '') {
            $metaParts[] = '<span>By ' . cmsBuilderEsc($authorName) . '</span>';
        }
        if ($metaParts !== []) {
            $html .= '<div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:#64748b">' . implode('', $metaParts) . '</div>';
        }
    }
    if ($showPricing && $pricingText !== '') {
        $html .= '<div style="font-size:15px;font-weight:700;color:#0f766e">' . cmsBuilderEsc($pricingText) . '</div>';
    }
    if ($showInventory && !empty($inventory)) {
        $inventoryText = !empty($inventory['out_of_stock']) ? 'Out of stock' : (!empty($inventory['low_stock']) ? 'Low stock' : 'In stock');
        $inventoryColor = !empty($inventory['out_of_stock']) ? '#dc2626' : (!empty($inventory['low_stock']) ? '#d97706' : '#16a34a');
        $skuText = $showSku && !empty($inventory['sku']) ? ' <span style="color:#64748b;font-weight:500">SKU ' . cmsBuilderEsc((string)$inventory['sku']) . '</span>' : '';
        $html .= '<div style="font-size:13px;font-weight:600;color:' . $inventoryColor . '">' . $inventoryText . $skuText . '</div>';
    }
    $html .= '</div>';

    if ($showProgress && !empty($capabilities['progress_tracking'])) {
        $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
        if (($progress['authenticated'] ?? true) === false) {
            $html .= '<div style="padding:16px 18px;border:1px solid #dbeafe;border-radius:16px;background:#f8fbff;color:#0369a1;font-size:14px;font-weight:600">Sign in to track progress</div>';
        } else {
            $html .= '<div style="padding:16px 18px;border:1px solid #dbeafe;border-radius:16px;background:#f8fbff;display:flex;flex-direction:column;gap:10px">';
            $html .= '<div style="display:flex;justify-content:space-between;font-size:13px;color:#0369a1;font-weight:600"><span>Progress</span><span>' . $percent . '%</span></div>';
            $html .= '<div style="height:10px;border-radius:999px;background:#dbeafe;overflow:hidden"><div style="width:' . $percent . '%;height:100%;background:#0ea5e9"></div></div>';
            $html .= '</div>';
        }
    }

    if ($showLessons && !empty($capabilities['lessons_index']) && !empty($lessons['items']) && is_array($lessons['items'])) {
        $childType = trim((string)($lessons['child_type'] ?? 'lesson')) ?: 'lesson';
        $html .= '<div style="padding:18px;border:1px solid #e2e8f0;border-radius:18px;display:flex;flex-direction:column;gap:12px">';
        $html .= '<h3 style="margin:0;font-size:18px;color:#0f172a">Contents</h3>';
        $html .= '<ol style="margin:0;padding-left:20px;display:flex;flex-direction:column;gap:8px;color:#475569">';
        foreach ($lessons['items'] as $lesson) {
            if (!is_array($lesson)) {
                continue;
            }
            $lessonTitle = cmsBuilderEsc((string)($lesson['title'] ?? 'Lesson'));
            $lessonUrl = cmsBuilderEntityPermalink($childType, (string)($lesson['slug'] ?? ''));
            $html .= '<li><a href="' . cmsBuilderEsc($lessonUrl) . '" style="color:#0f172a;text-decoration:none">' . $lessonTitle . '</a></li>';
        }
        $html .= '</ol></div>';
    }

    if ($showActions) {
        $actionHtml = trim((string)($rootContext['action_sections'] ?? ''));
        if ($actionHtml !== '') {
            $html .= '<div style="display:flex;flex-wrap:wrap;gap:12px">' . $actionHtml . '</div>';
        } else {
            $buttons = [];
            if (!empty($capabilities['pricing'])) {
                if (!empty($inventory['out_of_stock'])) {
                    $buttons[] = '<span style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:#e2e8f0;color:#64748b;font-size:14px;font-weight:600">Out of Stock</span>';
                } else {
                    $buttons[] = '<a href="' . cmsBuilderEsc($entityUrl) . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:#0f172a;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600">Buy Now</a>';
                }
            }
            if (!empty($capabilities['booking']) && $entitySlug !== '') {
                $buttons[] = '<a href="' . cmsBuilderEsc(rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/cms/' . rawurlencode($entityType) . '/' . rawurlencode($entitySlug) . '/book') . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:#ffffff;border:1px solid #cbd5e1;color:#0f172a;text-decoration:none;font-size:14px;font-weight:600">Book Now</a>';
            }
            if (!empty($capabilities['inquiry']) && $entitySlug !== '') {
                $label = trim((string)($inquiry['label'] ?? 'Inquire')) ?: 'Inquire';
                $buttons[] = '<a href="' . cmsBuilderEsc(rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/cms/' . rawurlencode($entityType) . '/' . rawurlencode($entitySlug) . '/inquire') . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 18px;border-radius:12px;background:#ffffff;border:1px solid #cbd5e1;color:#0f172a;text-decoration:none;font-size:14px;font-weight:600">' . cmsBuilderEsc($label) . '</a>';
            }

            if ($buttons !== []) {
                $html .= '<div style="display:flex;flex-wrap:wrap;gap:12px">' . implode('', $buttons) . '</div>';
            }
        }
    }

    if ($showBody && $contentHtml !== '') {
        $html .= '<div style="font-size:15px;line-height:1.7;color:#475569">' . $contentHtml . '</div>';
    }

    return $html . '</article>';
}

function cmsRenderWidget_entity_list(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $entityType = trim((string)($props['entityType'] ?? 'post')) ?: 'post';
    $itemCount = max(1, min(12, (int)($props['itemCount'] ?? 6)));
    $gridCols = max(1, min(6, (int)($props['gridColumns'] ?? 3)));
    $layout = (string)($props['layout'] ?? 'grid');
    $showFeaturedImage = ($props['showFeaturedImage'] ?? true) !== false;
    $showTitle = ($props['showTitle'] ?? true) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $excerptLen = max(20, (int)($props['excerptLength'] ?? 120));
    $showPricing = ($props['showPricing'] ?? true) !== false;
    $showInventory = ($props['showInventory'] ?? true) !== false;
    $showProgress = ($props['showProgress'] ?? false) === true;
    $showActions = ($props['showActions'] ?? false) === true;
    $emptyMessage = cmsBuilderEsc((string)($props['emptyMessage'] ?? 'No items found.'));
    $order = strtolower((string)($props['order'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';
    $orderByMap = [
        'title' => 'c.title',
        'name' => 'c.title',
        'date' => 'COALESCE(c.published_at, c.created_at)',
    ];
    $orderBy = $orderByMap[(string)($props['orderBy'] ?? 'date')] ?? 'COALESCE(c.published_at, c.created_at)';

    try {
        $stmt = cmsDb()->prepare(
            'SELECT c.id, c.title, c.slug, c.excerpt, c.type, c.published_at, c.featured_image_id, m.file_path AS featured_image '
            . 'FROM cms_content c '
            . 'LEFT JOIN cms_media m ON m.id = c.featured_image_id '
            . 'WHERE c.deleted_at IS NULL AND c.type = :type AND ' . cmsPublicVisibilitySql('c') . ' '
            . 'ORDER BY ' . $orderBy . ' ' . $order . ' LIMIT ' . $itemCount
        );
        $stmt->execute([':type' => $entityType]);
        $items = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        write_log('cms.builder.entity_list.query_error', 'error', ['message' => $e->getMessage(), 'type' => $entityType]);
        $items = [];
    }

    if ($items === []) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMessage . '</p></div>';
    }

    $wrapperStyle = cmsBuilderGridStyle($style, $layout === 'list' ? '1fr' : 'repeat(' . $gridCols . ', 1fr)');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapperStyle) . '>';

    foreach ($items as $item) {
        $projection = cmsEntityRenderProjection($item);
        $capabilities = $projection['capabilities'];
        $capabilityData = $projection['capability_data'];
        $pricing = is_array($capabilityData['pricing'] ?? null) ? $capabilityData['pricing'] : [];
        $inventory = is_array($capabilityData['inventory'] ?? null) ? $capabilityData['inventory'] : [];
        $progress = is_array($capabilityData['progress_tracking'] ?? null) ? $capabilityData['progress_tracking'] : [];
        $inquiry = is_array($capabilityData['inquiry'] ?? null) ? $capabilityData['inquiry'] : [];
        $imageUrl = !empty($item['featured_image']) && function_exists('cmsResolveUploadUrl') ? cmsResolveUploadUrl((string)$item['featured_image']) : '';
        $itemUrl = cmsBuilderEntityPermalink($entityType, (string)($item['slug'] ?? ''));
        $pricingText = trim((string)($pricing['formatted'] ?? ''));
        if ($pricingText === '' && isset($pricing['active_price'])) {
            $currency = trim((string)($pricing['currency'] ?? 'USD'));
            $pricingText = $currency . ' ' . number_format((float)$pricing['active_price'], 2);
        }

        $html .= '<a href="' . cmsBuilderEsc($itemUrl) . '" style="display:block;text-decoration:none;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.06)">';
        if ($showFeaturedImage && $imageUrl !== '') {
            $html .= '<div style="aspect-ratio:16 / 9;overflow:hidden;background:#e2e8f0"><img src="' . cmsBuilderEsc($imageUrl) . '" alt="' . cmsBuilderEsc((string)($item['title'] ?? '')) . '" loading="lazy" style="display:block;width:100%;height:100%;object-fit:cover"></div>';
        }
        $html .= '<div style="padding:16px;display:flex;flex-direction:column;gap:10px">';
        if ($showTitle) {
            $html .= '<h3 style="margin:0;font-size:18px;line-height:1.35;color:#0f172a">' . cmsBuilderEsc((string)($item['title'] ?? 'Untitled')) . '</h3>';
        }
        if ($showExcerpt && !empty($item['excerpt'])) {
            $html .= '<p style="margin:0;font-size:14px;line-height:1.6;color:#64748b">' . cmsBuilderEsc(mb_strimwidth((string)$item['excerpt'], 0, $excerptLen, '...')) . '</p>';
        }
        if ($showPricing && $pricingText !== '') {
            $html .= '<div style="font-size:13px;font-weight:700;color:#0f766e">' . cmsBuilderEsc($pricingText) . '</div>';
        }
        if ($showInventory && !empty($inventory)) {
            $inventoryText = !empty($inventory['out_of_stock']) ? 'Out of stock' : (!empty($inventory['low_stock']) ? 'Low stock' : 'In stock');
            $inventoryColor = !empty($inventory['out_of_stock']) ? '#dc2626' : (!empty($inventory['low_stock']) ? '#d97706' : '#16a34a');
            $html .= '<div style="font-size:12px;font-weight:600;color:' . $inventoryColor . '">' . $inventoryText . '</div>';
        }
        if ($showProgress && !empty($capabilities['progress_tracking'])) {
            $percent = max(0, min(100, (int)($progress['percent'] ?? 0)));
            if (($progress['authenticated'] ?? true) === false) {
                $html .= '<div style="padding:10px 12px;border-radius:12px;background:#f8fbff;border:1px solid #dbeafe;color:#0369a1;font-size:12px;font-weight:600">Sign in to track progress</div>';
            } else {
                $html .= '<div style="display:flex;flex-direction:column;gap:6px">';
                $html .= '<div style="display:flex;justify-content:space-between;font-size:12px;color:#0369a1;font-weight:600"><span>Progress</span><span>' . $percent . '%</span></div>';
                $html .= '<div style="height:8px;border-radius:999px;background:#dbeafe;overflow:hidden"><div style="width:' . $percent . '%;height:100%;background:#0ea5e9"></div></div>';
                $html .= '</div>';
            }
        }
        if ($showActions) {
            $slug = trim((string)($item['slug'] ?? ''));
            $primaryAction = '';
            if (!empty($capabilities['booking']) && $slug !== '') {
                $primaryAction = '<a href="' . cmsBuilderEsc(rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/cms/' . rawurlencode($entityType) . '/' . rawurlencode($slug) . '/book') . '" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;background:#0f172a;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700">Book Now</a>';
            } elseif (!empty($capabilities['inquiry']) && $slug !== '') {
                $label = trim((string)($inquiry['label'] ?? 'Inquire')) ?: 'Inquire';
                $primaryAction = '<a href="' . cmsBuilderEsc(rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/') . '/cms/' . rawurlencode($entityType) . '/' . rawurlencode($slug) . '/inquire') . '" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;background:#0f172a;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700">' . cmsBuilderEsc($label) . '</a>';
            } elseif (!empty($capabilities['pricing'])) {
                if (!empty($inventory['out_of_stock'])) {
                    $primaryAction = '<span style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;background:#f1f5f9;color:#94a3b8;font-size:12px;font-weight:700">Out of stock</span>';
                } else {
                    $label = match ($entityType) {
                        'course' => 'Enroll Now',
                        'product' => 'Buy Now',
                        default => 'View Details',
                    };
                    $primaryAction = '<a href="' . cmsBuilderEsc($itemUrl) . '" style="display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:10px;background:#0f172a;color:#ffffff;text-decoration:none;font-size:12px;font-weight:700">' . cmsBuilderEsc($label) . '</a>';
                }
            }

            if ($primaryAction !== '') {
                $html .= '<div style="display:flex;flex-wrap:wrap;gap:10px;margin-top:2px">' . $primaryAction . '</div>';
            }
        }
        $html .= '</div></a>';
    }

    return $html . '</div>';
}

function cmsRenderWidget_pricing_table(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $planName = cmsBuilderEsc((string)($props['planName'] ?? 'Plan'));
    $price = cmsBuilderEsc((string)($props['price'] ?? '0'));
    $currency = cmsBuilderEsc((string)($props['currency'] ?? '$'));
    $period = cmsBuilderEsc((string)($props['period'] ?? '/month'));
    $features = cmsBuilderNormalizeItems($props['features'] ?? [], 'features');
    $btnText = cmsBuilderEsc((string)($props['buttonText'] ?? 'Get Started'));
    $btnUrl = cmsBuilderEsc((string)($props['buttonUrl'] ?? '#'));
    $highlighted = !empty($props['highlighted']);
    $ribbon = cmsBuilderEsc((string)($props['ribbon'] ?? ''));
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['padding' => '32px', 'backgroundColor' => '#ffffff', 'borderRadius' => '16px', 'boxShadow' => '0 4px 20px rgba(0,0,0,0.08)', 'textAlign' => 'center', 'position' => 'relative'], $highlighted ? ['border' => '2px solid #3B82F6'] : [], $style)) . '>';
    if ($ribbon !== '') {
        $html .= '<div style="position:absolute;top:12px;right:-8px;background:#3B82F6;color:#fff;padding:4px 16px;font-size:12px;font-weight:600;border-radius:4px">' . $ribbon . '</div>';
    }
    $html .= '<h3 style="font-size:20px;font-weight:600;color:#1f2937;margin:0 0 16px">' . $planName . '</h3>';
    $html .= '<div style="margin-bottom:24px"><span style="font-size:14px;color:#6b7280">' . $currency . '</span><span style="font-size:48px;font-weight:700;color:#1f2937">' . $price . '</span><span style="font-size:14px;color:#6b7280">' . $period . '</span></div>';
    $html .= '<ul style="list-style:none;padding:0;margin:0 0 24px;text-align:left">';
    foreach ($features as $f) {
        if (!is_array($f)) continue;
        $included = !empty($f['included']);
        $fText = cmsBuilderEsc((string)($f['text'] ?? ''));
        $html .= '<li style="padding:8px 0;border-bottom:1px solid #f3f4f6;display:flex;align-items:center;gap:8px;color:' . ($included ? '#374151' : '#d1d5db') . '"><span style="color:' . ($included ? '#22C55E' : '#d1d5db') . '">' . ($included ? '✓' : '✗') . '</span>' . $fText . '</li>';
    }
    $html .= '</ul>';
    $html .= '<a href="' . $btnUrl . '" class="cms-builder-button" style="display:inline-block;padding:12px 32px;background-color:#3B82F6;color:#fff;border-radius:8px;text-decoration:none;font-weight:500;font-size:14px">' . $btnText . '</a>';
    return $html . '</div>';
}

function cmsRenderWidget_countdown(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $targetDate = (string)($props['targetDate'] ?? '');
    $labels = isset($props['labels']) && is_array($props['labels']) ? $props['labels'] : [];
    $dLabel = cmsBuilderEsc((string)($labels['days'] ?? 'Days'));
    $hLabel = cmsBuilderEsc((string)($labels['hours'] ?? 'Hours'));
    $mLabel = cmsBuilderEsc((string)($labels['minutes'] ?? 'Minutes'));
    $sLabel = cmsBuilderEsc((string)($labels['seconds'] ?? 'Seconds'));
    $expiredMsg = cmsBuilderEsc((string)($props['expiredMessage'] ?? 'Event has ended!'));
    $remaining = $targetDate !== '' ? max(0, (int)(strtotime($targetDate) - time())) : 0;
    if ($remaining <= 0) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['textAlign' => 'center', 'padding' => '24px', 'fontSize' => '18px', 'color' => '#6b7280'], $style)) . '>' . $expiredMsg . '</div>';
    }
    $d = (int)floor($remaining / 86400);
    $h = (int)floor(($remaining % 86400) / 3600);
    $m = (int)floor(($remaining % 3600) / 60);
    $s = $remaining % 60;
    $boxStyle = 'display:flex;flex-direction:column;align-items:center;padding:16px 20px;background:#f9fafb;border-radius:8px;min-width:80px';
    $numStyle = 'font-size:36px;font-weight:700;color:#1f2937;line-height:1';
    $lblStyle = 'font-size:12px;color:#6b7280;margin-top:4px;text-transform:uppercase';
    $html = '<div' . cmsBuilderAttrString($attrs) . ' data-target-date="' . cmsBuilderEsc($targetDate) . '" data-expired-message="' . $expiredMsg . '"' . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'justifyContent' => 'center', 'gap' => '16px'], $style)) . '>';
    if (($props['showDays'] ?? true) !== false) $html .= '<div style="' . $boxStyle . '"><span class="cms-countdown-value" style="' . $numStyle . '">' . $d . '</span><span style="' . $lblStyle . '">' . $dLabel . '</span></div>';
    if (($props['showHours'] ?? true) !== false) $html .= '<div style="' . $boxStyle . '"><span class="cms-countdown-value" style="' . $numStyle . '">' . $h . '</span><span style="' . $lblStyle . '">' . $hLabel . '</span></div>';
    if (($props['showMinutes'] ?? true) !== false) $html .= '<div style="' . $boxStyle . '"><span class="cms-countdown-value" style="' . $numStyle . '">' . $m . '</span><span style="' . $lblStyle . '">' . $mLabel . '</span></div>';
    if (($props['showSeconds'] ?? true) !== false) $html .= '<div style="' . $boxStyle . '"><span class="cms-countdown-value" style="' . $numStyle . '">' . $s . '</span><span style="' . $lblStyle . '">' . $sLabel . '</span></div>';
    return $html . '</div>';
}

function cmsRenderWidget_star_rating(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $rating = (float)($props['rating'] ?? 4.5);
    $maxRating = max(1, (int)($props['maxRating'] ?? 5));
    $showNumber = ($props['showNumber'] ?? true) !== false;
    $starColor = cmsBuilderEsc((string)($props['color'] ?? '#fbbf24'));
    $emptyColor = cmsBuilderEsc((string)($props['emptyColor'] ?? '#e5e7eb'));
    $starSize = (string)($props['size'] ?? '20');
    $starFontSize = is_numeric($starSize) ? $starSize . 'px' : $starSize;
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'inline-flex', 'alignItems' => 'center', 'gap' => '4px'], $style)) . '>';
    for ($i = 1; $i <= $maxRating; $i++) {
        $html .= '<span style="color:' . ($i <= $rating ? $starColor : ($i - 0.5 <= $rating ? $starColor : $emptyColor)) . ';font-size:' . cmsBuilderEsc($starFontSize) . '">' . ($i <= $rating ? '★' : ($i - 0.5 <= $rating ? '★' : '☆')) . '</span>';
    }
    if ($showNumber) {
        $html .= '<span style="margin-left:8px;font-size:14px;font-weight:600;color:#374151">' . number_format($rating, 1) . '</span>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_call_to_action(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $ctaTitle = cmsBuilderEsc((string)($props['title'] ?? 'Ready to Get Started?'));
    $ctaDesc = cmsBuilderEsc((string)($props['description'] ?? ''));
    $ctaBtnText = cmsBuilderEsc((string)($props['buttonText'] ?? 'Get Started'));
    $ctaBtnUrl = cmsBuilderEsc((string)($props['buttonUrl'] ?? '#'));
    $ctaSecText = cmsBuilderEsc((string)($props['secondaryButtonText'] ?? ''));
    $ctaSecUrl = cmsBuilderEsc((string)($props['secondaryButtonUrl'] ?? '#'));
    $ctaLayout = (string)($props['layout'] ?? 'horizontal');
    if ($ctaLayout === 'vertical') {
        $flexDir = 'column';
        $alignItems = 'center';
        $justify = 'center';
    } elseif ($ctaLayout === 'split') {
        $flexDir = 'row';
        $alignItems = 'center';
        $justify = 'space-between';
    } else {
        $flexDir = 'row';
        $alignItems = 'center';
        $justify = 'space-between';
    }
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['padding' => '48px', 'backgroundColor' => '#3b82f6', 'borderRadius' => '16px', 'color' => '#ffffff', 'display' => 'flex', 'flexDirection' => $flexDir, 'alignItems' => $alignItems, 'justifyContent' => $justify, 'gap' => '24px'], $style)) . '>';
    $html .= '<div' . ($ctaLayout !== 'vertical' ? ' style="flex:1"' : '') . '>';
    $html .= '<h2 style="font-size:28px;font-weight:700;margin:0 0 8px;color:inherit">' . $ctaTitle . '</h2>';
    if ($ctaDesc !== '') $html .= '<p style="font-size:16px;margin:0;opacity:0.9;color:inherit">' . $ctaDesc . '</p>';
    $html .= '</div><div style="display:flex;gap:12px;flex-shrink:0' . ($ctaLayout === 'vertical' ? ';margin-top:16px' : '') . '">';
    $html .= '<a href="' . $ctaBtnUrl . '" class="cms-builder-button" style="display:inline-block;padding:14px 28px;background:#fff;color:#3b82f6;border-radius:8px;text-decoration:none;font-weight:600;font-size:14px">' . $ctaBtnText . '</a>';
    if ($ctaSecText !== '') {
        $html .= '<a href="' . $ctaSecUrl . '" style="display:inline-block;padding:14px 28px;background:transparent;color:#fff;border:1px solid rgba(255,255,255,0.4);border-radius:8px;text-decoration:none;font-weight:500;font-size:14px">' . $ctaSecText . '</a>';
    }
    $html .= '</div></div>';
    return $html;
}

function cmsRenderWidget_flip_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $fIcon = cmsBuilderEsc((string)($props['frontIcon'] ?? 'Zap'));
    $fTitle = cmsBuilderEsc((string)($props['frontTitle'] ?? 'Front Title'));
    $fDesc = cmsBuilderEsc((string)($props['frontDescription'] ?? 'Hover to see more'));
    $bTitle = cmsBuilderEsc((string)($props['backTitle'] ?? 'Back Title'));
    $bDesc = cmsBuilderEsc((string)($props['backDescription'] ?? ''));
    $bBtnText = cmsBuilderEsc((string)($props['backButtonText'] ?? 'Learn More'));
    $bBtnUrl = cmsBuilderEsc((string)($props['backButtonUrl'] ?? '#'));
    $flipDir = (string)($props['flipDirection'] ?? 'horizontal');
    $backRotate = $flipDir === 'vertical' ? 'rotateX(180deg)' : 'rotateY(180deg)';
    $flippedRotate = $flipDir === 'vertical' ? 'rotateX(180deg)' : 'rotateY(180deg)';
    // Scoped CSS for 3D flip effect
    $flipCss = '<style>'
        . '.cms-flip-box-inner{position:relative;width:100%;height:100%;transition:transform 0.6s;transform-style:preserve-3d}'
        . '.cms-builder-node--flip_box:hover .cms-flip-box-inner,.cms-builder-node--flip_box.flipped .cms-flip-box-inner{transform:' . $flippedRotate . '}'
        . '.cms-flip-box-face{position:absolute;width:100%;height:100%;backface-visibility:hidden;-webkit-backface-visibility:hidden;border-radius:12px;overflow:hidden}'
        . '.cms-flip-box-back{transform:' . $backRotate . '}'
        . '</style>';
    $html = $flipCss;
    $html .= '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['width' => '300px', 'height' => '300px', 'perspective' => '1000px'], $style)) . '>';
    $html .= '<div class="cms-flip-box-inner">';
    // Front face
    $html .= '<div class="cms-flip-box-face" style="padding:32px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#f8fafc">';
    $html .= '<div style="font-size:32px;margin-bottom:16px">' . $fIcon . '</div>';
    $html .= '<h3 style="font-size:20px;font-weight:600;margin:0 0 8px;color:#1f2937">' . $fTitle . '</h3>';
    $html .= '<p style="font-size:14px;color:#6b7280;margin:0">' . $fDesc . '</p>';
    $html .= '</div>';
    // Back face
    $html .= '<div class="cms-flip-box-face cms-flip-box-back" style="padding:32px;text-align:center;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#3B82F6;color:#fff">';
    $html .= '<h3 style="font-size:20px;font-weight:600;margin:0 0 8px">' . $bTitle . '</h3>';
    $html .= '<p style="font-size:14px;margin:0 0 16px;opacity:0.9">' . $bDesc . '</p>';
    $html .= '<a href="' . $bBtnUrl . '" style="display:inline-block;padding:10px 24px;background:#fff;color:#3B82F6;border-radius:8px;text-decoration:none;font-weight:500;font-size:14px">' . $bBtnText . '</a>';
    $html .= '</div>';
    $html .= '</div></div>';
    return $html;
}

function cmsRenderWidget_image_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $ibSrc = cmsBuilderEsc((string)($props['src'] ?? ''));
    $ibAlt = cmsBuilderEsc((string)($props['alt'] ?? 'Image'));
    $ibTitle = cmsBuilderEsc((string)($props['title'] ?? ''));
    $ibDesc = cmsBuilderEsc((string)($props['description'] ?? ''));
    $ibLink = (string)($props['linkUrl'] ?? '');
    $titlePosition = (string)($props['titlePosition'] ?? 'below');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['textAlign' => 'center'], $style)) . '>';
    $imgTag = '';
    if ($ibSrc !== '') {
        $imgInner = '<img src="' . $ibSrc . '" alt="' . $ibAlt . '" style="width:100%;height:auto;border-radius:8px;display:block" loading="lazy">';
        $imgTag = $ibLink !== '' ? '<a href="' . cmsBuilderEsc($ibLink) . '">' . $imgInner . '</a>' : $imgInner;
    }
    $textHtml = '';
    if ($ibTitle !== '') $textHtml .= '<h3 style="font-size:18px;font-weight:600;margin:12px 0 4px;color:#1f2937">' . $ibTitle . '</h3>';
    if ($ibDesc !== '') $textHtml .= '<p style="font-size:14px;color:#6b7280;margin:0;line-height:1.5">' . $ibDesc . '</p>';
    if ($titlePosition === 'above') {
        $html .= $textHtml . $imgTag;
    } elseif ($titlePosition === 'overlay' && $ibSrc !== '') {
        $html .= '<div style="position:relative">' . $imgTag . '<div style="position:absolute;bottom:0;left:0;right:0;padding:16px;background:linear-gradient(transparent,rgba(0,0,0,0.7));color:#fff;border-radius:0 0 8px 8px">';
        if ($ibTitle !== '') $html .= '<h3 style="font-size:18px;font-weight:600;margin:0 0 4px;color:inherit">' . $ibTitle . '</h3>';
        if ($ibDesc !== '') $html .= '<p style="font-size:14px;margin:0;line-height:1.5;opacity:0.9;color:inherit">' . $ibDesc . '</p>';
        $html .= '</div></div>';
    } else {
        $html .= $imgTag . $textHtml;
    }
    return $html . '</div>';
}

function cmsRenderWidget_logo_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $logos = isset($props['logos']) && is_array($props['logos']) ? $props['logos'] : [];
    $lgCols = max(1, (int)($props['columns'] ?? 4));
    $grayscale = !empty($props['grayscale']);
    $hoverEffect = (string)($props['hoverEffect'] ?? 'color');
    $gridId = 'cms-logo-grid-' . substr(md5(serialize(['c' => $lgCols, 'h' => $hoverEffect])), 0, 8);
    $css = '';
    if ($hoverEffect === 'color' && $grayscale) {
        $css = '<style>#' . $gridId . ' img{transition:filter 0.25s,opacity 0.25s}#' . $gridId . ' a:hover img,#' . $gridId . ' div:hover img{filter:none!important;opacity:1!important}</style>';
    } elseif ($hoverEffect === 'scale') {
        $css = '<style>#' . $gridId . ' a,#' . $gridId . ' div{transition:transform 0.2s}#' . $gridId . ' a:hover,#' . $gridId . ' div:hover{transform:scale(1.06)}</style>';
    }
    $html = $css . '<div id="' . $gridId . '"' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'grid', 'gridTemplateColumns' => 'repeat(' . $lgCols . ', 1fr)', 'gap' => '32px', 'alignItems' => 'center'], $style)) . '>';
    foreach ($logos as $logo) {
        if (!is_array($logo)) continue;
        $lSrc = cmsBuilderEsc((string)($logo['src'] ?? ''));
        $lAlt = cmsBuilderEsc((string)($logo['alt'] ?? ''));
        $lUrl = (string)($logo['url'] ?? '');
        $filterCss = $grayscale ? 'filter:grayscale(100%);opacity:0.6;' : '';
        $imgHtml = $lSrc !== '' ? '<img src="' . $lSrc . '" alt="' . $lAlt . '" style="max-width:100%;height:auto;' . $filterCss . '" loading="lazy">' : '<div style="width:100%;height:60px;background:#f3f4f6;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#9ca3af;font-size:12px">' . ($lAlt !== '' ? $lAlt : 'Logo') . '</div>';
        $html .= $lUrl !== '' ? '<a href="' . cmsBuilderEsc($lUrl) . '" target="_blank" rel="noopener noreferrer" style="display:flex;align-items:center;justify-content:center">' . $imgHtml . '</a>' : '<div style="display:flex;align-items:center;justify-content:center">' . $imgHtml . '</div>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_blockquote(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $bqContent = cmsBuilderEsc((string)($props['content'] ?? ''));
    $bqAuthor = cmsBuilderEsc((string)($props['author'] ?? ''));
    $bqAuthorTitle = cmsBuilderEsc((string)($props['authorTitle'] ?? ''));
    $bqStyle = (string)($props['style'] ?? 'classic');
    // Style variants
    $baseStyle = ['padding' => '32px', 'margin' => '0'];
    if ($bqStyle === 'modern') {
        $baseStyle['borderLeft'] = '4px solid #3b82f6';
        $baseStyle['backgroundColor'] = 'transparent';
        $baseStyle['fontStyle'] = 'normal';
        $baseStyle['fontSize'] = '24px';
    } elseif ($bqStyle === 'minimal') {
        $baseStyle['backgroundColor'] = 'transparent';
        $baseStyle['fontStyle'] = 'italic';
        $baseStyle['fontSize'] = '18px';
        $baseStyle['borderLeft'] = 'none';
    } else { // classic
        $baseStyle['borderLeft'] = '4px solid #3b82f6';
        $baseStyle['backgroundColor'] = '#f8fafc';
        $baseStyle['fontStyle'] = 'italic';
        $baseStyle['fontSize'] = '20px';
    }
    $html = '<blockquote' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge($baseStyle, $style)) . '>';
    $html .= '<p style="margin:0 0 16px;line-height:1.6;color:#1f2937">' . $bqContent . '</p>';
    if ($bqAuthor !== '') {
        $html .= '<footer style="font-style:normal;font-size:14px"><strong style="color:#1f2937">' . $bqAuthor . '</strong>';
        if ($bqAuthorTitle !== '') $html .= '<span style="color:#6b7280"> — ' . $bqAuthorTitle . '</span>';
        $html .= '</footer>';
    }
    return $html . '</blockquote>';
}

function cmsRenderWidget_toggle(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $tgTitle = cmsBuilderEsc((string)($props['title'] ?? 'Click to expand'));
    $tgContent = cmsBuilderEsc((string)($props['content'] ?? ''));
    $tgOpen = !empty($props['isOpen']);
    return '<details' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['width' => '100%', 'borderRadius' => '8px', 'border' => '1px solid #e5e7eb'], $style)) . ($tgOpen ? ' open' : '') . '>'
        . '<summary style="padding:16px;cursor:pointer;font-weight:500;font-size:15px;color:#1f2937;list-style:none;display:flex;justify-content:space-between;align-items:center">' . $tgTitle . '<span style="font-size:12px;color:#9ca3af">▼</span></summary>'
        . '<div style="padding:0 16px 16px;font-size:14px;color:#6b7280;line-height:1.6">' . $tgContent . '</div>'
        . '</details>';
}

function cmsBuilderRenderThemeWidgetShell(array $attrs, array $style, string $title, string $body): string
{
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>';
    if ($title !== '') {
        $html .= '<div style="margin:0 0 16px;font-size:16px;font-weight:700;line-height:1.3;color:#0f172a">' . cmsBuilderEsc($title) . '</div>';
    }
    return $html . $body . '</div>';
}

function cmsBuilderRenderThemeWidgetEmpty(string $message): string
{
    return '<p style="margin:0;font-size:14px;line-height:1.6;color:#64748b">' . cmsBuilderEsc($message) . '</p>';
}

function cmsBuilderRenderMenuWidgetItems(array $items, ?string $scope = null, int $depth = 0, string $orientation = 'vertical', string $menuStyle = 'plain'): string
{
    if ($items === []) {
        return '';
    }

    $isHorizontal = $depth === 0 && $orientation === 'horizontal';
    $ulStyle = $isHorizontal
        ? 'list-style:none;margin:0;padding:0;display:flex;flex-wrap:wrap;gap:4px 20px;align-items:center'
        : 'list-style:none;margin:' . ($depth === 0 ? '0' : '10px 0 0') . ';padding:' . ($depth === 0 ? '0' : '0 0 0 16px') . ';display:grid;gap:10px';
    $linkStyle = match ($menuStyle) {
        'underline' => 'color:#0f172a;text-decoration:underline;font-size:14px;line-height:1.5',
        'button'    => 'display:inline-block;color:#0f172a;text-decoration:none;font-size:13px;line-height:1;padding:6px 12px;border-radius:4px;background:#f1f5f9',
        default     => 'color:#0f172a;text-decoration:none;font-size:14px;line-height:1.5',
    };

    $html = '<ul style="' . $ulStyle . '">';
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $label = cmsBuilderEsc((string)($item['label'] ?? 'Menu Item'));
        $url = function_exists('cmsResolveMenuItemUrl')
            ? cmsResolveMenuItemUrl($item, $scope)
            : (string)($item['url'] ?? '#');
        $target = (string)($item['target'] ?? '') === '_blank' ? ' target="_blank" rel="noopener noreferrer"' : '';

        $html .= '<li>';
        $html .= '<a href="' . cmsBuilderEsc($url) . '"' . $target . ' style="' . $linkStyle . '">' . $label . '</a>';
        if (!empty($item['children']) && is_array($item['children'])) {
            $html .= cmsBuilderRenderMenuWidgetItems($item['children'], $scope, $depth + 1);
        }
        $html .= '</li>';
    }
    return $html . '</ul>';
}

function cmsRenderWidget_nav_menu(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title     = trim((string)($props['title']   ?? ''));
    $menuId    = (int)($props['menuId'] ?? 0);
    $orientation = in_array((string)($props['orientation'] ?? ''), ['horizontal', 'vertical'], true) ? (string)$props['orientation'] : 'vertical';
    $menuStyle   = in_array((string)($props['menuStyle']   ?? ''), ['plain', 'underline', 'button'], true) ? (string)$props['menuStyle'] : 'plain';

    if ($menuId <= 0 || !function_exists('cmsGetMenuItemsTree')) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('Select a menu to display here.'));
    }

    $items = cmsGetMenuItemsTree($menuId);
    if ($items === []) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('This menu does not have any items yet.'));
    }

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderMenuWidgetItems($items, null, 0, $orientation, $menuStyle));
}

function cmsRenderWidget_recent_posts(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title         = trim((string)($props['title'] ?? ''));
    $count         = max(1, min(10, (int)($props['count'] ?? 5)));
    $showDate      = ($props['showDate'] ?? true) !== false;
    $showThumbnail = !empty($props['showThumbnail']);
    $showExcerpt   = !empty($props['showExcerpt']);
    $orderBy       = (string)($props['orderBy'] ?? 'date');
    $categoryIds   = is_array($props['categoryIds'] ?? null) ? array_map('intval', (array)$props['categoryIds']) : [];
    $categoryIds   = array_values(array_filter($categoryIds, fn($v) => $v > 0));
    $posts = cmsBuilderFetchPosts([
        'type' => 'post',
        'limit' => $count,
        'source_mode' => (string)($props['sourceMode'] ?? ''),
        'category_ids' => $categoryIds,
        'post_ids' => $props['postIds'] ?? [],
        'order_by' => $orderBy,
        'order' => 'desc',
        'include_featured_image' => $showThumbnail,
        'log_key' => 'cms.builder.recent_posts.query_error',
    ]);

    if ($posts === []) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('No published posts found.'));
    }

    $body = '<div style="display:grid;gap:16px">';
    foreach ($posts as $post) {
        $slug = trim((string)($post['slug'] ?? ''));
        $href = $slug !== '' && function_exists('cmsBuilderEntityPermalink')
            ? cmsBuilderEntityPermalink('post', $slug)
            : '/cms/blog/' . rawurlencode($slug);
        $postTitle = cmsBuilderEsc((string)($post['title'] ?? 'Untitled'));
        $body .= '<article style="display:grid;gap:6px">';
        if ($showThumbnail && !empty($post['featured_image'])) {
            $imgUrl = function_exists('cmsResolveUploadUrl') ? cmsBuilderEsc(cmsResolveUploadUrl((string)$post['featured_image'])) : '';
            if ($imgUrl !== '') {
                $body .= '<a href="' . cmsBuilderEsc($href) . '"><img src="' . $imgUrl . '" alt="' . $postTitle . '" loading="lazy" style="width:100%;height:160px;object-fit:cover;border-radius:4px;display:block"></a>';
            }
        }
        $body .= '<a href="' . cmsBuilderEsc($href) . '" style="color:#0f172a;text-decoration:none;font-size:14px;font-weight:600;line-height:1.5">' . $postTitle . '</a>';
        if ($showDate && !empty($post['published_at'])) {
            $body .= '<div style="font-size:12px;line-height:1.4;color:#64748b">' . cmsBuilderEsc(date('M j, Y', strtotime((string)$post['published_at']))) . '</div>';
        }
        if ($showExcerpt && !empty($post['excerpt'])) {
            $raw     = mb_substr(strip_tags((string)$post['excerpt']), 0, 120);
            $excerpt = mb_strlen((string)$post['excerpt']) > 120 ? $raw . "\u{2026}" : $raw;
            $body   .= '<div style="font-size:13px;line-height:1.5;color:#475569">' . cmsBuilderEsc($excerpt) . '</div>';
        }
        $body .= '</article>';
    }
    $body .= '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_social_links_widget(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title        = trim((string)($props['title'] ?? ''));
    $displayStyle = (string)($props['displayStyle'] ?? 'icons');
    $iconSize     = max(16, min(64, (int)($props['iconSize'] ?? 40)));
    $targetBlank  = ($props['targetBlank'] ?? true) !== false;
    $targetAttr   = $targetBlank ? ' target="_blank" rel="noopener noreferrer"' : '';
    if (!in_array($displayStyle, ['icons', 'labels', 'inline'], true)) {
        $displayStyle = 'icons';
    }

    $links = cmsBuilderSocialLinksData();
    if ($links === []) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('Add social links in CMS settings to populate this widget.'));
    }

    $monograms = [
        'facebook' => 'Fb',
        'twitter' => 'X',
        'instagram' => 'Ig',
        'youtube' => 'Yt',
        'linkedin' => 'In',
    ];

    if ($displayStyle === 'labels') {
        $body = '<div style="display:grid;gap:10px">';
        foreach ($links as $link) {
            $label = cmsBuilderEsc((string)($link['label'] ?? 'Link'));
            $url = cmsBuilderEsc((string)($link['url'] ?? '#'));
            $body .= '<a href="' . $url . '"' . $targetAttr . ' style="display:inline-flex;align-items:center;justify-content:center;padding:10px 12px;border:1px solid #e5e7eb;border-radius:999px;color:#0f172a;text-decoration:none;font-size:14px;font-weight:500">' . $label . '</a>';
        }
        $body .= '</div>';
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
    }

    if ($displayStyle === 'inline') {
        $body = '<div style="display:flex;flex-wrap:wrap;gap:12px">';
        foreach ($links as $link) {
            $label = cmsBuilderEsc((string)($link['label'] ?? 'Link'));
            $url = cmsBuilderEsc((string)($link['url'] ?? '#'));
            $body .= '<a href="' . $url . '"' . $targetAttr . ' style="color:#2563eb;text-decoration:none;font-size:14px;font-weight:500">' . $label . '</a>';
        }
        $body .= '</div>';
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
    }

    $sz  = $iconSize . 'px';
    $body = '<div style="display:flex;flex-wrap:wrap;gap:12px">';
    foreach ($links as $link) {
        $name  = strtolower(trim((string)($link['name'] ?? 'link')));
        $label = cmsBuilderEsc((string)($link['label'] ?? ucfirst($name)));
        $url   = cmsBuilderEsc((string)($link['url'] ?? '#'));
        $badge = $monograms[$name] ?? strtoupper(substr($name, 0, 2));
        $body .= '<a href="' . $url . '"' . $targetAttr . ' title="' . $label . '" aria-label="' . $label . '" style="display:inline-flex;align-items:center;justify-content:center;width:' . $sz . ';height:' . $sz . ';border-radius:999px;background-color:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;text-decoration:none;font-size:12px;font-weight:700">' . cmsBuilderEsc($badge) . '</a>';
    }
    $body .= '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_contact_info(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title      = trim((string)($props['title']   ?? ''));
    $address    = trim((string)($props['address'] ?? ''));
    $phone      = trim((string)($props['phone']   ?? ''));
    $email      = trim((string)($props['email']   ?? ''));
    $website    = trim((string)($props['website'] ?? ''));
    $showMapLink = ($props['showMapLink'] ?? false) !== false;

    $rows = [];
    if ($address !== '') {
        $mapLink = '';
        if ($showMapLink) {
            $mapQuery = rawurlencode($address);
            $mapLink  = '<div style="margin-top:4px"><a href="https://maps.google.com/?q=' . $mapQuery . '" target="_blank" rel="noopener noreferrer" style="font-size:12px;color:#2563eb;text-decoration:none">View on map &rarr;</a></div>';
        }
        $rows[] = '<div style="display:grid;gap:4px"><div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b">Address</div><div style="font-size:14px;line-height:1.6;color:#0f172a">' . cmsBuilderEsc($address) . $mapLink . '</div></div>';
    }
    if ($phone !== '') {
        $phoneHref = preg_replace('/[^0-9+]/', '', $phone) ?? '';
        $phoneHtml = $phoneHref !== ''
            ? '<a href="tel:' . cmsBuilderEsc($phoneHref) . '" style="color:#0f172a;text-decoration:none">' . cmsBuilderEsc($phone) . '</a>'
            : cmsBuilderEsc($phone);
        $rows[] = '<div style="display:grid;gap:4px"><div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b">Phone</div><div style="font-size:14px;line-height:1.6;color:#0f172a">' . $phoneHtml . '</div></div>';
    }
    if ($email !== '') {
        $rows[] = '<div style="display:grid;gap:4px"><div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b">Email</div><div style="font-size:14px;line-height:1.6;color:#0f172a"><a href="mailto:' . cmsBuilderEsc($email) . '" style="color:#0f172a;text-decoration:none">' . cmsBuilderEsc($email) . '</a></div></div>';
    }
    if ($website !== '') {
        $websiteHref  = cmsBuilderEsc($website);
        $websiteLabel = cmsBuilderEsc(preg_replace('#^https?://#', '', $website) ?? $website);
        $rows[] = '<div style="display:grid;gap:4px"><div style="font-size:11px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;color:#64748b">Website</div><div style="font-size:14px;line-height:1.6;color:#0f172a"><a href="' . $websiteHref . '" target="_blank" rel="noopener noreferrer" style="color:#2563eb;text-decoration:none">' . $websiteLabel . '</a></div></div>';
    }

    $body = $rows === []
        ? cmsBuilderRenderThemeWidgetEmpty('Add an address, phone number, or email to populate this widget.')
        : '<div style="display:grid;gap:14px">' . implode('', $rows) . '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_categories(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title = trim((string)($props['title'] ?? ''));
    $count = max(1, min(50, (int)($props['count'] ?? 8)));
    $showCount = ($props['showCount'] ?? true) !== false;
    $module = (string)($props['module'] ?? 'post');
    $orderBy = (string)($props['orderBy'] ?? 'name');
    $showEmpty = !empty($props['showEmpty']);

    // Resolve taxonomy and content type from the module prop
    $taxonomy = $module === 'product' ? 'product' : 'default';
    $contentType = $module === 'product' ? 'product' : 'post';
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $rows = cmsBuilderFetchCategorySummary([
        'module' => $module,
        'count' => $count,
        'order_by' => $orderBy,
        'show_empty' => $showEmpty,
    ]);

    if ($rows === []) {
        $emptyMsg = $module === 'product' ? 'No product categories found.' : 'No categories found.';
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty($emptyMsg));
    }

    // Build link URLs — blog uses query param, products use slug path
    $body = '<div style="display:grid;gap:10px">';
    foreach ($rows as $row) {
        $slug = (string)($row['slug'] ?? '');
        if ($module === 'product') {
            $href = $baseUrl . '/ecommerce/shop?category=' . rawurlencode($slug);
        } else {
            $href = $baseUrl . '/cms/category/' . rawurlencode($slug);
        }
        $body .= '<a href="' . cmsBuilderEsc($href) . '" style="display:flex;align-items:center;justify-content:space-between;gap:12px;color:#0f172a;text-decoration:none;font-size:14px;line-height:1.5">';
        $body .= '<span>' . cmsBuilderEsc((string)($row['name'] ?? 'Category')) . '</span>';
        if ($showCount) {
            $body .= '<span style="color:#64748b">' . (int)($row['post_count'] ?? 0) . '</span>';
        }
        $body .= '</a>';
    }
    $body .= '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_tag_cloud(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title = trim((string)($props['title'] ?? ''));
    $count = max(1, min(60, (int)($props['count'] ?? 16)));
    $orderBy = (string)($props['orderBy'] ?? 'count');
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $rows = cmsBuilderFetchTagSummary([
        'count' => $count,
        'order_by' => $orderBy,
    ]);

    if ($rows === []) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('No tags found.'));
    }

    $body = '<div style="display:flex;flex-wrap:wrap;gap:10px">';
    foreach ($rows as $row) {
        $slug = (string)($row['slug'] ?? '');
        $href = $baseUrl . '/cms/blog?tag=' . rawurlencode($slug);
        $body .= '<a href="' . cmsBuilderEsc($href) . '" style="display:inline-flex;align-items:center;padding:6px 12px;border-radius:999px;border:1px solid #e2e8f0;background-color:#f8fafc;color:#0f172a;text-decoration:none;font-size:12px;font-weight:600;line-height:1.2">' . cmsBuilderEsc((string)($row['name'] ?? 'Tag')) . '</a>';
    }
    $body .= '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_archives(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title = trim((string)($props['title'] ?? ''));
    $count = max(1, min(36, (int)($props['count'] ?? 6)));
    $showCount = ($props['showCount'] ?? true) !== false;
    $orderBy = (string)($props['orderBy'] ?? 'date_desc');
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $rows = cmsBuilderFetchArchiveSummary([
        'count' => $count,
        'order_by' => $orderBy,
    ]);

    if ($rows === []) {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('No archive months found.'));
    }

    $body = '<div style="display:grid;gap:10px">';
    foreach ($rows as $row) {
        $ym = trim((string)($row['ym'] ?? ''));
        if ($ym === '') {
            continue;
        }
        $href = $baseUrl . '/cms/blog?archive=' . rawurlencode($ym);
        $body .= '<a href="' . cmsBuilderEsc($href) . '" style="display:flex;align-items:center;justify-content:space-between;gap:12px;color:#0f172a;text-decoration:none;font-size:14px;line-height:1.5">';
        $body .= '<span>' . cmsBuilderEsc((string)($row['label'] ?? $ym)) . '</span>';
        if ($showCount) {
            $body .= '<span style="color:#64748b">' . (int)($row['post_count'] ?? 0) . '</span>';
        }
        $body .= '</a>';
    }
    $body .= '</div>';

    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_opening_hours(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title       = trim((string)($props['title'] ?? ''));
    $displayMode = (string)($props['displayMode'] ?? 'text');
    $showIcon    = ($props['showIcon'] ?? true) !== false;

    $icon = '';
    if ($showIcon) {
        $icon = '<span aria-hidden="true" style="display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:999px;background-color:#f8fafc;border:1px solid #e2e8f0;color:#0f172a;flex-shrink:0"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg></span>';
    }

    if ($displayMode === 'table') {
        $schedule = is_array($props['schedule'] ?? null) ? $props['schedule'] : [];
        $days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
        $hasAny = false;
        $rows = '';
        foreach ($days as $day) {
            $entry  = is_array($schedule[$day] ?? null) ? $schedule[$day] : [];
            $closed = !empty($entry['closed']);
            $open   = trim((string)($entry['open']  ?? ''));
            $close  = trim((string)($entry['close'] ?? ''));
            if ($open !== '' || $close !== '' || $closed) {
                $hasAny = true;
            }
            $hoursText = $closed ? 'Closed' : (($open !== '' || $close !== '') ? trim($open . ($close !== '' ? ' – ' . $close : '')) : '—');
            $dayColor  = $closed ? '#94a3b8' : '#0f172a';
            $rows .= '<div style="display:contents">'
                . '<div style="font-size:13px;color:#64748b;padding:4px 0">' . cmsBuilderEsc($day) . '</div>'
                . '<div style="font-size:13px;color:' . $dayColor . ';padding:4px 0;text-align:right">' . cmsBuilderEsc($hoursText) . '</div>'
                . '</div>';
        }
        if (!$hasAny) {
            return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('Set your opening hours in the editor to display this widget.'));
        }
        $tableHtml = '<div style="display:grid;grid-template-columns:auto 1fr;gap:0 16px">' . $rows . '</div>';
        $body = $icon !== '' ? '<div style="display:flex;align-items:flex-start;gap:12px">' . $icon . $tableHtml . '</div>' : $tableHtml;
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
    }

    // Fallback: free-text mode
    $text = trim((string)($props['text'] ?? ''));
    if ($text === '') {
        return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, cmsBuilderRenderThemeWidgetEmpty('Add your opening hours text to display this widget.'));
    }
    $body = '<div style="display:flex;align-items:flex-start;gap:12px;font-size:14px;line-height:1.6;color:#0f172a">' . $icon . '<div>' . cmsBuilderEsc($text) . '</div></div>';
    return cmsBuilderRenderThemeWidgetShell($attrs, $style, $title, $body);
}

function cmsRenderWidget_search_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $sbPlaceholder = cmsBuilderEsc((string)($props['placeholder'] ?? 'Search...'));
    $sbBtnText = cmsBuilderEsc((string)($props['buttonText'] ?? 'Search'));
    $sbShowBtn = ($props['showButton'] ?? true) !== false;
    $sbUrl = cmsBuilderEsc((string)($props['searchUrl'] ?? '/cms/search'));
    $sbInputStyle = (string)($props['style'] ?? 'rounded');
    $borderRadiusMap = ['rounded' => '8px', 'square' => '0', 'pill' => '999px'];
    $inputBorderRadius = $borderRadiusMap[$sbInputStyle] ?? '8px';
    return '<form' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['maxWidth' => '500px', 'width' => '100%', 'display' => 'flex', 'gap' => '8px'], $style)) . ' action="' . $sbUrl . '" method="get">'
        . '<input type="search" name="q" placeholder="' . $sbPlaceholder . '" style="flex:1;padding:10px 16px;border:1px solid #d1d5db;border-radius:' . $inputBorderRadius . ';font-size:14px;outline:none">'
        . ($sbShowBtn ? '<button type="submit" style="padding:10px 20px;background-color:#3B82F6;color:#fff;border:none;border-radius:' . $inputBorderRadius . ';font-weight:500;font-size:14px;cursor:pointer">' . $sbBtnText . '</button>' : '')
        . '</form>';
}

function cmsRenderWidget_badge(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $text = cmsBuilderEsc((string)($props['text'] ?? 'Featured'));
    $variant = (string)($props['variant'] ?? 'primary');
    $size = (string)($props['size'] ?? 'md');

    $paletteMap = [
        'primary' => ['backgroundColor' => '#dbeafe', 'color' => '#1d4ed8'],
        'success' => ['backgroundColor' => '#dcfce7', 'color' => '#15803d'],
        'warning' => ['backgroundColor' => '#fef3c7', 'color' => '#b45309'],
        'neutral' => ['backgroundColor' => '#e5e7eb', 'color' => '#374151'],
    ];
    $sizeMap = [
        'sm' => ['padding' => '4px 10px', 'fontSize' => '11px'],
        'md' => ['padding' => '6px 12px', 'fontSize' => '12px'],
        'lg' => ['padding' => '8px 14px', 'fontSize' => '13px'],
    ];

    $palette = $paletteMap[$variant] ?? $paletteMap['primary'];
    $sizeStyle = $sizeMap[$size] ?? $sizeMap['md'];

    return '<span' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge([
        'display' => 'inline-flex',
        'alignItems' => 'center',
        'justifyContent' => 'center',
        'borderRadius' => '999px',
        'fontWeight' => '700',
        'letterSpacing' => '0.08em',
        'textTransform' => 'uppercase',
    ], $palette, $sizeStyle, $style)) . '>' . $text . '</span>';
}

function cmsRenderWidget_stat_card(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $value = cmsBuilderEsc((string)($props['value'] ?? '128'));
    $label = cmsBuilderEsc((string)($props['label'] ?? 'Happy Customers'));
    $description = cmsBuilderEsc((string)($props['description'] ?? 'A quick metric you want visitors to notice immediately.'));
    $accentColor = cmsBuilderEsc((string)($props['accentColor'] ?? '#0f172a'));

    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge([
        'padding' => '24px',
        'border' => '1px solid #e5e7eb',
        'borderRadius' => '16px',
        'backgroundColor' => '#ffffff',
    ], $style)) . '>';
    $html .= '<div style="font-size:40px;font-weight:800;line-height:1;color:' . $accentColor . '">' . $value . '</div>';
    $html .= '<div style="margin-top:10px;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;color:#64748b">' . $label . '</div>';
    if ($description !== '') {
        $html .= '<p style="margin:12px 0 0;font-size:14px;line-height:1.6;color:#475569">' . $description . '</p>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_contact_card(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $title = cmsBuilderEsc((string)($props['title'] ?? 'Let\'s Talk'));
    $description = cmsBuilderEsc((string)($props['description'] ?? 'Share your project, request a quote, or visit our studio.'));
    $phone = cmsBuilderEsc((string)($props['phone'] ?? '+63 900 000 0000'));
    $email = cmsBuilderEsc((string)($props['email'] ?? 'hello@example.com'));
    $address = cmsBuilderEsc((string)($props['address'] ?? '123 Market Street, Manila'));
    $buttonText = cmsBuilderEsc((string)($props['buttonText'] ?? 'Contact Us'));
    $buttonUrl = cmsBuilderEsc((string)($props['buttonUrl'] ?? '/cms/contact'));

    $phoneHref = preg_replace('/[^0-9+]/', '', (string)($props['phone'] ?? '+639000000000')) ?? '';
    $emailHref = trim((string)($props['email'] ?? ''));

    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge([
        'padding' => '24px',
        'border' => '1px solid #e5e7eb',
        'borderRadius' => '16px',
        'backgroundColor' => '#ffffff',
    ], $style)) . '>';
    $html .= '<div style="font-size:24px;font-weight:700;color:#0f172a">' . $title . '</div>';
    if ($description !== '') {
        $html .= '<p style="margin:10px 0 18px;font-size:14px;line-height:1.7;color:#475569">' . $description . '</p>';
    }
    $html .= '<div style="display:grid;gap:10px;margin-bottom:' . ($buttonText !== '' ? '18px' : '0') . '">';
    if ($phone !== '') {
        $phoneHtml = $phoneHref !== '' ? '<a href="tel:' . cmsBuilderEsc($phoneHref) . '" style="color:#0f172a;text-decoration:none">' . $phone . '</a>' : $phone;
        $html .= '<div style="font-size:14px;color:#0f172a"><strong style="color:#64748b">Phone:</strong> ' . $phoneHtml . '</div>';
    }
    if ($email !== '') {
        $emailHtml = $emailHref !== '' ? '<a href="mailto:' . cmsBuilderEsc($emailHref) . '" style="color:#0f172a;text-decoration:none">' . $email . '</a>' : $email;
        $html .= '<div style="font-size:14px;color:#0f172a"><strong style="color:#64748b">Email:</strong> ' . $emailHtml . '</div>';
    }
    if ($address !== '') {
        $html .= '<div style="font-size:14px;color:#0f172a"><strong style="color:#64748b">Address:</strong> ' . $address . '</div>';
    }
    $html .= '</div>';
    if ($buttonText !== '') {
        $html .= '<a href="' . $buttonUrl . '" style="display:inline-flex;align-items:center;justify-content:center;padding:12px 20px;border-radius:999px;background-color:#0f172a;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none">' . $buttonText . '</a>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_breadcrumbs(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $bcItems = isset($props['items']) && is_array($props['items']) ? $props['items'] : [];
    $bcSep = cmsBuilderEsc((string)($props['separator'] ?? '/'));
    $showHome = ($props['showHome'] ?? true) !== false;
    // Auto-prepend Home link if showHome is enabled and items don't already start with one
    if ($showHome && (empty($bcItems) || ((string)($bcItems[0]['label'] ?? '')) !== 'Home')) {
        array_unshift($bcItems, ['label' => 'Home', 'url' => '/']);
    }
    $html = '<nav' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['fontSize' => '14px', 'color' => '#6b7280'], $style)) . ' aria-label="breadcrumb"><ol style="list-style:none;display:flex;align-items:center;gap:8px;padding:0;margin:0">';
    foreach ($bcItems as $idx => $bc) {
        if (!is_array($bc)) continue;
        $bcLabel = cmsBuilderEsc((string)($bc['label'] ?? ''));
        $bcUrl = (string)($bc['url'] ?? '');
        if ($idx > 0) $html .= '<li style="color:#9ca3af">' . $bcSep . '</li>';
        if ($bcUrl !== '' && $idx < count($bcItems) - 1) {
            $html .= '<li><a href="' . cmsBuilderEsc($bcUrl) . '" style="color:#3B82F6;text-decoration:none">' . $bcLabel . '</a></li>';
        } else {
            $html .= '<li style="color:#1f2937;font-weight:500">' . $bcLabel . '</li>';
        }
    }
    return $html . '</ol></nav>';
}

function cmsRenderWidget_code_block(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $code = cmsBuilderEsc((string)($props['code'] ?? ''));
    $lang = cmsBuilderEsc((string)($props['language'] ?? 'text'));
    $theme = (string)($props['theme'] ?? 'dark');
    $showLineNumbers = ($props['showLineNumbers'] ?? false) !== false;
    $showCopyButton = ($props['showCopyButton'] ?? false) !== false;
    $bg = $theme === 'light' ? '#f8fafc' : '#1e1e2e';
    $fg = $theme === 'light' ? '#1f2937' : '#cdd6f4';
    $headerRight = '';
    if ($showCopyButton) {
        $headerRight = '<button type="button" class="cms-code-copy" style="background:transparent;border:none;cursor:pointer;color:inherit;font-size:12px;padding:2px 8px" data-code="' . $code . '" onclick="navigator.clipboard.writeText(this.dataset.code);this.textContent=\'Copied!\';setTimeout(()=>this.textContent=\'Copy\',1500)">Copy</button>';
    }
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['borderRadius' => '8px', 'overflow' => 'hidden'], $style)) . '>';
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 16px;background:' . ($theme === 'light' ? '#e2e8f0' : '#181825') . ';font-size:12px;color:' . ($theme === 'light' ? '#64748b' : '#a6adc8') . '">';
    $caption = trim((string)($props['caption'] ?? ''));
    if ($caption !== '') {
        $html .= '<span>' . cmsBuilderEsc($caption) . '<span style="margin-left:8px;opacity:0.55">' . $lang . '</span></span>';
    } else {
        $html .= $lang;
    }
    $html .= $headerRight . '</div>';
    if ($showLineNumbers) {
        $lines = explode("\n", $code);
        $lineNums = '';
        $lineContent = '';
        foreach ($lines as $i => $line) {
            $n = $i + 1;
            $lineNums .= '<span style="display:block;color:' . ($theme === 'light' ? '#94a3b8' : '#585b70') . '">' . $n . '</span>';
            $lineContent .= '<span style="display:block">' . ($line !== '' ? $line : '&nbsp;') . '</span>';
        }
        $html .= '<pre style="margin:0;padding:16px;background:' . $bg . ';color:' . $fg . ';font-size:13px;line-height:1.6;overflow-x:auto;display:flex;gap:16px"><code style="user-select:none;text-align:right;min-width:2em">' . $lineNums . '</code><code style="flex:1">' . $lineContent . '</code></pre>';
    } else {
        $html .= '<pre style="margin:0;padding:16px;background:' . $bg . ';color:' . $fg . ';font-size:13px;line-height:1.6;overflow-x:auto"><code>' . $code . '</code></pre>';
    }
    return $html . '</div>';
}

/**
 * Audio player widget.
 */
function cmsRenderWidget_audio(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $src = (string)($props['src'] ?? '');
    if ($src === '') {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . ' style="padding:16px;border:1px dashed #d1d5db;border-radius:6px;color:#6b7280;font-size:13px">No audio source set.</div>';
    }
    $title = trim((string)($props['title'] ?? ''));
    $artist = trim((string)($props['artist'] ?? ''));
    $controls = ($props['controls'] ?? true) !== false;
    $autoplay = !empty($props['autoplay']);
    $loop = !empty($props['loop']);
    $audioAttrs = ['src' => cmsBuilderEsc($src)];
    if ($controls) $audioAttrs[] = 'controls';
    if ($autoplay) $audioAttrs[] = 'autoplay';
    if ($loop) $audioAttrs[] = 'loop';
    $attrStr = ' src="' . cmsBuilderEsc($src) . '"'
        . ($controls ? ' controls' : '')
        . ($autoplay ? ' autoplay' : '')
        . ($loop ? ' loop' : '');
    $wrapStyle = array_merge(['width' => '100%'], $style);
    $html = '<figure' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapStyle) . '>';
    if ($title !== '' || $artist !== '') {
        $html .= '<figcaption style="margin-bottom:8px;font-size:13px;color:#374151">';
        if ($title !== '') $html .= '<strong>' . cmsBuilderEsc($title) . '</strong>';
        if ($artist !== '') $html .= ' <span style="color:#6b7280">— ' . cmsBuilderEsc($artist) . '</span>';
        $html .= '</figcaption>';
    }
    $html .= '<audio' . $attrStr . ' style="width:100%;display:block"></audio>';
    return $html . '</figure>';
}

/**
 * HTML Embed widget — outputs raw HTML.
 */
function cmsRenderWidget_html_embed(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $html = (string)($props['html'] ?? '');
    if ($html === '') {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . ' style="padding:16px;border:1px dashed #d1d5db;border-radius:6px;color:#6b7280;font-size:13px">HTML embed is empty.</div>';
    }
    // Output raw — no escaping. The user intentionally embedded this HTML.
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . $html . '</div>';
}

/**
 * Default renderer for unknown widget types.
 */
function cmsRenderWidget_default(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . ($children !== '' ? $children : cmsBuilderEsc(cmsBuilderNodeContent($props))) . '</div>';
}
