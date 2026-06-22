<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Builds EntityQueryState from the current HTTP request.
 *
 * Reads namespaced query parameters (e.g. ?guidance-cases_page=2)
 * and resolves to a typed EntityQueryState with validated values.
 *
 * @package Ikabud\Kernel\EntityContext
 */
final class EntityQueryStateResolver
{
    /** Maximum allowed limit (admin cap). */
    public const MAX_LIMIT = 100;

    /** Default limit when none specified. */
    public const DEFAULT_LIMIT = 15;

    /**
     * Build an EntityQueryState from $_GET parameters for a given list ID.
     *
     * Reads both total-based params (page, limit) and cursor-based params
     * (cursor, prev). Cursor takes priority over page.
     *
     * @param string $listId       Stable list identifier (e.g. 'guidance-cases')
     * @param array  $serverGet    Usually $_GET — injected for testability
     * @param array  $defaults     Default overrides (limit, sort, direction)
     */
    public function resolve(string $listId, array $serverGet = [], array $defaults = []): EntityQueryState
    {
        $prefix = $listId !== '' ? $listId . '_' : '';

        $page = max(1, (int)($serverGet[$prefix . 'page'] ?? 1));
        $limit = min(self::MAX_LIMIT, max(1, (int)($serverGet[$prefix . 'limit'] ?? $defaults['limit'] ?? self::DEFAULT_LIMIT)));
        $sort = (string)($serverGet[$prefix . 'sort'] ?? $defaults['sort'] ?? '');
        $directionRaw = (string)($serverGet[$prefix . 'dir'] ?? $defaults['direction'] ?? 'desc');
        $direction = in_array($directionRaw, ['asc', 'desc'], true) ? $directionRaw : 'desc';

        // Cursor params — take priority over page
        $cursor = (string)($serverGet[$prefix . 'cursor'] ?? '');
        $prevCursor = (string)($serverGet[$prefix . 'prev'] ?? '');

        // Also check non-namespaced params as fallback
        if ($sort === '' && $listId !== '') {
            $sort = (string)($serverGet['sort'] ?? '');
            $directionRaw = (string)($serverGet['dir'] ?? 'desc');
            $direction = in_array($directionRaw, ['asc', 'desc'], true) ? $directionRaw : 'desc';
        }
        if ($cursor === '' && $listId !== '') {
            $cursor = (string)($serverGet['cursor'] ?? '');
            $prevCursor = (string)($serverGet['prev'] ?? '');
        }

        return new EntityQueryState(
            page: $cursor !== '' ? 0 : $page,
            limit: $limit,
            sort: $sort !== '' ? $sort : null,
            direction: $direction,
            listId: $listId,
            cursor: $cursor !== '' ? $cursor : null,
            prevCursor: $prevCursor !== '' ? $prevCursor : null,
        );
    }
}
