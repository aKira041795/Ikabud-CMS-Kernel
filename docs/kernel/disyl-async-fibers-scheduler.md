# DiSyL Async System — Fibers Scheduler

> **Subsystem:** `kernel/DiSyL/Async/Scheduler.php` + `kernel/DiSyL/Async/HttpClient.php`
> **Version:** 4.5.1 (upgraded from 4.5.0)
> **Updated:** June 26, 2026

The DiSyL async system provides **Fiber-based cooperative multitasking** for
concurrent HTTP operations inside `{await}` blocks. Multiple `fetch()` calls
run in parallel via PHP 8.1 Fibers and multi-curl I/O multiplexing.

> **Scope:** Only scheduler-integrated HTTP `fetch()` calls are concurrent.
> PDO queries, filesystem access, blocking PHP libraries, and arbitrary
> capability handlers remain synchronous.

---

## Architecture

```
TemplateEngine::evaluateAwaitBody()
    → Collects all fetch() calls into Promise objects
    → Scheduler::add(Promise) for each
    → Scheduler::run() execution
        → Iterates Promises:
            → Settled? Resolve synchronously (zero overhead)
            → Pending? Create Fiber, suspend, drive I/O
        → HttpClient::tick() drives curl_multi_select()
            → Fiber resumes when Promise resolves
    → All resolved → template continues
```

### Components

| Class | File | Purpose |
|---|---|---|
| `Scheduler` | `kernel/DiSyL/Async/Scheduler.php` | Fiber lifecycle: add, run, suspend, resume |
| `HttpClient` | `kernel/DiSyL/Async/HttpClient.php` | Multi-curl HTTP multiplexing |
| `Promise` | `kernel/DiSyL/Async/Promise.php` | Async result container (settled/pending) |

---

## Template Syntax

```disyl
{await}
    {var profile = fetch('/api/user/profile')}
    {var orders = fetch('/api/user/orders')}
    {var notifications = fetch('/api/user/notifications')}
    <div class="dashboard">
        {profile.name} — {orders.count} orders
        {notifications unread=3}
    </div>
{endawait}
```

### `src` Attribute

The `{await}` tag supports a `src` attribute for specifying the data source URL
directly in the tag:

```disyl
{await src="/api/dashboard/stats"}
    <div class="stats">{stats.total_users} users</div>
{endawait}
```

---

## Fiber Execution Model

### Settled Promises (Fast Path)
Promises that resolve immediately (sync data, cache hits) never enter the Fiber
scheduler — they complete synchronously with zero overhead.

### Pending Promises (Async Path)
Promises awaiting HTTP responses are wrapped in PHP 8.1 `Fiber` objects:

```
1. Scheduler creates Fiber for each pending Promise
2. Fiber starts executing the fetch()
3. fetch() internally calls HttpClient::request()
4. HttpClient queues the curl handle
5. request() calls Fiber::suspend() — Fiber yields control
6. After all Fibers suspended, Scheduler calls HttpClient::tick()
7. tick() calls curl_multi_select() — blocks until I/O ready
8. curl_multi_info_read() checks completed transfers
9. Completed responses resume their Fiber via Fiber::resume()
10. Resumed Fiber receives response data, continues execution
```

### Sync Fallback
If `fetch()` is called outside a Fiber context (not inside `{await}`), it
falls back to synchronous `file_get_contents()` with stream context. This
ensures backward compatibility with templates that don't use async blocks.

---

## HttpClient — Multi-Curl Multiplexing

```php
class HttpClient {
    public function request(string $url, array $options = []): Promise;
    public function tick(): void;
    public function setHttpHandler(callable $handler): void; // Test seam
}
```

### Key Properties

- **Multi-handle reuse** — single `curl_multi` handle per `HttpClient` instance
- **Non-blocking** — `request()` returns a `Promise` immediately
- **I/O driven** — `tick()` uses `curl_multi_select()` for efficient I/O waiting
- **Test seam** — `setHttpHandler()` allows offline testing without real HTTP

### Configuration

| Setting | Default | Description |
|---|---|---|
| Timeout | 10s | Per-request curl timeout |
| Max concurrent | 10 | Max simultaneous multi-curl handles |
| Follow redirects | true | `CURLOPT_FOLLOWLOCATION` |
| SSL verify | false | `CURLOPT_SSL_VERIFYPEER` |

---

## Compiled Mode

Async tags (`{await}`, `{parallel}`, `{fetch}`) use the **interpreted pipeline**
only. Compiled mode (v4 Parser) does not handle async tags. When a template
contains async blocks, the TemplateEngine automatically falls back to
interpreted mode for that block.

---

## Migration from 4.5.0

The `Scheduler` interface is unchanged — `add()` and `run()` method signatures
are identical. The internal implementation was upgraded from:

```
4.5.0: foreach → sync curl_exec → collect results
4.5.1: foreach → Fiber::suspend() → curl_multi_select() → Fiber::resume()
```

No template changes required. Existing `{await}` blocks automatically benefit
from concurrent I/O.

---

## Test Coverage

```
tests/disyl_v45_async_test.php: 23 PASS, 0 FAIL
```

Tests cover:
- Single fetch resolution
- Multiple concurrent fetches
- Sync fallback outside Fiber
- Error handling (timeout, connection failure)
- Mixed settled + pending promises
- HttpClient multi-curl multiplexing
