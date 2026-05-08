<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class LiteralNode extends AbstractNode
{
    private mixed $value;

    public function __construct(array $span, mixed $value)
    {
        parent::__construct($span);
        $this->value = $value;
    }

    public function getType(): string
    {
        return 'literal';
    }

    public function getValue(): mixed
    {
        return $this->value;
    }
}
