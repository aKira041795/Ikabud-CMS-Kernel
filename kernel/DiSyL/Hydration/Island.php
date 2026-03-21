<?php
/**
 * DiSyL v11.0 Island Component
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Island component definition
 */
class Island
{
    public function __construct(
        public readonly string $id,
        public readonly string $component,
        public readonly array $props = [],
        public readonly HydrationStrategy $strategy = HydrationStrategy::LOAD,
        public readonly ?string $mediaQuery = null,
        public readonly ?string $fallback = null
    ) {}
    
    public function toManifestEntry(): array
    {
        return [
            'id' => $this->id,
            'component' => $this->component,
            'strategy' => $this->strategy->value,
            'media' => $this->mediaQuery,
        ];
    }
}
