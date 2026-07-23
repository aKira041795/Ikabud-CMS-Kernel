<?php
declare(strict_types=1);

class AcademicSimilarityInternetSourceIngestionService
{
    private string $tenantId;
    private AcademicSimilaritySourceRepository $sourceRepo;
    private AcademicSimilaritySourceService $sourceService;

    public function __construct(string $tenantId)
    {
        $this->tenantId = $tenantId;
        $this->sourceRepo = new AcademicSimilaritySourceRepository($tenantId);
        $this->sourceService = new AcademicSimilaritySourceService($tenantId);
    }

    public function ingest(int $institutionId, array $candidate, string $retrievedText, int $maxChars): array
    {
        $text = trim(strip_tags($retrievedText));
        if ($maxChars > 0 && strlen($text) > $maxChars) {
            $text = substr($text, 0, $maxChars);
        }
        $wordCount = $this->countWords($text);
        if ($wordCount < 20) {
            return ['ok' => false, 'error' => 'Retrieved source text is too short to index'];
        }

        $url = (string)($candidate['url'] ?? '');
        $metadata = [
            'internet_discovered' => true,
            'source_url' => $url,
            'retrieved_at' => date('c'),
            'provider' => (string)($candidate['provider'] ?? ''),
            'query' => (string)($candidate['query'] ?? ''),
            'snippet' => (string)($candidate['snippet'] ?? ''),
            'publisher' => (string)($candidate['publisher'] ?? ''),
            'retrieved_text_hash' => hash('sha256', $text),
        ];

        $sourceId = $this->sourceRepo->create([
            'institution_id' => $institutionId,
            'collection_id' => 0,
            'title' => (string)($candidate['title'] ?? $url ?: 'Internet Source'),
            'author' => (string)($candidate['author'] ?? ''),
            'source_type' => 'pasted',
            'classification' => 'published',
            'original_filename' => $url,
            'storage_path' => '',
            'storage_name' => '',
            'mime_type' => 'text/plain',
            'file_size_bytes' => strlen($text),
            'word_count' => $wordCount,
            'page_count' => 0,
            'checksum_sha256' => hash('sha256', $url . '|' . $text),
            'text_hash_sha256' => hash('sha256', $text),
            'metadata_json' => $metadata,
        ]);

        $this->sourceService->indexSourceText($sourceId, $text, $wordCount, 'text/plain');

        return [
            'ok' => true,
            'source_id' => $sourceId,
            'word_count' => $wordCount,
            'text' => $text,
        ];
    }

    private function countWords(string $text): int
    {
        if (preg_match_all('/[\p{L}\p{N}]+(?:[\'’\-][\p{L}\p{N}]+)*/u', $text, $matches) !== false) {
            return count($matches[0]);
        }
        return str_word_count($text);
    }
}
