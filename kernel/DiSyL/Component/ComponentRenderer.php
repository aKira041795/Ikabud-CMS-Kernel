<?php
/**
 * DiSyL Component Renderers
 *
 * Extracted from TemplateEngine (D8 refactor) — the governed component HTML
 * builders plus entity-view / AI / report / state / island renderers.
 * Purely a rendering library: all TemplateEngine state it needs is reached
 * through the injected engine reference (custom component registry, debug
 * flag, error logging, AI provider factory).
 *
 * @package Ikabud\Kernel\DiSyL\Component
 */

namespace Ikabud\Kernel\DiSyL\Component;

use Ikabud\Kernel\DiSyL\TemplateEngine;

final class ComponentRenderer
{
        private TemplateEngine $engine;

    public function __construct(TemplateEngine $engine)
    {
        $this->engine = $engine;
    }

    /**
     * Render a component error state (AI disabled, policy denied, provider
     * error). Ported from DefaultEntityRenderer::entityErrorState to fix the
     * undefined-method latent bug where TemplateEngine::renderAiSummary /
     * renderAiAssist called a method that did not exist on TemplateEngine.
     */
    public function entityErrorState(string $message, string $class = ''): string
    {
        $safeMsg = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        return <<<HTML
        <div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg {$class}">
            <div class="text-center">
                <svg class="mx-auto h-8 w-8 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4.5c-.77-.833-2.694-.833-3.464 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path></svg>
                <p class="mt-2 text-sm text-red-600">{$safeMsg}</p>
            </div>
        </div>
        HTML;
    }
    public function renderComponent(string $component, array $attrs, string $children, array $context): string
    {
        if (isset($this->engine->internalComponents()[$component])) {
            return call_user_func($this->engine->internalComponents()[$component], $attrs, $children, $context);
        }

        return match($component) {
            'ikb_section' => $this->renderSection($attrs, $children),
            'ikb_container' => $this->renderContainer($attrs, $children),
            'ikb_grid' => $this->renderGrid($attrs, $children),
            'ikb_card' => $this->renderCard($attrs, $children),
            'ikb_text' => $this->renderText($attrs, $children),
            'ikb_button' => $this->renderButton($attrs, $children),
            'ikb_badge' => $this->renderBadge($attrs, $children),
            'ikb_input' => $this->renderInput($attrs),
            'ikb_textarea' => $this->renderTextarea($attrs, $children),
            'ikb_select' => $this->renderSelect($attrs, $children),
            'ikb_icon' => $this->renderIcon($attrs),
            'ikb_image' => $this->renderImage($attrs),
            'ikb_link' => $this->renderLink($attrs, $children),
            'ikb_table' => $this->renderTable($attrs, $children),
            'ikb_modal' => $this->renderModal($attrs, $children),
            'ikb_alert' => $this->renderAlert($attrs, $children),
            'ikb_spinner' => $this->renderSpinner($attrs),
            'ikb_component' => $this->renderIkbComponent($attrs, $children, $context),
            'ikb_entity_view' => $this->renderEntityViewConfig($attrs, $children, $context),
            'state' => $this->renderStateDeclaration($attrs, $children, $context),
            'ikb_entity_list' => $this->renderEntityListViaService($attrs, $children, $context),
            'ikb_entity_detail' => $this->renderEntityDetailViaService($attrs, $children, $context),
            'ikb_export_button' => $this->renderExportButton($attrs, $children),
            'ikb_form' => $this->renderForm($attrs, $children, $context),
            'ikb_stat_card' => $this->renderStatCard($attrs, $children),
            'ikb_timeline' => $this->renderTimeline($attrs, $children),
            'ikb_confirm_action' => $this->renderConfirmAction($attrs, $children),
            'ikb_panel' => $this->renderPanel($attrs, $children),
            'ikb_region' => $this->renderRegion($attrs, $children, $context),
            'ikb_slot' => $this->renderSlot($attrs, $children, $context),
            'ikb_drawer' => $this->renderDrawer($attrs, $children),
            'ikb_audit_log' => $this->renderAuditLog($attrs, $children, $context),
            'ikb_ai_summary' => $this->renderAiSummary($attrs, $children, $context),
            'ikb_ai_assist' => $this->renderAiAssist($attrs, $children, $context),
            'ikb_report' => $this->renderReport($attrs, $children, $context),
            'ikb_signature_block' => $this->renderSignatureBlock($attrs, $children),
            'island' => $this->renderIsland($attrs, $children),
            default => $this->renderUnknownComponent($component, $children),
        };
    }

    /** @var array<string>|null Lazily-built list of known governed component names for typo suggestions */
    private static ?array $knownGovernedComponents = null;

    /**
     * Get the list of known governed component names (for typo suggestions).
     */
    private function getKnownGovernedComponents(): array
    {
        if (self::$knownGovernedComponents !== null) {
            return self::$knownGovernedComponents;
        }

        // Built-in governed components from the renderComponent match block
        $governed = [
            'ikb_section',
            'ikb_container',
            'ikb_grid',
            'ikb_card',
            'ikb_text',
            'ikb_button',
            'ikb_badge',
            'ikb_input',
            'ikb_textarea',
            'ikb_select',
            'ikb_icon',
            'ikb_image',
            'ikb_link',
            'ikb_table',
            'ikb_modal',
            'ikb_alert',
            'ikb_spinner',
            'ikb_component',
            'ikb_entity_view',
            'ikb_entity_list',
            'ikb_entity_detail',
            'ikb_export_button',
            'ikb_form',
            'ikb_stat_card',
            'ikb_timeline',
            'ikb_confirm_action',
            'ikb_panel',
            'ikb_drawer',
            'ikb_audit_log',
            'ikb_ai_summary',
            'ikb_ai_assist',
            'ikb_report',
            'ikb_signature_block',
            'ikb_region',
            'ikb_slot',
        ];

        // Add any custom registered components
        foreach (array_keys($this->engine->internalComponents()) as $custom) {
            if (!in_array($custom, $governed, true)) {
                $governed[] = $custom;
            }
        }

        self::$knownGovernedComponents = $governed;
        return $governed;
    }

    /**
     * Find the closest matching known component name by Levenshtein distance.
     *
     * @param string $input The unknown component name
     * @param array<string> $candidates List of known component names
     * @return string|null The closest match, or null if distance is too large
     */
    private function findClosestComponent(string $input, array $candidates): ?string
    {
        $best = null;
        $bestDist = PHP_INT_MAX;

        foreach ($candidates as $candidate) {
            $dist = levenshtein($input, $candidate);
            if ($dist < $bestDist) {
                $bestDist = $dist;
                $best = $candidate;
            }
        }

        // Only suggest if the distance is within reasonable threshold
        $threshold = max(3, (int)(strlen($input) * 0.4));
        if ($bestDist > 0 && $bestDist <= $threshold) {
            return $best;
        }

        return null;
    }

    /**
     * Handle unknown/unregistered component names.
     * Logs a warning, suggests the closest known component, and returns a visible
     * HTML comment so developers catch typos.
     */
    private function renderUnknownComponent(string $component, string $children): string
    {
        if (str_starts_with($component, 'ikb_') || $component === 'state' || $component === 'island') {
            $suggestion = $this->findClosestComponent($component, $this->getKnownGovernedComponents());
            $msg = "Unknown component '{$component}' — not registered.";
            if ($suggestion !== null) {
                $msg .= " Did you mean '{$suggestion}'?";
            } else {
                $msg .= " Check for typos. If using a custom component, register it via ComponentRegistry::register().";
            }
            $this->engine->logError($msg);
            return "<!-- Unknown DiSyL component: {$component} -->";
        }
        return $children;
    }

    private function buildHtmxAttrs(array $attrs): string
    {
        $htmxAttrs = [];
        $htmxKeys = ['hx-get', 'hx-post', 'hx-put', 'hx-delete', 'hx-patch',
                     'hx-trigger', 'hx-target', 'hx-swap', 'hx-push-url',
                     'hx-select', 'hx-indicator', 'hx-confirm', 'hx-vals',
                     'hx-boost', 'hx-ext', 'hx-headers', 'hx-include',
                     'hx-params', 'hx-preserve', 'hx-prompt', 'hx-replace-url'];
        
        foreach ($htmxKeys as $key) {
            $camelKey = str_replace('-', '_', $key);
            $value = $attrs[$key] ?? $attrs[$camelKey] ?? null;
            
            if ($value !== null) {
                // For all HTMX attributes, use double quotes and htmlspecialchars to prevent
                // attribute injection regardless of the attribute value's content.
                $htmxAttrs[] = "{$key}=\"" . htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8') . "\"";
            }
        }
        
        return !empty($htmxAttrs) ? ' ' . implode(' ', $htmxAttrs) : '';
    }
    
    /**
     * Sanitize a URL for use in an href attribute.
     * Rejects javascript:, vbscript:, and data: schemes to prevent XSS.
     */
    private function sanitizeHref(string $href): string
    {
        $href = trim($href);
        if ($href === '') {
            return '#';
        }
        // Block protocol-relative URLs (//evil.com) that bypass parse_url scheme detection
        if (str_starts_with($href, '//')) {
            return '#';
        }
        // Check scheme — strip everything before first colon and compare
        $scheme = strtolower((string) parse_url($href, PHP_URL_SCHEME));
        if ($scheme !== '' && !in_array($scheme, ['http', 'https', 'mailto', 'tel', 'ftp'], true)) {
            return '#';
        }
        return htmlspecialchars($href, ENT_QUOTES, 'UTF-8');
    }

    private function renderSection(array $attrs, string $children): string
    {
        $padding = $attrs['padding'] ?? 'medium';
        $bg = $attrs['background'] ?? '';
        $class = $attrs['class'] ?? '';
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string) $attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        
        $paddingClass = match($padding) {
            'none' => '', 'small' => 'py-4', 'medium' => 'py-8',
            'large' => 'py-12', 'xlarge' => 'py-16', default => 'py-8',
        };
        
        $bgClass = match($bg) {
            'white' => 'bg-white', 'gray' => 'bg-gray-50',
            'dark' => 'bg-gray-900 text-white', 'primary' => 'bg-indigo-600 text-white',
            'gradient' => 'bg-gradient-to-br from-indigo-500 to-purple-600 text-white',
            default => '',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<section{$id} class=\"{$paddingClass} {$bgClass} {$class}\"{$htmx}>{$children}</section>";
    }
    
    private function renderContainer(array $attrs, string $children): string
    {
        $size = $attrs['size'] ?? 'large';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'small' => 'max-w-2xl', 'medium' => 'max-w-4xl', 'large' => 'max-w-6xl',
            'xlarge' => 'max-w-7xl', 'full' => 'max-w-full', default => 'max-w-6xl',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div class=\"container mx-auto px-4 {$sizeClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderGrid(array $attrs, string $children): string
    {
        $columns = $attrs['columns'] ?? '3';
        $gap = $attrs['gap'] ?? 'medium';
        $class = $attrs['class'] ?? '';
        
        $colClass = "grid-cols-1 md:grid-cols-{$columns}";
        $gapClass = match($gap) {
            'none' => 'gap-0', 'small' => 'gap-2', 'medium' => 'gap-4',
            'large' => 'gap-6', 'xlarge' => 'gap-8', default => 'gap-4',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div class=\"grid {$colClass} {$gapClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderCard(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'default';
        $padding = $attrs['padding'] ?? 'medium';
        $class = $attrs['class'] ?? '';
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string) $attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        
        $variantClass = match($variant) {
            'elevated' => 'bg-white shadow-lg hover:shadow-xl transition-shadow',
            'outlined' => 'bg-white border border-gray-200',
            'flat' => 'bg-gray-50', 'stat' => 'bg-white shadow rounded-lg text-center',
            default => 'bg-white shadow rounded-lg',
        };
        
        $paddingClass = match($padding) {
            'none' => '', 'small' => 'p-3', 'medium' => 'p-4', 'large' => 'p-6', default => 'p-4',
        };
        
        $htmx = $this->buildHtmxAttrs($attrs);
        return "<div{$id} class=\"rounded-lg {$variantClass} {$paddingClass} {$class}\"{$htmx}>{$children}</div>";
    }
    
    private function renderText(array $attrs, string $children): string
    {
        $allowedTags = ['p', 'span', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'strong', 'em', 'small', 'label', 'li', 'dt', 'dd', 'figcaption'];
        $requestedTag = (string) ($attrs['tag'] ?? 'p');
        $tag = in_array($requestedTag, $allowedTags, true) ? $requestedTag : 'p';
        $size = $attrs['size'] ?? 'base';
        $weight = $attrs['weight'] ?? 'normal';
        $color = $attrs['color'] ?? '';
        $align = $attrs['align'] ?? '';
        $class = $attrs['class'] ?? '';
        $bind = $attrs['bind'] ?? '';
        
        $sizeClass = match($size) {
            'xs' => 'text-xs', 'sm' => 'text-sm', 'base' => 'text-base',
            'lg' => 'text-lg', 'xl' => 'text-xl', '2xl' => 'text-2xl',
            '3xl' => 'text-3xl', '4xl' => 'text-4xl', default => 'text-base',
        };
        
        $weightClass = match($weight) {
            'light' => 'font-light', 'normal' => 'font-normal', 'medium' => 'font-medium',
            'semibold' => 'font-semibold', 'bold' => 'font-bold', default => '',
        };
        
        $colorClass = match($color) {
            'muted' => 'text-gray-500', 'primary' => 'text-indigo-600',
            'success' => 'text-green-600', 'warning' => 'text-yellow-600',
            'danger' => 'text-red-600', 'white' => 'text-white', default => '',
        };
        
        $alignClass = match($align) {
            'left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right', default => '',
        };
        
        $classAttr = "{$sizeClass} {$weightClass} {$colorClass} {$alignClass} {$class}";
        
        // If bind attribute is set, emit framework-neutral binding via current bridge
        if ($bind !== '') {
            $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
            $bindAttr = $bridge->renderBind($bind);
            $classAttr = trim($classAttr) !== '' ? " class=\"" . trim($classAttr) . "\"" : '';
            return "<{$tag}{$bindAttr}{$classAttr}>{$children}</{$tag}>";
        }
        
        return "<{$tag} class=\"{$classAttr}\">{$children}</{$tag}>";
    }
    
    private function renderButton(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'primary';
        $size = $attrs['size'] ?? 'medium';
        $href = $attrs['href'] ?? '';
        $type = $attrs['type'] ?? 'button';
        $class = $attrs['class'] ?? '';
        $disabled = isset($attrs['disabled']) && $attrs['disabled'];
        
        $variantClass = match($variant) {
            'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700',
            'secondary' => 'bg-gray-200 text-gray-800 hover:bg-gray-300',
            'success' => 'bg-green-600 text-white hover:bg-green-700',
            'danger' => 'bg-red-600 text-white hover:bg-red-700',
            'warning' => 'bg-yellow-500 text-white hover:bg-yellow-600',
            'outline' => 'border border-indigo-600 text-indigo-600 hover:bg-indigo-50',
            'ghost' => 'text-gray-600 hover:bg-gray-100',
            'link' => 'text-indigo-600 hover:underline',
            default => 'bg-indigo-600 text-white hover:bg-indigo-700',
        };
        
        $sizeClass = match($size) {
            'small' => 'px-3 py-1.5 text-sm', 'medium' => 'px-4 py-2',
            'large' => 'px-6 py-3 text-lg', default => 'px-4 py-2',
        };
        
        $disabledClass = $disabled ? 'opacity-50 cursor-not-allowed' : '';
        $disabledAttr = $disabled ? ' disabled' : '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        if ($href && !$disabled) {
            $safeHref = $this->sanitizeHref($href);
            return "<a href=\"{$safeHref}\" class=\"inline-flex items-center justify-center rounded-lg font-medium transition {$variantClass} {$sizeClass} {$class}\"{$htmx}>{$children}</a>";
        }
        
        return "<button type=\"{$type}\" class=\"inline-flex items-center justify-center rounded-lg font-medium transition {$variantClass} {$sizeClass} {$disabledClass} {$class}\"{$disabledAttr}{$htmx}>{$children}</button>";
    }
    
    private function renderBadge(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'default';
        $class = $attrs['class'] ?? '';
        
        $variantClass = match($variant) {
            'primary' => 'bg-indigo-100 text-indigo-800',
            'success' => 'bg-green-100 text-green-800',
            'warning' => 'bg-yellow-100 text-yellow-800',
            'danger', 'critical', 'high' => 'bg-red-100 text-red-800',
            'info' => 'bg-blue-100 text-blue-800',
            'medium' => 'bg-yellow-100 text-yellow-800',
            'low' => 'bg-gray-100 text-gray-800',
            'open' => 'bg-blue-100 text-blue-800',
            'in_progress' => 'bg-indigo-100 text-indigo-800',
            'closed' => 'bg-green-100 text-green-800',
            'on_hold' => 'bg-yellow-100 text-yellow-800',
            'scheduled' => 'bg-blue-100 text-blue-800',
            'completed' => 'bg-green-100 text-green-800',
            'cancelled' => 'bg-red-100 text-red-800',
            'no_show' => 'bg-yellow-100 text-yellow-800',
            default => 'bg-gray-100 text-gray-800',
        };
        
        return "<span class=\"inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium {$variantClass} {$class}\">{$children}</span>";
    }
    
    private function renderInput(array $attrs): string
    {
        $type = $attrs['type'] ?? 'text';
        $name = $attrs['name'] ?? '';
        $id = $attrs['id'] ?? $name;
        $value = htmlspecialchars($attrs['value'] ?? '', ENT_QUOTES);
        $placeholder = htmlspecialchars($attrs['placeholder'] ?? '', ENT_QUOTES);
        $required = isset($attrs['required']) ? ' required' : '';
        $disabled = isset($attrs['disabled']) ? ' disabled' : '';
        $class = $attrs['class'] ?? '';
        $model = $attrs['model'] ?? '';
        $htmx = $this->buildHtmxAttrs($attrs);
        
        // If model attribute is set, emit framework-neutral binding via current bridge
        if ($model !== '') {
            $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
            $modelAttr = $bridge->renderModel($model);
            return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$disabled}{$modelAttr}{$htmx}>";
        }
        
        return "<input type=\"{$type}\" id=\"{$id}\" name=\"{$name}\" value=\"{$value}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$disabled}{$htmx}>";
    }
    
    private function renderTextarea(array $attrs, string $children): string
    {
        $name = htmlspecialchars($attrs['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars($attrs['id'] ?? $name, ENT_QUOTES, 'UTF-8');
        $rows = (int)($attrs['rows'] ?? 4);
        $placeholder = htmlspecialchars($attrs['placeholder'] ?? '', ENT_QUOTES, 'UTF-8');
        $required = isset($attrs['required']) ? ' required' : '';
        $class = htmlspecialchars($attrs['class'] ?? '', ENT_QUOTES, 'UTF-8');
        $escapedChildren = htmlspecialchars($children, ENT_QUOTES, 'UTF-8');
        
        return "<textarea id=\"{$id}\" name=\"{$name}\" rows=\"{$rows}\" placeholder=\"{$placeholder}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}>{$escapedChildren}</textarea>";
    }
    
    private function renderSelect(array $attrs, string $children): string
    {
        $name = htmlspecialchars($attrs['name'] ?? '', ENT_QUOTES, 'UTF-8');
        $id = htmlspecialchars($attrs['id'] ?? $name, ENT_QUOTES, 'UTF-8');
        $required = isset($attrs['required']) ? ' required' : '';
        $class = htmlspecialchars($attrs['class'] ?? '', ENT_QUOTES, 'UTF-8');
        $htmx = $this->buildHtmxAttrs($attrs);
        
        return "<select id=\"{$id}\" name=\"{$name}\" class=\"w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 {$class}\"{$required}{$htmx}>{$children}</select>";
    }
    
    private function renderIcon(array $attrs): string
    {
        $name = htmlspecialchars($attrs['name'] ?? 'circle', ENT_QUOTES, 'UTF-8');
        $size = $attrs['size'] ?? 'md';
        $class = htmlspecialchars($attrs['class'] ?? '', ENT_QUOTES, 'UTF-8');
        
        $sizeClass = match($size) {
            'sm' => 'w-4 h-4', 'md' => 'w-5 h-5', 'lg' => 'w-6 h-6', 'xl' => 'w-8 h-8', default => 'w-5 h-5',
        };
        
        return "<i class=\"fas fa-{$name} {$sizeClass} {$class}\"></i>";
    }
    
    private function renderImage(array $attrs): string
    {
        $src = htmlspecialchars($attrs['src'] ?? '', ENT_QUOTES);
        $alt = htmlspecialchars($attrs['alt'] ?? '', ENT_QUOTES);
        $class = $attrs['class'] ?? '';
        
        return "<img src=\"{$src}\" alt=\"{$alt}\" class=\"{$class}\" loading=\"lazy\">";
    }
    
    private function renderLink(array $attrs, string $children): string
    {
        $href = $this->sanitizeHref($attrs['href'] ?? '#');
        $class = htmlspecialchars($attrs['class'] ?? 'text-indigo-600 hover:underline', ENT_QUOTES, 'UTF-8');
        $htmx = $this->buildHtmxAttrs($attrs);
        
        return "<a href=\"{$href}\" class=\"{$class}\"{$htmx}>{$children}</a>";
    }
    
    private function renderTable(array $attrs, string $children): string
    {
        $class = $attrs['class'] ?? '';
        return "<div class=\"overflow-x-auto\"><table class=\"min-w-full divide-y divide-gray-200 {$class}\">{$children}</table></div>";
    }
    
    private function renderModal(array $attrs, string $children): string
    {
        $id = htmlspecialchars($attrs['id'] ?? 'modal', ENT_QUOTES, 'UTF-8');
        $title = htmlspecialchars($attrs['title'] ?? '', ENT_QUOTES, 'UTF-8');
        $size = $attrs['size'] ?? 'medium';
        
        $sizeClass = match($size) {
            'small' => 'max-w-md', 'medium' => 'max-w-lg', 'large' => 'max-w-2xl',
            'xlarge' => 'max-w-4xl', default => 'max-w-lg',
        };
        
        return "<div id=\"{$id}\" class=\"hidden fixed inset-0 z-50 overflow-y-auto\" aria-modal=\"true\">
            <div class=\"flex items-center justify-center min-h-screen px-4\">
                <div class=\"fixed inset-0 bg-black bg-opacity-50\" onclick=\"document.getElementById('{$id}').classList.add('hidden')\"></div>
                <div class=\"relative bg-white rounded-lg shadow-xl {$sizeClass} w-full\">
                    <div class=\"flex items-center justify-between p-4 border-b\">
                        <h3 class=\"text-lg font-semibold\">{$title}</h3>
                        <button onclick=\"document.getElementById('{$id}').classList.add('hidden')\" class=\"text-gray-400 hover:text-gray-600\">
                            <i class=\"fas fa-times\"></i>
                        </button>
                    </div>
                    <div class=\"p-4\">{$children}</div>
                </div>
            </div>
        </div>";
    }
    
    private function renderAlert(array $attrs, string $children): string
    {
        $variant = $attrs['variant'] ?? 'info';
        $class = $attrs['class'] ?? '';
        
        $config = match($variant) {
            'success' => ['bg-green-50 border-green-500 text-green-800', 'check-circle'],
            'warning' => ['bg-yellow-50 border-yellow-500 text-yellow-800', 'exclamation-triangle'],
            'danger', 'error' => ['bg-red-50 border-red-500 text-red-800', 'exclamation-circle'],
            default => ['bg-blue-50 border-blue-500 text-blue-800', 'info-circle'],
        };
        
        return "<div class=\"flex items-start p-4 border-l-4 rounded-r-lg {$config[0]} {$class}\">
            <i class=\"fas fa-{$config[1]} mr-3 mt-0.5\"></i>
            <div>{$children}</div>
        </div>";
    }
    
    private function renderSpinner(array $attrs): string
    {
        $size = $attrs['size'] ?? 'md';
        $class = $attrs['class'] ?? '';
        
        $sizeClass = match($size) {
            'sm' => 'w-4 h-4', 'md' => 'w-6 h-6', 'lg' => 'w-8 h-8', default => 'w-6 h-6',
        };
        
        return "<div class=\"animate-spin rounded-full border-2 border-gray-300 border-t-indigo-600 {$sizeClass} {$class}\"></div>";
    }


    /**
     * Render an export button — generates downloadable exports from entity data.
     *
     * Attributes:
     *   source   — entity type (e.g. "orders", "cases", "ledger")
     *   format   — export format: pdf, docx, csv, xlsx (default: csv)
     *   label    — button label (default: "Export {format}")
     *   variant  — button variant: primary, outline, secondary (default: outline)
     *   size     — button size: small, medium, large (default: medium)
     *   class    — additional CSS classes
     */
    private function renderExportButton(array $attrs, string $children): string
    {
        $source = (string)($attrs['source'] ?? '');
        $format = strtolower((string)($attrs['format'] ?? 'csv'));
        $label = (string)($attrs['label'] ?? '');
        $variant = (string)($attrs['variant'] ?? 'outline');
        $size = (string)($attrs['size'] ?? 'medium');
        $class = (string)($attrs['class'] ?? '');

        if ($source === '') {
            // No source — render a disabled placeholder
            $safeLabel = htmlspecialchars($label ?: 'Export', ENT_QUOTES, 'UTF-8');
            return "<button type=\"button\" class=\"ikb-export-btn opacity-50 cursor-not-allowed inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 text-gray-500 {$class}\" disabled>"
                . '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>'
                . "{$safeLabel}</button>";
        }

        $safeFormat = htmlspecialchars($format, ENT_QUOTES, 'UTF-8');
        $safeSource = htmlspecialchars($source, ENT_QUOTES, 'UTF-8');

        if ($label === '') {
            $label = 'Export ' . strtoupper($format);
        }
        $safeLabel = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');

        $variantClass = match ($variant) {
            'primary' => 'bg-indigo-600 text-white hover:bg-indigo-700 border-indigo-600',
            'secondary' => 'bg-gray-200 text-gray-800 hover:bg-gray-300 border-gray-200',
            default => 'border border-gray-300 text-gray-700 hover:bg-gray-50',
        };

        $sizeClass = match ($size) {
            'small' => 'px-3 py-1.5 text-xs',
            'large' => 'px-6 py-3 text-base',
            default => 'px-4 py-2 text-sm',
        };

        $icon = '<svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path></svg>';

        // Export URL: /api/v1/export?source={source}&format={format}
        $exportUrl = htmlspecialchars("/api/v1/export?source={$safeSource}&format={$safeFormat}", ENT_QUOTES, 'UTF-8');

        return "<a href=\"{$exportUrl}\" "
            . "class=\"ikb-export-btn inline-flex items-center gap-2 rounded-lg font-medium transition {$variantClass} {$sizeClass} {$class}\" "
            . "data-export-source=\"{$safeSource}\" data-export-format=\"{$safeFormat}\" "
            . "download>"
            . "{$icon}{$safeLabel}</a>";
    }

    /**
     * Render a governed form component.
     *
     * Attributes:
     *   action   — capability action ID (e.g. "ticket.create", "order.submit")
     *   method   — POST (default) or GET
     *   layout   — form layout: stacked, inline, guided (default: stacked)
     *   csrf     — include CSRF token (default: true)
     *   class    — additional CSS classes
     *   id       — form element id
     *   hx-*     — HTMX attributes pass through
     */
    private function renderForm(array $attrs, string $children, array $context): string
    {
        $action = (string)($attrs['action'] ?? '');
        $method = strtoupper((string)($attrs['method'] ?? 'post'));
        $layout = (string)($attrs['layout'] ?? 'stacked');
        $includeCsrf = !isset($attrs['csrf']) || $attrs['csrf'];
        $class = (string)($attrs['class'] ?? '');
        $id = isset($attrs['id']) ? ' id="' . htmlspecialchars((string)$attrs['id'], ENT_QUOTES, 'UTF-8') . '"' : '';
        $htmx = $this->buildHtmxAttrs($attrs);

        if ($method !== 'GET' && $method !== 'POST') {
            $method = 'POST';
        }

        // Build form action URL from capability action
        $formAction = '';
        if ($action !== '') {
            $safeAction = htmlspecialchars($action, ENT_QUOTES, 'UTF-8');
            // Route to the capability handler endpoint
            $formAction = htmlspecialchars("/api/v1/capability/{$safeAction}", ENT_QUOTES, 'UTF-8');
        } else {
            $formAction = '#';
        }

        $layoutClass = match ($layout) {
            'inline' => 'ikb-form--inline flex flex-wrap items-end gap-4',
            'guided' => 'ikb-form--guided space-y-6',
            default => 'ikb-form--stacked space-y-4',
        };

        $csrfHtml = '';
        if ($includeCsrf && $method === 'POST') {
            // Try to inject CSRF token from context or app
            $token = '';
            if (isset($context['csrf_token'])) {
                $token = (string)$context['csrf_token'];
            } elseif (\function_exists('app') && ($a = \app()) !== null && method_exists($a, 'csrfToken')) {
                $token = (string)$a->csrfToken();
            }
            if ($token !== '') {
                $safeToken = htmlspecialchars($token, ENT_QUOTES, 'UTF-8');
                $csrfHtml = "<input type=\"hidden\" name=\"_token\" value=\"{$safeToken}\">";
            }
        }

        $methodOverride = '';
        if ($method === 'GET') {
            $methodOverride = '';
        }

        return <<<HTML
        <form{$id} method="{$method}" action="{$formAction}" class="ikb-form {$layoutClass} {$class}"{$htmx}>
            {$csrfHtml}
            {$methodOverride}
            {$children}
        </form>
        HTML;
    }

    /**
     * Render a stat card — single metric with label, value, and optional trend.
     *
     * Attributes:
     *   label    — stat label (e.g. "Total Orders")
     *   value    — stat value (e.g. "1,234")
     *   trend    — direction: up, down, neutral (default: none)
     *   trend_value — percentage or absolute change (e.g. "+12%")
     *   icon     — FontAwesome icon name (e.g. "shopping-cart")
     *   variant  — card variant: elevated, outlined, flat (default: elevated)
     *   class    — additional CSS classes
     */
    private function renderStatCard(array $attrs, string $children): string
    {
        $label = htmlspecialchars((string)($attrs['label'] ?? 'Stat'), ENT_QUOTES, 'UTF-8');
        $value = htmlspecialchars((string)($attrs['value'] ?? '—'), ENT_QUOTES, 'UTF-8');
        $trend = (string)($attrs['trend'] ?? '');
        $trendValue = htmlspecialchars((string)($attrs['trend_value'] ?? ''), ENT_QUOTES, 'UTF-8');
        $icon = (string)($attrs['icon'] ?? '');
        $variant = (string)($attrs['variant'] ?? 'elevated');
        $class = (string)($attrs['class'] ?? '');

        $variantClass = match ($variant) {
            'outlined' => 'bg-white border border-gray-200',
            'flat' => 'bg-gray-50',
            default => 'bg-white shadow-sm border border-gray-100',
        };

        $trendHtml = '';
        if ($trend !== '' && $trendValue !== '') {
            $trendColors = match ($trend) {
                'up' => 'text-green-600',
                'down' => 'text-red-600',
                default => 'text-gray-500',
            };
            $trendIcon = match ($trend) {
                'up' => 'fa-arrow-up',
                'down' => 'fa-arrow-down',
                default => 'fa-minus',
            };
            $trendHtml = <<<TREND
            <div class="ikb-stat-trend flex items-center gap-1 mt-1 text-xs font-medium {$trendColors}">
                <i class="fas {$trendIcon}"></i>
                <span>{$trendValue}</span>
            </div>
            TREND;
        }

        $iconHtml = '';
        if ($icon !== '') {
            $iconHtml = "<div class=\"ikb-stat-icon w-10 h-10 rounded-lg bg-indigo-50 flex items-center justify-center flex-shrink-0\"><i class=\"fas fa-{$icon} text-indigo-600\"></i></div>";
        }

        $slotHtml = trim($children) !== '' ? "<div class=\"mt-2\">{$children}</div>" : '';

        return <<<HTML
        <div class="ikb-stat-card rounded-xl {$variantClass} p-5 {$class}">
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1 min-w-0">
                    <p class="ikb-stat-label text-xs font-semibold text-gray-500 uppercase tracking-wider">{$label}</p>
                    <p class="ikb-stat-value text-2xl font-bold text-gray-900 mt-1">{$value}</p>
                    {$trendHtml}
                </div>
                {$iconHtml}
            </div>
            {$slotHtml}
        </div>
        HTML;
    }

    /**
     * Render a timeline component — chronological list of events.
     *
     * Attributes:
     *   source   — entity source for data (optional; supports child nodes as items)
     *   class    — additional CSS classes
     *
     * Children: {ikb_timeline_item} elements or plain divs
     */
    private function renderTimeline(array $attrs, string $children): string
    {
        $class = (string)($attrs['class'] ?? '');

        // Process ikb_timeline_item children if present
        $processedChildren = $children;

        return <<<HTML
        <div class="ikb-timeline relative pl-6 space-y-6 before:absolute before:left-[11px] before:top-1 before:bottom-1 before:w-0.5 before:bg-gray-200 {$class}">
            {$processedChildren}
        </div>
        HTML;
    }

    /**
     * Render a confirm action wrapper — wraps destructive actions with a confirmation step.
     *
     * Attributes:
     *   message  — confirmation message (default: "Are you sure?")
     *   confirm  — confirm button label (default: "Confirm")
     *   cancel   — cancel button label (default: "Cancel")
     *   variant  — danger (red), warning (yellow), default (indigo)
     *   class    — additional CSS classes
     *
     * Children: the action button(s) that trigger the confirmation
     */
    private function renderConfirmAction(array $attrs, string $children): string
    {
        $message = htmlspecialchars((string)($attrs['message'] ?? 'Are you sure?'), ENT_QUOTES, 'UTF-8');
        $confirmLabel = htmlspecialchars((string)($attrs['confirm'] ?? 'Confirm'), ENT_QUOTES, 'UTF-8');
        $cancelLabel = htmlspecialchars((string)($attrs['cancel'] ?? 'Cancel'), ENT_QUOTES, 'UTF-8');
        $variant = (string)($attrs['variant'] ?? 'danger');
        $class = (string)($attrs['class'] ?? '');

        $confirmClass = match ($variant) {
            'warning' => 'bg-yellow-500 hover:bg-yellow-600 text-white',
            'danger' => 'bg-red-600 hover:bg-red-700 text-white',
            default => 'bg-indigo-600 hover:bg-indigo-700 text-white',
        };

        $uid = 'ikb-confirm-' . bin2hex(random_bytes(4));

        return <<<HTML
        <div class="ikb-confirm-action inline-block {$class}" x-data="{ open: false }" @keydown.escape.window="open = false">
            <div @click="open = true" class="cursor-pointer inline-block">
                {$children}
            </div>
            <template x-teleport="body">
                <div x-show="open" class="fixed inset-0 z-[9999] flex items-center justify-center" x-transition.opacity>
                    <div class="fixed inset-0 bg-black/40" @click="open = false"></div>
                    <div class="relative bg-white rounded-xl shadow-2xl max-w-sm w-full mx-4 p-6" @click.stop>
                        <p class="text-sm text-gray-700 mb-4">{$message}</p>
                        <div class="flex gap-3 justify-end">
                            <button type="button" @click="open = false"
                                class="px-4 py-2 text-sm font-medium rounded-lg border border-gray-300 text-gray-700 hover:bg-gray-50 transition">
                                {$cancelLabel}
                            </button>
                            <button type="button" @click="\$el.closest('.ikb-confirm-action').querySelector('[data-confirm-target]')?.click(); open = false"
                                class="px-4 py-2 text-sm font-medium rounded-lg {$confirmClass} transition">
                                {$confirmLabel}
                            </button>
                        </div>
                    </div>
                </div>
            </template>
        </div>
        HTML;
    }

    /**
     * Render a semantic panel — theme-aware container with design tokens.
     *
     * Attributes:
     *   tone     — surface, muted, elevated, primary (default: surface)
     *   spacing  — none, sm, md, lg, xl (default: md)
     *   radius   — none, sm, md, lg, full (default: md)
     *   class    — additional CSS classes
     *
     * The theme controls how these tokens render via CSS custom properties.
     */
    private function renderPanel(array $attrs, string $children): string
    {
        $tone = (string)($attrs['tone'] ?? 'surface');
        $spacing = (string)($attrs['spacing'] ?? 'md');
        $radius = (string)($attrs['radius'] ?? 'md');
        $class = (string)($attrs['class'] ?? '');

        $toneClass = match ($tone) {
            'surface' => 'bg-white border border-gray-100',
            'muted' => 'bg-gray-50 border border-gray-100',
            'elevated' => 'bg-white shadow-md border border-gray-100',
            'primary' => 'bg-indigo-600 text-white',
            default => 'bg-white border border-gray-100',
        };

        $spacingClass = match ($spacing) {
            'none' => 'p-0', 'sm' => 'p-3', 'md' => 'p-5', 'lg' => 'p-8', 'xl' => 'p-12',
            default => 'p-5',
        };

        $radiusClass = match ($radius) {
            'none' => 'rounded-none', 'sm' => 'rounded-md', 'md' => 'rounded-xl', 'lg' => 'rounded-2xl', 'full' => 'rounded-full',
            default => 'rounded-xl',
        };

        return "<div class=\"ikb-panel {$toneClass} {$spacingClass} {$radiusClass} {$class}\">{$children}</div>";
    }

    /**
     * Render a governed theme slot — resolves module-contributed content via SlotRegistry.
     *
     * Attributes:
     *   name — slot identifier (required, e.g. "content.after", "header.main")
     *
     * At render time, queries SlotRegistry for all contributions matching the current
     * rendering context (entity_type, view, route, role, capabilities). Each matching
     * contribution is rendered using the contributed component with its attributes.
     */
    private function renderSlot(array $attrs, string $children, array $context): string
    {
        $slotName = (string)($attrs['name'] ?? '');
        if ($slotName === '') {
            return '<!-- ikb_slot: missing name attribute -->';
        }

        $contributions = \Ikabud\Kernel\Services\SlotRegistry::resolve($slotName, $context);

        if (empty($contributions)) {
            // Render children as fallback content when no contributions match
            return $children;
        }

        $output = '';
        foreach ($contributions as $contribution) {
            $component = $contribution['component'] ?? 'ikb_panel';
            $contributionAttrs = $contribution['attrs'] ?? [];

            // Render the contributed component with its children
            $contributionChildren = $contribution['children'] ?? '';
            $output .= $this->renderComponent($component, $contributionAttrs, $contributionChildren, $context);
        }

        // Wrap with an identifying comment for debugging
        if ($this->engine->isDebug()) {
            $output = '<!-- slot:' . htmlspecialchars($slotName, ENT_QUOTES, 'UTF-8') . ' -->' . $output;
        }

        return $output;
    }

    /**
     * Render a governed theme region — resolves region HTML from ThemeCustomizerOrchestrator.
     *
     * Attributes:
     *   name     — Region identifier (required, e.g. "header", "footer", "sidebar")
     *   position — Sidebar position override ("left" or "right", sidebar only)
     *   width    — Sidebar width override (sidebar only, e.g. "300")
     *
     * At render time, checks the render context for {name}_region.present and
     * {name}_region.html. If present and non-empty, renders the region HTML.
     * Otherwise renders children as fallback content (typically an {include}).
     *
     * Replaces the traditional boilerplate:
     *   {if header_region_present}{header_region_html|raw}{else}{include "..."}{/if}
     * With:
     *   {ikb_region name="header"}{include "..."}{/ikb_region}
     */
    private function renderRegion(array $attrs, string $children, array $context): string
    {
        $name = (string)($attrs['name'] ?? '');
        if ($name === '') {
            return '<!-- ikb_region: missing name attribute -->';
        }

        // Check context for {name}_region.present and {name}_region.html
        $regionKey = $name . '_region';
        $present = (bool)($context[$regionKey]['present'] ?? false);
        $html = (string)($context[$regionKey]['html'] ?? '');

        if ($present && $html !== '') {
            // For sidebar regions, emit a wrapper with position/width metadata
            if ($name === 'sidebar') {
                $position = $attrs['position']
                    ?? $context['sidebar_region_position']
                    ?? $context['sidebar_position']
                    ?? 'right';
                $width = $attrs['width']
                    ?? $context['sidebar_region_width']
                    ?? $context['sidebar_width']
                    ?? '300';
                $html = '<aside class="ark-sidebar ark-sidebar--' . htmlspecialchars($position, ENT_QUOTES, 'UTF-8')
                    . '" style="--sidebar-width:' . htmlspecialchars($width, ENT_QUOTES, 'UTF-8') . 'px;">'
                    . $html . '</aside>';
            }

            if ($this->engine->isDebug()) {
                $html = '<!-- region:' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . ' -->' . $html;
            }

            return $html;
        }

        // No region HTML available — render children as fallback
        return $children;
    }

    /**
     * Render a slide-out drawer panel.
     *
     * Attributes:
     *   id       — drawer ID (required)
     *   position — left or right (default: right)
     *   title    — drawer header title
     *   open     — initially open (default: false)
     *   width    — CSS width (default: 320px)
     *   class    — additional CSS classes
     */
    private function renderDrawer(array $attrs, string $children): string
    {
        $id = htmlspecialchars((string)($attrs['id'] ?? 'drawer'), ENT_QUOTES, 'UTF-8');
        $position = (string)($attrs['position'] ?? 'right');
        $title = htmlspecialchars((string)($attrs['title'] ?? ''), ENT_QUOTES, 'UTF-8');
        $open = !empty($attrs['open']);
        $width = (string)($attrs['width'] ?? '320px');
        $class = (string)($attrs['class'] ?? '');

        $translateFrom = $position === 'left' ? '-translate-x-full' : 'translate-x-full';
        $translateTo = $position === 'left' ? 'translate-x-0' : 'translate-x-0';
        $positionClass = $position === 'left' ? 'left-0' : 'right-0';
        $initOpen = $open ? 'true' : 'false';

        $titleHtml = '';
        if ($title !== '') {
            $titleHtml = <<<TITLE
            <div class="ikb-drawer-header flex items-center justify-between px-4 py-3 border-b border-gray-200">
                <h3 class="text-lg font-semibold text-gray-900">{$title}</h3>
                <button type="button" @click="open = false" class="text-gray-400 hover:text-gray-600">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
                </button>
            </div>
            TITLE;
        }

        return <<<HTML
        <div class="ikb-drawer {$class}" x-data="{ open: {$initOpen} }" @keydown.escape.window="open = false">
            <template x-teleport="body">
                <div>
                    <div x-show="open" class="fixed inset-0 z-[9998] bg-black/40 transition-opacity" @click="open = false" x-transition.opacity></div>
                    <div x-show="open" class="fixed {$positionClass} top-0 h-full z-[9999] bg-white shadow-2xl overflow-y-auto transition-transform"
                         :class="open ? '{$translateTo}' : '{$translateFrom}'"
                         style="width: {$width}; max-width: 100vw;">
                        {$titleHtml}
                        <div class="ikb-drawer-body p-4">
                            {$children}
                        </div>
                    </div>
                </div>
            </template>
        </div>
        HTML;
    }

    /**
     * Render an audit log viewer — governed display of audit trail entries.
     *
     * Attributes:
     *   source   — entity type whose audit entries to display
     *   entity_id — specific entity ID (optional; omit for all audit entries of type)
     *   limit    — max entries (default: 20)
     *   class    — additional CSS classes
     */
    private function renderAuditLog(array $attrs, string $children, array $context): string
    {
        $source = (string)($attrs['source'] ?? '');
        $entityId = (string)($attrs['entity_id'] ?? '');
        $limit = (int)($attrs['limit'] ?? 20);
        $class = (string)($attrs['class'] ?? '');

        // Resolve audit data via the capability bus
        $rows = [];
        $error = null;

        if ($source !== '') {
            try {
                if (\function_exists('app') && ($app = \app()) !== null && method_exists($app, 'capabilities')) {
                    $result = $app->cap()->call('kernel.audit.list@1', [
                        'entity_type' => $source,
                        'entity_id' => $entityId !== '' ? $entityId : null,
                        'limit' => $limit,
                    ]);
                    if (is_array($result)) {
                        $rows = $result['rows'] ?? $result;
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null || ($source !== '' && empty($rows))) {
            $msg = $error ?: 'No audit entries found.';
            return $this->entityErrorState($msg, $class);
        }

        if (empty($rows)) {
            return <<<HTML
            <div class="ikb-audit-log--empty text-center py-6 text-sm text-gray-500 {$class}">
                <p>No audit entries to display.</p>
            </div>
            HTML;
        }

        $entries = '';
        foreach ($rows as $entry) {
            $timestamp = htmlspecialchars((string)($entry['created_at'] ?? $entry['timestamp'] ?? ''), ENT_QUOTES, 'UTF-8');
            $actor = htmlspecialchars((string)($entry['actor'] ?? $entry['user'] ?? 'System'), ENT_QUOTES, 'UTF-8');
            $action = htmlspecialchars((string)($entry['action'] ?? 'modified'), ENT_QUOTES, 'UTF-8');
            $detail = htmlspecialchars((string)($entry['detail'] ?? $entry['summary'] ?? ''), ENT_QUOTES, 'UTF-8');

            $actionBadge = match (strtolower($action)) {
                'created' => 'bg-green-100 text-green-800',
                'updated', 'modified' => 'bg-blue-100 text-blue-800',
                'deleted', 'removed' => 'bg-red-100 text-red-800',
                'login', 'authenticated' => 'bg-purple-100 text-purple-800',
                default => 'bg-gray-100 text-gray-800',
            };

            $entries .= <<<ENTRY
            <div class="ikb-audit-entry flex items-start gap-4 px-4 py-3 hover:bg-gray-50 transition">
                <div class="flex-shrink-0 w-8 h-8 rounded-full bg-gray-100 flex items-center justify-center text-xs font-bold text-gray-500">
                    {$actor[0]}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap">
                        <span class="text-sm font-medium text-gray-900">{$actor}</span>
                        <span class="inline-flex px-2 py-0.5 text-xs font-medium rounded-full {$actionBadge}">{$action}</span>
                    </div>
                    <p class="text-xs text-gray-500 mt-0.5">{$detail}</p>
                </div>
                <time class="flex-shrink-0 text-xs text-gray-400 whitespace-nowrap">{$timestamp}</time>
            </div>
            ENTRY;
        }

        return <<<HTML
        <div class="ikb-audit-log divide-y divide-gray-100 border border-gray-200 rounded-xl overflow-hidden {$class}">
            {$entries}
        </div>
        HTML;
    }

    /**
     * Render an AI-summarized block — governed AI content generation.
     *
     * Attributes:
     *   capability — capability ID that defines what data to summarize (e.g. "ledger.daily.summarize")
     *   source     — entity source to fetch data from
     *   review     — "required" (default) or "none" — if required, output is marked as draft
     *   model      — AI model ID (default: gpt-4o-mini)
     *   max_tokens — max output tokens (default: 256)
     *   class      — additional CSS classes
     *
     * The AI Policy governs: kill switch, model allowlist, cost ceiling, token cap.
     */
    private function renderAiSummary(array $attrs, string $children, array $context): string
    {
        $capability = (string)($attrs['capability'] ?? '');
        $source = (string)($attrs['source'] ?? '');
        $review = (string)($attrs['review'] ?? 'required');
        $model = (string)($attrs['model'] ?? 'gpt-4o-mini');
        $maxTokens = (int)($attrs['max_tokens'] ?? 256);
        $class = (string)($attrs['class'] ?? '');

        // Policy gate
        $policy = class_exists('Ikabud\\Kernel\\DiSyL\\AI\\Policy') ? new \Ikabud\Kernel\DiSyL\AI\Policy() : null;
        if ($policy !== null && $policy->isKilled()) {
            return $this->entityErrorState('AI features are disabled.', $class);
        }

        if ($policy !== null && !$policy->allowsModel($model)) {
            return $this->entityErrorState('AI model not permitted by policy.', $class);
        }

        if ($policy !== null && !$policy->canAfford($model, $maxTokens)) {
            return $this->entityErrorState('AI cost ceiling exceeded.', $class);
        }

        // Fetch source data via capability bus if source provided
        $sourceData = '';
        if ($source !== '' && \function_exists('app') && ($app = \app()) !== null && method_exists($app, 'entityViews')) {
            try {
                $resolved = $app->entityViews()->resolve($source, 'compact', ['limit' => 10]);
                if (!empty($resolved['rows'])) {
                    $sourceData = json_encode($resolved['rows'], JSON_UNESCAPED_SLASHES);
                }
            } catch (\Throwable $e) {
                // Continue without source data
            }
        }

        // Build the prompt
        $prompt = "Summarize the following data concisely. Be factual and brief.";
        if ($sourceData !== '') {
            $prompt .= "\n\nData:\n" . $sourceData;
        }
        if (trim($children) !== '') {
            // User-defined slot template provides additional instructions
            $prompt .= "\n\nContext: " . strip_tags($children);
        }

        // Call AI provider
        $resultText = '';
        $isDraft = $review === 'required';
        $error = null;

        try {
            // Engine-provided AI provider (creates EchoAiProvider when unset)
            $provider = $this->engine->aiProvider();

            $response = $provider->complete([
                'model' => $model,
                'prompt' => $prompt,
                'max_tokens' => $policy !== null ? $policy->capMaxTokens($maxTokens) : $maxTokens,
            ]);

            if ($policy !== null) {
                $policy->recordUsage($model, $response['output_tokens'] ?? 0);
            }
            $resultText = $response['text'] ?? '';
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            if (\function_exists('write_log')) {
                \write_log("ikb_ai_summary: AI call failed", 'warning', [
                    'capability' => $capability,
                    'model' => $model,
                    'error' => $error,
                ]);
            }
        }

        if ($error !== null) {
            return $this->entityErrorState('AI summary unavailable: ' . $error, $class);
        }

        $safeText = htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8');
        $draftBadge = '';
        if ($isDraft) {
            $draftBadge = '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 ml-2">Draft — requires review</span>';
        }

        return <<<HTML
        <div class="ikb-ai-summary rounded-xl border border-indigo-200 bg-indigo-50/30 p-5 {$class}">
            <div class="flex items-center mb-3">
                <svg class="w-4 h-4 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path></svg>
                <span class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">AI Summary</span>
                {$draftBadge}
            </div>
            <div class="ikb-ai-summary-content text-sm text-gray-800 leading-relaxed">
                {$safeText}
            </div>
        </div>
        HTML;
    }

    /**
     * Render an AI-assisted block — governed AI drafting with human approval.
     *
     * Attributes:
     *   capability — capability ID for the draft operation
     *   mode       — "draft_only" (default; read-only, no mutation) or "suggest" (pre-filled, user approves)
     *   model      — AI model ID
     *   max_tokens — max output tokens (default: 512)
     *   class      — additional CSS classes
     *
     * Children: fallback content shown while AI is generating or if unavailable.
     */
    private function renderAiAssist(array $attrs, string $children, array $context): string
    {
        $capability = (string)($attrs['capability'] ?? '');
        $mode = (string)($attrs['mode'] ?? 'draft_only');
        $model = (string)($attrs['model'] ?? 'gpt-4o-mini');
        $maxTokens = (int)($attrs['max_tokens'] ?? 512);
        $class = (string)($attrs['class'] ?? '');

        // Policy gate
        $policy = class_exists('Ikabud\\Kernel\\DiSyL\\AI\\Policy') ? new \Ikabud\Kernel\DiSyL\AI\Policy() : null;
        if ($policy !== null && $policy->isKilled()) {
            return $this->entityErrorState('AI features are disabled.', $class);
        }

        if (!$policy->allowsModel($model)) {
            return $this->entityErrorState('AI model not permitted by policy.', $class);
        }

        if (!$policy->canAfford($model, $maxTokens)) {
            return $this->entityErrorState('AI cost ceiling exceeded.', $class);
        }

        $fallbackHtml = trim($children) !== ''
            ? '<div class="ikb-ai-assist-fallback text-sm text-gray-500 italic mt-2">' . $children . '</div>'
            : '';

        // Deterministic placeholder for non-interactive rendering.
        // In a full implementation, this would be an Alpine.js island that
        // fetches AI content on user interaction.

        $resultText = '';
        $error = null;

        try {
            // Engine-provided AI provider (creates EchoAiProvider when unset)
            $provider = $this->engine->aiProvider();
            if ($provider !== null) {
                $response = $provider->complete([
                    'model' => $model,
                    'prompt' => "Draft a response for capability: {$capability}. Mode: {$mode}. Be concise.",
                    'max_tokens' => $policy !== null ? $policy->capMaxTokens($maxTokens) : $maxTokens,
                ]);
                if ($policy !== null) {
                    $policy->recordUsage($model, $response['output_tokens'] ?? 0);
                }
                $resultText = $response['text'] ?? '';
            } else {
                $resultText = '[AI provider not available]';
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if ($error !== null) {
            return $this->entityErrorState('AI assist unavailable: ' . $error, $class);
        }

        $safeText = htmlspecialchars($resultText, ENT_QUOTES, 'UTF-8');

        $modeBadge = match ($mode) {
            'suggest' => '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-blue-100 text-blue-800 ml-2">Suggestion</span>',
            default => '<span class="ikb-ai-draft-badge inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-amber-100 text-amber-800 ml-2">Draft Only</span>',
        };

        return <<<HTML
        <div class="ikb-ai-assist rounded-xl border border-indigo-200 bg-white p-5 {$class}">
            <div class="flex items-center mb-3">
                <svg class="w-4 h-4 text-indigo-500 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                <span class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">AI Assist</span>
                {$modeBadge}
            </div>
            <div class="ikb-ai-assist-content text-sm text-gray-800 leading-relaxed">
                {$safeText}
            </div>
            {$fallbackHtml}
        </div>
        HTML;
    }

    /**
     * Render a governed report component — business document with header, body, and signature block.
     *
     * Attributes:
     *   title    — report title
     *   subtitle — report subtitle/description
     *   source   — entity source (optional; for data-driven reports)
     *   format   — report format: summary, detailed, official (default: summary)
     *   class    — additional CSS classes
     *
     * Children: report body content (tables, entity lists, text)
     */
    private function renderReport(array $attrs, string $children, array $context): string
    {
        $title = htmlspecialchars((string)($attrs['title'] ?? 'Report'), ENT_QUOTES, 'UTF-8');
        $subtitle = htmlspecialchars((string)($attrs['subtitle'] ?? ''), ENT_QUOTES, 'UTF-8');
        $format = (string)($attrs['format'] ?? 'summary');
        $class = (string)($attrs['class'] ?? '');

        $formatClass = match ($format) {
            'official' => 'ikb-report--official',
            'detailed' => 'ikb-report--detailed',
            default => 'ikb-report--summary',
        };

        $dateStr = date('F j, Y');
        $subtitleHtml = $subtitle !== ''
            ? "<p class=\"ikb-report-subtitle text-sm text-gray-500 mt-1\">{$subtitle}</p>"
            : '';

        return <<<HTML
        <div class="ikb-report max-w-4xl mx-auto bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden {$formatClass} {$class}">
            <div class="ikb-report-header px-8 py-6 border-b border-gray-100 bg-gray-50/50">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h1 class="ikb-report-title text-xl font-bold text-gray-900">{$title}</h1>
                        {$subtitleHtml}
                    </div>
                    <div class="ikb-report-meta text-right text-xs text-gray-400 flex-shrink-0">
                        <p>{$dateStr}</p>
                    </div>
                </div>
            </div>
            <div class="ikb-report-body px-8 py-6">
                {$children}
            </div>
        </div>
        HTML;
    }

    /**
     * Render a signature block for official documents and reports.
     *
     * Attributes:
     *   roles    — comma-separated role labels (e.g. "Prepared By,Checked By,Approved By")
     *   class    — additional CSS classes
     *
     * Children: optional additional content below signatures
     */
    private function renderSignatureBlock(array $attrs, string $children): string
    {
        $rolesStr = (string)($attrs['roles'] ?? 'Prepared By,Reviewed By,Approved By');
        $class = (string)($attrs['class'] ?? '');
        $roles = array_map('trim', explode(',', $rolesStr));

        $signatures = '';
        foreach ($roles as $index => $role) {
            if ($role === '') { continue; }
            $safeRole = htmlspecialchars($role, ENT_QUOTES, 'UTF-8');
            $signatures .= <<<SIG
            <div class="ikb-signature flex-1 min-w-[120px]">
                <div class="ikb-signature-line border-b border-gray-400 pt-12 mb-2"></div>
                <p class="ikb-signature-label text-xs text-gray-600 font-medium">{$safeRole}</p>
                <p class="ikb-signature-date text-xs text-gray-400 mt-0.5">Date: _______________</p>
            </div>
            SIG;
        }

        $slotHtml = trim($children) !== ''
            ? "<div class=\"ikb-signature-extra mt-4 text-xs text-gray-500\">{$children}</div>"
            : '';

        return <<<HTML
        <div class="ikb-signature-block mt-10 pt-6 border-t border-gray-200 {$class}">
            <p class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-4">Signatures</p>
            <div class="ikb-signature-row flex flex-wrap gap-6">
                {$signatures}
            </div>
            {$slotHtml}
        </div>
        HTML;
    }

    /**
     * Render {ikb_entity_view} — DiSyL entity view configuration.
     *
     * Registers an entity view contract with EntityViewResolver using
     * a declarative DiSyL syntax instead of PHP arrays.
     *
     * Usage in .disyl config files:
     *   {ikb_entity_view name="employee_profile" view="table"}
     *     {field name="first_name" type="string" renderer="text"}
     *     {field name="last_name"  type="string" renderer="text"}
     *     {field name="salary_type" type="enum" renderer="badge:{hourly|Daily}"}
     *     {field name="employment_status" type="enum" renderer="badge:{regular|Regular|green}"}
     *     {action name="view" url="/admin/wage/employees/{id}/view"}
     *     {action name="edit" url="/admin/wage/employees/{id}"}
     *   {/ikb_entity_view}
     *
     * Produces no output — only registers the view contract at runtime.
     *
     * @param array $attrs Component attributes
     * @param string $children Raw child content (not compiled — preserves {field}/{action} tags)
     * @param array $context Template rendering context
     * @return string Empty string (config-only, no output)
     */
    private function renderEntityViewConfig(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? '';
        $view = $attrs['view'] ?? 'table';
        $renderer = $attrs['renderer'] ?? '';
        $class = $attrs['class'] ?? '';

        // Collect semantic role→field mapping from {field role="..."} attributes
        $roleFields = [];

        if ($name === '') {
            $this->engine->logError("ikb_entity_view missing required 'name' attribute");
            return '';
        }

        $validViews = ['table', 'compact', 'card_grid', 'detailed', 'summary'];
        if (!in_array($view, $validViews, true)) {
            $this->engine->logError("ikb_entity_view '{$name}': unknown view type '{$view}' — expected one of: " . implode(', ', $validViews));
        }

        $timeoutMs = isset($attrs['timeout_ms']) ? (int)$attrs['timeout_ms'] : null;

        // Parse {field name="..." type="..." renderer="..." visible="true/false"} from raw children
        $fields = [];
        $fieldRenderers = [];
        $visibleFields = [];
        if (preg_match_all('/\{field\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $fieldMatches)) {
            foreach ($fieldMatches[1] as $fieldStr) {
                $fieldAttrs = $this->parseSimpleAttrs($fieldStr);
                $fieldName = $fieldAttrs['name'] ?? '';
                if ($fieldName === '') {
                    $this->engine->logError("ikb_entity_view '{$name}': {field} missing required 'name' attribute");
                    continue;
                }
                $fields[] = $fieldName;

                // Track semantic role if present (e.g. role="title", role="subtitle", role="image")
                $fieldRole = $fieldAttrs['role'] ?? '';
                if ($fieldRole !== '' && in_array($fieldRole, ['title', 'subtitle', 'image', 'body', 'description'], true)) {
                    $roleFields[$fieldRole] = $fieldName;
                }

                // Track visible fields — fields with visible="false" are excluded from public wildcard expansion
                $isVisible = ($fieldAttrs['visible'] ?? 'true') !== 'false';
                if ($isVisible) {
                    $visibleFields[] = $fieldName;
                }

                // Validate renderer format if present
                if (!empty($fieldAttrs['renderer'])) {
                    $fieldRenderers[$fieldName] = $fieldAttrs['renderer']; 
                    $this->validateFieldRenderer($name, $fieldName, $fieldAttrs['renderer']);
                }
            }
        }

        // Parse {action name="..." url="..." method="..." ...} from raw children
        $actions = [];
        $actionUrls = [];
        $actionMethods = [];
        $actionLabels = [];
        $actionConfirm = [];
        $actionShowIf = [];
        $actionRoles = [];

        if (preg_match_all('/\{action\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $actionMatches)) {
            foreach ($actionMatches[1] as $actionStr) {
                $actionAttrs = $this->parseSimpleAttrs($actionStr);
                $actionName = $actionAttrs['name'] ?? '';
                if ($actionName === '') {
                    continue;
                }
                $actions[] = $actionName;

                if (!empty($actionAttrs['url'])) {
                    $actionUrls[$actionName] = $actionAttrs['url'];
                }
                if (!empty($actionAttrs['method'])) {
                    $actionMethods[$actionName] = $actionAttrs['method'];
                }
                if (!empty($actionAttrs['label'])) {
                    $actionLabels[$actionName] = $actionAttrs['label'];
                }
                if (!empty($actionAttrs['confirm'])) {
                    $actionConfirm[$actionName] = $actionAttrs['confirm'];
                }
                if (!empty($actionAttrs['show_if'])) {
                    $actionShowIf[$actionName] = $actionAttrs['show_if'];
                }
                if (!empty($actionAttrs['roles'])) {
                    $actionRoles[$actionName] = explode(',', $actionAttrs['roles']);
                }
            }
        }

        // Parse {filter name="..." type="..." values="..."} from raw children
        // Declares allowed filters for the entity source with type constraints.
        $filterSchema = [];
        if (preg_match_all('/\{filter\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $filterMatches)) {
            foreach ($filterMatches[1] as $filterStr) {
                $filterAttrs = $this->parseSimpleAttrs($filterStr);
                $filterName = $filterAttrs['name'] ?? '';
                if ($filterName === '') continue;

                $entry = ['type' => $filterAttrs['type'] ?? 'string'];
                if (!empty($filterAttrs['values'])) {
                    $entry['values'] = array_map('trim', explode(',', $filterAttrs['values']));
                }
                $filterSchema[$filterName] = $entry;
            }
        }

        // Validate the collected contract before registering
        $this->validateViewContract($name, $view, $fields, $roleFields, $actionUrls);

        // Build contract
        $contract = [
            'fields' => $fields,
            'actions' => $actions,
        ];

        if (!empty($actionUrls)) { $contract['action_urls'] = $actionUrls; }
        if (!empty($actionMethods)) { $contract['action_methods'] = $actionMethods; }
        if (!empty($actionLabels)) { $contract['action_labels'] = $actionLabels; }
        if (!empty($actionConfirm)) { $contract['action_confirm'] = $actionConfirm; }
        if (!empty($actionShowIf)) { $contract['action_show_if'] = $actionShowIf; }
        if (!empty($actionRoles)) { $contract['action_roles'] = $actionRoles; }
        if (!empty($fieldRenderers)) { $contract['renderers'] = $fieldRenderers; }
        if (!empty($visibleFields)) { $contract['visible_fields'] = $visibleFields; }
        if (!empty($filterSchema)) { $contract['filter_schema'] = $filterSchema; }
        if ($renderer !== '') { $contract['renderer'] = $renderer; }
        if ($class !== '') { $contract['class'] = $class; }
        if ($timeoutMs !== null) { $contract['timeout_ms'] = $timeoutMs; }

        // Store role→field mapping in contract so renderers can use semantic roles
        if (!empty($roleFields)) {
            $contract['role_fields'] = $roleFields;
        }

        // Register with EntityViewResolver
        try {
            if (class_exists(\Ikabud\Kernel\EntityContext\EntityViewResolver::class, true)) {
                $resolver = \Ikabud\Kernel\EntityContext\EntityViewResolver::getInstance();
                $resolver->registerView($name, $view, $contract);
            }
        } catch (\Throwable $e) {
            $this->engine->logError("Failed to register entity view {$name}/{$view}: " . $e->getMessage());
        }

        return ''; // No output
    }

    /**
     * Validate an entity view contract before registration.
     *
     * Checks for:
     * - Duplicate field names
     * - Duplicate semantic role assignments
     * - Action URL placeholders ({id}, {slug}) that don't match any declared field
     *
     * Logs errors via logError() but does not abort — the contract is still registered.
     */
    private function validateViewContract(string $entityName, string $view, array $fields, array $roleFields, array $actionUrls): void
    {
        // Check 1: duplicate field names
        $seen = [];
        foreach ($fields as $f) {
            if (isset($seen[$f])) {
                $this->engine->logError("ikb_entity_view '{$entityName}/{$view}': duplicate field '{$f}' declared multiple times");
            }
            $seen[$f] = true;
        }

        // Check 2: duplicate role values (last-writer-wins detection)
        $roleSeen = [];
        foreach ($roleFields as $role => $fieldName) {
            if (isset($roleSeen[$role])) {
                $this->engine->logError("ikb_entity_view '{$entityName}/{$view}': role '{$role}' assigned to both '{$roleSeen[$role]}' and '{$fieldName}' — last definition wins");
            }
            $roleSeen[$role] = $fieldName;
        }

        // Check 3: action URL placeholders not in field list
        $fieldSet = array_flip($fields);
        foreach ($actionUrls as $actionName => $url) {
            if (preg_match_all('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', $url, $placeholderMatches)) {
                foreach ($placeholderMatches[1] as $placeholder) {
                    // Skip standard context variables that don't need field declarations
                    if (in_array($placeholder, ['base_url', 'current_url', 'request_id'], true)) {
                        continue;
                    }
                    if (!isset($fieldSet[$placeholder])) {
                        $this->engine->logError("ikb_entity_view '{$entityName}/{$view}' action '{$actionName}': URL placeholder '{{$placeholder}}' not in declared fields — will render as literal");
                    }
                }
            }
        }
    }

    /**
     * Validate a field renderer string from a view contract {field} tag.
     * Logs a warning for unrecognized renderer patterns without aborting.
     */
    private function validateFieldRenderer(string $entityName, string $fieldName, string $renderer): void
    {
        $validPrefixes = ['badge', 'badge:map', 'money', 'datetime', 'boolean', 'string', 'text', 'number', 'enum', 'date', 'image'];
        $prefix = explode(':', $renderer, 2)[0];
        // Allow dynamic badge:JSON patterns (e.g. badge:{draft|gray|...})
        if ($prefix === 'badge' && str_contains($renderer, '{')) {
            return; // dynamic badge map — accept
        }
        if (!in_array($prefix, $validPrefixes, true)) {
            $this->engine->logError("ikb_entity_view '{$entityName}' field '{$fieldName}': unknown renderer '{$renderer}' — expected prefix one of: " . implode(', ', $validPrefixes));
        }
    }

    /**
     * Render {state} — declarative state manager bridge.
     *
     * Declares a state namespace with typed variables, default values,
     * and an optional server-side source handler. Renders as an Alpine
     * x-data container with computed initial state.
     *
     * Usage:
     *   {state name="kiosk" source="attendance-wage/kiosk-state"}
     *     {variable name="step" type="int" default="0"}
     *     {variable name="searchQuery" type="string" default=""}
     *     {variable name="selectedEmployee" type="?object"}
     *     <div class="kiosk-content">
     *       <span x-text="step"></span>
     *     </div>
     *   {/state}
     *
     * With explicit bridge:
     *   {state name="kiosk" bridge="htmx"}
     *   {state name="kiosk" bridge="custom"}
     *
     * @param array $attrs Component attributes
     * @param string $children Raw child content with {variable} tags
     * @param array $context Template rendering context
     * @return string HTML with framework-specific attributes
     */
    private function renderStateDeclaration(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? 'app';
        $source = $attrs['source'] ?? '';

        // Parse {variable name="..." type="..." default="..."} from raw children
        $variables = $this->parseStateVariables($children);

        // Build initial state from defaults
        $initialState = [];
        foreach ($variables as $var) {
            $varName = $var['name'];
            $default = $var['default'];
            $type = $var['type'];

            // Coerce default value to the declared type
            if ($default === null) {
                $initialState[$varName] = null;
            } elseif (str_starts_with($type, '?')) {
                // Nullable: use the raw default
                $initialState[$varName] = $this->coerceValue($default, substr($type, 1));
            } else {
                $initialState[$varName] = $this->coerceValue($default, $type);
            }
        }

        // Allow source handler to override initial state
        if ($source !== '') {
            try {
                $handlerState = $this->resolveStateSource($source, $name, $context);
                if (is_array($handlerState)) {
                    $initialState = array_merge($initialState, $handlerState);
                }
            } catch (\Throwable $e) {
                $this->engine->logError("State source {$source} failed: " . $e->getMessage());
            }
        }

        // Serialize as JSON
        $json = htmlspecialchars(
            json_encode($initialState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        // Strip {variable} tags from children before rendering
        $body = preg_replace('/\{variable\s+((?:[^{}]|\{[^{}]*\})*)\}/', '', $children);
        $body = trim($body);

        // Resolve bridge and delegate rendering
        $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
        return $bridge->renderState($name, $json, $body, $attrs);
    }

    /**
     * Parse {variable} declarations from raw child content.
     *
     * @param string $children Raw children containing {variable ...} tags
     * @return array<int, array{name: string, type: string, default: mixed}>
     */
    private function parseStateVariables(string $children): array
    {
        $variables = [];
        if (preg_match_all('/\{variable\s+((?:[^{}]|\{[^{}]*\})*)\}/', $children, $matches)) {
            foreach ($matches[1] as $varStr) {
                $attrs = $this->parseSimpleAttrs($varStr);
                $varName = $attrs['name'] ?? '';
                if ($varName === '') {
                    continue;
                }
                $type = $attrs['type'] ?? 'string';
                $defaultStr = $attrs['default'] ?? '';
                $default = $this->parseDefaultValue($defaultStr, $type);
                $variables[] = [
                    'name' => $varName,
                    'type' => $type,
                    'default' => $default,
                ];
            }
        }
        return $variables;
    }

    /**
     * Parse a default value string to the appropriate PHP type.
     */
    private function parseDefaultValue(string $value, string $type): mixed
    {
        $baseType = ltrim($type, '?');

        // Empty string means null for non-string types
        if ($value === '') {
            return match ($baseType) {
                'string' => '',
                'int', 'integer' => 0,
                'float', 'number' => 0.0,
                'bool', 'boolean' => false,
                'array' => [],
                default => null,
            };
        }

        return match ($baseType) {
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'bool', 'boolean' => in_array(strtolower($value), ['true', '1', 'yes'], true),
            'array' => explode(',', $value),
            default => $value,
        };
    }

    /**
     * Coerce a value to the specified type.
     */
    private function coerceValue(mixed $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int)$value,
            'float', 'number' => (float)$value,
            'bool', 'boolean' => (bool)$value,
            'string' => (string)$value,
            'array' => is_array($value) ? $value : [],
            default => $value,
        };
    }

    /**
     * Resolve state from a source handler.
     *
     * The source format is "module-id/handler-name", e.g. "attendance-wage/kiosk-state".
     * The handler is called via the capability bus or a direct function lookup.
     *
     * @param string $source Source identifier
     * @param string $stateName State namespace name
     * @param array $context Template rendering context
     * @return array|null Computed state or null if unavailable
     */
    private function resolveStateSource(string $source, string $stateName, array $context): ?array
    {
        // Support module-level state handler functions: moduleId_state_handler()
        $parts = explode('/', $source);
        if (count($parts) === 2) {
            $moduleId = str_replace('-', '_', $parts[0]);
            $handler = str_replace('-', '_', $parts[1]);
            $fnName = $moduleId . '_' . $handler;
            if (function_exists($fnName)) {
                $result = $fnName($stateName, $context);
                return is_array($result) ? $result : null;
            }
        }

        // Fallback: try capability-based resolution via the app
        if (function_exists('app') && ($app = @app()) !== null) {
            try {
                if (method_exists($app, 'capabilities')) {
                    $caps = $app->capabilities();
                    if (method_exists($caps, 'call')) {
                        $result = $caps->call("state.{$source}", [
                            'state_name' => $stateName,
                            'context' => $context,
                        ]);
                        return is_array($result) ? $result : null;
                    }
                }
            } catch (\Throwable $e) {
                // Capability not available — return null
            }
        }

        return null;
    }

    /**
     * Parse simple key="value" attributes from a string without resolving
     * template variables — used by renderEntityViewConfig for {field}/{action}.
     *
     * @param string $str Attribute string like name="view" url="/test/{id}"
     * @return array<string, string> Parsed attribute key => value map
     */
    private function parseSimpleAttrs(string $str): array
    {
        $attrs = [];
        preg_match_all('/([\w-]+)="([^"]*)"/', $str, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $attrs[$m[1]] = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        }
        return $attrs;
    }

    /**
     * Render {ikb_component} — server-rendered component bridge.
     *
     * Delegates to the configured frontend framework bridge.
     * Default bridge is Alpine.js (x-data="ikbComponent(...)"), but can be
     * overridden per-invocation via the "bridge" attribute.
     *
     * Usage:
     *   {ikb_component name="employee-profile" data="selectedEmployee"}
     *     <div class="...">{name}</div>
     *     <div class="...">{position}</div>
     *   {/ikb_component}
     *
     * With explicit bridge:
     *   {ikb_component name="employee-profile" data="selectedEmployee" bridge="htmx"}
     *   {ikb_component name="employee-profile" data="selectedEmployee" bridge="custom"}
     *
     * @param array $attrs Component attributes: name, data, class, bridge
     * @param string $children Compiled child content
     * @param array $context Template rendering context
     * @return string HTML with framework-specific attributes
     */
    private function renderIkbComponent(array $attrs, string $children, array $context): string
    {
        $name = $attrs['name'] ?? 'component';
        $dataVar = $attrs['data'] ?? '';

        // Resolve the data variable from template context
        $data = [];
        if ($dataVar !== '' && isset($context[$dataVar])) {
            $data = $context[$dataVar];
        } elseif ($dataVar !== '') {
            // Support dot-path for nested data
            $segments = explode('.', $dataVar);
            $current = $context;
            foreach ($segments as $seg) {
                if (is_array($current) && isset($current[$seg])) {
                    $current = $current[$seg];
                } else {
                    $current = [];
                    break;
                }
            }
            if (is_array($current)) {
                $data = $current;
            }
        }

        // Serialize data as JSON
        $json = htmlspecialchars(
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ENT_QUOTES,
            'UTF-8'
        );

        // Resolve bridge and delegate rendering
        $bridge = BridgeManager::resolve($attrs['bridge'] ?? 'alpine');
        return $bridge->renderComponent($name, $json, $children, $attrs);
    }

    private function renderIsland(array $attrs, string $children): string
    {
        $name = $attrs['name'] ?? 'island';
        $strategy = $attrs['strategy'] ?? 'load';
        $class = $attrs['class'] ?? '';
        
        $strategyAttr = match($strategy) {
            'visible' => 'data-hydrate="visible"', 'idle' => 'data-hydrate="idle"',
            'interaction' => 'data-hydrate="interaction"', default => 'data-hydrate="load"',
        };
        
        return "<div data-island=\"{$name}\" {$strategyAttr} class=\"{$class}\">{$children}</div>";
    }

    /**
     * Render {ikb_entity_list} — delegates to the DefaultEntityRenderer service.
     *
     * Replaces the former EntityRenderingTrait::renderEntityList().
     */
    private function renderEntityListViaService(array $attrs, string $children, array $context): string
    {
        if (!\function_exists('app') || ($app = \app()) === null || !method_exists($app, 'entityRenderers')) {
            return '<div class="ikb-entity-error px-4 py-2 text-sm text-red-600">Entity renderer service not available.</div>';
        }

        $source = (string)($attrs['source'] ?? '');
        $view = (string)($attrs['view'] ?? 'compact');
        $overrides = [];
        if (isset($attrs['limit'])) { $overrides['limit'] = (int)$attrs['limit']; }
        if (isset($attrs['actions'])) { $overrides['actions'] = array_map('trim', explode(',', (string)$attrs['actions'])); }

        // Parse filter attribute: filter="project_id={project.id},status=approved"
        // Resolves {var.path} references from the template context.
        if (isset($attrs['filter']) && $attrs['filter'] !== '') {
            $overrides['filters'] = [];
            foreach (explode(',', (string)$attrs['filter']) as $pair) {
                $pair = trim($pair);
                if ($pair === '' || !str_contains($pair, '=')) continue;
                [$key, $rawVal] = explode('=', $pair, 2);
                $key = trim($key);
                $rawVal = trim($rawVal);
                // Resolve {var.path} from context if present
                if (str_starts_with($rawVal, '{') && str_ends_with($rawVal, '}')) {
                    $varPath = substr($rawVal, 1, -1);
                    $segments = explode('.', $varPath);
                    $current = $context;
                    foreach ($segments as $seg) {
                        $current = is_array($current) && isset($current[$seg]) ? $current[$seg] : null;
                        if ($current === null) break;
                    }
                    $overrides['filters'][$key] = $current ?? $rawVal;
                } else {
                    $overrides['filters'][$key] = $rawVal;
                }
            }
        }

        $resolved = null;
        try {
            if (method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolve($source, $view, $overrides);
            }
        } catch (\Throwable $e) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg">'
                . '<p class="text-sm text-red-600">Failed to resolve entity list: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        if ($resolved === null || !empty($resolved['error'])) {
            $errorMsg = $resolved['error'] ?? '';
            $emptyMessage = (string)($attrs['empty'] ?? '');
            $class = (string)($attrs['class'] ?? '');
            if ($errorMsg !== '' && $emptyMessage !== '' && (
                str_contains($errorMsg, 'Capability not found') ||
                str_contains($errorMsg, 'Data source unavailable') ||
                str_contains($errorMsg, 'No view contract')
            )) {
                return '<div class="ikb-entity-list--empty text-center py-8 text-gray-500 ' . $class . '">' . htmlspecialchars($emptyMessage, ENT_QUOTES, 'UTF-8') . '</div>';
            }
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">' . htmlspecialchars($errorMsg ?: 'Unable to load data.', ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        $rows = $resolved['rows'] ?? [];
        $attrs['_children'] = $children;
        $excerptLength = (int)($attrs['excerptLength'] ?? $attrs['excerpt_length'] ?? $attrs['excerpt-length'] ?? 0);
        $subtitleField = is_array($resolved['view']['role_fields'] ?? null)
            ? (string)($resolved['view']['role_fields']['subtitle'] ?? '')
            : '';
        if ($excerptLength > 0 && $subtitleField !== '') {
            foreach ($rows as &$row) {
                if (!is_array($row) || !isset($row[$subtitleField])) {
                    continue;
                }
                $value = (string)$row[$subtitleField];
                $row[$subtitleField] = mb_strlen($value) > $excerptLength
                    ? mb_substr($value, 0, max(0, $excerptLength - 3)) . '...'
                    : $value;
            }
            unset($row);
        }

        // Validate requested fields against the view contract
        if (isset($attrs['fields']) && is_string($attrs['fields']) && $attrs['fields'] !== '') {
            $requestedFields = array_map('trim', explode(',', $attrs['fields']));
            $contractFields = $resolved['view']['fields'] ?? null;
            if (is_array($contractFields) && $contractFields !== []) {
                $unknownFields = array_diff($requestedFields, $contractFields);
                if (!empty($unknownFields)) {
                    $this->engine->logError("ikb_entity_list '{$source}': unknown field(s) '" . implode(', ', $unknownFields)
                        . "' — valid fields: " . implode(', ', $contractFields));
                }
            }
        }

        if (empty($rows)) {
            $msg = (string)($attrs['empty'] ?: $resolved['view']['empty_state'] ?? 'No records found.');
            return '<div class="ikb-entity-list--empty text-center py-8 text-gray-500 ' . (string)($attrs['class'] ?? '') . '">' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</div>';
        }

        return $app->entityRenderers()->renderList($rows, $resolved['view'], $attrs, $context);
    }

    /**
     * Render {ikb_entity_detail} — delegates to the DefaultEntityRenderer service.
     *
     * Replaces the former EntityRenderingTrait::renderEntityDetail().
     */
    private function renderEntityDetailViaService(array $attrs, string $children, array $context): string
    {
        if (!\function_exists('app') || ($app = \app()) === null || !method_exists($app, 'entityRenderers')) {
            return '<div class="ikb-entity-error px-4 py-2 text-sm text-red-600">Entity renderer service not available.</div>';
        }

        $source = (string)($attrs['source'] ?? '');
        $entityId = (string)($attrs['id'] ?? $attrs['entity_id'] ?? '');
        $view = (string)($attrs['view'] ?? 'detailed');
        $class = (string)($attrs['class'] ?? '');
        $requestedFields = isset($attrs['fields']) ? array_map('trim', explode(',', (string)$attrs['fields'])) : null;

        if ($source === '') {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Missing source attribute on ikb_entity_detail.</p></div>';
        }
        if ($entityId === '') {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Missing id attribute on ikb_entity_detail.</p></div>';
        }

        $resolved = null;
        try {
            if (method_exists($app, 'entityViews')) {
                $resolved = $app->entityViews()->resolveDetail($source, $entityId, $view);
            }
        } catch (\Throwable $e) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Failed to resolve entity detail: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        if ($resolved === null || !empty($resolved['error'])) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">' . htmlspecialchars($resolved['error'] ?? 'Entity not found.', ENT_QUOTES, 'UTF-8') . '</p></div>';
        }

        $entity = $resolved['entity'] ?? null;
        if ($entity === null || empty($entity)) {
            return '<div class="ikb-entity-error flex items-center justify-center py-8 px-4 bg-red-50 border border-red-200 rounded-lg ' . $class . '">'
                . '<p class="text-sm text-red-600">Entity not found.</p></div>';
        }

        $attrs['_children'] = $children;
        $attrs['fields'] = $requestedFields ?? ($resolved['view']['fields'] ?? null);

        return $app->entityRenderers()->renderDetail($entity, $resolved['view'], $attrs, $context);
    }
}
