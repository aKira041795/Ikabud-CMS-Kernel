<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Exceptions;

/**
 * Thrown when a request requires authentication but no valid session/token is present.
 *
 * The global exception handler (bootstrap.php) maps this to:
 * - 401 JSON for API routes
 * - Redirect to /login for web routes
 */
class AuthenticationException extends \RuntimeException
{
    public function __construct(string $message = 'Authentication required')
    {
        parent::__construct($message, 401);
    }
}
