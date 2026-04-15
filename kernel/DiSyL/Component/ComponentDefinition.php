<?php
/**
 * DiSyL Component Definition v1.0.0
 * 
 * Represents a parsed component block from DiSyL v3.1 grammar.
 * 
 * Component structure:
 * {component MyComponent extends BaseComponent}
 *   {props}
 *     {prop title: string required}
 *     {prop count: number = 0}
 *   {/props}
 *   {state}
 *     {let isOpen: bool = false}
 *   {/state}
 *   {computed fullName = firstName + " " + lastName}
 *   {watch count}...{/watch}
 *   {template}...{/template}
 *   {style scoped}...{/style}
 * {/component}
 * 
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

class ComponentDefinition implements \JsonSerializable
{
    /** @var string Component name */
    public string $name;
    
    /** @var string|null Parent component name */
    public ?string $extends = null;
    
    /** @var array Props definitions */
    public array $props = [];
    
    /** @var array Slot definitions */
    public array $slots = [];
    
    /** @var array State variables */
    public array $state = [];
    
    /** @var array Computed properties */
    public array $computed = [];
    
    /** @var array Watch declarations */
    public array $watchers = [];
    
    /** @var array Event handlers */
    public array $eventHandlers = [];
    
    /** @var array Methods (functions) */
    public array $methods = [];
    
    /** @var array|null Template AST */
    public ?array $template = null;
    
    /** @var array|null Style block */
    public ?array $style = null;
    
    /** @var array|null Client-side JavaScript */
    public ?array $client = null;
    
    /** @var array Decorators applied to component */
    public array $decorators = [];
    
    /** @var array Source location */
    public array $loc = [];
    
    /**
     * Constructor
     */
    public function __construct(string $name, ?string $extends = null)
    {
        $this->name = $name;
        $this->extends = $extends;
    }
    
    /**
     * Add a prop definition
     */
    public function addProp(PropDefinition $prop): self
    {
        $this->props[$prop->name] = $prop;
        return $this;
    }
    
    /**
     * Add a slot definition
     */
    public function addSlot(SlotDefinition $slot): self
    {
        $this->slots[$slot->name] = $slot;
        return $this;
    }
    
    /**
     * Add a state variable
     */
    public function addState(string $name, mixed $initialValue, ?array $type = null): self
    {
        $this->state[$name] = [
            'name' => $name,
            'initialValue' => $initialValue,
            'type' => $type,
        ];
        return $this;
    }
    
    /**
     * Add a computed property
     */
    public function addComputed(string $name, array $expression, ?array $type = null): self
    {
        $this->computed[$name] = [
            'name' => $name,
            'expression' => $expression,
            'type' => $type,
        ];
        return $this;
    }
    
    /**
     * Add a watcher
     */
    public function addWatcher(array $expression, array $body, array $options = []): self
    {
        $this->watchers[] = [
            'expression' => $expression,
            'body' => $body,
            'options' => $options,
        ];
        return $this;
    }
    
    /**
     * Add an event handler
     */
    public function addEventHandler(string $event, array $params, array $body): self
    {
        $this->eventHandlers[$event] = [
            'event' => $event,
            'params' => $params,
            'body' => $body,
        ];
        return $this;
    }
    
    /**
     * Add a method
     */
    public function addMethod(string $name, array $params, array $body, ?array $returnType = null): self
    {
        $this->methods[$name] = [
            'name' => $name,
            'params' => $params,
            'body' => $body,
            'returnType' => $returnType,
        ];
        return $this;
    }
    
    /**
     * Set template AST
     */
    public function setTemplate(array $template): self
    {
        $this->template = $template;
        return $this;
    }
    
    /**
     * Set style block
     */
    public function setStyle(string $content, bool $scoped = true, bool $global = false): self
    {
        $this->style = [
            'content' => $content,
            'scoped' => $scoped,
            'global' => $global,
        ];
        return $this;
    }
    
    /**
     * Set client-side JavaScript
     */
    public function setClient(string $content): self
    {
        $this->client = [
            'content' => $content,
        ];
        return $this;
    }
    
    /**
     * Add decorator
     */
    public function addDecorator(string $name, array $args = []): self
    {
        $this->decorators[] = [
            'name' => $name,
            'args' => $args,
        ];
        return $this;
    }
    
    /**
     * Get prop by name
     */
    public function getProp(string $name): ?PropDefinition
    {
        return $this->props[$name] ?? null;
    }
    
    /**
     * Get required props
     */
    public function getRequiredProps(): array
    {
        return array_filter($this->props, fn($p) => $p->required);
    }
    
    /**
     * Get optional props
     */
    public function getOptionalProps(): array
    {
        return array_filter($this->props, fn($p) => !$p->required);
    }
    
    /**
     * Validate props against provided values
     */
    public function validateProps(array $values): array
    {
        $errors = [];
        
        // Check required props
        foreach ($this->getRequiredProps() as $name => $prop) {
            if (!isset($values[$name])) {
                $errors[] = "Missing required prop: {$name}";
            }
        }
        
        // Type checking would go here in the future
        
        return $errors;
    }
    
    /**
     * Get initial state values
     */
    public function getInitialState(): array
    {
        $state = [];
        foreach ($this->state as $name => $def) {
            $state[$name] = $def['initialValue'];
        }
        return $state;
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'type' => 'ComponentDefinition',
            'name' => $this->name,
            'extends' => $this->extends,
            'props' => array_map(fn($p) => $p->toArray(), $this->props),
            'slots' => array_map(fn($s) => $s->toArray(), $this->slots),
            'state' => $this->state,
            'computed' => $this->computed,
            'watchers' => $this->watchers,
            'eventHandlers' => $this->eventHandlers,
            'methods' => $this->methods,
            'template' => $this->template,
            'style' => $this->style,
            'client' => $this->client,
            'decorators' => $this->decorators,
            'loc' => $this->loc,
        ];
    }
    
    /**
     * JSON serialization
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    /**
     * Create from AST node
     */
    public static function fromAST(array $node): self
    {
        $component = new self(
            $node['name'] ?? 'Anonymous',
            $node['extends'] ?? null
        );
        
        $component->loc = $node['loc'] ?? [];
        
        // Process props
        foreach ($node['props'] ?? [] as $propNode) {
            $component->addProp(PropDefinition::fromAST($propNode));
        }
        
        // Process slots
        foreach ($node['slots'] ?? [] as $slotNode) {
            $component->addSlot(SlotDefinition::fromAST($slotNode));
        }
        
        // Process state
        foreach ($node['state'] ?? [] as $stateNode) {
            $component->addState(
                $stateNode['name'] ?? '',
                $stateNode['init'] ?? null,
                $stateNode['type'] ?? null
            );
        }
        
        // Process computed
        foreach ($node['computed'] ?? [] as $computedNode) {
            $component->addComputed(
                $computedNode['name'] ?? '',
                $computedNode['expression'] ?? [],
                $computedNode['type'] ?? null
            );
        }
        
        // Process watchers
        foreach ($node['watchers'] ?? [] as $watchNode) {
            $component->addWatcher(
                $watchNode['expression'] ?? [],
                $watchNode['body'] ?? [],
                $watchNode['options'] ?? []
            );
        }
        
        // Process event handlers
        foreach ($node['eventHandlers'] ?? [] as $eventNode) {
            $component->addEventHandler(
                $eventNode['event'] ?? '',
                $eventNode['params'] ?? [],
                $eventNode['body'] ?? []
            );
        }
        
        // Process methods
        foreach ($node['methods'] ?? [] as $methodNode) {
            $component->addMethod(
                $methodNode['name'] ?? '',
                $methodNode['params'] ?? [],
                $methodNode['body'] ?? [],
                $methodNode['returnType'] ?? null
            );
        }
        
        // Template
        if (isset($node['template'])) {
            $component->setTemplate($node['template']);
        }
        
        // Style
        if (isset($node['style'])) {
            $component->setStyle(
                $node['style']['content'] ?? '',
                $node['style']['scoped'] ?? true,
                $node['style']['global'] ?? false
            );
        }
        
        // Client
        if (isset($node['client'])) {
            $component->setClient($node['client']['content'] ?? '');
        }
        
        // Decorators
        foreach ($node['decorators'] ?? [] as $dec) {
            $component->addDecorator($dec['name'] ?? '', $dec['args'] ?? []);
        }
        
        return $component;
    }
}

/**
 * Prop Definition
 */
class PropDefinition implements \JsonSerializable
{
    public string $name;
    public ?array $type = null;
    public bool $required = false;
    public bool $optional = false;
    public mixed $defaultValue = null;
    public array $decorators = [];
    
    public function __construct(string $name)
    {
        $this->name = $name;
    }
    
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'optional' => $this->optional,
            'defaultValue' => $this->defaultValue,
            'decorators' => $this->decorators,
        ];
    }
    
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
    
    public static function fromAST(array $node): self
    {
        $prop = new self($node['name'] ?? '');
        $prop->type = $node['type'] ?? null;
        $prop->required = $node['required'] ?? false;
        $prop->optional = $node['optional'] ?? false;
        $prop->defaultValue = $node['defaultValue'] ?? null;
        $prop->decorators = $node['decorators'] ?? [];
        return $prop;
    }
}

// SlotDefinition is canonically defined in SlotSystem.php.
// Import it here so ComponentDefinition consumers get the class without a
// separate require.
require_once __DIR__ . '/SlotSystem.php';
