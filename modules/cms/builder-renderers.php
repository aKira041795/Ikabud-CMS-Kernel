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
        'team_grid'       => 'cmsRenderWidget_placeholder_grid',
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
        'breadcrumbs'     => 'cmsRenderWidget_breadcrumbs',
        'code_block'      => 'cmsRenderWidget_code_block',
    ];

    // Allow modules to extend/override widget renderers via kernel Hooks (filter).
    // app()->hooks()->on('cms.builder.renderers', function(array $map): array { $map['my_type'] = 'myRenderFn'; return $map; }, 10);
    $map = app()->hooks()->filter('cms.builder.renderers', $map);

    return $map;
}

// ─── Widget Render Functions ─────────────────────────────────────────────

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
    if ($type === 'container') {
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

    $imgStyleStr = cmsBuilderStyleAttr($imgStyle);
    $html = '<figure' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>'
        . '<img src="' . cmsBuilderEsc($src) . '" alt="' . cmsBuilderEsc($alt) . '" loading="lazy"' . $imgStyleStr . '>';
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
    // Map panel props into style equivalents (panel props override defaults but user style wins)
    $base = ['border' => 'none', 'width' => '100%'];
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
    $size = (string)($props['size'] ?? '');
    if ($size !== '' && !isset($style['fontSize'])) {
        $style['fontSize'] = $size . (is_numeric($size) ? 'px' : '');
    }
    return '<span' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . cmsBuilderEsc((string)($props['icon'] ?? 'Icon')) . '</span>';
}

function cmsRenderWidget_icon_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>'
        . '<div>' . cmsBuilderEsc((string)($props['icon'] ?? 'Star')) . '</div>'
        . '<h3>' . cmsBuilderEsc((string)($props['title'] ?? '')) . '</h3>'
        . '<p>' . cmsBuilderEsc((string)($props['description'] ?? '')) . '</p></div>';
}

function cmsRenderWidget_tabs(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $tabs = cmsBuilderNormalizeItems($props['tabs'] ?? [], 'tabs');
    $activeTab = (string)($props['activeTab'] ?? ($tabs[0]['id'] ?? ''));
    $nav = '';
    $panels = '';
    foreach ($tabs as $tab) {
        if (!is_array($tab)) {
            continue;
        }
        $tabId = cmsBuilderEsc((string)($tab['id'] ?? ''));
        $isActive = (string)($tab['id'] ?? '') === $activeTab;
        $activeCls = $isActive ? ' active' : '';
        $nav .= '<button type="button" class="cms-builder-tab-btn' . $activeCls . '" data-tab="' . $tabId . '">' . cmsBuilderEsc((string)($tab['label'] ?? 'Tab')) . '</button>';
        $panels .= '<div class="cms-builder-tab-panel' . $activeCls . '" data-tab="' . $tabId . '">' . cmsBuilderEsc((string)($tab['content'] ?? '')) . '</div>';
    }
    // Inline CSS to hide inactive panels (JS adds/removes .active class)
    $panelCss = '<style>.cms-builder-tab-panel{display:none}.cms-builder-tab-panel.active{display:block}'
        . '.cms-builder-tab-btn{padding:10px 20px;border:none;background:transparent;cursor:pointer;font-size:14px;font-weight:500;color:#6b7280;border-bottom:2px solid transparent}'
        . '.cms-builder-tab-btn.active{color:#2563EB;border-bottom-color:#2563EB}</style>';
    return $panelCss . '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><div style="display:flex;border-bottom:1px solid #e5e7eb;margin-bottom:16px">' . $nav . '</div><div>' . $panels . '</div></div>';
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
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'gap' => '12px', 'alignItems' => 'center'], $style)) . '>';
    foreach ($icons as $ico) {
        if (!is_array($ico)) continue;
        $platform = cmsBuilderEsc((string)($ico['platform'] ?? 'link'));
        $url = cmsBuilderEsc((string)($ico['url'] ?? '#'));
        $html .= '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" class="cms-builder-social-icon" data-platform="' . $platform . '" style="display:inline-flex;align-items:center;justify-content:center;width:' . ($size + 16) . 'px;height:' . ($size + 16) . 'px;border-radius:50%;background-color:#f3f4f6;color:#374151">' . $platform . '</a>';
    }
    return $html . '</div>';
}

function cmsRenderWidget_list(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $listItems = cmsBuilderNormalizeItems($props['items'] ?? []);
    $listType = (string)($props['listType'] ?? 'bullet');
    $tag = $listType === 'number' ? 'ol' : 'ul';
    $isCheck = $listType === 'check';
    $listStyle = $isCheck ? ['listStyle' => 'none', 'paddingLeft' => '0'] : ['paddingLeft' => '1.5em'];
    $html = '<' . $tag . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'flexDirection' => 'column', 'gap' => '8px'], $listStyle, $style)) . '>';
    foreach ($listItems as $li) {
        $prefix = $isCheck ? '<span style="color:#22c55e;margin-right:8px">✓</span>' : '';
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
    $slides = cmsBuilderNormalizeItems($props['slides'] ?? [], 'slides');
    $height = cmsBuilderEsc((string)($props['height'] ?? '500px'));
    $interval = (int)($props['interval'] ?? 5000);
    $showArrows = ($props['showArrows'] ?? true) !== false;
    $showDots = ($props['showDots'] ?? true) !== false;
    $autoplay = !empty($props['autoplay']);
    $animationStyle = cmsBuilderEsc((string)($props['animationStyle'] ?? 'slide'));
    $fullWidth = !empty($props['fullWidth']);
    $captionAlign = cmsBuilderEsc((string)($props['captionAlign'] ?? 'center'));
    $captionPosition = (string)($props['captionPosition'] ?? 'bottom');
    $captionColor = cmsBuilderEsc((string)($props['captionColor'] ?? '#ffffff'));
    $captionTitleSize = cmsBuilderEsc((string)($props['captionTitleSize'] ?? '32px'));
    $captionDescSize = cmsBuilderEsc((string)($props['captionDescSize'] ?? '18px'));
    // Position styles for caption overlay
    $posStyle = 'position:absolute;left:0;right:0;padding:24px;z-index:2;';
    if ($captionPosition === 'top') {
        $posStyle .= 'top:0;';
    } elseif ($captionPosition === 'center') {
        $posStyle .= 'top:50%;transform:translateY(-50%);';
    } else {
        $posStyle .= 'bottom:0;';
    }
    $bgGrad = $captionPosition === 'center' ? 'rgba(0,0,0,0.4)' : 'linear-gradient(transparent,rgba(0,0,0,0.6))';
    // Full-width support
    $wrapStyle = ['position' => 'relative', 'overflow' => 'hidden'];
    if ($fullWidth) {
        // Remove conflicting node styles that would override the breakout
        unset($style['width'], $style['margin'], $style['marginLeft'], $style['marginRight']);
        $wrapStyle['width'] = '100vw';
        $wrapStyle['marginLeft'] = 'calc(-50vw + 50%)';
        // Prevent flex cross-axis centering from shifting the breakout
        $wrapStyle['alignSelf'] = 'flex-start';
    }
    $dataAttrs = ' data-interval="' . $interval . '"'
        . ' data-autoplay="' . ($autoplay ? 'true' : 'false') . '"'
        . ' data-animation="' . $animationStyle . '"';
    $html = '<div' . cmsBuilderAttrString($attrs) . $dataAttrs . cmsBuilderStyleAttr(array_merge($style, $wrapStyle)) . '>';
    // For slide animation: use a flex track wrapper; for fade/kenburns/zoom: stack slides
    $useSlideTrack = in_array($animationStyle, ['slide', 'carousel', 'coverflow']);
    if ($useSlideTrack) {
        $html .= '<div class="cms-builder-slide-track" style="display:flex;transition:transform 0.5s ease-in-out;height:' . $height . '">';
    }
    foreach ($slides as $idx => $slide) {
        if (!is_array($slide)) continue;
        $img = cmsBuilderEsc((string)($slide['image'] ?? ''));
        $sTitle = cmsBuilderEsc((string)($slide['title'] ?? ''));
        $sDesc = cmsBuilderEsc((string)($slide['description'] ?? ''));
        $sLink = cmsBuilderEsc((string)($slide['link'] ?? ''));
        $sCtaText = cmsBuilderEsc((string)($slide['ctaText'] ?? ''));
        if ($useSlideTrack) {
            // Slide track: each slide is min-width:100%
            $html .= '<div class="cms-builder-slide" style="min-width:100%;height:100%;position:relative;flex-shrink:0">';
        } else {
            // Stacked: first slide relative (holds height), rest absolute
            $stackStyle = $idx === 0
                ? 'position:relative;width:100%;height:' . $height . ';overflow:hidden'
                : 'position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;overflow:hidden;transition:opacity 0.8s ease-in-out';
            $html .= '<div class="cms-builder-slide" style="' . $stackStyle . '">';
        }
        $imgClass = $animationStyle === 'kenburns' ? ' class="cms-kb-img"' : '';
        $html .= ($img !== '' ? '<img' . $imgClass . ' src="' . $img . '" alt="' . $sTitle . '" style="width:100%;height:100%;object-fit:cover" loading="lazy">' : '');
        // Caption overlay
        $hasCaption = $sTitle !== '' || $sDesc !== '' || ($sCtaText !== '' && $sLink !== '');
        if ($hasCaption) {
            $html .= '<div style="' . $posStyle . 'background:' . $bgGrad . ';color:' . $captionColor . ';text-align:' . $captionAlign . ';text-shadow:0 2px 4px rgba(0,0,0,0.5)">';
            $html .= ($sTitle !== '' ? '<h3 style="margin:0 0 8px 0;font-size:' . $captionTitleSize . '">' . $sTitle . '</h3>' : '');
            $html .= ($sDesc !== '' ? '<p style="margin:0 0 12px 0;font-size:' . $captionDescSize . ';opacity:0.9">' . $sDesc . '</p>' : '');
            $html .= ($sCtaText !== '' && $sLink !== '' ? '<a href="' . $sLink . '" style="display:inline-block;padding:10px 20px;background:#2563EB;color:#fff;border-radius:6px;text-decoration:none;font-size:14px;font-weight:500">' . $sCtaText . '</a>' : '');
            $html .= '</div>';
        }
        $html .= '</div>';
    }
    if ($useSlideTrack) {
        $html .= '</div>';
    }
    // Arrow navigation
    if ($showArrows && count($slides) > 1) {
        $arrowStyle = 'position:absolute;top:50%;transform:translateY(-50%);z-index:2;background:rgba(0,0,0,0.4);color:#fff;border:none;cursor:pointer;width:48px;height:48px;display:flex;align-items:center;justify-content:center;font-size:18px;border-radius:50%;line-height:1;box-shadow:0 2px 8px rgba(0,0,0,0.2)';
        $html .= '<button type="button" class="cms-builder-slide-prev" style="' . $arrowStyle . ';left:12px" aria-label="Previous slide">&#8249;</button>';
        $html .= '<button type="button" class="cms-builder-slide-next" style="' . $arrowStyle . ';right:12px" aria-label="Next slide">&#8250;</button>';
    }
    // Dot navigation
    if ($showDots && count($slides) > 1) {
        $html .= '<div style="position:absolute;bottom:12px;left:50%;transform:translateX(-50%);display:flex;gap:8px;z-index:2">';
        foreach ($slides as $di => $ds) {
            $dotActive = $di === 0 ? 'opacity:1' : 'opacity:0.5';
            $html .= '<button type="button" class="cms-builder-slide-dot" style="width:10px;height:10px;border-radius:50%;background:#fff;border:none;cursor:pointer;padding:0;' . $dotActive . '" aria-label="Go to slide ' . ($di + 1) . '"></button>';
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
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge($baseStyle, $style)) . '>' . $dismissBtn . $alertContent . '</div>';
}

function cmsRenderWidget_anchor(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $anchorId = cmsBuilderEsc((string)($props['anchorId'] ?? 'anchor'));
    return '<div id="' . $anchorId . '"' . cmsBuilderAttrString($attrs) . ' style="display:block;height:0;visibility:hidden"></div>';
}

function cmsRenderWidget_posts_grid(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $postCount = max(1, min(12, (int)($props['postCount'] ?? 3)));
    $gridCols = max(1, min(6, (int)($props['gridColumns'] ?? 3)));
    $showDate = ($props['showDate'] ?? true) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $showReadMore = ($props['showReadMore'] ?? true) !== false;
    $excerptLen = max(20, (int)($props['excerptLength'] ?? 120));
    $postType = (string)($props['postType'] ?? 'post');
    $posts = [];
    try {
        $db = cmsDb();
        $sql = "SELECT c.id, c.title, c.slug, c.excerpt, c.published_at, u.display_name as author_name FROM cms_content c LEFT JOIN cms_users u ON u.id = c.author_id WHERE c.deleted_at IS NULL AND c.type = :type AND " . cmsPublicVisibilitySql('c') . " ORDER BY COALESCE(c.published_at, c.created_at) DESC LIMIT " . $postCount;
        $stmt = $db->prepare($sql);
        $stmt->execute([':type' => $postType]);
        $posts = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    } catch (\Throwable $e) {
        $posts = [];
    }
    if (empty($posts)) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center">No posts found.</p></div>';
    }
    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'grid', 'gridTemplateColumns' => 'repeat(' . $gridCols . ', 1fr)', 'gap' => '24px', 'width' => '100%'], $style)) . '>';
    foreach ($posts as $p) {
        $pTitle = cmsBuilderEsc((string)($p['title'] ?? 'Untitled'));
        $pSlug = cmsBuilderEsc((string)($p['slug'] ?? ''));
        $pExcerpt = cmsBuilderEsc(mb_strimwidth((string)($p['excerpt'] ?? ''), 0, $excerptLen, '...'));
        $pDate = !empty($p['published_at']) ? date('M j, Y', strtotime((string)$p['published_at'])) : '';
        $pUrl = $baseUrl . '/cms/blog/' . $pSlug;
        $html .= '<div style="background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.1);display:flex;flex-direction:column">';
        $html .= '<div style="padding:20px;flex:1"><h3 style="margin:0 0 8px;font-size:18px;font-weight:600"><a href="' . cmsBuilderEsc($pUrl) . '" style="color:#1f2937;text-decoration:none">' . $pTitle . '</a></h3>';
        if ($showDate && $pDate !== '') {
            $html .= '<div style="font-size:12px;color:#9ca3af;margin-bottom:8px">' . cmsBuilderEsc($pDate) . '</div>';
        }
        if ($showExcerpt && $pExcerpt !== '') {
            $html .= '<p style="font-size:14px;color:#6b7280;line-height:1.5;margin:0">' . $pExcerpt . '</p>';
        }
        $html .= '</div>';
        if ($showReadMore) {
            $html .= '<div style="padding:12px 20px;border-top:1px solid #f3f4f6"><a href="' . cmsBuilderEsc($pUrl) . '" style="font-size:13px;color:#3B82F6;text-decoration:none;font-weight:500">Read more &rarr;</a></div>';
        }
        $html .= '</div>';
    }
    return $html . '</div>';
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
    $orderBy     = in_array($props['orderBy'] ?? '', ['title', 'updated_at'], true) ? $props['orderBy'] : 'created_at';
    $actionText  = trim((string)($props['actionText'] ?? 'View Product'));

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
        'status'       => 'published',
    ]);
    $products = $result['items'] ?? [];

    if (empty($products)) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">No products found.</p></div>';
    }

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'grid', 'gridTemplateColumns' => 'repeat(' . $gridCols . ', 1fr)', 'gap' => '24px', 'width' => '100%'], $style)) . '>';

    foreach ($products as $p) {
        $pTitle    = cmsBuilderEsc((string)($p['title'] ?? 'Untitled'));
        $pSlug     = cmsBuilderEsc((string)($p['slug']  ?? ''));
        $pExcerpt  = cmsBuilderEsc(mb_strimwidth((string)($p['excerpt'] ?? ''), 0, $excerptLen, '...'));
        $pUrl      = $baseUrl . '/shop/product/' . $pSlug;
        $imgUrl    = (string)($p['primary_image_url'] ?? $p['featured_image_url'] ?? '');
        $pricing   = is_array($p['pricing'] ?? null) ? $p['pricing'] : [];
        $price     = isset($pricing['price']) ? number_format((float)$pricing['price'], 2) : null;
        $currency  = cmsBuilderEsc((string)($pricing['currency'] ?? 'USD'));

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
        if ($showMeta && $price !== null) {
            $html .= '<div style="font-size:18px;font-weight:700;color:#111827;margin-top:auto">' . $currency . ' ' . cmsBuilderEsc($price) . '</div>';
        }
        $html .= '</div>';

        if ($showAction) {
            $html .= '<div style="padding:12px 16px;border-top:1px solid #f3f4f6"><a href="' . cmsBuilderEsc($pUrl) . '" style="display:block;text-align:center;background:#0f172a;color:#fff;font-size:13px;font-weight:500;padding:8px 16px;border-radius:6px;text-decoration:none">' . cmsBuilderEsc($actionText) . '</a></div>';
        }

        $html .= '</div>';
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
    $entity = $context;
    $entityId = (int)($entity['id'] ?? 0);

    if ($entityId > 0) {
        try {
            $entity['capabilities'] = cmsEntityCapabilityContext($entityId);
            $entity['capability_data'] = cmsEntityCapabilityData($entityId, $entity);
        } catch (\Throwable $e) {
            $entity['capabilities'] = [];
            $entity['capability_data'] = [];
        }
    } else {
        $entity['capabilities'] = [];
        $entity['capability_data'] = [];
    }

    $entity['featured_image_url'] = cmsBuilderEntityFeaturedImageUrl($entity);

    return $entity;
}

function cmsRenderWidget_entity_view(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $entity = cmsBuilderEntityViewContext($context);
    $showFeaturedImage = ($props['showFeaturedImage'] ?? true) !== false;
    $showTitle = ($props['showTitle'] ?? true) !== false;
    $showMeta = ($props['showMeta'] ?? true) !== false;
    $showPricing = ($props['showPricing'] ?? true) !== false;
    $showInventory = ($props['showInventory'] ?? true) !== false;
    $showBody = ($props['showBody'] ?? true) !== false;

    $title = cmsBuilderEsc((string)($entity['title'] ?? 'Current Entity'));
    $excerpt = trim((string)($entity['excerpt'] ?? ''));
    $body = trim((string)($entity['body'] ?? ''));
    $contentHtml = $body !== '' ? nl2br(cmsBuilderEsc($body)) : ($excerpt !== '' ? '<p>' . cmsBuilderEsc($excerpt) . '</p>' : '');
    $publishedAt = !empty($entity['published_at']) ? date('M j, Y', strtotime((string)$entity['published_at'])) : '';
    $pricing = is_array($entity['capability_data']['pricing'] ?? null) ? $entity['capability_data']['pricing'] : [];
    $inventory = is_array($entity['capability_data']['inventory'] ?? null) ? $entity['capability_data']['inventory'] : [];

    $html = '<article' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'flex', 'flexDirection' => 'column', 'gap' => '24px', 'width' => '100%'], $style)) . '>';
    if ($showFeaturedImage && !empty($entity['featured_image_url'])) {
        $html .= '<div style="overflow:hidden;border-radius:18px;background:#e2e8f0"><img src="' . cmsBuilderEsc((string)$entity['featured_image_url']) . '" alt="' . $title . '" loading="lazy" style="display:block;width:100%;height:auto"></div>';
    }

    $html .= '<div style="display:flex;flex-direction:column;gap:12px">';
    if ($showTitle) {
        $html .= '<h2 style="margin:0;font-size:32px;line-height:1.2;color:#0f172a">' . $title . '</h2>';
    }
    if ($showMeta && $publishedAt !== '') {
        $html .= '<div style="display:flex;flex-wrap:wrap;gap:12px;font-size:13px;color:#64748b"><span>' . cmsBuilderEsc((string)($entity['type'] ?? 'content')) . '</span><span>' . cmsBuilderEsc($publishedAt) . '</span></div>';
    }
    if ($showPricing && !empty($pricing['formatted'])) {
        $html .= '<div style="font-size:15px;font-weight:700;color:#0f766e">' . cmsBuilderEsc((string)$pricing['formatted']) . '</div>';
    }
    if ($showInventory && !empty($inventory)) {
        $inventoryText = !empty($inventory['out_of_stock']) ? 'Out of stock' : (!empty($inventory['low_stock']) ? 'Low stock' : 'In stock');
        $inventoryColor = !empty($inventory['out_of_stock']) ? '#dc2626' : (!empty($inventory['low_stock']) ? '#d97706' : '#16a34a');
        $html .= '<div style="font-size:13px;font-weight:600;color:' . $inventoryColor . '">' . $inventoryText . '</div>';
    }
    $html .= '</div>';

    if ($showBody && $contentHtml !== '') {
        $html .= '<div style="font-size:15px;line-height:1.7;color:#475569">' . $contentHtml . '</div>';
    }

    return $html . '</article>';
}

function cmsRenderWidget_entity_list(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $entityType = trim((string)($props['entityType'] ?? 'post')) ?: 'post';
    $itemCount = max(1, min(12, (int)($props['itemCount'] ?? 6)));
    $gridCols = max(1, min(4, (int)($props['gridColumns'] ?? 3)));
    $layout = (string)($props['layout'] ?? 'grid');
    $showFeaturedImage = ($props['showFeaturedImage'] ?? true) !== false;
    $showExcerpt = ($props['showExcerpt'] ?? true) !== false;
    $showPricing = ($props['showPricing'] ?? true) !== false;
    $showInventory = ($props['showInventory'] ?? true) !== false;
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
        $items = [];
    }

    if ($items === []) {
        return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '><p style="color:#6b7280;text-align:center;padding:24px">' . $emptyMessage . '</p></div>';
    }

    $baseUrl = rtrim((string)(defined('BASE_URL') ? BASE_URL : ''), '/');
    $wrapperStyle = array_merge([
        'display' => 'grid',
        'gridTemplateColumns' => $layout === 'list' ? '1fr' : 'repeat(' . $gridCols . ', 1fr)',
        'gap' => '24px',
        'width' => '100%',
    ], $style);
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($wrapperStyle) . '>';

    foreach ($items as $item) {
        $entityId = (int)($item['id'] ?? 0);
        try {
            $capabilityData = $entityId > 0 ? cmsEntityCapabilityData($entityId, $item) : [];
        } catch (\Throwable $e) {
            $capabilityData = [];
        }

        $pricing = is_array($capabilityData['pricing'] ?? null) ? $capabilityData['pricing'] : [];
        $inventory = is_array($capabilityData['inventory'] ?? null) ? $capabilityData['inventory'] : [];
        $imageUrl = !empty($item['featured_image']) && function_exists('cmsResolveUploadUrl') ? cmsResolveUploadUrl((string)$item['featured_image']) : '';
        $itemUrl = $baseUrl . '/cms/' . rawurlencode($entityType) . '/' . rawurlencode((string)($item['slug'] ?? ''));

        $html .= '<a href="' . cmsBuilderEsc($itemUrl) . '" style="display:block;text-decoration:none;background:#ffffff;border:1px solid #e2e8f0;border-radius:18px;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,0.06)">';
        if ($showFeaturedImage && $imageUrl !== '') {
            $html .= '<div style="aspect-ratio:16 / 9;overflow:hidden;background:#e2e8f0"><img src="' . cmsBuilderEsc($imageUrl) . '" alt="' . cmsBuilderEsc((string)($item['title'] ?? '')) . '" loading="lazy" style="display:block;width:100%;height:100%;object-fit:cover"></div>';
        }
        $html .= '<div style="padding:16px;display:flex;flex-direction:column;gap:10px">';
        $html .= '<h3 style="margin:0;font-size:18px;line-height:1.35;color:#0f172a">' . cmsBuilderEsc((string)($item['title'] ?? 'Untitled')) . '</h3>';
        if ($showExcerpt && !empty($item['excerpt'])) {
            $html .= '<p style="margin:0;font-size:14px;line-height:1.6;color:#64748b">' . cmsBuilderEsc((string)$item['excerpt']) . '</p>';
        }
        if ($showPricing && !empty($pricing['formatted'])) {
            $html .= '<div style="font-size:13px;font-weight:700;color:#0f766e">' . cmsBuilderEsc((string)$pricing['formatted']) . '</div>';
        }
        if ($showInventory && !empty($inventory)) {
            $inventoryText = !empty($inventory['out_of_stock']) ? 'Out of stock' : (!empty($inventory['low_stock']) ? 'Low stock' : 'In stock');
            $inventoryColor = !empty($inventory['out_of_stock']) ? '#dc2626' : (!empty($inventory['low_stock']) ? '#d97706' : '#16a34a');
            $html .= '<div style="font-size:12px;font-weight:600;color:' . $inventoryColor . '">' . $inventoryText . '</div>';
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
    $flexDir = $ctaLayout === 'vertical' ? 'column' : 'row';
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['padding' => '48px', 'backgroundColor' => '#3b82f6', 'borderRadius' => '16px', 'color' => '#ffffff', 'display' => 'flex', 'flexDirection' => $flexDir, 'alignItems' => 'center', 'justifyContent' => 'space-between', 'gap' => '24px'], $style)) . '>';
    $html .= '<div' . ($ctaLayout !== 'vertical' ? ' style="flex:1"' : '') . '>';
    $html .= '<h2 style="font-size:28px;font-weight:700;margin:0 0 8px;color:inherit">' . $ctaTitle . '</h2>';
    if ($ctaDesc !== '') $html .= '<p style="font-size:16px;margin:0;opacity:0.9;color:inherit">' . $ctaDesc . '</p>';
    $html .= '</div><div style="display:flex;gap:12px;flex-shrink:0">';
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
    $fDesc = cmsBuilderEsc((string)($props['frontDescription'] ?? ''));
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
    $html = '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['display' => 'grid', 'gridTemplateColumns' => 'repeat(' . $lgCols . ', 1fr)', 'gap' => '32px', 'alignItems' => 'center'], $style)) . '>';
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

function cmsRenderWidget_search_box(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    $sbPlaceholder = cmsBuilderEsc((string)($props['placeholder'] ?? 'Search...'));
    $sbBtnText = cmsBuilderEsc((string)($props['buttonText'] ?? 'Search'));
    $sbShowBtn = ($props['showButton'] ?? true) !== false;
    $sbUrl = cmsBuilderEsc((string)($props['searchUrl'] ?? '/search'));
    $sbInputStyle = (string)($props['style'] ?? 'rounded');
    $borderRadiusMap = ['rounded' => '8px', 'square' => '0', 'pill' => '999px'];
    $inputBorderRadius = $borderRadiusMap[$sbInputStyle] ?? '8px';
    return '<form' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr(array_merge(['maxWidth' => '500px', 'width' => '100%', 'display' => 'flex', 'gap' => '8px'], $style)) . ' action="' . $sbUrl . '" method="get">'
        . '<input type="search" name="q" placeholder="' . $sbPlaceholder . '" style="flex:1;padding:10px 16px;border:1px solid #d1d5db;border-radius:' . $inputBorderRadius . ';font-size:14px;outline:none">'
        . ($sbShowBtn ? '<button type="submit" style="padding:10px 20px;background-color:#3B82F6;color:#fff;border:none;border-radius:' . $inputBorderRadius . ';font-weight:500;font-size:14px;cursor:pointer">' . $sbBtnText . '</button>' : '')
        . '</form>';
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
    $html .= '<div style="display:flex;justify-content:space-between;align-items:center;padding:8px 16px;background:' . ($theme === 'light' ? '#e2e8f0' : '#181825') . ';font-size:12px;color:' . ($theme === 'light' ? '#64748b' : '#a6adc8') . '">' . $lang . $headerRight . '</div>';
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
 * Default renderer for unknown widget types.
 */
function cmsRenderWidget_default(array $props, array $style, array $attrs, string $children, array $node, array $context): string
{
    return '<div' . cmsBuilderAttrString($attrs) . cmsBuilderStyleAttr($style) . '>' . ($children !== '' ? $children : cmsBuilderEsc(cmsBuilderNodeContent($props))) . '</div>';
}
