<?php
/**
 * DiSyL v6.0 Client Block Registry
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 6.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

class ClientBlockRegistry
{
    private array $blocks = [];
    
    public function add(ClientBlock $block): void
    {
        $this->blocks[$block->getId()] = $block;
    }
    
    public function getAll(): array
    {
        return $this->blocks;
    }
    
    public function renderScripts(): string
    {
        if (empty($this->blocks)) return '';
        
        $scripts = '<script type="module">';
        foreach ($this->blocks as $block) {
            $scripts .= "\n" . $block->toModule();
        }
        $scripts .= "\n</script>";
        
        return $scripts;
    }
    
    public function clear(): void
    {
        $this->blocks = [];
    }
}
