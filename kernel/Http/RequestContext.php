<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Central request context — resolved once after route matching, consumed everywhere.
 *
 * Replaces ad-hoc URL-prefix detection scattered across bootstrap.php, App.php,
 * module-manager.php, and public/index.php with a single value object.
 */
class RequestContext
{
    public readonly string $requestId;
    public readonly string $method;
    public readonly string $path;
    public readonly string $responseFormat; // 'json' | 'html'
    public readonly ?string $apiVersion;    // 'v1', 'v2', null for non-API
    public readonly ?int $tenantId;
    public readonly ?array $authenticatedUser;
    public readonly string $clientIp;
    public readonly bool $isStateless;      // skip session for this request
    public readonly ?array $route;          // resolved route metadata

    private function __construct(array $params)
    {
        foreach ($params as $key => $value) {
            $this->$key = $value;
        }
    }

    /**
     * Create a RequestContext from superglobals — call once early in the request lifecycle.
     * At creation time route/auth may not be resolved yet; use withRoute()/withUser() later.
     */
    public static function fromGlobals(): self
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $isApiRoute = self::matchIsApiRoute($uri);
        $version = null;

        if ($isApiRoute && preg_match('#^/(?:[a-zA-Z0-9\-]+/)?api/(v\d+)/#', $uri, $m)) {
            $version = $m[1];
        }

        $forwardedFor = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $clientIp = trim(explode(',', $forwardedFor)[0]);

        $reqId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        if ($reqId === '' || !preg_match('/^[a-zA-Z0-9\-]{1,64}$/', $reqId)) {
            $reqId = function_exists('request_id') ? request_id() : bin2hex(random_bytes(16));
        }

        return new self([
            'requestId'         => $reqId,
            'method'            => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'path'              => $uri,
            'responseFormat'    => $isApiRoute ? 'json' : 'html',
            'apiVersion'        => $version,
            'tenantId'          => null,
            'authenticatedUser' => null,
            'clientIp'          => $clientIp,
            'isStateless'       => $isApiRoute,
            'route'             => null,
        ]);
    }

    /** Attach route metadata after route matching. Returns $this for chaining. */
    public function withRoute(?array $route): self
    {
        $this->route = $route;
        if ($route !== null) {
            $this->responseFormat = $route['format'] ?? $this->responseFormat;
            $this->apiVersion = $route['version'] ?? $this->apiVersion;
            $this->isStateless = $route['stateless'] ?? $this->isStateless;
        }
        return $this;
    }

    /** Attach authenticated user after auth resolution. Returns $this for chaining. */
    public function withUser(?array $user): self
    {
        $this->authenticatedUser = $user;
        return $this;
    }

    /** Convenience: is this an API (JSON) request? */
    public function isApi(): bool
    {
        return $this->responseFormat === 'json';
    }

    /** Convenience: is this a web (HTML) request? */
    public function isWeb(): bool
    {
        return $this->responseFormat === 'html';
    }

    /**
     * URL-prefix-based API route detection.
     * This is the canonical implementation; all code should delegate here.
     * Over time, route metadata replaces this; the regex remains as fallback
     * for routes that have not yet declared metadata.
     */
    public static function matchIsApiRoute(string $uri): bool
    {
        return str_starts_with($uri, '/api/')
            || (bool) preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/api/#', $uri)
            || (bool) preg_match('#^/(?:admin/)?[a-zA-Z0-9\-]+/auth/refresh$#', $uri);
    }
}
