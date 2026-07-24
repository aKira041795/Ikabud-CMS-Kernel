<?php
declare(strict_types=1);

class AcademicSimilarityInternetDiscoveryService
{
    private string $tenantId;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
    }

    public function buildQueries(array $submission, string $text, array $settings): array
    {
        $maxQueries = max(1, min(10, (int)($settings['internet_check_max_queries'] ?? 3)));
        $allowFull = (($settings['internet_check_allow_full_document_query'] ?? '0') === '1');
        $queries = [];

        $title = trim((string)($submission['submission_title'] ?? ''));
        if ($title !== '') {
            $queries[] = '"' . $this->clipQuery($title, 160) . '"';
        }

        $plain = preg_replace('/\s+/', ' ', trim(strip_tags($text))) ?: '';
        if ($plain !== '') {
            $sentences = preg_split('/(?<=[.!?])\s+/', $plain) ?: [];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                if (str_word_count($sentence) >= 8) {
                    $queries[] = '"' . $this->clipQuery($sentence, $allowFull ? 220 : 140) . '"';
                }
                if (count($queries) >= $maxQueries) {
                    break;
                }
            }
        }

        $unique = [];
        foreach ($queries as $query) {
            if ($query !== '""' && !in_array($query, $unique, true)) {
                $unique[] = $query;
            }
            if (count($unique) >= $maxQueries) {
                break;
            }
        }

        return $unique;
    }

    public function discover(array $queries, array $settings): array
    {
        $provider = (string)($settings['internet_check_provider'] ?? 'capability');
        $maxSources = max(1, min(25, (int)($settings['internet_check_max_sources'] ?? 5)));
        $candidates = [];

        if ($provider === 'seed_urls') {
            foreach ($this->seedUrlCandidates((string)($settings['internet_check_seed_urls'] ?? ''), $queries) as $candidate) {
                $candidates[] = $candidate;
                if (count($candidates) >= $maxSources) {
                    break;
                }
            }
        }

        if ($candidates === [] && $provider === 'ai') {
            try {
                $result = app()->cap()->call('ai.search.discover@1', [
                    'tenant_id' => $this->tenantId,
                    'queries' => $queries,
                    'max_sources' => $maxSources,
                    'payload_policy' => (string)($settings['internet_check_payload_policy'] ?? 'snippets_only'),
                    'internet_search_backend' => (string)($settings['internet_search_backend'] ?? 'serpapi'),                    'timeout_seconds' => (int)($settings['internet_check_timeout'] ?? 15),                ], ['caller' => ['module' => 'academic-similarity']]);
                if (is_array($result) && !empty($result['candidates']) && is_array($result['candidates'])) {
                    foreach ($result['candidates'] as $idx => $candidate) {
                        if (!is_array($candidate)) {
                            continue;
                        }
                        $candidate['provider'] = (string)($candidate['provider'] ?? 'ai');
                        $candidate['rank'] = (int)($candidate['rank'] ?? ($idx + 1));
                        $candidate['query'] = (string)($candidate['query'] ?? ($queries[0] ?? ''));
                        $candidates[] = $candidate;
                        if (count($candidates) >= $maxSources) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // AI search unavailable — fall through silently, candidates stay empty
                if (function_exists('write_log')) {
                    write_log('AISS ai.search.discover@1 call failed: ' . $e->getMessage(), 'warning');
                }
            }
        }

        if ($candidates === [] && $provider === 'capability') {
            try {
                $result = app()->cap()->call('academic_similarity.internet.discover@1', [
                    'tenant_id' => $this->tenantId,
                    'queries' => $queries,
                    'max_sources' => $maxSources,
                    'payload_policy' => (string)($settings['internet_check_payload_policy'] ?? 'snippets_only'),
                ], ['caller' => ['module' => 'academic-similarity']]);
                if (is_array($result) && !empty($result['candidates']) && is_array($result['candidates'])) {
                    foreach ($result['candidates'] as $idx => $candidate) {
                        if (!is_array($candidate)) {
                            continue;
                        }
                        $candidate['provider'] = (string)($candidate['provider'] ?? 'capability');
                        $candidate['rank'] = (int)($candidate['rank'] ?? ($idx + 1));
                        $candidate['query'] = (string)($candidate['query'] ?? ($queries[0] ?? ''));
                        $candidates[] = $candidate;
                        if (count($candidates) >= $maxSources) {
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                return ['ok' => false, 'candidates' => [], 'error' => $e->getMessage()];
            }
        }

        return ['ok' => true, 'candidates' => array_slice($candidates, 0, $maxSources)];
    }

    public function fetchText(string $url, int $maxChars): array
    {
        if (!preg_match('#^https?://#i', $url)) {
            return ['ok' => false, 'error' => 'Only http/https URLs are allowed'];
        }

        // ── Wikipedia: use the clean text API instead of scraping HTML ──
        if (preg_match('#^https?://([a-z]+)\.wikipedia\.org/wiki/(.+)$#i', $url, $m)) {
            $lang = $m[1];
            $title = urldecode(str_replace('_', ' ', $m[2]));
            $apiUrl = "https://{$lang}.wikipedia.org/w/api.php?action=query&prop=extracts&explaintext&titles=" . rawurlencode($title) . "&format=json";
            $apiCtx = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 10,
                    'header' => "User-Agent: AISS-InternetCheck/1.0\r\nAccept: application/json\r\n",
                ],
            ]);
            $apiRaw = @file_get_contents($apiUrl, false, $apiCtx);
            if (is_string($apiRaw)) {
                $apiData = json_decode($apiRaw, true);
                $pages = $apiData['query']['pages'] ?? [];
                foreach ($pages as $page) {
                    $extract = trim((string)($page['extract'] ?? ''));
                    if ($extract !== '') {
                        if ($maxChars > 0 && strlen($extract) > $maxChars) {
                            $extract = substr($extract, 0, $maxChars);
                        }
                        return ['ok' => true, 'text' => $extract];
                    }
                }
            }
            // Fall through to generic HTML scraper if API fails
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => 12,
                'header' => "User-Agent: AISS Internet Check/1.0\r\nAccept: text/html,text/plain;q=0.9,*/*;q=0.1\r\n",
            ],
        ]);
        $raw = @file_get_contents($url, false, $context, 0, max(4096, $maxChars * 2));
        if (!is_string($raw) || trim($raw) === '') {
            return ['ok' => false, 'error' => 'Unable to retrieve source URL'];
        }

        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/is', ' ', $raw) ?? $raw;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/is', ' ', $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;
        $text = trim($text);
        if ($maxChars > 0 && strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars);
        }

        return ['ok' => $text !== '', 'text' => $text, 'error' => $text === '' ? 'No extractable text retrieved' : null];
    }

    private function seedUrlCandidates(string $seedUrls, array $queries): array
    {
        $urls = preg_split('/[\r\n,]+/', $seedUrls) ?: [];
        $candidates = [];
        foreach ($urls as $idx => $url) {
            $url = trim($url);
            if ($url === '') {
                continue;
            }
            $candidates[] = [
                'provider' => 'seed_urls',
                'query' => (string)($queries[0] ?? ''),
                'rank' => $idx + 1,
                'url' => $url,
                'title' => $url,
                'snippet' => '',
                'metadata' => ['configured_seed' => true],
            ];
        }
        return $candidates;
    }

    private function clipQuery(string $text, int $maxChars): string
    {
        $text = preg_replace('/\s+/', ' ', trim($text)) ?: '';
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return rtrim(substr($text, 0, $maxChars));
    }
}
