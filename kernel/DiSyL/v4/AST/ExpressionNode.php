<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class ExpressionNode extends AbstractNode
{
    private AbstractNode $expression;
    private ?FilterChain $filters;
    private bool $autoEscape;

    public function __construct(array $span, AbstractNode $expression, ?FilterChain $filters = null, bool $autoEscape = true)
    {
        parent::__construct($span);
        $this->expression = $expression;
        $this->filters = $filters;
        $this->autoEscape = $autoEscape;
    }

    public function getType(): string
    {
        return 'expression';
    }

    public function getExpression(): AbstractNode
    {
        return $this->expression;
    }

    public function hasFilters(): bool
    {
        return $this->filters !== null && count($this->filters->getFilters()) > 0;
    }

    public function getFilters(): ?FilterChain
    {
        return $this->filters;
    }

    public function isAutoEscape(): bool
    {
        return $this->autoEscape;
    }
}
