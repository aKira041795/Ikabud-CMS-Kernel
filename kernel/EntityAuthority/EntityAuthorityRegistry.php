<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityAuthority;

final class EntityAuthorityRegistry
{
    /**
     * @var array<string, string> entityType => moduleId
     */
    private array $authorities = [];

    /**
     * @var array<string, array<string, mixed>> entityType => definition
     */
    private array $entityDefinitions = [];

    public function registerAuthority(string $entityType, string $moduleId, array $definition = []): void
    {
        if (isset($this->authorities[$entityType]) && $this->authorities[$entityType] !== $moduleId) {
            throw new \RuntimeException(sprintf(
                'Entity authority conflict: "%s" is already claimed by module "%s". Module "%s" cannot claim authority.',
                $entityType,
                $this->authorities[$entityType],
                $moduleId
            ));
        }

        $this->authorities[$entityType] = $moduleId;
        $this->entityDefinitions[$entityType] = $definition;
    }

    public function getAuthority(string $entityType): ?string
    {
        return $this->authorities[$entityType] ?? null;
    }

    public function getDefinitions(): array
    {
        return $this->entityDefinitions;
    }

    /**
     * Returns true if $moduleId is the authoritative owner for $entityType.
     */
    public function isAuthoritative(string $entityType, string $moduleId): bool
    {
        return ($this->authorities[$entityType] ?? null) === $moduleId;
    }

    public function reset(): void
    {
        $this->authorities = [];
        $this->entityDefinitions = [];
    }
}
