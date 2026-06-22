<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Immutable value object representing the current query state for an entity list.
 *
 * Encapsulates pagination, sorting, cursor state, and filter parameters so
 * the renderer does not need to read directly from $_GET or other superglobals.
 * Built by a resolver and passed into the render pipeline.
 *
 * Supports both:
 *   - Total-based pagination (offset/limit with page numbers)
 *   - Keyset/cursor-based pagination (next_cursor/has_more for infinite scroll)
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntityQueryState
{
    /**
     * @param int         $page      Current page (1-based; 0 for cursor-first-page)
     * @param int         $limit     Rows per page
     * @param string|null $sort      Sort field name (null = default from view contract)
     * @param string      $direction Sort direction: 'asc' or 'desc'
     * @param array       $filters   Active filters (field => value)
     * @param string      $listId    Stable list identifier for namespaced query params
     * @param string|null $cursor    Opaque cursor value for keyset pagination
     * @param bool        $hasMore   Whether more rows exist beyond cursor
     * @param string|null $prevCursor Cursor for previous page (for cursor-based nav)
     */
    public function __construct(
        public readonly int $page = 1,
        public readonly int $limit = 15,
        public readonly ?string $sort = null,
        public readonly string $direction = 'desc',
        public readonly array $filters = [],
        public readonly string $listId = '',
        public readonly ?string $cursor = null,
        public readonly bool $hasMore = false,
        public readonly ?string $prevCursor = null,
    ) {}

    /**
     * Check whether this state uses cursor-based pagination.
     */
    public function isCursorBased(): bool
    {
        return $this->cursor !== null || $this->hasMore;
    }

    /**
     * Calculate the offset for SQL LIMIT/OFFSET (total-based only).
     */
    public function offset(): int
    {
        return ($this->page - 1) * $this->limit;
    }

    /**
     * Get the total number of pages for a given total row count (total-based only).
     */
    public function totalPages(int $total): int
    {
        return max(1, (int)ceil($total / max(1, $this->limit)));
    }

    /**
     * Create a new state with the sort toggled for a given field.
     */
    public function withSort(string $field): self
    {
        $newDir = ($this->sort === $field && $this->direction === 'asc') ? 'desc' : 'asc';
        return new self(
            page: 1,
            limit: $this->limit,
            sort: $field,
            direction: $newDir,
            filters: $this->filters,
            listId: $this->listId,
        );
    }

    /**
     * Create a new state with a different page (total-based).
     */
    public function withPage(int $page): self
    {
        return new self(
            page: max(1, $page),
            limit: $this->limit,
            sort: $this->sort,
            direction: $this->direction,
            filters: $this->filters,
            listId: $this->listId,
        );
    }

    /**
     * Create a new state with cursor-based pagination (for next page).
     */
    public function withCursor(string $cursor, bool $hasMore): self
    {
        return new self(
            page: 0,
            limit: $this->limit,
            sort: $this->sort,
            direction: $this->direction,
            filters: $this->filters,
            listId: $this->listId,
            cursor: $cursor,
            hasMore: $hasMore,
        );
    }

    /**
     * Build query parameters for this state, namespaced by listId.
     *
     * @return array<string, string>
     */
    public function toQueryParams(): array
    {
        $prefix = $this->listId !== '' ? $this->listId . '_' : '';
        $params = [];

        if ($this->cursor !== null) {
            $params[$prefix . 'cursor'] = $this->cursor;
        } elseif ($this->page > 1) {
            $params[$prefix . 'page'] = (string)$this->page;
        }

        if ($this->prevCursor !== null) {
            $params[$prefix . 'prev'] = $this->prevCursor;
        }

        if ($this->sort !== null) {
            $params[$prefix . 'sort'] = $this->sort;
            $params[$prefix . 'dir'] = $this->direction;
        }

        return $params;
    }

    /**
     * Build a query string from the state params.
     */
    public function toQueryString(): string
    {
        $params = $this->toQueryParams();
        return $params !== [] ? http_build_query($params) : '';
    }
}
