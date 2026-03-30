<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

final class ContextProfile
{
    private string $id;

    private string $label;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $capabilities = [];

    /**
     * @var array<string, mixed>
     */
    private array $meta = [];

    /**
     * @var string[]
     */
    private array $sources = [];

    public function __construct(string $id, array $definition = [])
    {
        $this->id = self::normalizeId($id);
        $this->label = self::defaultLabel($this->id);
        $this->merge($definition);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $label = trim($label);
        if ($label !== '') {
            $this->label = $label;
        }

        return $this;
    }

    public function addCapability(string $capabilityId, array $definition = []): self
    {
        $capabilityId = self::normalizeId($capabilityId);
        if ($capabilityId === '') {
            return $this;
        }

        $normalized = $definition;
        $normalized['id'] = $capabilityId;

        if (isset($this->capabilities[$capabilityId])) {
            $this->capabilities[$capabilityId] = array_replace_recursive($this->capabilities[$capabilityId], $normalized);
        } else {
            $this->capabilities[$capabilityId] = $normalized;
        }

        return $this;
    }

    /**
     * @param array<int|string, mixed> $capabilities
     */
    public function addCapabilities(array $capabilities): self
    {
        foreach ($capabilities as $key => $entry) {
            if (is_string($entry)) {
                $this->addCapability($entry);
                continue;
            }

            if (is_array($entry)) {
                $capabilityId = '';
                if (isset($entry['id']) && is_string($entry['id'])) {
                    $capabilityId = $entry['id'];
                } elseif (is_string($key)) {
                    $capabilityId = $key;
                }

                $this->addCapability($capabilityId, $entry);
                continue;
            }

            if (is_string($key) && $key !== '') {
                $this->addCapability($key);
            }
        }

        return $this;
    }

    public function addSource(string $source): self
    {
        $source = trim($source);
        if ($source === '' || in_array($source, $this->sources, true)) {
            return $this;
        }

        $this->sources[] = $source;
        sort($this->sources);

        return $this;
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function mergeMeta(array $meta): self
    {
        if ($meta !== []) {
            $this->meta = array_replace_recursive($this->meta, $meta);
        }

        return $this;
    }

    /**
     * @param array<string, mixed> $definition
     */
    public function merge(array $definition): self
    {
        if (isset($definition['label']) && is_string($definition['label'])) {
            $this->setLabel($definition['label']);
        }

        if (isset($definition['capabilities']) && is_array($definition['capabilities'])) {
            $this->addCapabilities($definition['capabilities']);
        }

        if (isset($definition['meta']) && is_array($definition['meta'])) {
            $this->mergeMeta($definition['meta']);
        }

        if (isset($definition['sources']) && is_array($definition['sources'])) {
            foreach ($definition['sources'] as $source) {
                if (is_string($source)) {
                    $this->addSource($source);
                }
            }
        }

        if (isset($definition['source']) && is_string($definition['source'])) {
            $this->addSource($definition['source']);
        }

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function capabilities(): array
    {
        $capabilities = $this->capabilities;
        ksort($capabilities);
        return $capabilities;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }

    /**
     * @return string[]
     */
    public function sources(): array
    {
        return $this->sources;
    }

    /**
     * @return array{
     *   id: string,
     *   label: string,
     *   capabilities: array<string, array<string, mixed>>,
     *   meta: array<string, mixed>,
     *   sources: string[]
     * }
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'capabilities' => $this->capabilities(),
            'meta' => $this->meta,
            'sources' => $this->sources,
        ];
    }

    private static function normalizeId(string $value): string
    {
        return strtolower(trim($value));
    }

    private static function defaultLabel(string $id): string
    {
        $label = str_replace(['_', '.'], ' ', $id);
        return ucwords($label);
    }
}
