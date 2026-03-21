<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

final class CapabilityCallException extends CapabilityException
{
    public function __construct(
        string $message,
        public readonly string $capabilityId,
        public readonly ?string $providerId = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
