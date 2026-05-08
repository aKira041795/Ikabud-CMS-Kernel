<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class FilterChain
{
    /** @var FilterNode[] */
    private array $filters;

    /**
     * @param FilterNode[] $filters
     */
    public function __construct(array $filters = [])
    {
        $this->filters = $filters;
    }

    /** @return FilterNode[] */
    public function getFilters(): array
    {
        return $this->filters;
    }
}
