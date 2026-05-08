<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class ControlNode extends AbstractNode
{
    private string $tag;
    private array $attributes;
    private ?DocumentNode $body;
    private ?DocumentNode $else;

    public function __construct(
        array $span,
        string $tag,
        array $attributes = [],
        ?DocumentNode $body = null,
        ?DocumentNode $else = null
    ) {
        parent::__construct($span);
        $this->tag = $tag;
        $this->attributes = $attributes;
        $this->body = $body;
        $this->else = $else;
    }

    public function getType(): string
    {
        return 'control';
    }

    public function getTag(): string
    {
        return $this->tag;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getBody(): ?DocumentNode
    {
        return $this->body;
    }

    public function hasElse(): bool
    {
        return $this->else !== null;
    }

    public function getElse(): ?DocumentNode
    {
        return $this->else;
    }
}
