<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Http;

/**
 * Pluggable rate limiter for API routes.
 *
 * Emits standard RateLimit-* headers and legacy X-RateLimit-* headers.
 * Supports pluggable storage backends (database default, APCu optional).
 *
 * Usage (in public/index.php or middleware):
 *   $limiter = new RateLimiter();
 *   $result = $limiter->attempt('api:login:' . $clientIp, 10, 60);
 *   $limiter->emitHeaders($result);
 *   if (!$result['allowed']) { exit; }
 */
class RateLimiter
{
    /** @var callable */
    private $storage;

    /**
     * @param callable|null $storage Storage backend. Defaults to DB-based.
     *        Signature: (string $key, int $maxRequests, int $windowSeconds): array
     *        Returns: ['allowed' => bool, 'limit' => int, 'remaining' => int, 'reset' => int, 'retryAfter' => ?int]
     */
    public function __construct(?callable $storage = null)
    {
        $this->storage = $storage ?? [$this, 'dbStorage'];
    }

    /**
     * Attempt to consume a rate limit token.
     *
     * @param string $key           Rate limit key (e.g. 'api:login:127.0.0.1')
     * @param int    $maxRequests   Maximum requests in the window
     * @param int    $windowSeconds Window duration in seconds
     * @return array{allowed: bool, limit: int, remaining: int, reset: int, retryAfter: ?int}
     */
    public function attempt(string $key, int $maxRequests, int $windowSeconds): array
    {
        return ($this->storage)($key, $maxRequests, $windowSeconds);
    }

    /**
     * Emit standard rate-limit response headers.
     */
    public function emitHeaders(array $result): void
    {
        if (headers_sent()) {
            return;
        }
        // Standard (draft) headers
        header('RateLimit-Limit: ' . $result['limit']);
        header('RateLimit-Remaining: ' . $result['remaining']);
        header('RateLimit-Reset: ' . $result['reset']);
        // Legacy headers
        header('X-RateLimit-Limit: ' . $result['limit']);
        header('X-RateLimit-Remaining: ' . $result['remaining']);
        header('X-RateLimit-Reset: ' . $result['reset']);
        if ($result['retryAfter'] !== null) {
            header('Retry-After: ' . $result['retryAfter']);
        }
        // If rate limited, emit 429 JSON and exit
        if (!$result['allowed']) {
            http_response_code(429);
            ApiResponse::error('rate_limited', 'Too many requests', 429, [
                'retry_after' => $result['retryAfter'],
            ]);
        }
    }

    /**
     * Build a composite rate limit key from request context.
     *
     * @param string $prefix    Prefix for the key (e.g. 'api:login')
     * @param string $clientIp  Client IP address
     * @param string $route     Optional route pattern for route-specific limits
     * @return string
     */
    public static function buildKey(string $prefix, string $clientIp, string $route = ''): string
    {
        $parts = [$prefix, $clientIp];
        if ($route !== '') {
            $parts[] = $route;
        }
        return implode(':', $parts);
    }

    /**
     * Default DB-backed storage using the rate_limits table.
     */
    private function dbStorage(string $key, int $maxRequests, int $windowSeconds): array
    {
        $db = $this->db();
        $now = time();
        $windowStart = $now - $windowSeconds;

        // Clean expired entries for this key
        $db->prepare(
            'DELETE FROM rate_limits WHERE identifier = ? AND window_start < FROM_UNIXTIME(?)'
        )->execute([$key, $windowStart]);

        // Insert or update with sliding window
        $stmt = $db->prepare(
            'INSERT INTO rate_limits (identifier, action, attempts, window_start)
             VALUES (?, ?, 1, FROM_UNIXTIME(?))
             ON DUPLICATE KEY UPDATE
                 attempts = IF(window_start >= FROM_UNIXTIME(?), attempts + 1, 1),
                 window_start = IF(window_start >= FROM_UNIXTIME(?), window_start, FROM_UNIXTIME(?))'
        );
        $stmt->execute([$key, 'api_rate_limit', $now, $windowStart, $windowStart, $now]);

        // Read current count
        $stmt = $db->prepare(
            'SELECT attempts, UNIX_TIMESTAMP(window_start) AS ws
             FROM rate_limits WHERE identifier = ?'
        );
        $stmt->execute([$key]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $attempts = $row ? (int)$row['attempts'] : 1;
        $reset = $row ? (int)$row['ws'] + $windowSeconds : $now + $windowSeconds;
        $remaining = max(0, $maxRequests - $attempts);
        $retryAfter = $attempts >= $maxRequests ? ($reset - $now) : null;

        return [
            'allowed'     => $attempts <= $maxRequests,
            'limit'       => $maxRequests,
            'remaining'   => $remaining,
            'reset'       => $reset,
            'retryAfter'  => $retryAfter,
        ];
    }

    /**
     * APCu-backed storage for higher throughput.
     * Falls back to DB if APCu is unavailable.
     */
    public static function apcuStorage(string $key, int $maxRequests, int $windowSeconds): array
    {
        if (!function_exists('apcu_fetch')) {
            // Fall back to DB
            $limiter = new self();
            return $limiter->attempt($key, $maxRequests, $windowSeconds);
        }

        $now = time();
        $windowKey = "rate_limit:{$key}";
        $windowStartKey = "rate_limit:{$key}:start";

        $windowStart = apcu_fetch($windowStartKey);
        if ($windowStart === false || $now - $windowStart > $windowSeconds) {
            apcu_store($windowStartKey, $now, $windowSeconds * 2);
            apcu_store($windowKey, 1, $windowSeconds * 2);
            return [
                'allowed'    => true,
                'limit'      => $maxRequests,
                'remaining'  => $maxRequests - 1,
                'reset'      => $now + $windowSeconds,
                'retryAfter' => null,
            ];
        }

        $attempts = apcu_inc($windowKey, 1);
        $remaining = max(0, $maxRequests - $attempts);
        $reset = $windowStart + $windowSeconds;
        $retryAfter = $attempts >= $maxRequests ? ($reset - $now) : null;

        return [
            'allowed'    => $attempts <= $maxRequests,
            'limit'      => $maxRequests,
            'remaining'  => $remaining,
            'reset'      => $reset,
            'retryAfter' => $retryAfter,
        ];
    }

    private function db(): \PDO
    {
        if (function_exists('app') && $app = \app()) {
            return $app->db();
        }
        throw new \RuntimeException('Application not available');
    }
}
