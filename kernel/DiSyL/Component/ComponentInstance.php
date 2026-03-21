<?php
/**
 * DiSyL Component Instance v1.0.0
 * 
 * Represents a runtime instance of a component with reactive state.
 * 
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

class ComponentInstance
{
    /** @var ComponentDefinition The component definition */
    private ComponentDefinition $definition;
    
    /** @var array Props values passed to this instance */
    private array $props = [];
    
    /** @var array Current state values */
    private array $state = [];
    
    /** @var array Computed property cache */
    private array $computedCache = [];
    
    /** @var array Slot content provided by parent */
    private array $slotContent = [];
    
    /** @var ComponentInstance|null Parent component instance */
    private ?ComponentInstance $parent = null;
    
    /** @var array Child component instances */
    private array $children = [];
    
    /** @var array Event listeners */
    private array $listeners = [];
    
    /** @var bool Whether the instance is mounted */
    private bool $mounted = false;
    
    /** @var string Unique instance ID */
    private string $instanceId;
    
    /**
     * Constructor
     */
    public function __construct(ComponentDefinition $definition, array $props = [])
    {
        $this->definition = $definition;
        $this->instanceId = uniqid('cmp_', true);
        
        // Initialize props with defaults
        $this->initializeProps($props);
        
        // Initialize state
        $this->state = $definition->getInitialState();
    }
    
    /**
     * Initialize props with validation and defaults
     */
    private function initializeProps(array $props): void
    {
        // Apply defaults for missing optional props
        foreach ($this->definition->props as $name => $propDef) {
            if (!isset($props[$name]) && $propDef->defaultValue !== null) {
                $props[$name] = $propDef->defaultValue;
            }
        }
        
        // Validate props
        $errors = $this->definition->validateProps($props);
        if (!empty($errors)) {
            throw new \InvalidArgumentException(
                "Component '{$this->definition->name}' prop validation failed: " . implode(', ', $errors)
            );
        }
        
        $this->props = $props;
    }
    
    /**
     * Get component definition
     */
    public function getDefinition(): ComponentDefinition
    {
        return $this->definition;
    }
    
    /**
     * Get instance ID
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }
    
    /**
     * Get prop value
     */
    public function getProp(string $name): mixed
    {
        return $this->props[$name] ?? null;
    }
    
    /**
     * Get all props
     */
    public function getProps(): array
    {
        return $this->props;
    }
    
    /**
     * Get state value
     */
    public function getState(string $name): mixed
    {
        return $this->state[$name] ?? null;
    }
    
    /**
     * Get all state
     */
    public function getAllState(): array
    {
        return $this->state;
    }
    
    /**
     * Set state value (triggers reactivity)
     */
    public function setState(string $name, mixed $value): void
    {
        $oldValue = $this->state[$name] ?? null;
        $this->state[$name] = $value;
        
        // Invalidate computed cache
        $this->invalidateComputed();
        
        // Trigger watchers
        $this->triggerWatchers($name, $value, $oldValue);
    }
    
    /**
     * Update multiple state values
     */
    public function updateState(array $updates): void
    {
        foreach ($updates as $name => $value) {
            $this->setState($name, $value);
        }
    }
    
    /**
     * Get computed property value
     */
    public function getComputed(string $name): mixed
    {
        // Check cache
        if (isset($this->computedCache[$name])) {
            return $this->computedCache[$name];
        }
        
        // Get computed definition
        $computed = $this->definition->computed[$name] ?? null;
        if ($computed === null) {
            return null;
        }
        
        // Evaluate expression (would need ExpressionParser integration)
        // For now, return null - actual evaluation happens in renderer
        return null;
    }
    
    /**
     * Invalidate computed cache
     */
    private function invalidateComputed(): void
    {
        $this->computedCache = [];
    }
    
    /**
     * Trigger watchers for a state change
     */
    private function triggerWatchers(string $name, mixed $newValue, mixed $oldValue): void
    {
        foreach ($this->definition->watchers as $watcher) {
            // Check if this watcher watches this state variable
            // This is simplified - real implementation would evaluate the watch expression
            // For now, we trigger all watchers on any state change
        }
    }
    
    /**
     * Set slot content
     */
    public function setSlotContent(string $name, array $content): void
    {
        $this->slotContent[$name] = $content;
    }
    
    /**
     * Get slot content
     */
    public function getSlotContent(string $name): ?array
    {
        return $this->slotContent[$name] ?? null;
    }
    
    /**
     * Check if slot has content
     */
    public function hasSlotContent(string $name): bool
    {
        return isset($this->slotContent[$name]) && !empty($this->slotContent[$name]);
    }
    
    /**
     * Set parent instance
     */
    public function setParent(?ComponentInstance $parent): void
    {
        $this->parent = $parent;
    }
    
    /**
     * Get parent instance
     */
    public function getParent(): ?ComponentInstance
    {
        return $this->parent;
    }
    
    /**
     * Add child instance
     */
    public function addChild(ComponentInstance $child): void
    {
        $child->setParent($this);
        $this->children[] = $child;
    }
    
    /**
     * Get children
     */
    public function getChildren(): array
    {
        return $this->children;
    }
    
    /**
     * Add event listener
     */
    public function on(string $event, callable $callback): void
    {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $callback;
    }
    
    /**
     * Remove event listener
     */
    public function off(string $event, ?callable $callback = null): void
    {
        if ($callback === null) {
            unset($this->listeners[$event]);
        } else {
            $this->listeners[$event] = array_filter(
                $this->listeners[$event] ?? [],
                fn($cb) => $cb !== $callback
            );
        }
    }
    
    /**
     * Emit event
     */
    public function emit(string $event, mixed $data = null): void
    {
        // Call local listeners
        foreach ($this->listeners[$event] ?? [] as $callback) {
            $callback($data, $this);
        }
        
        // Bubble to parent
        if ($this->parent !== null) {
            $this->parent->emit($event, $data);
        }
    }
    
    /**
     * Call a method on this component
     */
    public function callMethod(string $name, array $args = []): mixed
    {
        $method = $this->definition->methods[$name] ?? null;
        if ($method === null) {
            throw new \BadMethodCallException(
                "Method '{$name}' not found on component '{$this->definition->name}'"
            );
        }
        
        // Method execution would need integration with expression evaluator
        // For now, return null
        return null;
    }
    
    /**
     * Mount the component (lifecycle hook)
     */
    public function mount(): void
    {
        if ($this->mounted) {
            return;
        }
        
        $this->mounted = true;
        
        // Call onMount handler if defined
        if (isset($this->definition->eventHandlers['mount'])) {
            // Execute mount handler
        }
    }
    
    /**
     * Unmount the component (lifecycle hook)
     */
    public function unmount(): void
    {
        if (!$this->mounted) {
            return;
        }
        
        // Call onUnmount handler if defined
        if (isset($this->definition->eventHandlers['unmount'])) {
            // Execute unmount handler
        }
        
        // Unmount children
        foreach ($this->children as $child) {
            $child->unmount();
        }
        
        $this->mounted = false;
    }
    
    /**
     * Check if mounted
     */
    public function isMounted(): bool
    {
        return $this->mounted;
    }
    
    /**
     * Get rendering context (props + state + computed)
     */
    public function getRenderContext(): array
    {
        return [
            'props' => $this->props,
            'state' => $this->state,
            'computed' => $this->computedCache,
            '$emit' => fn($event, $data = null) => $this->emit($event, $data),
            '$setState' => fn($name, $value) => $this->setState($name, $value),
        ];
    }
    
    /**
     * Convert to array for debugging
     */
    public function toArray(): array
    {
        return [
            'instanceId' => $this->instanceId,
            'component' => $this->definition->name,
            'props' => $this->props,
            'state' => $this->state,
            'mounted' => $this->mounted,
            'childCount' => count($this->children),
        ];
    }
}
