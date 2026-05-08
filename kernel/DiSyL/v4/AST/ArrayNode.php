<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class ArrayNode extends AbstractNode
{
    /** @var AbstractNode[] */
    private array $elements;

    /**
     * @param AbstractNode[] $elements
     */
    public function __construct(array $span, array $elements = [])
    {
        parent::__construct($span);
        $this->elements = $elements;
    }

    public function getType(): string
    {
        return 'array';
    }

    /** @return AbstractNode[] */
    public function getElements(): array
    {
        return $this->elements;
    }
}
