<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Exceptions;

/**
 * Thrown when a user is authenticated but lacks the required role/permission.
 *
 * The global exception handler (bootstrap.php) maps this to:
 * - 403 JSON for API routes
 * - Redirect to / for web routes (or HTMX partial)
 */
class AuthorizationException extends \RuntimeException
{
    public readonly ?string $requiredRole;

    public function __construct(?string $requiredRole = null, string $message = 'Forbidden')
    {
        $this->requiredRole = $requiredRole;
        parent::__construct($message, 403);
    }
}
