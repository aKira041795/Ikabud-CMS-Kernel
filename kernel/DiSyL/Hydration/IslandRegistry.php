<?php
/**
 * DiSyL v11.0 Island Registry
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Islands registry
 */
class IslandRegistry
{
    /** @var array<string, Island> */
    private array $islands = [];
    
    /** @var array<string, string> Component to module mapping */
    private array $componentModules = [];
    
    public function register(Island $island): void
    {
        $this->islands[$island->id] = $island;
    }
    
    public function registerComponent(string $name, string $modulePath): void
    {
        $this->componentModules[$name] = $modulePath;
    }
    
    public function getIslands(): array
    {
        return $this->islands;
    }
    
    public function getIsland(string $id): ?Island
    {
        return $this->islands[$id] ?? null;
    }
    
    public function getComponentModule(string $name): ?string
    {
        return $this->componentModules[$name] ?? null;
    }
    
    public function clear(): void
    {
        $this->islands = [];
    }
}
