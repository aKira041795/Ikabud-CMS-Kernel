<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Content negotiation for API vs web responses.
 *
 * Determines response format based on URL prefix.
 * Accept-header-based negotiation may be added in a future phase
 * but is intentionally omitted for now to avoid cache variation,
 * handler ambiguity, and CDN complexity.
 *
 * @see RequestContext::matchIsApiRoute() for the canonical URL-prefix detection logic.
 */
class ContentNegotiator
{
    /**
     * Does the client prefer JSON?
     * Currently URL-prefix-based: /api/v1/*, /modules/api/*, /auth/refresh etc.
     */
    public static function prefersJson(): bool
    {
        return self::isApiRoute();
    }

    /**
     * Does the client prefer HTML?
     */
    public static function prefersHtml(): bool
    {
        return !self::isApiRoute();
    }

    /**
     * Check if the request URI matches an API route pattern.
     * Delegates to RequestContext::matchIsApiRoute() — the canonical implementation.
     */
    public static function isApiRoute(): bool
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return RequestContext::matchIsApiRoute($uri);
    }
}
