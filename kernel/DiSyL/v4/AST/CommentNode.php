<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

final class CommentNode extends AbstractNode
{
    private string $content;

    public function __construct(array $span, string $content = '')
    {
        parent::__construct($span);
        $this->content = $content;
    }

    public function getType(): string
    {
        return 'comment';
    }

    public function getContent(): string
    {
        return $this->content;
    }
}
