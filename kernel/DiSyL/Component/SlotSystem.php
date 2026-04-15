<?php
/**
 * DiSyL Slot System v1.0.0
 * 
 * Handles slot content distribution for components.
 * 
 * Slot Types:
 * - Default slot: {slot} or {slot /}
 * - Named slots: {slot header} ... {/slot}
 * - Scoped slots: {slot item(data)} ... {/slot}
 * 
 * Usage in components:
 *   {component Card}
 *     {slots}
 *       {slot header}
 *       {slot default}
 *       {slot footer}
 *     {/slots}
 *     {template}
 *       <div class="card">
 *         <header>{slot header /}</header>
 *         <main>{slot /}</main>
 *         <footer>{slot footer /}</footer>
 *       </div>
 *     {/template}
 *   {/component}
 * 
 * Usage when consuming:
 *   {Card}
 *     {#header}Card Title{/header}
 *     Main content goes here
 *     {#footer}Footer content{/footer}
 *   {/Card}
 * 
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Component;

/**
 * Slot definition in a component.
 *
 * This is the canonical SlotDefinition — imported by ComponentDefinition.php.
 */
class SlotDefinition implements \JsonSerializable
{
    /** @var string Slot name */
    public readonly string $name;
    
    /** @var array Slot parameters for scoped slots */
    public readonly array $parameters;
    
    /** @var array|null Default content AST */
    public ?array $defaultContent = null;
    
    /** @var bool Whether slot is required */
    public bool $required = false;
    
    public function __construct(
        string $name = 'default',
        array $parameters = [],
        ?array $defaultContent = null,
        bool $required = false
    ) {
        $this->name = $name;
        $this->parameters = $parameters;
        $this->defaultContent = $defaultContent;
        $this->required = $required;
    }
    
    /**
     * Check if this is a scoped slot
     */
    public function isScoped(): bool
    {
        return !empty($this->parameters);
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'parameters' => $this->parameters,
            'defaultContent' => $this->defaultContent,
            'required' => $this->required,
            'isScoped' => $this->isScoped(),
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Create from AST node array (backward-compatible factory).
     */
    public static function fromAST(array $node): self
    {
        $slot = new self(
            $node['name'] ?? 'default',
            $node['params'] ?? $node['parameters'] ?? [],
            $node['fallback'] ?? $node['defaultContent'] ?? null,
            (bool)($node['required'] ?? false)
        );
        return $slot;
    }
}

/**
 * Slot content provided by parent
 */
class SlotContent
{
    /** @var string Slot name */
    public readonly string $name;
    
    /** @var array Content AST nodes */
    public readonly array $content;
    
    /** @var array Props passed to slot */
    public readonly array $props;
    
    /** @var string|null Scoped variable name for slot props */
    public readonly ?string $scopeVariable;
    
    public function __construct(
        string $name = 'default',
        array $content = [],
        array $props = [],
        ?string $scopeVariable = null
    ) {
        $this->name = $name;
        $this->content = $content;
        $this->props = $props;
        $this->scopeVariable = $scopeVariable;
    }
    
    /**
     * Check if content is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->content);
    }
    
    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'content' => $this->content,
            'props' => $this->props,
            'scopeVariable' => $this->scopeVariable,
        ];
    }
}

/**
 * Slot context for rendering
 */
class SlotContext
{
    /** @var array<string, SlotContent> Named slot contents */
    private array $slots = [];
    
    /** @var SlotContent|null Default slot content */
    private ?SlotContent $defaultSlot = null;
    
    /** @var array<string, SlotDefinition> Slot definitions from component */
    private array $definitions = [];
    
    /**
     * Constructor
     */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $def) {
            if ($def instanceof SlotDefinition) {
                $this->definitions[$def->name] = $def;
            }
        }
    }
    
    /**
     * Add slot content
     */
    public function addContent(SlotContent $content): void
    {
        if ($content->name === 'default') {
            $this->defaultSlot = $content;
        } else {
            $this->slots[$content->name] = $content;
        }
    }
    
    /**
     * Get slot content by name
     */
    public function getContent(string $name = 'default'): ?SlotContent
    {
        if ($name === 'default') {
            return $this->defaultSlot;
        }
        return $this->slots[$name] ?? null;
    }
    
    /**
     * Check if slot has content
     */
    public function hasContent(string $name = 'default'): bool
    {
        $content = $this->getContent($name);
        return $content !== null && !$content->isEmpty();
    }
    
    /**
     * Get slot definition
     */
    public function getDefinition(string $name): ?SlotDefinition
    {
        return $this->definitions[$name] ?? null;
    }
    
    /**
     * Get all slot names with content
     */
    public function getFilledSlotNames(): array
    {
        $names = array_keys($this->slots);
        if ($this->defaultSlot !== null && !$this->defaultSlot->isEmpty()) {
            $names[] = 'default';
        }
        return $names;
    }
    
    /**
     * Validate that required slots are filled
     */
    public function validate(): array
    {
        $errors = [];
        
        foreach ($this->definitions as $name => $def) {
            if ($def->required && !$this->hasContent($name)) {
                $errors[] = "Required slot '{$name}' is not provided";
            }
        }
        
        return $errors;
    }
    
    /**
     * Get content or default for a slot
     */
    public function getContentOrDefault(string $name = 'default'): ?array
    {
        $content = $this->getContent($name);
        
        if ($content !== null && !$content->isEmpty()) {
            return $content->content;
        }
        
        $def = $this->getDefinition($name);
        if ($def !== null && $def->defaultContent !== null) {
            return $def->defaultContent;
        }
        
        return null;
    }
}

/**
 * Slot parser - extracts slot content from component children
 */
class SlotParser
{
    /**
     * Parse children nodes into slot contents
     * 
     * Children can be:
     * - Plain content (goes to default slot)
     * - Named slot blocks: {#slotName}...{/slotName}
     * - Slot elements: {slot name="slotName"}...{/slot}
     */
    public function parseChildren(array $children): SlotContext
    {
        $context = new SlotContext();
        $defaultContent = [];
        
        foreach ($children as $child) {
            $type = $child['type'] ?? '';
            
            // Named slot content: {#header}...{/header}
            if ($type === 'SlotContent' || $type === 'slot_content') {
                $name = $child['name'] ?? 'default';
                $content = $child['content'] ?? $child['children'] ?? [];
                $props = $child['props'] ?? [];
                $scopeVar = $child['scopeVariable'] ?? $child['as'] ?? null;
                
                $context->addContent(new SlotContent($name, $content, $props, $scopeVar));
            }
            // Tag with slot attribute
            elseif ($type === 'tag' && isset($child['attrs']['slot'])) {
                $name = $child['attrs']['slot'];
                unset($child['attrs']['slot']);
                
                $context->addContent(new SlotContent($name, [$child]));
            }
            // Everything else goes to default slot
            else {
                $defaultContent[] = $child;
            }
        }
        
        // Add default slot content if any
        if (!empty($defaultContent)) {
            $context->addContent(new SlotContent('default', $defaultContent));
        }
        
        return $context;
    }
    
    /**
     * Parse slot definitions from component's slots block
     */
    public function parseDefinitions(array $slotsBlock): array
    {
        $definitions = [];
        
        foreach ($slotsBlock as $slotNode) {
            $type = $slotNode['type'] ?? '';
            
            if ($type === 'SlotDeclaration' || $type === 'slot_decl') {
                $name = $slotNode['name'] ?? 'default';
                $params = $slotNode['parameters'] ?? $slotNode['params'] ?? [];
                $defaultContent = $slotNode['default'] ?? $slotNode['defaultContent'] ?? null;
                $required = $slotNode['required'] ?? false;
                
                $definitions[$name] = new SlotDefinition($name, $params, $defaultContent, $required);
            }
        }
        
        return $definitions;
    }
}

/**
 * Slot renderer - renders slot content with proper context
 */
class SlotRenderer
{
    /** @var callable Render function for AST nodes */
    private $renderFn;
    
    /** @var callable Context merger function */
    private $contextMerger;
    
    public function __construct(callable $renderFn, ?callable $contextMerger = null)
    {
        $this->renderFn = $renderFn;
        $this->contextMerger = $contextMerger ?? fn($ctx, $add) => array_merge($ctx, $add);
    }
    
    /**
     * Render a slot
     * 
     * @param SlotContext $slotContext The slot context
     * @param string $name Slot name
     * @param array $slotProps Props to pass to scoped slot
     * @param array $parentContext Parent rendering context
     * @return string Rendered HTML
     */
    public function render(
        SlotContext $slotContext,
        string $name = 'default',
        array $slotProps = [],
        array $parentContext = []
    ): string {
        $content = $slotContext->getContentOrDefault($name);
        
        if ($content === null) {
            return '';
        }
        
        $slotContent = $slotContext->getContent($name);
        $definition = $slotContext->getDefinition($name);
        
        // Build render context
        $renderContext = $parentContext;
        
        // For scoped slots, add slot props to context
        if ($slotContent !== null && $slotContent->scopeVariable !== null) {
            // Scoped slot: {#items as item}
            $renderContext[$slotContent->scopeVariable] = $slotProps;
        } elseif ($definition !== null && $definition->isScoped()) {
            // Slot defines parameters: {slot items(item)}
            foreach ($definition->parameters as $i => $param) {
                $paramName = $param['name'] ?? $param;
                if (isset($slotProps[$paramName])) {
                    $renderContext[$paramName] = $slotProps[$paramName];
                } elseif (isset($slotProps[$i])) {
                    $renderContext[$paramName] = $slotProps[$i];
                }
            }
        }
        
        // Merge any explicit props
        if ($slotContent !== null && !empty($slotContent->props)) {
            $renderContext = ($this->contextMerger)($renderContext, $slotContent->props);
        }
        
        // Render content
        $html = '';
        foreach ($content as $node) {
            $html .= ($this->renderFn)($node, $renderContext);
        }
        
        return $html;
    }
    
    /**
     * Check if slot has content
     */
    public function hasContent(SlotContext $slotContext, string $name = 'default'): bool
    {
        return $slotContext->hasContent($name) || 
               ($slotContext->getDefinition($name)?->defaultContent !== null);
    }
}

/**
 * Slot fallback - handles slot fallback content
 */
class SlotFallback
{
    /**
     * Create a fallback slot definition with default content
     */
    public static function withDefault(string $name, array $defaultContent): SlotDefinition
    {
        return new SlotDefinition($name, [], $defaultContent, false);
    }
    
    /**
     * Create a required slot definition
     */
    public static function required(string $name, array $parameters = []): SlotDefinition
    {
        return new SlotDefinition($name, $parameters, null, true);
    }
    
    /**
     * Create a scoped slot definition
     */
    public static function scoped(string $name, array $parameters, ?array $defaultContent = null): SlotDefinition
    {
        return new SlotDefinition($name, $parameters, $defaultContent, false);
    }
}
