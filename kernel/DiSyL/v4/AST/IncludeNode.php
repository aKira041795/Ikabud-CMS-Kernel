<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class IncludeNode extends AbstractNode
{
    private string $template;
    private array $variables;

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

    public function getVariables(): array
    {
        return $this->variables;
    }
}
