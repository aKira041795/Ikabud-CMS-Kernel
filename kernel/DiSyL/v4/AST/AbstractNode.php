<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

abstract class AbstractNode
{
    protected array $span;

    public function __construct(array $span = [])
    {
        $this->span = $span;
    }

    public function getSpan(): array
    {
        return $this->span;
    }

    abstract public function getType(): string;
}
