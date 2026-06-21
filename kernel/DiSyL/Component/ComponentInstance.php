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
     *
     * Evaluates the computed property's expression against the component's
     * props and state scope. Results are cached until state changes.
     *
     * Supported expressions: variable access, binary ops (+, -, *, /, .),
     * property access (user.name), string/number literals.
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
        
        // Build evaluation scope: props take priority, then state
        $scope = array_merge($this->state, $this->props);
        
        // Evaluate the expression against scope
        $result = $this->evaluateExpression($computed['expression'] ?? '', $scope);
        $this->computedCache[$name] = $result;
        return $result;
    }
    
    /**
     * Evaluate a simple expression string against a variable scope.
     * Supports: variables, numbers, strings, binary ops (+, -, *, /, .),
     * and dotted property access (a.b.c).
     */
    private function evaluateExpression(mixed $expr, array $scope): mixed
    {
        if (is_string($expr)) {
            $expr = trim($expr);
            if ($expr === '') return null;
            
            // Numeric literal
            if (is_numeric($expr)) {
                return str_contains($expr, '.') ? (float)$expr : (int)$expr;
            }
            
            // String literal (single or double quoted)
            if ((str_starts_with($expr, "'") && str_ends_with($expr, "'"))
                || (str_starts_with($expr, '"') && str_ends_with($expr, '"'))) {
                return substr($expr, 1, -1);
            }
            
            // Boolean/null literals
            if ($expr === 'true') return true;
            if ($expr === 'false') return false;
            if ($expr === 'null') return null;
            
            // Binary operations (simple left-to-right for common cases)
            // Pattern: left op right
            if (preg_match('/^(.+?)\s*([+\-*\/\.])\s*(.+)$/s', $expr, $m)) {
                $left = $this->evaluateExpression(trim($m[1]), $scope);
                $right = $this->evaluateExpression(trim($m[3]), $scope);
                return match ($m[2]) {
                    '+' => (is_string($left) || is_string($right)) ? $left . $right : $left + $right,
                    '-' => $left - $right,
                    '*' => $left * $right,
                    '/' => $right != 0 ? $left / $right : 0,
                    '.' => (string)$left . (string)$right,
                    default => null,
                };
            }
            
            // Dotted property access: a.b.c
            if (str_contains($expr, '.')) {
                $parts = explode('.', $expr);
                $val = $this->resolveScopeVar(trim($parts[0]), $scope);
                for ($i = 1; $i < count($parts); $i++) {
                    $key = trim($parts[$i]);
                    if (is_array($val) && array_key_exists($key, $val)) {
                        $val = $val[$key];
                    } elseif (is_object($val) && property_exists($val, $key)) {
                        $val = $val->$key;
                    } else {
                        return null;
                    }
                }
                return $val;
            }
            
            // Simple variable lookup
            return $this->resolveScopeVar($expr, $scope);
        }
        
        // If expression is already an AST array from the parser, attempt structured evaluation
        if (is_array($expr)) {
            return $this->evaluateAstNode($expr, $scope);
        }
        
        return $expr;
    }
    
    /**
     * Resolve a variable name from the component scope.
     */
    private function resolveScopeVar(string $name, array $scope): mixed
    {
        return $scope[$name] ?? null;
    }
    
    /**
     * Evaluate a structured AST node array from the component parser.
     */
    private function evaluateAstNode(array $node, array $scope): mixed
    {
        $type = $node['type'] ?? '';
        
        return match ($type) {
            'literal' => $node['value'] ?? null,
            'identifier' => $this->resolveScopeVar($node['name'] ?? '', $scope),
            'property_access' => $this->evaluatePropertyAccess($node, $scope),
            'binary_op' => $this->evaluateBinaryOp($node, $scope),
            'unary_op' => $this->evaluateUnaryOp($node, $scope),
            'array' => array_map(fn($e) => $this->evaluateExpression($e, $scope), $node['elements'] ?? []),
            default => null,
        };
    }
    
    private function evaluatePropertyAccess(array $node, array $scope): mixed
    {
        $object = $this->evaluateExpression($node['object'] ?? '', $scope);
        $property = $node['property'] ?? '';
        if (is_array($object) && array_key_exists($property, $object)) {
            return $object[$property];
        }
        if (is_object($object) && property_exists($object, $property)) {
            return $object->$property;
        }
        return null;
    }
    
    private function evaluateBinaryOp(array $node, array $scope): mixed
    {
        $left = $this->evaluateExpression($node['left'] ?? '', $scope);
        $right = $this->evaluateExpression($node['right'] ?? '', $scope);
        $op = $node['operator'] ?? '';
        
        return match ($op) {
            '+' => (is_string($left) || is_string($right)) ? $left . $right : $left + $right,
            '-' => $left - $right,
            '*' => $left * $right,
            '/' => $right != 0 ? $left / $right : 0,
            '%' => $right != 0 ? $left % $right : 0,
            '.' => (string)$left . (string)$right,
            '==' => $left == $right,
            '!=' => $left != $right,
            '>' => $left > $right,
            '<' => $left < $right,
            '>=' => $left >= $right,
            '<=' => $left <= $right,
            '&&' => $left && $right,
            '||' => $left || $right,
            default => null,
        };
    }
    
    private function evaluateUnaryOp(array $node, array $scope): mixed
    {
        $operand = $this->evaluateExpression($node['operand'] ?? '', $scope);
        $op = $node['operator'] ?? '';
        
        return match ($op) {
            '!' => !$operand,
            '-' => -$operand,
            'not' => !$operand,
            default => null,
        };
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
     *
     * Evaluates each watcher's watch expression against the current scope.
     * If the expression references the changed state variable, the watcher
     * body is executed. Simple string matching is used for expression
     * references; full expression analysis would require the DiSyL parser.
     */
    private function triggerWatchers(string $name, mixed $newValue, mixed $oldValue): void
    {
        foreach ($this->definition->watchers as $watcher) {
            $watchExpr = $watcher['expression'] ?? '';
            $watchStr = is_string($watchExpr) ? $watchExpr : (is_array($watchExpr) ? ($watchExpr['name'] ?? '') : '');
            
            // Check if this watcher references the changed variable
            if (str_contains($watchStr, $name)) {
                $scope = array_merge($this->state, $this->props);
                $body = $watcher['body'] ?? [];
                foreach ($body as $expr) {
                    $this->evaluateExpression($expr, $scope);
                }
            }
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
     *
     * Executes the method's body expressions with bound parameters.
     * Method bodies are arrays of expression AST nodes evaluated
     * sequentially against a scope of (props + state + params).
     *
     * @param string $name Method name
     * @param array $args Positional or associative arguments
     * @return mixed Last expression result, or null if method not found
     * @throws \BadMethodCallException If method is not defined
     */
    public function callMethod(string $name, array $args = []): mixed
    {
        $method = $this->definition->methods[$name] ?? null;
        if ($method === null) {
            throw new \BadMethodCallException(
                "Method '{$name}' not found on component '{$this->definition->name}'"
            );
        }
        
        // Build execution scope: state + props + bound params
        $params = $method['params'] ?? [];
        $body = $method['body'] ?? [];
        
        // Bind positional args to parameter names
        $bound = [];
        foreach ($params as $i => $param) {
            $paramName = is_string($param) ? $param : ($param['name'] ?? 'p' . $i);
            $bound[$paramName] = $args[$i] ?? ($args[$paramName] ?? null);
        }
        
        $scope = array_merge($this->state, $this->props, $bound);
        
        // Execute body expressions sequentially, return last result
        $result = null;
        foreach ($body as $expr) {
            $result = $this->evaluateExpression($expr, $scope);
        }
        
        return $result;
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
            $handler = $this->definition->eventHandlers['mount'];
            $body = $handler['body'] ?? [];
            foreach ($body as $expr) {
                $this->evaluateExpression($expr, array_merge($this->state, $this->props));
            }
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
            $handler = $this->definition->eventHandlers['unmount'];
            $body = $handler['body'] ?? [];
            foreach ($body as $expr) {
                $this->evaluateExpression($expr, array_merge($this->state, $this->props));
            }
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
