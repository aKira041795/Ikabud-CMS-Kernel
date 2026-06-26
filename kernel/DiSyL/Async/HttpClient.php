<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\Async;

/**
 * DiSyL 4.5.1 HTTP client — multi-curl multiplexing with Fiber integration.
 *
 * Uses curl_multi_exec() for true concurrent HTTP I/O when called from
 * within a Fiber. Falls back to synchronous curl_exec outside Fiber context.
 */
final class HttpClient
{
    /** @var (callable(string $url, array $opts): array{status:int, body:string, headers:array<string,string>})|null */
    private $handler = null;

    /** @var array<int, array{ch: \CurlHandle, resolve: callable, reject: callable}> Active multi-curl transfers */
    private static array $activeTransfers = [];

    /** @var \CurlMultiHandle|null */
    private static $multiHandle = null;

    /** @var int Next transfer ID */
    private static int $nextTransferId = 1;

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
     * When called inside a Fiber, adds the request to the multi-curl handle
     * and suspends the calling Fiber. Outside Fiber, falls back to sync curl.
     *
     * @param array{timeout?: int, method?: string, headers?: array<string,string>, body?: string} $opts
     */
    public function fetch(string $url, array $opts = []): Promise
    {
        // Test seam
        if ($this->handler !== null) {
            try {
                $result = ($this->handler)($url, $opts);
                return self::settleResult($result);
            } catch (\Throwable $e) {
                return Promise::rejected($e);
            }
        }

        // Fiber-aware multi-curl path
        if (\Fiber::getCurrent() !== null) {
            return $this->fiberFetch($url, $opts);
        }

        // Sync fallback
        try {
            $result = $this->curlFetch($url, $opts);
            return self::settleResult($result);
        } catch (\Throwable $e) {
            return Promise::rejected($e);
        }
    }

    /**
     * Process pending multi-curl transfers. Called by the scheduler
     * between fiber resumptions to advance I/O.
     *
     * @return int Number of transfers still active
     */
    public static function tick(): int
    {
        if (self::$multiHandle === null || self::$activeTransfers === []) {
            return 0;
        }

        $active = 0;
        do {
            $mrc = curl_multi_exec(self::$multiHandle, $active);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        // Process completed transfers
        while ($info = curl_multi_info_read(self::$multiHandle)) {
            $ch = $info['handle'];
            $id = self::findTransferId($ch);

            if ($id !== null && isset(self::$activeTransfers[$id])) {
                $transfer = self::$activeTransfers[$id];
                $raw = curl_multi_getcontent($ch);
                $error = curl_error($ch);

                if ($raw === false || $error !== '') {
                    $transfer['reject'](new \RuntimeException("DISYL_FETCH_NETWORK: {$error}"));
                } else {
                    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
                    $rawHeaders = substr((string)$raw, 0, $headerSize);
                    $body = substr((string)$raw, $headerSize);
                    $parsed = [];
                    foreach (explode("\r\n", $rawHeaders) as $line) {
                        if (str_contains($line, ':')) {
                            [$k, $v] = explode(':', $line, 2);
                            $parsed[strtolower(trim($k))] = trim($v);
                        }
                    }
                    $transfer['resolve'](['status' => $status, 'body' => (string)$body, 'headers' => $parsed]);
                }

                curl_multi_remove_handle(self::$multiHandle, $ch);
                curl_close($ch);
                unset(self::$activeTransfers[$id]);
            }
        }

        return count(self::$activeTransfers);
    }

    /**
     * Reset global multi-curl state (for test cleanup).
     */
    public static function resetMulti(): void
    {
        if (self::$multiHandle !== null) {
            foreach (self::$activeTransfers as $id => $t) {
                curl_multi_remove_handle(self::$multiHandle, $t['ch']);
                curl_close($t['ch']);
            }
            curl_multi_close(self::$multiHandle);
        }
        self::$multiHandle = null;
        self::$activeTransfers = [];
        self::$nextTransferId = 1;
    }

    /**
     * Fiber-aware fetch: adds to multi-curl, suspends fiber, returns when complete.
     */
    private function fiberFetch(string $url, array $opts): Promise
    {
        return new Promise(function (callable $resolve, callable $reject) use ($url, $opts): void {
            $ch = $this->initCurl($url, $opts);
            if ($ch === false) {
                $reject(new \RuntimeException("curl_init failed for {$url}"));
                return;
            }

            $id = self::$nextTransferId++;

            if (self::$multiHandle === null) {
                self::$multiHandle = curl_multi_init();
            }

            curl_multi_add_handle(self::$multiHandle, $ch);
            self::$activeTransfers[$id] = ['ch' => $ch, 'resolve' => $resolve, 'reject' => $reject];

            // Suspend — the scheduler will call tick() between fiber resumptions
            \Fiber::suspend();

            // Transfer may still be in-flight; tick() will complete it.
            // If still pending, the scheduler loop will re-suspend and retry.
            if (isset(self::$activeTransfers[$id])) {
                // Still pending — will be resolved on next tick
            }
        });
    }

    /**
     * Initialize a curl handle with the given options.
     *
     * @return \CurlHandle|false
     */
    private function initCurl(string $url, array $opts)
    {
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('curl extension required');
        }
        $ch = curl_init($url);
        if ($ch === false) return false;

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

        return $ch;
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function curlFetch(string $url, array $opts): array
    {
        $ch = $this->initCurl($url, $opts);
        if ($ch === false) throw new \RuntimeException('curl_init failed');

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("DISYL_FETCH_NETWORK: {$err}");
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

    /**
     * Settle a result into a resolved or rejected Promise.
     */
    private static function settleResult(array $result): Promise
    {
        $status = $result['status'] ?? 0;
        if ($status >= 400) {
            return Promise::rejected(new \RuntimeException("DISYL_FETCH_HTTP_{$status}: " . ($result['body'] ?? '')));
        }
        $body = $result['body'] ?? '';
        $ct = $result['headers']['content-type'] ?? $result['headers']['Content-Type'] ?? '';
        $value = (str_contains($ct, 'json') && $body !== '')
            ? json_decode($body, true, 512, JSON_THROW_ON_ERROR)
            : $body;
        return Promise::resolved($value);
    }

    /**
     * Find a transfer ID by curl handle.
     */
    private static function findTransferId($ch): ?int
    {
        foreach (self::$activeTransfers as $id => $t) {
            if ($t['ch'] === $ch) return $id;
        }
        return null;
    }
}
