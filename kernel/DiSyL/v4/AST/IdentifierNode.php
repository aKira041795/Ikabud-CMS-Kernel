<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

class IdentifierNode extends AbstractNode
{
    private string $name;

    public function __construct(array $span, string $name)
    {
        parent::__construct($span);
        $this->name = $name;
    }

    public function getType(): string
    {
        return 'identifier';
    }

    public function getName(): string
    {
        return $this->name;
    }
}
