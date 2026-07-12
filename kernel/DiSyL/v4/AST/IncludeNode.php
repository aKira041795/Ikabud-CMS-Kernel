<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class IncludeNode extends AbstractNode
{
    private string $template;
    /** @var array<string, AbstractNode> variable name → parsed expression */
    private array $variables;
    private ?DocumentNode $body = null;

    /**
     * @param array $span
     * @param string $template
     * @param array<string, AbstractNode> $variables
     * @param DocumentNode|null $body Body content for block includes ({include}...{/include})
     */
    public function __construct(array $span, string $template, array $variables = [], ?DocumentNode $body = null)
    {
        parent::__construct($span);
        $this->template = $template;
        $this->variables = $variables;
        $this->body = $body;
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

    public function getBody(): ?DocumentNode
    {
        return $this->body;
    }

    public function hasBody(): bool
    {
        return $this->body !== null;
    }
}
