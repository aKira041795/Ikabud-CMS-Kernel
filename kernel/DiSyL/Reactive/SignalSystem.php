<?php
/**
 * DiSyL v11.0 Signal-Based Reactive System
 * 
 * Implements a modern reactive system inspired by Solid.js/Preact Signals:
 * - Fine-grained reactivity with signals
 * - Computed/derived values with automatic dependency tracking
 * - Effects with cleanup
 * - Batched updates
 * - Async state management
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * Global reactive context for dependency tracking
 */
class ReactiveContext
{
    private static ?ReactiveContext $instance = null;
    private static array $effectStack = [];
    private static array $batchQueue = [];
    private static bool $isBatching = false;
    private static int $signalId = 0;
    
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public static function generateId(): int
    {
        return ++self::$signalId;
    }
    
    public static function getCurrentEffect(): ?Effect
    {
        return end(self::$effectStack) ?: null;
    }
    
    public static function pushEffect(Effect $effect): void
    {
        self::$effectStack[] = $effect;
    }
    
    public static function popEffect(): void
    {
        array_pop(self::$effectStack);
    }
    
    public static function batch(callable $fn): void
    {
        if (self::$isBatching) {
            $fn();
            return;
        }
        
        self::$isBatching = true;
        try {
            $fn();
        } finally {
            self::$isBatching = false;
            self::flushBatch();
        }
    }
    
    public static function queueUpdate(callable $update): void
    {
        if (self::$isBatching) {
            self::$batchQueue[] = $update;
        } else {
            $update();
        }
    }
    
    private static function flushBatch(): void
    {
        $queue = self::$batchQueue;
        self::$batchQueue = [];
        
        foreach ($queue as $update) {
            $update();
        }
    }
}

/**
 * Base signal interface
 */
interface SignalInterface
{
    public function get(): mixed;
    public function subscribe(callable $callback): callable;
}

/**
 * Writable signal interface
 */
interface WritableSignal extends SignalInterface
{
    public function set(mixed $value): void;
    public function update(callable $updater): void;
}

/**
 * Core Signal implementation - reactive primitive
 */
class Signal implements WritableSignal
{
    private int $id;
    private mixed $value;
    private array $subscribers = [];
    private array $dependents = [];
    private bool $dirty = false;
    
    public function __construct(mixed $initialValue = null)
    {
        $this->id = ReactiveContext::generateId();
        $this->value = $initialValue;
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function get(): mixed
    {
        // Track dependency
        $effect = ReactiveContext::getCurrentEffect();
        if ($effect !== null) {
            $this->dependents[$effect->getId()] = $effect;
            $effect->addDependency($this);
        }
        
        return $this->value;
    }
    
    public function set(mixed $value): void
    {
        if ($this->value === $value) {
            return;
        }
        
        $this->value = $value;
        $this->notify();
    }
    
    public function update(callable $updater): void
    {
        $this->set($updater($this->value));
    }
    
    public function subscribe(callable $callback): callable
    {
        $id = spl_object_id((object)$callback);
        $this->subscribers[$id] = $callback;
        
        // Return unsubscribe function
        return function() use ($id) {
            unset($this->subscribers[$id]);
        };
    }
    
    public function notify(): void
    {
        ReactiveContext::queueUpdate(function() {
            // Notify subscribers
            foreach ($this->subscribers as $callback) {
                $callback($this->value);
            }
            
            // Notify dependent effects
            foreach ($this->dependents as $effect) {
                $effect->run();
            }
        });
    }
    
    public function removeDependent(Effect $effect): void
    {
        unset($this->dependents[$effect->getId()]);
    }
}

/**
 * Computed/Derived signal - automatically tracks dependencies
 */
class Computed implements SignalInterface
{
    private int $id;
    private \Closure $compute;
    private mixed $cachedValue = null;
    private bool $dirty = true;
    private array $dependencies = [];
    private array $subscribers = [];
    private ?Effect $trackingEffect = null;
    
    public function __construct(callable $compute)
    {
        $this->id = ReactiveContext::generateId();
        $this->compute = \Closure::fromCallable($compute);
        
        // Create internal effect for tracking
        $this->trackingEffect = new Effect(function() {
            $this->dirty = true;
            $this->notifySubscribers();
        });
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function get(): mixed
    {
        // Track this computed as dependency
        $effect = ReactiveContext::getCurrentEffect();
        if ($effect !== null) {
            $this->subscribers[$effect->getId()] = $effect;
        }
        
        if ($this->dirty) {
            $this->recompute();
        }
        
        return $this->cachedValue;
    }
    
    private function recompute(): void
    {
        // Clear old dependencies
        foreach ($this->dependencies as $dep) {
            if ($dep instanceof Signal) {
                $dep->removeDependent($this->trackingEffect);
            }
        }
        $this->dependencies = [];
        
        // Track new dependencies
        ReactiveContext::pushEffect($this->trackingEffect);
        try {
            $this->cachedValue = ($this->compute)();
            $this->dirty = false;
        } finally {
            ReactiveContext::popEffect();
        }
    }
    
    public function subscribe(callable $callback): callable
    {
        $id = spl_object_id((object)$callback);
        $this->subscribers[$id] = $callback;
        
        return function() use ($id) {
            unset($this->subscribers[$id]);
        };
    }
    
    private function notifySubscribers(): void
    {
        foreach ($this->subscribers as $subscriber) {
            if ($subscriber instanceof Effect) {
                $subscriber->run();
            } elseif (is_callable($subscriber)) {
                $subscriber($this->get());
            }
        }
    }
}

/**
 * Effect - side effect that runs when dependencies change
 */
class Effect
{
    private int $id;
    private \Closure $fn;
    private ?\Closure $cleanup = null;
    private array $dependencies = [];
    private bool $active = true;
    private bool $running = false;
    
    public function __construct(callable $fn, bool $immediate = true)
    {
        $this->id = ReactiveContext::generateId();
        $this->fn = \Closure::fromCallable($fn);
        
        if ($immediate) {
            $this->run();
        }
    }
    
    public function getId(): int
    {
        return $this->id;
    }
    
    public function addDependency(Signal $signal): void
    {
        $this->dependencies[$signal->getId()] = $signal;
    }
    
    public function run(): void
    {
        if (!$this->active || $this->running) {
            return;
        }
        
        $this->running = true;
        
        // Run cleanup from previous execution
        if ($this->cleanup !== null) {
            ($this->cleanup)();
            $this->cleanup = null;
        }
        
        // Clear old dependencies
        foreach ($this->dependencies as $dep) {
            $dep->removeDependent($this);
        }
        $this->dependencies = [];
        
        // Run effect and track dependencies
        ReactiveContext::pushEffect($this);
        try {
            $result = ($this->fn)();
            
            // If effect returns a cleanup function, store it
            if (is_callable($result)) {
                $this->cleanup = \Closure::fromCallable($result);
            }
        } finally {
            ReactiveContext::popEffect();
            $this->running = false;
        }
    }
    
    public function dispose(): void
    {
        $this->active = false;
        
        if ($this->cleanup !== null) {
            ($this->cleanup)();
            $this->cleanup = null;
        }
        
        foreach ($this->dependencies as $dep) {
            $dep->removeDependent($this);
        }
        $this->dependencies = [];
    }
}

/**
 * Async signal for handling async state
 */
class AsyncSignal implements SignalInterface
{
    private Signal $value;
    private Signal $loading;
    private Signal $error;
    private ?\Closure $fetcher = null;
    
    public function __construct(callable $fetcher, mixed $initialValue = null)
    {
        $this->value = new Signal($initialValue);
        $this->loading = new Signal(false);
        $this->error = new Signal(null);
        $this->fetcher = \Closure::fromCallable($fetcher);
    }
    
    public function get(): mixed
    {
        return $this->value->get();
    }
    
    public function isLoading(): bool
    {
        return $this->loading->get();
    }
    
    public function getError(): mixed
    {
        return $this->error->get();
    }
    
    public function subscribe(callable $callback): callable
    {
        return $this->value->subscribe($callback);
    }
    
    public function fetch(...$args): void
    {
        $this->loading->set(true);
        $this->error->set(null);
        
        try {
            $result = ($this->fetcher)(...$args);
            
            // Handle promises/generators
            if ($result instanceof \Generator) {
                $this->handleGenerator($result);
            } else {
                $this->value->set($result);
                $this->loading->set(false);
            }
        } catch (\Throwable $e) {
            $this->error->set($e);
            $this->loading->set(false);
        }
    }
    
    private function handleGenerator(\Generator $generator): void
    {
        try {
            while ($generator->valid()) {
                $yielded = $generator->current();
                $generator->send($yielded);
            }
            $this->value->set($generator->getReturn());
        } catch (\Throwable $e) {
            $this->error->set($e);
        } finally {
            $this->loading->set(false);
        }
    }
    
    public function mutate(callable $mutator): void
    {
        $this->value->update($mutator);
    }
    
    public function refetch(...$args): void
    {
        $this->fetch(...$args);
    }
}

/**
 * Store - collection of signals with actions
 */
class Store
{
    private array $signals = [];
    private array $computed = [];
    private array $actions = [];
    private string $name;
    
    public function __construct(string $name, array $initialState = [])
    {
        $this->name = $name;
        
        foreach ($initialState as $key => $value) {
            $this->signals[$key] = new Signal($value);
        }
    }
    
    public function getName(): string
    {
        return $this->name;
    }
    
    public function get(string $key): mixed
    {
        if (isset($this->computed[$key])) {
            return $this->computed[$key]->get();
        }
        
        if (!isset($this->signals[$key])) {
            $this->signals[$key] = new Signal(null);
        }
        
        return $this->signals[$key]->get();
    }
    
    public function set(string $key, mixed $value): void
    {
        if (!isset($this->signals[$key])) {
            $this->signals[$key] = new Signal($value);
        } else {
            $this->signals[$key]->set($value);
        }
    }
    
    public function update(string $key, callable $updater): void
    {
        if (isset($this->signals[$key])) {
            $this->signals[$key]->update($updater);
        }
    }
    
    public function addComputed(string $key, callable $compute): void
    {
        $this->computed[$key] = new Computed($compute);
    }
    
    public function addAction(string $name, callable $action): void
    {
        $this->actions[$name] = $action;
    }
    
    public function dispatch(string $action, ...$args): mixed
    {
        if (!isset($this->actions[$action])) {
            throw new \RuntimeException("Unknown action: {$action}");
        }
        
        return ReactiveContext::batch(function() use ($action, $args) {
            return ($this->actions[$action])($this, ...$args);
        });
    }
    
    public function subscribe(string $key, callable $callback): callable
    {
        if (isset($this->computed[$key])) {
            return $this->computed[$key]->subscribe($callback);
        }
        
        if (!isset($this->signals[$key])) {
            $this->signals[$key] = new Signal(null);
        }
        
        return $this->signals[$key]->subscribe($callback);
    }
    
    public function getState(): array
    {
        $state = [];
        foreach ($this->signals as $key => $signal) {
            $state[$key] = $signal->get();
        }
        foreach ($this->computed as $key => $computed) {
            $state[$key] = $computed->get();
        }
        return $state;
    }
    
    public function toJSON(): string
    {
        return json_encode([
            'name' => $this->name,
            'state' => $this->getState(),
        ]);
    }
}

/**
 * Helper functions for creating reactive primitives
 */
function signal(mixed $value = null): Signal
{
    return new Signal($value);
}

function computed(callable $fn): Computed
{
    return new Computed($fn);
}

function effect(callable $fn, bool $immediate = true): Effect
{
    return new Effect($fn, $immediate);
}

function batch(callable $fn): void
{
    ReactiveContext::batch($fn);
}

function store(string $name, array $initial = []): Store
{
    return new Store($name, $initial);
}

function asyncSignal(callable $fetcher, mixed $initial = null): AsyncSignal
{
    return new AsyncSignal($fetcher, $initial);
}
