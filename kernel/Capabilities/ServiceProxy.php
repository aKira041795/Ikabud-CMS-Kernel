<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Capabilities;

/**
 * ServiceProxy — polyglot capability dispatch via HTTP.
 *
 * A callable that translates CapabilityBus invocations into HTTP requests
 * to external services (Python, Node, Go, etc.). Drop-in compatible with
 * CapabilityRegistry::register() — the bus wraps it with circuit breaking,
 * retries, timeouts, and metrics automatically.
 *
 * Wire protocol (http+json):
 *   POST {endpoint}/capability/call
 *   Authorization: Bearer {signed_token}
 *   Content-Type: application/json
 *   Body: {capability_id, payload, caller}
 *
 * Response:
 *   {"ok": true, "data": ...}  → returns data
 *   {"ok": false, "error": "..."}  → throws CapabilityCallException
 */
final class ServiceProxy
{
    private string $endpoint;
    private string $protocol;
    private array $auth;
    private int $timeoutMs;
    private ?string $serviceToken;

    /** @var (callable(string $url, array $opts): array{status:int, body:string})|null */
    private $httpHandler = null;

    /**
     * @param array $serviceConfig The raw service{} block from module.json
     */
    public function __construct(array $serviceConfig)
    {
        $this->endpoint  = rtrim((string)($serviceConfig['endpoint'] ?? ''), '/');
        $this->protocol  = (string)($serviceConfig['protocol'] ?? 'http+json');
        $this->auth      = is_array($serviceConfig['auth'] ?? null) ? $serviceConfig['auth'] : [];
        $this->timeoutMs = max(1000, (int)($serviceConfig['timeout_ms'] ?? 30000));

        $this->serviceToken = $this->resolveToken();
    }

    /**
     * Test seam: inject a custom HTTP handler (e.g. for unit tests).
     *
     * @param callable(string $url, array $opts): array{status:int, body:string} $handler
     */
    public function setHttpHandler(callable $handler): void
    {
        $this->httpHandler = $handler;
    }

    /**
     * Build a ServiceProxy from a parsed module.json manifest.
     *
     * @param array $manifest Full parsed module.json
     * @return self|null null if the manifest lacks a valid service config
     */
    public static function fromManifest(array $manifest): ?self
    {
        $service = $manifest['service'] ?? null;
        if (!is_array($service) || empty($service['endpoint'])) {
            return null;
        }
        return new self($service);
    }

    /**
     * CapabilityRegistry-compatible callable.
     *
     * @param mixed  $payload       The capability payload
     * @param string $capabilityId  The resolved capability ID (e.g. "ai.summarize@1")
     * @param string $providerId    The module ID of the provider
     * @return mixed The decoded response data
     * @throws CapabilityCallException on HTTP or protocol errors
     */
    public function __invoke(mixed $payload, string $capabilityId = '', string $providerId = ''): mixed
    {
        if ($this->endpoint === '') {
            throw new CapabilityCallException(
                'ServiceProxy has no endpoint configured',
                $capabilityId,
                $providerId
            );
        }

        $caller = $this->resolveCallerContext();

        $requestBody = json_encode([
            'capability_id' => $capabilityId,
            'payload'       => $payload,
            'caller'        => $caller,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['Content-Type: application/json'];
        if ($this->serviceToken !== null && $this->serviceToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->serviceToken;
        }

        $url = $this->endpoint . '/capability/call';

        try {
            $result = $this->httpHandler !== null
                ? ($this->httpHandler)($url, ['body' => $requestBody, 'headers' => $headers, 'timeout_ms' => $this->timeoutMs])
                : $this->curlPost($url, $requestBody, $headers);
        } catch (\Throwable $e) {
            throw new CapabilityCallException(
                'ServiceProxy HTTP request failed: ' . $e->getMessage(),
                $capabilityId,
                $providerId,
                $e
            );
        }

        $status  = $result['status'] ?? 0;
        $rawBody = (string)($result['body'] ?? '');

        if ($status < 200 || $status >= 300) {
            throw new CapabilityCallException(
                "ServiceProxy HTTP {$status}: {$rawBody}",
                $capabilityId,
                $providerId
            );
        }

        $decoded = json_decode($rawBody, true);
        if (!is_array($decoded)) {
            throw new CapabilityCallException(
                'ServiceProxy response is not valid JSON',
                $capabilityId,
                $providerId
            );
        }

        if (!($decoded['ok'] ?? false)) {
            $error = (string)($decoded['error'] ?? 'Unknown service error');
            throw new CapabilityCallException(
                "ServiceProxy error: {$error}",
                $capabilityId,
                $providerId
            );
        }

        return $decoded['data'] ?? null;
    }

    /**
     * @return array{status:int, body:string}
     */
    private function curlPost(string $url, string $body, array $headers): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension is required for ServiceProxy');
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \RuntimeException('curl_init failed');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT_MS     => $this->timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => min(5000, $this->timeoutMs),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new \RuntimeException("curl error: {$error}");
        }

        return ['status' => $status, 'body' => (string)$raw];
    }

    private function resolveToken(): ?string
    {
        $tokenEnv = (string)($this->auth['token_env'] ?? '');
        if ($tokenEnv === '') {
            return null;
        }

        $token = $_ENV[$tokenEnv] ?? $_SERVER[$tokenEnv] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }

        // Fallback: check getenv()
        $token = getenv($tokenEnv);
        return is_string($token) && $token !== '' ? $token : null;
    }

    private function resolveCallerContext(): array
    {
        $ctx = ['module' => 'kernel'];

        if (function_exists('kernel_request_context_get')) {
            $capCtx = \kernel_request_context_get('_capability_call_context');
            if (is_array($capCtx)) {
                $ctx['module']     = (string)($capCtx['module'] ?? $ctx['module']);
                $ctx['request_id'] = (string)($capCtx['request_id'] ?? '');
                $ctx['tenant_id']  = (string)($capCtx['tenant_id'] ?? '');
                $user = $capCtx['user'] ?? null;
                if (is_array($user)) {
                    $ctx['user'] = [
                        'id'   => (string)($user['id'] ?? $user['sub'] ?? ''),
                        'role' => (string)($user['role'] ?? ''),
                    ];
                }
            }
        }

        if (empty($ctx['request_id']) && function_exists('request_id')) {
            $ctx['request_id'] = (string)request_id();
        }

        return $ctx;
    }
}
