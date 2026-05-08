<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class BinaryOpNode extends AbstractNode
{
    private AbstractNode $left;
    private AbstractNode $right;
    private string $operator;

    public function __construct(array $span, AbstractNode $left, string $operator, AbstractNode $right)
    {
        parent::__construct($span);
        $this->left = $left;
        $this->operator = $operator;
        $this->right = $right;
    }

    public function getType(): string
    {
        return 'binary_op';
    }

    public function getLeft(): AbstractNode
    {
        return $this->left;
    }

    public function getRight(): AbstractNode
    {
        return $this->right;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }
}
