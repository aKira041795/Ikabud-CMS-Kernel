<?php

declare(strict_types=1);

// ─────────────────────────────────────────────────────────────────────────────
// AI Automation — Web Search Grounding
//
// Fetches live search results before generation so the AI can cite real,
// verifiable sources and produce attribution-rich content. Supports three
// providers: Brave Search, Tavily, and Serper (Google wrapper).
//
// Settings are stored in the AI module's resolved settings (global + tenant
// merge via aiResolvedSettings()). These keys are read:
//   search_grounding_provider     — 'brave' | 'tavily' | 'serper' | ''
//   search_grounding_api_key      — API key for the provider
//   search_grounding_max_results  — integer 1–10 (default 5)
//
// Per-plan override: plan['search_grounding_enabled'] = true/false/null
//   null  → defer to global (enabled when provider + key are configured)
//   true  → force on  (still needs global key/provider)
//   false → force off for this plan
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Returns true if search grounding should run for this plan.
 * Plan-level flag overrides global setting; null means use global.
 */
function cmsAiAutomationSearchGroundingEnabled(array $plan): bool
{
    $planFlag = array_key_exists('search_grounding_enabled', $plan) ? $plan['search_grounding_enabled'] : null;
    if ($planFlag !== null) {
        if (!(bool)$planFlag) {
            return false;
        }
    }

    $settings = cmsAiAutomationSearchGroundingSettings();
    return $settings['search_grounding_provider'] !== '' && $settings['search_grounding_api_key'] !== '';
}

/**
 * Reads search provider settings from the AI module's resolved settings.
 * Results are statically cached per request.
 *
 * @return array{search_grounding_provider: string, search_grounding_api_key: string, search_grounding_max_results: int}
 */
function cmsAiAutomationSearchGroundingSettings(): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $s = [];
    if (function_exists('aiResolvedSettings')) {
        try {
            $s = aiResolvedSettings();
        } catch (\Throwable $e) {
            // Settings unavailable — grounding will be disabled
        }
    }

    $cached = [
        'search_grounding_provider'    => trim((string)($s['search_grounding_provider'] ?? '')),
        'search_grounding_api_key'     => trim((string)($s['search_grounding_api_key'] ?? '')),
        'search_grounding_max_results' => max(1, min(10, (int)($s['search_grounding_max_results'] ?? 5))),
    ];

    return $cached;
}

/**
 * Main entry point. Builds a search query from the topic + optional keywords,
 * dispatches to the configured search provider, and returns normalized results.
 *
 * Fails silently (returns []) on any network or configuration error so content
 * generation is never blocked by a search API outage.
 *
 * @param  string   $topic    Plan topic string (used as primary query).
 * @param  string[] $keywords Additional keyword signals (up to first 3 used).
 * @return array Array of {title: string, url: string, snippet: string, source: string}
 */
function cmsAiAutomationFetchSearchGrounding(string $topic, array $keywords = []): array
{
    $settings = cmsAiAutomationSearchGroundingSettings();
    $provider = $settings['search_grounding_provider'];
    $apiKey   = $settings['search_grounding_api_key'];
    $max      = $settings['search_grounding_max_results'];

    if ($provider === '' || $apiKey === '' || trim($topic) === '') {
        return [];
    }

    // Build a tight, high-signal query
    $queryParts = [trim($topic)];
    foreach (array_slice($keywords, 0, 3) as $kw) {
        $kw = trim((string)$kw);
        if ($kw !== '') {
            $queryParts[] = $kw;
        }
    }
    $query = mb_substr(implode(' ', $queryParts), 0, 200);

    try {
        $raw = match ($provider) {
            'brave'  => cmsAiAutomationSearchBrave($query, $apiKey, $max),
            'tavily' => cmsAiAutomationSearchTavily($query, $apiKey, $max),
            'serper' => cmsAiAutomationSearchSerper($query, $apiKey, $max),
            default  => [],
        };
    } catch (\Throwable $e) {
        write_log(
            'cms ai search grounding fetch error: ' . $e->getMessage(),
            'error',
            ['provider' => $provider, 'topic' => $topic]
        );
        return [];
    }

    // Normalize, deduplicate, validate URLs (SSRF guard included)
    $seen       = [];
    $normalized = [];
    foreach ($raw as $r) {
        $url = trim((string)($r['url'] ?? ''));
        if (!preg_match('#^https?://#i', $url)) {
            continue;
        }
        if (isset($seen[$url])) {
            continue;
        }
        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || cmsAiAutomationIsPrivateHost($host)) {
            continue;
        }
        $seen[$url] = true;
        $normalized[] = [
            'title'   => mb_substr(trim((string)($r['title'] ?? '')), 0, 200),
            'url'     => $url,
            'snippet' => mb_substr(trim((string)($r['snippet'] ?? '')), 0, 600),
            'source'  => $provider,
        ];
    }

    return array_values(array_slice($normalized, 0, $max));
}

// ─── Search provider implementations ────────────────────────────────────────

/**
 * Brave Search API  https://api.search.brave.com/res/v1/web/search
 * Auth: X-Subscription-Token header. Free and paid tiers available.
 */
function cmsAiAutomationSearchBrave(string $query, string $apiKey, int $max): array
{
    $url = 'https://api.search.brave.com/res/v1/web/search?' . http_build_query([
        'q'             => $query,
        'count'         => $max,
        'result_filter' => 'web',
        'safesearch'    => 'moderate',
    ]);

    $resp = cmsAiAutomationSearchHttp(
        $url,
        [
            'Accept: application/json',
            'Accept-Encoding: gzip',
            'X-Subscription-Token: ' . $apiKey,
        ],
        null,
        10
    );

    $out = [];
    foreach ($resp['web']['results'] ?? [] as $item) {
        $out[] = [
            'title'   => (string)($item['title'] ?? ''),
            'url'     => (string)($item['url'] ?? ''),
            'snippet' => (string)($item['description'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Tavily AI Search  https://api.tavily.com/search
 * Purpose-built for LLM grounding. Returns pre-summarized snippets per result.
 */
function cmsAiAutomationSearchTavily(string $query, string $apiKey, int $max): array
{
    $resp = cmsAiAutomationSearchHttp(
        'https://api.tavily.com/search',
        ['Content-Type: application/json'],
        [
            'api_key'             => $apiKey,
            'query'               => $query,
            'search_depth'        => 'basic',
            'max_results'         => $max,
            'include_raw_content' => false,
        ],
        12
    );

    $out = [];
    foreach ($resp['results'] ?? [] as $item) {
        $out[] = [
            'title'   => (string)($item['title'] ?? ''),
            'url'     => (string)($item['url'] ?? ''),
            'snippet' => (string)($item['content'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Serper.dev  https://google.serper.dev/search
 * Wraps Google Search. Fast and affordable. Auth via X-API-KEY header.
 */
function cmsAiAutomationSearchSerper(string $query, string $apiKey, int $max): array
{
    $resp = cmsAiAutomationSearchHttp(
        'https://google.serper.dev/search',
        [
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
        ],
        [
            'q'   => $query,
            'num' => $max,
        ],
        10
    );

    $out = [];
    foreach ($resp['organic'] ?? [] as $item) {
        $out[] = [
            'title'   => (string)($item['title'] ?? ''),
            'url'     => (string)($item['link'] ?? ''),
            'snippet' => (string)($item['snippet'] ?? ''),
        ];
    }

    return $out;
}

// ─── HTTP utility ────────────────────────────────────────────────────────────

/**
 * Minimal curl wrapper for search API requests.
 *
 * GET when $postData is null, POST (JSON body) when $postData is an array.
 * SSL verification is always enforced. Returns [] on any error.
 *
 * @param  string[]   $headers  HTTP headers (e.g. ['Content-Type: application/json'])
 * @param  array|null $postData POST body; JSON-encoded automatically
 * @param  int        $timeoutSeconds  Total request timeout
 * @return array  Decoded JSON response or [] on error
 */
function cmsAiAutomationSearchHttp(string $url, array $headers, ?array $postData, int $timeoutSeconds): array
{
    if (!function_exists('curl_init')) {
        write_log('cms ai search grounding: curl extension not available', 'error');
        return [];
    }

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,     // No redirects — prevent SSRF via redirect
        CURLOPT_MAXREDIRS      => 0,
        CURLOPT_TIMEOUT        => $timeoutSeconds,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_ENCODING       => 'gzip, deflate',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_USERAGENT      => 'ikabud-cms-grounding/1.0',
        CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS | CURLPROTO_HTTP,
    ]);

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = (string)curl_error($ch);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        write_log('cms ai search grounding curl error: ' . $err, 'error', ['url' => $url]);
        return [];
    }

    if ($code < 200 || $code >= 300) {
        write_log(
            'cms ai search grounding non-2xx response: ' . $code,
            'warning',
            ['url' => $url, 'body_preview' => mb_substr((string)$raw, 0, 400)]
        );
        return [];
    }

    $decoded = json_decode((string)$raw, true);
    return is_array($decoded) ? $decoded : [];
}

// ─── Citation extraction ─────────────────────────────────────────────────────

/**
 * Extracts all external HTTP/HTTPS links from generated HTML.
 *
 * Returns a deduplicated array of {title, url}. Skips relative URLs and any
 * URL whose host resolves to a private/internal address (SSRF guard).
 *
 * @return array<int, array{title: string, url: string}>
 */
function cmsAiAutomationExtractCitationsFromHtml(string $html): array
{
    if ($html === '') {
        return [];
    }

    if (!preg_match_all(
        '/<a\b[^>]*\bhref=["\']?(https?:\/\/[^"\'>\s]+)["\']?[^>]*>(.*?)<\/a>/is',
        $html,
        $matches,
        PREG_SET_ORDER
    )) {
        return [];
    }

    $citations = [];
    $seen      = [];

    foreach ($matches as $m) {
        $url   = trim($m[1]);
        $label = trim(strip_tags($m[2]));

        if ($url === '' || isset($seen[$url])) {
            continue;
        }

        $host = (string)parse_url($url, PHP_URL_HOST);
        if ($host === '' || cmsAiAutomationIsPrivateHost($host)) {
            continue;
        }

        $seen[$url] = true;
        $citations[] = [
            'title' => $label !== '' ? mb_substr($label, 0, 200) : $host,
            'url'   => $url,
        ];
    }

    return $citations;
}

// ─── SSRF guard ──────────────────────────────────────────────────────────────

/**
 * Returns true when the hostname resolves to a private / reserved address.
 *
 * This protects against AI-generated content that embeds internal URLs,
 * and prevents those URLs from being cited or fetched. It is a best-effort
 * pattern check on the hostname string — it does NOT perform a DNS lookup
 * (intentionally, to avoid timing attacks). Complement with network-level
 * egress controls in production.
 */
function cmsAiAutomationIsPrivateHost(string $host): bool
{
    $lhost = strtolower(trim($host));

    // Localhost and common internal TLDs
    if (
        $lhost === 'localhost'
        || $lhost === '0.0.0.0'
        || str_ends_with($lhost, '.local')
        || str_ends_with($lhost, '.internal')
        || str_ends_with($lhost, '.localhost')
    ) {
        return true;
    }

    // Bare IP addresses: reject private / loopback / link-local ranges
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $privateRanges = [
            '127.',              // Loopback
            '10.',               // RFC 1918 class A
            '192.168.',          // RFC 1918 class C
            '172.16.', '172.17.', '172.18.', '172.19.',
            '172.20.', '172.21.', '172.22.', '172.23.',
            '172.24.', '172.25.', '172.26.', '172.27.',
            '172.28.', '172.29.', '172.30.', '172.31.',  // RFC 1918 class B
            '169.254.',          // Link-local (APIPA)
            '0.',                // "This" network
            '100.64.',           // RFC 6598 shared address space
            '192.0.2.',          // TEST-NET-1
            '198.51.100.',       // TEST-NET-2
            '203.0.113.',        // TEST-NET-3
            '240.',              // Reserved
            '::1',               // IPv6 loopback
            'fc', 'fd',          // IPv6 unique-local
            'fe80',              // IPv6 link-local
        ];
        foreach ($privateRanges as $prefix) {
            if (str_starts_with($host, $prefix)) {
                return true;
            }
        }
    }

    return false;
}
