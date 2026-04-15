<?php
/**
 * DiSyL v11.0 Island Manifest
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Island manifest generator
 */
class IslandManifest
{
    private IslandRegistry $registry;
    
    public function __construct(IslandRegistry $registry)
    {
        $this->registry = $registry;
    }
    
    public function generate(): string
    {
        $manifest = [
            'version' => '1.0',
            'islands' => [],
            'modules' => [],
        ];
        
        foreach ($this->registry->getIslands() as $island) {
            $manifest['islands'][$island->id] = $island->toManifestEntry();
            
            $modulePath = $this->registry->getComponentModule($island->component);
            if ($modulePath && !isset($manifest['modules'][$island->component])) {
                $manifest['modules'][$island->component] = $modulePath;
            }
        }
        
        return json_encode($manifest, JSON_PRETTY_PRINT);
    }
    
    public function generateScriptTag(): string
    {
        // Use JSON_HEX_TAG to prevent </script> breakout in embedded JSON.
        $json = json_encode(
            json_decode($this->generate(), true),
            JSON_PRETTY_PRINT | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
        return "<script type=\"application/json\" id=\"disyl-islands-manifest\">{$json}</script>";
    }
}
