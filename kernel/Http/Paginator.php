<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Offset-based paginator for admin/list views.
 *
 * For mobile synchronization, prefer CursorPaginator which is stable
 * against insertions/deletions between pages.
 *
 * Usage:
 *   $pager = new Paginator(98, 20, 1);
 *   $sql = "SELECT * FROM products LIMIT ? OFFSET ?";
 *   $rows = $db->query($sql, [$pager->limit(), $pager->offset()])->fetchAll();
 *   $pager->emitHeaders();
 *   ApiResponse::paginated($rows, $pager);
 */
class Paginator
{
    private int $total;
    private int $perPage;
    private int $currentPage;
    private string $baseUrl;

    /**
     * @param int    $total       Total number of items across all pages
     * @param int    $perPage     Items per page (default 20, max 100)
     * @param int    $currentPage Current page number (1-based)
     * @param string $baseUrl     Base URL for link generation (defaults to current request URI)
     */
    public function __construct(int $total, int $perPage = 20, int $currentPage = 1, string $baseUrl = '')
    {
        $this->total = max(0, $total);
        $this->perPage = max(1, min($perPage, 100));
        $this->baseUrl = $baseUrl ?: ($_SERVER['REQUEST_URI'] ?? '/');

        $lastPage = $this->lastPage();
        $this->currentPage = max(1, min($currentPage, $lastPage));
    }

    /** Total number of items across all pages. */
    public function total(): int
    {
        return $this->total;
    }

    /** Items per page. */
    public function perPage(): int
    {
        return $this->perPage;
    }

    /** Current page number (1-based, clamped to valid range). */
    public function currentPage(): int
    {
        return $this->currentPage;
    }

    /** Total number of pages. Never less than 1, even for empty collections. */
    public function lastPage(): int
    {
        return max(1, (int)ceil($this->total / $this->perPage));
    }

    /** SQL OFFSET for the query. */
    public function offset(): int
    {
        return ($this->currentPage - 1) * $this->perPage;
    }

    /** SQL LIMIT for the query. */
    public function limit(): int
    {
        return $this->perPage;
    }

    /** Does this page have a previous page? */
    public function hasPrev(): bool
    {
        return $this->currentPage > 1;
    }

    /** Does this page have a next page? */
    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage();
    }

    /**
     * Meta array for API response body.
     * Keys: current_page, last_page, per_page, total, from, to
     */
    public function meta(): array
    {
        return [
            'current_page' => $this->currentPage,
            'last_page'    => $this->lastPage(),
            'per_page'     => $this->perPage,
            'total'        => $this->total,
            'from'         => $this->total === 0 ? 0 : $this->offset() + 1,
            'to'           => $this->total === 0 ? 0 : min($this->offset() + $this->perPage, $this->total),
        ];
    }

    /**
     * Links array for response body (JSON:API-style).
     * Keys: self, prev, next, first, last
     */
    public function links(): array
    {
        $links = ['self' => $this->buildUrl($this->currentPage)];
        if ($this->hasPrev()) {
            $links['prev'] = $this->buildUrl($this->currentPage - 1);
        }
        if ($this->hasNext()) {
            $links['next'] = $this->buildUrl($this->currentPage + 1);
        }
        $links['first'] = $this->buildUrl(1);
        $links['last'] = $this->buildUrl($this->lastPage());
        return $links;
    }

    /**
     * RFC 5988 Link header value for HTTP response headers.
     */
    public function linkHeader(): string
    {
        $parts = [];
        if ($this->hasPrev()) {
            $parts[] = '<' . $this->buildUrl($this->currentPage - 1) . '>; rel="prev"';
        }
        if ($this->hasNext()) {
            $parts[] = '<' . $this->buildUrl($this->currentPage + 1) . '>; rel="next"';
        }
        $parts[] = '<' . $this->buildUrl(1) . '>; rel="first"';
        $parts[] = '<' . $this->buildUrl($this->lastPage()) . '>; rel="last"';
        return implode(', ', $parts);
    }

    /**
     * Emit Link header and X-Pagination-* legacy headers.
     */
    public function emitHeaders(): void
    {
        if (!headers_sent()) {
            header('Link: ' . $this->linkHeader());
            header('X-Pagination-Total: ' . $this->total);
            header('X-Pagination-Page: ' . $this->currentPage);
            header('X-Pagination-Per-Page: ' . $this->perPage);
            header('X-Pagination-Last-Page: ' . $this->lastPage());
        }
    }

    /**
     * Build a URL for a specific page, preserving existing query parameters.
     */
    private function buildUrl(int $page): string
    {
        $parsed = parse_url($this->baseUrl);
        $path = $parsed['path'] ?? '/';
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
        }
        $query['page'] = (string)$page;
        return $path . '?' . http_build_query($query);
    }
}
