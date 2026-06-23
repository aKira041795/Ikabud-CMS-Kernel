<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Standard result contract for entity detail queries.
 *
 * Wraps a single entity record from a capability handler into a
 * canonical envelope so the renderer never needs to know whether
 * data came from a PHP module, a polyglot service, or a cache.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntityDetailResult
{
    /**
     * @param array|null  $entity  The entity data row, or null if not found
     * @param bool        $found   Whether the entity was found
     * @param string|null $error   Error message if the query failed
     */
    public function __construct(
        public readonly ?array $entity = null,
        public readonly bool $found = false,
        public readonly ?string $error = null,
    ) {}

    /**
     * Create from a capability handler's return array.
     *
     * Handles the standard convention:
     *   ['ok' => true, 'data' => $row]
     *   ['ok' => false, 'error' => 'Not found.']
     */
    public static function fromCapabilityResult(array $result): self
    {
        if (!empty($result['ok']) && isset($result['data'])) {
            return new self(
                entity: $result['data'],
                found: true,
            );
        }
        return new self(
            error: $result['error'] ?? 'Entity not found.',
        );
    }

    /**
     * Check if the entity was found without error.
     */
    public function isSuccess(): bool
    {
        return $this->found && $this->entity !== null;
    }

    /**
     * Check if the result has an error.
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }
}
