<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class DocumentNode extends AbstractNode
{
    /** @var AbstractNode[] */
    private array $children;

    /**
     * @param AbstractNode[] $children
     */
    public function __construct(array $span, array $children = [])
    {
        parent::__construct($span);
        $this->children = $children;
    }

    public function getType(): string
    {
        return 'document';
    }

    /** @return AbstractNode[] */
    public function getChildren(): array
    {
        return $this->children;
    }
}
