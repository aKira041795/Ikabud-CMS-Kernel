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
        return "<div id=\"{$this->targetId}\" hx-swap-oob=\"{$swap}\">{$this->content}</div>";
    }
}
