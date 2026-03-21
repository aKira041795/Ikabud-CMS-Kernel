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
        $json = $this->generate();
        return "<script type=\"application/json\" id=\"disyl-islands-manifest\">{$json}</script>";
    }
}
