<?php
/**
 * DiSyL v11.0 HTMX Out-of-Band Swap
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * Out-of-band swap target
 */
class OOBSwap
{
    public function __construct(
        public readonly string $targetId,
        public readonly string $content,
        public readonly SwapStrategy $strategy = SwapStrategy::OUTER_HTML
    ) {}
    
    public function render(): string
    {
        $swap = $this->strategy->value;
        $safeId = htmlspecialchars($this->targetId, ENT_QUOTES, 'UTF-8');
        $safeContent = htmlspecialchars($this->content, ENT_QUOTES, 'UTF-8');
        return "<div id=\"{$safeId}\" hx-swap-oob=\"{$swap}\">{$safeContent}</div>";
    }
}
