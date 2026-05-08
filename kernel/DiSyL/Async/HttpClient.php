<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Async;

/**
 * DiSyL 4.5 HTTP client used by `fetch()` inside async templates.
 *
 * Default backend is synchronous curl. A test seam (`setHandler`) lets
 * tests inject a deterministic resolver so suites don't hit the network.
 *
 * 4.5.1: this class will gain multi-curl multiplexing driven by the
 * Fibers-based Scheduler. The fetch() signature does not change.
 */
final class HttpClient
{
    /** @var (callable(string $url, array $opts): array{status:int, body:string, headers:array<string,string>})|null */
    private $handler = null;

    /**
     * Set a custom handler (test seam).
     *
     * @param callable(string $url, array $opts): array{status:int, body:string, headers:array<string,string>} $handler
     */
    public function setHandler(callable $handler): void
    {
        $this->handler = $handler;
    }

    /**
     * Issue a request. Returns a settled Promise containing the decoded
     * body when Content-Type is JSON, otherwise the raw body string.
     *
     * @param array{timeout?: int, method?: string, headers?: array<string,string>, body?: string} $opts
     */
    public function fetch(string $url, array $opts = []): Promise
    {
        try {
            $result = $this->handler !== null
                ? ($this->handler)($url, $opts)
                : $this->curlFetch($url, $opts);
            $body = $result['body'] ?? '';
            $ct = $result['headers']['content-type'] ?? $result['headers']['Content-Type'] ?? '';
            $value = (str_contains($ct, 'json') && $body !== '')
                ? json_decode($body, true, 512, JSON_THROW_ON_ERROR)
                : $body;
            $status = $result['status'] ?? 0;
            if ($status >= 400) {
                return Promise::rejected(new \RuntimeException("DISYL_FETCH_HTTP_$status: $url"));
            }
            return Promise::resolved($value);
        } catch (\Throwable $e) {
            return Promise::rejected($e);
        }
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function curlFetch(string $url, array $opts): array
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension required for default fetch()');
        }
        $ch = curl_init($url);
        if ($ch === false) throw new \RuntimeException('curl_init failed');
        $timeoutMs = (int)($opts['timeout'] ?? 5000);
        $headers = [];
        foreach (($opts['headers'] ?? []) as $k => $v) { $headers[] = "$k: $v"; }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS     => $timeoutMs,
            CURLOPT_CUSTOMREQUEST  => $opts['method'] ?? 'GET',
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADER         => true,
            CURLOPT_POSTFIELDS     => $opts['body'] ?? null,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("DISYL_FETCH_NETWORK: $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        $rawHeaders = substr((string)$raw, 0, $headerSize);
        $body = substr((string)$raw, $headerSize);
        $parsed = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $parsed[strtolower(trim($k))] = trim($v);
            }
        }
        return ['status' => $status, 'body' => (string)$body, 'headers' => $parsed];
    }
}
