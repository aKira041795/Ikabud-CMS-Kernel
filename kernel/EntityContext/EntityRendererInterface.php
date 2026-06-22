<?php

declare(strict_types=1);

namespace Ikabud\Kernel\EntityContext;

/**
 * Contract for entity rendering — the primary replacement for the
 * private methods in EntityRenderingTrait.
 *
 * Implementations receive fully-resolved view contracts and data,
 * and return rendered HTML.
 *
 * @package Ikabud\Kernel\EntityContext
 */
interface EntityRendererInterface
{
    /**
     * Render an entity list (ikb_entity_list).
     *
     * @param array $rows    Data rows from the capability handler
     * @param array $view    Resolved view contract (fields, actions, renderers, etc.)
     * @param array $attrs   Original template attributes (for overrides)
     * @param array $context Global render context
     */
    public function renderList(array $rows, array $view, array $attrs, array $context = []): string;

    /**
     * Render an entity detail (ikb_entity_detail).
     *
     * @param array $entity  Single entity data from the capability handler
     * @param array $view    Resolved view contract
     * @param array $attrs   Original template attributes
     * @param array $context Global render context
     */
    public function renderDetail(array $entity, array $view, array $attrs, array $context = []): string;
}
