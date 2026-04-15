<?php
/**
 * DiSyL v6.0 Reactive State System
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 6.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

class ReactiveState
{
    private array $state = [];
    private array $watchers = [];
    private array $computed = [];
    private string $componentId;
    
    public function __construct(string $componentId, array $initial = [])
    {
        $this->componentId = $componentId;
        foreach ($initial as $key => $value) {
            $this->state[$key] = $value;
        }
    }
    
    public function get(string $key): mixed
    {
        if (isset($this->computed[$key])) {
            return ($this->computed[$key])($this->state);
        }
        return $this->state[$key] ?? null;
    }
    
    public function set(string $key, mixed $value): void
    {
        $old = $this->state[$key] ?? null;
        $this->state[$key] = $value;
        
        if ($old !== $value && isset($this->watchers[$key])) {
            foreach ($this->watchers[$key] as $watcher) {
                $watcher($value, $old);
            }
        }
    }
    
    public function watch(string $key, callable $callback): void
    {
        $this->watchers[$key][] = $callback;
    }
    
    public function computed(string $key, callable $getter): void
    {
        $this->computed[$key] = $getter;
    }
    
    public function toArray(): array { return $this->state; }
    public function getComponentId(): string { return $this->componentId; }
    
    public function toJSON(): string
    {
        return json_encode([
            'id' => $this->componentId,
            'state' => $this->state,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
}
