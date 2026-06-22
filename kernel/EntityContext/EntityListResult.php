<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Standard result contract for entity list queries.
 *
 * Supports both total-based pagination (SQL COUNT) and cursor-based
 * pagination (polyglot/external services).  Do NOT make 'total'
 * permanently mandatory — some sources only provide nextCursor + hasMore.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntityListResult
{
    /**
     * @param array<int, array<string, mixed>> $rows       Data rows
     * @param int|null    $total       Total row count (null if unknown/cursor-based)
     * @param string|null $nextCursor  Opaque cursor for the next page (cursor-based pagination)
     * @param bool        $hasMore     Whether more rows exist beyond this page
     * @param string|null $error       Error message if the query failed
     */
    public function __construct(
        public readonly array $rows = [],
        public readonly ?int $total = null,
        public readonly ?string $nextCursor = null,
        public readonly bool $hasMore = false,
        public readonly ?string $error = null,
    ) {}

    /**
     * Create from a capability handler's return array.
     *
     * Handles both:
     *   ['rows' => [...], 'total' => N]
     *   ['rows' => [...], 'next_cursor' => 'abc', 'has_more' => true]
     */
    public static function fromCapabilityResult(array $result): self
    {
        return new self(
            rows: $result['rows'] ?? [],
            total: isset($result['total']) ? (int)$result['total'] : null,
            nextCursor: $result['next_cursor'] ?? $result['nextCursor'] ?? null,
            hasMore: (bool)($result['has_more'] ?? $result['hasMore'] ?? false),
            error: $result['error'] ?? null,
        );
    }

    /**
     * Check if the result has an error.
     */
    public function hasError(): bool
    {
        return $this->error !== null;
    }

    /**
     * Check if pagination is cursor-based (no total count).
     */
    public function isCursorBased(): bool
    {
        return $this->total === null;
    }

    /**
     * Get the effective row count (from rows array or total).
     */
    public function count(): int
    {
        return count($this->rows);
    }
}
