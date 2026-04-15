<?php
/**
 * DiSyL v11.0 Client Bundle Generator
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Client bundle script generator
 */
class ClientBundleGenerator
{
    private IslandRegistry $registry;
    private string $runtimePath;
    
    public function __construct(IslandRegistry $registry, string $runtimePath = '/disyl-runtime.js')
    {
        $this->registry = $registry;
        $this->runtimePath = $runtimePath;
    }
    
    public function generateScripts(): string
    {
        $manifest = new IslandManifest($this->registry);
        $output = $manifest->generateScriptTag() . "\n";
        
        // Add preload hints for immediate-load components
        foreach ($this->registry->getIslands() as $island) {
            if ($island->strategy === HydrationStrategy::LOAD) {
                $modulePath = $this->registry->getComponentModule($island->component);
                if ($modulePath) {
                    $safePath = htmlspecialchars($modulePath, ENT_QUOTES, 'UTF-8');
                    $output .= "<link rel=\"modulepreload\" href=\"{$safePath}\">\n";
                }
            }
        }
        
        // Add runtime script
        $safeRuntimePath = htmlspecialchars($this->runtimePath, ENT_QUOTES, 'UTF-8');
        $output .= "<script type=\"module\" src=\"{$safeRuntimePath}\"></script>\n";
        
        return $output;
    }
    
    public function generateInlineScript(): string
    {
        return <<<'JS'
<script type="module">
import { hydrateIslands } from './disyl-runtime.js';

const manifest = JSON.parse(
    document.getElementById('disyl-islands-manifest').textContent
);

hydrateIslands(manifest);
</script>
JS;
    }
}
