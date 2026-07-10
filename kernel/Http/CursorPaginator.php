<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Cursor-based paginator — stable for mobile sync.
 *
 * Unlike offset pagination, cursor pagination is immune to insertions/deletions
 * between pages. Use this for all mobile-facing list endpoints.
 *
 * The cursor encodes stable sort fields (updated_at DESC, id DESC).
 * The client treats the cursor as an opaque string.
 *
 * Usage:
 *   $after = CursorPaginator::decodeCursor(app()->input('after') ?? '');
 *   $cursor = $after['updated_at'] ?? '';
 *   $id = $after['id'] ?? 0;
 *   $rows = $db->query(
 *       "SELECT * FROM products WHERE updated_at < ? OR (updated_at = ? AND id < ?) ORDER BY updated_at DESC, id DESC LIMIT ?",
 *       [$cursor, $cursor, $id, $limit + 1]
 *   )->fetchAll();
 *   $hasMore = count($rows) > $limit;
 *   if ($hasMore) { array_pop($rows); }
 *   $nextCursor = $hasMore ? CursorPaginator::encodeCursor(end($rows)) : null;
 *   $pager = new CursorPaginator($limit, $hasMore, $nextCursor);
 *   ApiResponse::cursorPaginated($rows, $pager);
 */
class CursorPaginator
{
    private int $limit;
    private bool $hasMore;
    private ?string $nextCursor;
    private ?string $prevCursor;

    /**
     * @param int     $limit      Items per page
     * @param bool    $hasMore    Whether there are more items beyond this page
     * @param string|null $nextCursor  Opaque cursor for the next page
     * @param string|null $prevCursor  Opaque cursor for the previous page
     */
    public function __construct(int $limit, bool $hasMore, ?string $nextCursor = null, ?string $prevCursor = null)
    {
        $this->limit = $limit;
        $this->hasMore = $hasMore;
        $this->nextCursor = $nextCursor;
        $this->prevCursor = $prevCursor;
    }

    /**
     * Build a cursor from sort fields.
     * Stable sort: updated_at DESC, id DESC.
     *
     * @param array $row A single row from the result set (must contain 'id' and 'updated_at')
     */
    public static function encodeCursor(array $row): string
    {
        return base64_encode((string)json_encode([
            'id'         => (int)($row['id'] ?? 0),
            'updated_at' => (string)($row['updated_at'] ?? ''),
        ]));
    }

    /**
     * Decode a cursor string back to sort field values.
     *
     * @return array{id: int, updated_at: string}|null
     */
    public static function decodeCursor(string $cursor): ?array
    {
        $decoded = base64_decode($cursor, true);
        if ($decoded === false) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['id'], $data['updated_at'])) {
            return null;
        }
        return [
            'id'         => (int)$data['id'],
            'updated_at' => (string)$data['updated_at'],
        ];
    }

    /** Items per page. */
    public function perPage(): int
    {
        return $this->limit;
    }

    /** Whether more items exist beyond this page. */
    public function hasMore(): bool
    {
        return $this->hasMore;
    }

    /** Opaque cursor for the next page. Null if this is the last page. */
    public function nextCursor(): ?string
    {
        return $this->nextCursor;
    }

    /** Opaque cursor for the previous page. Null if this is the first page. */
    public function prevCursor(): ?string
    {
        return $this->prevCursor;
    }

    /**
     * Meta array for API response body.
     * Keys: per_page, has_more, next_cursor, prev_cursor
     */
    public function meta(): array
    {
        return [
            'per_page'    => $this->limit,
            'has_more'    => $this->hasMore,
            'next_cursor' => $this->nextCursor,
            'prev_cursor' => $this->prevCursor,
        ];
    }
}
