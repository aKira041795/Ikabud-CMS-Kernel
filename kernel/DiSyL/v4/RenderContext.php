<?php

namespace Ikabud\Kernel\DiSyL\v4;

class RenderContext
{
    /** @var array<string, mixed>[] Variable scope stack */
    private array $scopes = [];
    /** @var array<string, mixed> Blocks defined by child templates */
    private array $blocks = [];
    /** @var array<string, mixed> Slots */
    private array $slots = [];
    private ?string $parentTemplate = null;

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

    public function setBlock(string $name, mixed $content): void
    {
        $this->blocks[$name] = $content;
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

    public function setParentTemplate(string $template): void
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
