<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext\Renderer;

use Ikabud\Kernel\EntityContext\CellRenderContext;
use Ikabud\Kernel\EntityContext\CellRenderResult;
use Ikabud\Kernel\EntityContext\CellRendererInterface;

/**
 * Renders an image thumbnail with optional click-to-view modal.
 *
 * Options:
 *   - thumb_width: int (default 40)
 *   - thumb_height: int (default 40)
 *   - modal: bool — show click-to-view modal (default false)
 *   - modal_url: callable — fn(string $value): string for modal image URL
 *   - alt_field: string — row field to use as alt text (default '')
 *   - placeholder: string — fallback text when no image (default '—')
 *
 * @package Ikabud\Kernel\EntityContext\Renderer
 */
final class ImageCellRenderer implements CellRendererInterface
{
    public function render(CellRenderContext $context): CellRenderResult
    {
        $value = (string)$context->value;
        if ($value === '') {
            $placeholder = (string)($context->options['placeholder'] ?? '—');
            return new CellRenderResult(
                html: '<span class="text-gray-300">' . htmlspecialchars($placeholder, ENT_QUOTES, 'UTF-8') . '</span>',
                text: $placeholder,
            );
        }

        $thumbWidth = (int)($context->options['thumb_width'] ?? 40);
        $thumbHeight = (int)($context->options['thumb_height'] ?? 40);
        $enableModal = !empty($context->options['modal']) || $context->options['arg'] === 'modal';
        $altField = (string)($context->options['alt_field'] ?? '');
        $altText = $altField !== '' ? (string)($context->row[$altField] ?? '') : basename($value);

        $imgUrl = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $altSafe = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');

        $imgTag = "<img src=\"{$imgUrl}\" alt=\"{$altSafe}\" width=\"{$thumbWidth}\" height=\"{$thumbHeight}\" loading=\"lazy\" class=\"rounded object-cover\">";

        if ($enableModal) {
            $modalUrl = $value;
            if (isset($context->options['modal_url']) && is_callable($context->options['modal_url'])) {
                $modalUrl = ($context->options['modal_url'])($value);
            }
            $safeModalUrl = htmlspecialchars((string)$modalUrl, ENT_QUOTES, 'UTF-8');
            $safeAlt = htmlspecialchars($altText, ENT_QUOTES, 'UTF-8');
            // Alpine lightbox: click thumbnail → show overlay with full image
            $html = '<div x-data="{ open: false }" class="inline-block">'
                . '<button type="button" class="bg-transparent border-none cursor-pointer p-0" @click="open = true" title="View full size">'
                . $imgTag
                . '</button>'
                // Overlay
                . '<div x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4" '
                . '@click.self="open = false" @keydown.escape="open = false">'
                . '<div class="relative max-w-[90vw] max-h-[90vh]">'
                . '<button type="button" class="absolute -top-3 -right-3 z-10 w-8 h-8 rounded-full bg-white shadow-md flex items-center justify-center text-gray-700 hover:text-gray-900 text-lg font-bold border-0 cursor-pointer" '
                . '@click="open = false" aria-label="Close">&times;</button>'
                . '<img src="' . $safeModalUrl . '" alt="' . $safeAlt . '" class="max-w-full max-h-[90vh] rounded-lg shadow-2xl" loading="lazy">'
                . '</div>'
                . '</div>'
                . '</div>';
        } else {
            $html = $imgTag;
        }

        return new CellRenderResult(
            html: $html,
            text: $altText,
            exportValue: $value,
            ariaLabel: "Image: {$altText}",
        );
    }
}
