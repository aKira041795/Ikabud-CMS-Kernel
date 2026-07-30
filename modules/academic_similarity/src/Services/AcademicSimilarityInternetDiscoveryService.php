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
        $queries = $this->buildAiQueries($submission, $text, $settings, $maxQueries);

        $body = preg_replace('/\s+/', ' ', trim(strip_tags($text))) ?: '';
        $stopwords = ['the','and','that','this','with','from','have','been','were','their','which','they','about','would','could','should','these','those','through','between','because','other','being','during','within','without','chapter','section','table','figure','research','study','findings','results','analysis','data','participants','respondents','university','college','student','students','master','thesis','presented','faculty','graduate','studies','fulfillment','requirements','degree','said','also','like','one','may','use','can','well','many','much','since','however','therefore','whereas','although','furthermore','moreover','indeed','thus','hence','etc','rather','than','will','shall'];

        // 1. Title — extract from body if submission_title is too short
        $title = $this->sanitizeQuery((string)($submission['submission_title'] ?? ''));
        $filenameTitle = $this->sanitizeQuery((string)pathinfo(
            (string)($submission['original_filename'] ?? ''),
            PATHINFO_FILENAME
        ));
        if (strlen($title) < 40 && strlen($filenameTitle) >= 40) {
            $title = $filenameTitle;
        } elseif (strlen($title) < 40 && $body !== '') {
            // Try to extract the real title from the first substantive line of body
            $lines = preg_split('/[\r\n]+/', $body);
            foreach ($lines as $line) {
                $line = trim(preg_replace('/^Master\'?s?\s*Thesis[-–—:\s]*/i', '', $line));
                if (str_word_count($line) >= 5 && strlen($line) >= 40) {
                    $title = $line;
                    break;
                }
            }
        }
        $title = $this->sanitizeQuery(preg_replace('/^Master\'?s?\s*Thesis[-–—:\s]*/i', '', $title) ?: $title);
        if ($title !== '' && count($queries) < $maxQueries) {
            $queries[] = '"' . $this->clipQuery($title, 160) . '"';
        }
        if ($title !== '' && count($queries) < $maxQueries) {
            $queries[] = $this->contextualizePhrase($title, 'academic literature');
        }

        // 2. Skip cover page: find body after abstract/intro marker
        if (preg_match('/(?:Abstract|INTRODUCTION|Chapter\s+1|The\s+study\s+(?:aims|seeks|investigates|explores))/i', $body, $m, PREG_OFFSET_CAPTURE)) {
            $body = substr($body, max(0, $m[0][1] - 50));
        } elseif (strlen($body) > 1000) {
            $body = substr($body, 600);
        }

        // 3. Bigrams: recurring meaningful word pairs (best search queries)
        $wordList = preg_split('/\s+/', preg_replace('/[^a-zA-Z\s]/', '', $body)) ?: [];
        $bigrams = [];
        for ($i = 0; $i < count($wordList) - 1; $i++) {
            $w1 = strtolower($wordList[$i]);
            $w2 = strtolower($wordList[$i + 1]);
            if (strlen($w1) >= 4 && strlen($w2) >= 4
                && !in_array($w1, $stopwords, true) && !in_array($w2, $stopwords, true)
                && ctype_alpha($w1) && ctype_alpha($w2)) {
                $bigrams[$w1 . ' ' . $w2] = ($bigrams[$w1 . ' ' . $w2] ?? 0) + 1;
            }
        }
        arsort($bigrams);
        foreach ($bigrams as $bg => $count) {
            if ($count < 2) continue;
            $q = $this->contextualizePhrase($title, ucwords($bg));
            if (strlen($q) < 10) continue;
            if (!in_array($q, $queries, true)) {
                $queries[] = $q;
            }
            if (count($queries) >= $maxQueries) break;
        }

        // 4. Trigram key phrases for more specificity
        if (count($queries) < $maxQueries) {
            $trigrams = [];
            for ($i = 0; $i < count($wordList) - 2; $i++) {
                $w1 = strtolower($wordList[$i]);
                $w2 = strtolower($wordList[$i + 1]);
                $w3 = strtolower($wordList[$i + 2]);
                if (strlen($w1) >= 3 && strlen($w2) >= 3 && strlen($w3) >= 3
                    && ctype_alpha($w1) && ctype_alpha($w2) && ctype_alpha($w3)
                    && !in_array($w1, $stopwords, true) && !in_array($w3, $stopwords, true)) {
                    $tg = $w1 . ' ' . $w2 . ' ' . $w3;
                    $trigrams[$tg] = ($trigrams[$tg] ?? 0) + 1;
                }
            }
            arsort($trigrams);
            foreach ($trigrams as $tg => $count) {
                if ($count < 2) continue;
                $q = $this->contextualizePhrase($title, ucwords($tg));
                if (!in_array($q, $queries, true)) {
                    $queries[] = $q;
                }
                if (count($queries) >= $maxQueries) break;
            }
        }

        // 5. Fallback: topic sentences from body
        if (count($queries) < $maxQueries) {
            $sentences = preg_split('/(?<=[.!?])\s+/', $body) ?: [];
            foreach ($sentences as $sentence) {
                $sentence = trim($sentence);
                $wc = str_word_count($sentence);
                if ($wc >= 8 && $wc <= 25) {
                    $q = $this->contextualizePhrase(
                        $title,
                        $this->clipQuery($sentence, $allowFull ? 160 : 120)
                    );
                    if (!in_array($q, $queries, true)) {
                        $queries[] = $q;
                    }
                }
                if (count($queries) >= $maxQueries) break;
            }
        }

        // 6. Final deterministic fill: combine recurring topic terms with title context.
        if (count($queries) < $maxQueries) {
            $frequencies = [];
            foreach ($wordList as $word) {
                $word = strtolower($word);
                if (strlen($word) < 4 || in_array($word, $stopwords, true) || !ctype_alpha($word)) {
                    continue;
                }
                $frequencies[$word] = ($frequencies[$word] ?? 0) + 1;
            }
            arsort($frequencies);
            $terms = array_slice(array_keys($frequencies), 0, 18);
            foreach (array_chunk($terms, 3) as $termGroup) {
                if ($termGroup === []) {
                    continue;
                }
                $query = $this->contextualizePhrase($title, implode(' ', $termGroup));
                if (!in_array($query, $queries, true)) {
                    $queries[] = $query;
                }
                if (count($queries) >= $maxQueries) {
                    break;
                }
            }
        }

        return array_values(array_slice($queries, 0, $maxQueries));
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
                $timeoutSeconds = max(5, min(30, (int)($settings['internet_check_timeout'] ?? 15)));
                $capabilityTimeoutMs = max(
                    10000,
                    min(180000, count($queries) * ($timeoutSeconds + 2) * 1000)
                );
                $result = app()->cap()->call('ai.search.discover@1', [
                    'tenant_id' => $this->tenantId,
                    'queries' => $queries,
                    'max_sources' => $maxSources,
                    'payload_policy' => (string)($settings['internet_check_payload_policy'] ?? 'snippets_only'),
                    'internet_search_backend' => (string)($settings['internet_search_backend'] ?? 'serpapi'),
                    'search_engine' => (string)($settings['internet_search_engine'] ?? 'google_scholar'),
                    'timeout_seconds' => $timeoutSeconds,
                ], [
                    'caller_module' => 'academic-similarity',
                    'timeout_ms' => $capabilityTimeoutMs,
                ]);
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
                ], ['caller_module' => 'academic-similarity']);
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

    private function buildAiQueries(array $submission, string $text, array $settings, int $maxQueries): array
    {
        if (($settings['internet_check_provider'] ?? 'capability') !== 'ai'
            || ($settings['internet_query_generation_mode'] ?? 'local') !== 'ai') {
            return [];
        }

        $title = $this->sanitizeQuery((string)($submission['submission_title'] ?? ''));
        $abstract = $this->extractAbstract($text);
        if ($abstract === '') {
            return [];
        }

        try {
            $result = app()->cap()->call('ai.text.generate@1', [
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You create precise academic literature-search queries. Return JSON only in the form {"queries":["..."]}.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Create {$maxQueries} distinct Google Scholar queries for papers directly related to this thesis. "
                            . "Each query must contain 5 to 14 meaningful words, include the concrete population/topic/relationship, "
                            . "and must not contain names, school details, cover-page language, emojis, generic phrases, or Boolean operators.\n\n"
                            . "Title: {$title}\n\nAbstract:\n{$abstract}",
                    ],
                ],
                'temperature' => 0.1,
                'json' => true,
                'timeout_ms' => max(5000, ((int)($settings['internet_check_timeout'] ?? 15)) * 1000),
                'max_tokens' => 300,
            ], ['caller_module' => 'academic-similarity']);
        } catch (\Throwable $e) {
            if (function_exists('write_log')) {
                write_log('AISS AI query extraction failed: ' . $e->getMessage(), 'warning');
            }
            return [];
        }

        if (!is_array($result) || empty($result['ok'])) {
            if (function_exists('write_log')) {
                write_log('AISS AI query extraction unavailable: ' . (string)($result['error'] ?? 'unknown error'), 'warning');
            }
            return [];
        }

        $content = trim((string)($result['content'] ?? ''));
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?: $content;
        $decoded = json_decode($content, true);
        if (!is_array($decoded) || !is_array($decoded['queries'] ?? null)) {
            if (function_exists('write_log')) {
                write_log('AISS AI query extraction returned malformed JSON', 'warning');
            }
            return [];
        }

        $queries = [];
        foreach ($decoded['queries'] as $query) {
            $query = $this->sanitizeQuery((string)$query);
            $wordCount = str_word_count($query);
            if ($wordCount < 5 || $wordCount > 16 || strlen($query) < 24 || strlen($query) > 180) {
                continue;
            }
            if (!in_array($query, $queries, true)) {
                $queries[] = $query;
            }
            if (count($queries) >= $maxQueries) {
                break;
            }
        }

        return $queries;
    }

    private function extractAbstract(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $plain = preg_replace('/[^\P{C}\r\n\t]+/u', ' ', $plain) ?: $plain;

        if (preg_match('/\babstract\b\s*[:\-]?\s*(.+?)(?=\n\s*(?:keywords?|introduction|chapter\s+(?:1|i))\b)/isu', $plain, $match)) {
            $plain = (string)$match[1];
        } elseif (preg_match('/\babstract\b\s*[:\-]?\s*(.+)/isu', $plain, $match)) {
            $plain = (string)$match[1];
        } elseif (strlen($plain) > 1200) {
            $plain = substr($plain, 600);
        }

        $plain = preg_replace('/\s+/', ' ', trim($plain)) ?: '';
        return $this->clipQuery($plain, 6000);
    }

    private function contextualizePhrase(string $title, string $phrase): string
    {
        $phrase = $this->sanitizeQuery($phrase);
        $title = $this->topicContext($title);
        $contextWords = array_flip(preg_split('/\s+/', strtolower($title)) ?: []);
        $phraseWords = preg_split('/\s+/', $phrase) ?: [];
        $phraseWords = array_values(array_filter(
            $phraseWords,
            static fn(string $word): bool => !isset($contextWords[strtolower($word)])
        ));
        $phrase = trim(implode(' ', $phraseWords));
        $query = trim($title . ' ' . ($phrase !== '' ? $phrase : 'academic literature'));
        return $this->clipQuery($query, 180);
    }

    private function sanitizeQuery(string $query): string
    {
        $query = html_entity_decode(strip_tags($query), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $query = preg_replace('/[\p{C}\p{M}\p{So}\p{Sk}]+/u', ' ', $query) ?: $query;
        $query = preg_replace('/^\s*(?:[-*•]|\d+[.)])\s*/u', '', $query) ?: $query;
        $query = preg_replace('/\s+/', ' ', trim($query, " \t\n\r\0\x0B\"'")) ?: '';
        return $this->clipQuery($query, 180);
    }

    private function topicContext(string $title): string
    {
        $generic = [
            'master', 'masters', 'thesis', 'research', 'paper', 'influencing',
            'implications', 'guidance', 'counseling', 'counselling', 'the', 'and',
            'for', 'with', 'from', 'into', 'among',
        ];
        $words = preg_split('/\s+/', strtolower(preg_replace('/[^a-zA-Z0-9\s-]/', ' ', $title))) ?: [];
        $context = [];
        foreach ($words as $word) {
            $word = trim($word, '-');
            if ((strlen($word) < 3 && $word !== 'z')
                || in_array($word, $generic, true)
                || in_array($word, $context, true)) {
                continue;
            }
            $context[] = $word;
            if (count($context) >= 8) {
                break;
            }
        }
        return implode(' ', $context);
    }
}
