<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Security;

/**
 * DiSyL 4.4 — Sandbox violation exception.
 *
 * Thrown by {@see Sandbox::require()} only when strict mode is active.
 * Caught by the engine at the nearest sandbox boundary and converted to
 * a 500 response by the caller.
 */
final class SandboxViolation extends \RuntimeException
{
}
