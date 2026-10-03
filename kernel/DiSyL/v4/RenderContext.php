<?php

namespace Ikabud\Kernel\DiSyL\v4;

final class RenderContext
{
    /** @var array<string, mixed>[] Variable scope stack */
    private array $scopes = [];
    /** @var array<string, mixed> Blocks defined by child templates */
    private array $blocks = [];
    /** @var array<string, mixed> Slots */
    private array $slots = [];
    private ?string $parentTemplate = null;
    /** @var array<int, array{blocks: array<string, mixed>, parentTemplate: ?string}> */
    private array $inheritanceScopes = [];

    public function __construct(array $variables = [])
    {
        $this->scopes[] = $variables;
    }

    public function get(string $name): mixed
    {
        // Search from innermost scope outward
        for ($i = count($this->scopes) - 1; $i >= 0; $i--) {
            if (array_key_exists($name, $this->scopes[$i])) {
                return $this->scopes[$i][$name];
            }
        }
        return null;
    }

    public function set(string $name, mixed $value): void
    {
        $this->scopes[count($this->scopes) - 1][$name] = $value;
    }

    public function pushScope(array $variables = []): void
    {
        $this->scopes[] = $variables;
    }

    public function popScope(): void
    {
        if (count($this->scopes) > 1) {
            array_pop($this->scopes);
        }
    }

    public function getProperty(mixed $object, mixed $property): mixed
    {
        if ($object === null) {
            return null;
        }

        $key = is_string($property) || is_int($property) ? $property : (string)$property;

        if (is_array($object)) {
            return $object[$key] ?? null;
        }

        if (is_object($object)) {
            if (isset($object->$key)) {
                return $object->$key;
            }
            $getter = 'get' . ucfirst((string)$key);
            if (method_exists($object, $getter)) {
                return $object->$getter();
            }
        }

        return null;
    }

    public function hasBlock(string $name): bool
    {
        return isset($this->blocks[$name]);
    }

    public function getBlock(string $name): mixed
    {
        return $this->blocks[$name] ?? null;
    }

    /**
     * Register or replace a block definition.
     *
     * Inheritance capture should normally use setBlockIfAbsent() so the
     * nearest (first-rendered) child definition wins across the chain.
     */
    public function setBlock(string $name, mixed $content): void
    {
        $this->blocks[$name] = $content;
    }

    /** Register a block only when no nearer child has already defined it. */
    public function setBlockIfAbsent(string $name, mixed $content): void
    {
        if (!isset($this->blocks[$name])) {
            $this->blocks[$name] = $content;
        }
    }

    public function hasSlot(string $name): bool
    {
        return isset($this->slots[$name]);
    }

    public function getSlot(string $name): mixed
    {
        return $this->slots[$name] ?? null;
    }

    public function setSlot(string $name, mixed $content): void
    {
        $this->slots[$name] = $content;
    }

    /**
     * Start an isolated inheritance boundary while retaining variable scopes.
     *
     * Includes render with the host context so they can read host variables,
     * but their parent and block definitions belong only to the included
     * template's inheritance chain.
     */
    public function pushInheritanceScope(): void
    {
        $this->inheritanceScopes[] = [
            'blocks' => $this->blocks,
            'parentTemplate' => $this->parentTemplate,
        ];
        $this->blocks = [];
        $this->parentTemplate = null;
    }

    public function popInheritanceScope(): void
    {
        $scope = array_pop($this->inheritanceScopes);
        if ($scope === null) {
            return;
        }

        $this->blocks = $scope['blocks'];
        $this->parentTemplate = $scope['parentTemplate'];
    }

    public function setParentTemplate(?string $template): void
    {
        $this->parentTemplate = $template;
    }

    public function getParentTemplate(): ?string
    {
        return $this->parentTemplate;
    }

    public function toArray(): array
    {
        $merged = [];
        foreach ($this->scopes as $scope) {
            $merged = array_merge($merged, $scope);
        }
        return $merged;
    }
}
