<?php

namespace Ikabud\Kernel\DiSyL\v4\AST;

class PropertyAccessNode extends AbstractNode
{
    private AbstractNode $object;
    /** @var AbstractNode|string */
    private $property;
    private bool $computed;

    /**
     * @param AbstractNode|string $property
     */
    public function __construct(array $span, AbstractNode $object, $property, bool $computed = false)
    {
        parent::__construct($span);
        $this->object = $object;
        $this->property = $property;
        $this->computed = $computed;
    }

    public function getType(): string
    {
        return 'property_access';
    }

    public function getObject(): AbstractNode
    {
        return $this->object;
    }

    /** @return AbstractNode|string */
    public function getProperty()
    {
        return $this->property;
    }

    public function isComputed(): bool
    {
        return $this->computed;
    }
}
