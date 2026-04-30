<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

/**
 * Represents a built-in function call expression: funcname(arg1, arg2, ...).
 *
 * Only functions registered in FunctionRegistry are allowed at runtime;
 * unknown names evaluate to null rather than executing arbitrary PHP.
 */
class FunctionCallNode extends AbstractNode
{
    private string $name;
    /** @var AbstractNode[] */
    private array $arguments;

    /**
     * @param array        $span      Source span (start/end offsets, may be empty)
     * @param string       $name      Function name (e.g. 'range', 'min')
     * @param AbstractNode[] $arguments Parsed argument expression nodes
     */
    public function __construct(array $span, string $name, array $arguments = [])
    {
        parent::__construct($span);
        $this->name = $name;
        $this->arguments = $arguments;
    }

    public function getType(): string
    {
        return 'function_call';
    }

    public function getName(): string
    {
        return $this->name;
    }

    /** @return AbstractNode[] */
    public function getArguments(): array
    {
        return $this->arguments;
    }
}
