<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

class UnaryOpNode extends AbstractNode
{
    private AbstractNode $operand;
    private string $operator;

    public function __construct(array $span, string $operator, AbstractNode $operand)
    {
        parent::__construct($span);
        $this->operator = $operator;
        $this->operand = $operand;
    }

    public function getType(): string
    {
        return 'unary_op';
    }

    public function getOperand(): AbstractNode
    {
        return $this->operand;
    }

    public function getOperator(): string
    {
        return $this->operator;
    }
}
