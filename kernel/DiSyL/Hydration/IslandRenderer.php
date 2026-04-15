<?php
/**
 * DiSyL v11.0 Island Renderer
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Island HTML renderer
 */
class IslandRenderer
{
    private IslandRegistry $registry;
    
    public function __construct(IslandRegistry $registry)
    {
        $this->registry = $registry;
    }
    
    public function render(Island $island): string
    {
        $this->registry->register($island);
        
        $propsJson = htmlspecialchars(json_encode($island->props), ENT_QUOTES, 'UTF-8');
        $attrs = [
            "data-island=\"{$island->id}\"",
            "data-component=\"{$island->component}\"",
            "data-hydrate=\"{$island->strategy->value}\"",
            "data-props=\"{$propsJson}\"",
        ];
        
        if ($island->mediaQuery) {
            $safeMedia = htmlspecialchars($island->mediaQuery, ENT_QUOTES, 'UTF-8');
            $attrs[] = "data-media=\"{$safeMedia}\"";
        }
        
        $attrString = implode(' ', $attrs);
        $content = $island->fallback ?? '';
        
        return "<div {$attrString}>{$content}</div>";
    }
    
    public function renderWithContent(Island $island, string $ssrContent): string
    {
        $this->registry->register($island);
        
        $propsJson = htmlspecialchars(json_encode($island->props), ENT_QUOTES, 'UTF-8');
        $attrs = [
            "data-island=\"{$island->id}\"",
            "data-component=\"{$island->component}\"",
            "data-hydrate=\"{$island->strategy->value}\"",
            "data-props=\"{$propsJson}\"",
            "data-ssr=\"true\"",
        ];
        
        if ($island->mediaQuery) {
            $safeMedia = htmlspecialchars($island->mediaQuery, ENT_QUOTES, 'UTF-8');
            $attrs[] = "data-media=\"{$safeMedia}\"";
        }
        
        $attrString = implode(' ', $attrs);
        
        return "<div {$attrString}>{$ssrContent}</div>";
    }
}
