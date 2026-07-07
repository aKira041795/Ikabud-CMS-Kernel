<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class IncludeNode extends AbstractNode
{
    private string $template;
    /** @var array<string, AbstractNode> variable name → parsed expression */
    private array $variables;

    /**
     * @param array $span
     * @param string $template
     * @param array<string, AbstractNode> $variables
     */
    public function __construct(array $span, string $template, array $variables = [])
    {
        parent::__construct($span);
        $this->template = $template;
        $this->variables = $variables;
    }

    public function getType(): string
    {
        return 'include';
    }

    public function getTemplate(): string
    {
        return $this->template;
    }

    /** @return array<string, AbstractNode> */
    public function getVariables(): array
    {
        return $this->variables;
    }
}
