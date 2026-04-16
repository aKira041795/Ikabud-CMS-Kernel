<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

class SlotNode extends AbstractNode
{
    private string $name;
    private ?DocumentNode $body;

    public function __construct(array $span, string $name, ?DocumentNode $body = null)
    {
        parent::__construct($span);
        $this->name = $name;
        $this->body = $body;
    }

    public function getType(): string
    {
        return 'slot';
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function hasDefaultContent(): bool
    {
        return $this->body !== null;
    }

    public function getBody(): ?DocumentNode
    {
        return $this->body;
    }
}
