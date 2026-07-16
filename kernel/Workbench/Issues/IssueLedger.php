<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Issues;

/** Append-safe issue clustering and governed knowledge promotion. */
final class IssueLedger
{
    private const TRANSITIONS = [
        'observed' => ['clustered', 'triaged', 'dismissed', 'flaky', 'environment_only'],
        'clustered' => ['triaged', 'dismissed', 'flaky', 'environment_only'],
        'triaged' => ['reproduced', 'diagnosed', 'accepted', 'dismissed', 'flaky', 'environment_only'],
        'reproduced' => ['diagnosed', 'dismissed', 'flaky'],
        'diagnosed' => ['fixed', 'dismissed', 'accepted'],
        'fixed' => ['verified', 'diagnosed'],
        'verified' => ['promoted_to_case'],
    ];

    public function __construct(private readonly string $storagePath) { $this->ensure(); }

    public function ingest(array $finding, array $occurrence = []): array
    {
        $normalized = $this->normalize($finding);
        $fingerprint = $this->fingerprint($normalized);
        return $this->withLock(function (array &$index) use ($normalized, $fingerprint, $occurrence): array {
            $id = $index['fingerprints'][$fingerprint] ?? 'issue-' . substr($fingerprint, 0, 24);
            $issue = $this->readIssue($id) ?? [
                'schema_version' => '1.0', 'id' => $id, 'fingerprint' => $fingerprint,
                'module_id' => $normalized['module_id'], 'action_id' => $normalized['action_id'],
                'failing_node' => $normalized['failing_node'], 'state' => 'observed',
                'category' => $normalized['category'], 'severity' => $normalized['severity'],
                'summary' => $normalized['summary'], 'occurrences' => [], 'diagnoses' => [],
                'resolution' => null, 'first_seen' => date('c'), 'last_seen' => date('c'),
            ];
            $occurrence += ['run_id' => null, 'observation_id' => null, 'seen_at' => date('c'), 'source_fingerprint' => $normalized['source_fingerprint']];
            $occurrenceId = hash('sha256', json_encode([$occurrence['run_id'], $occurrence['observation_id'], $occurrence['source_fingerprint'], $normalized['summary']]));
            $seen = array_column($issue['occurrences'], 'id');
            if (!in_array($occurrenceId, $seen, true)) $issue['occurrences'][] = ['id' => $occurrenceId] + $occurrence;
            $issue['last_seen'] = date('c');
            if (count($issue['occurrences']) > 1 && $issue['state'] === 'observed') $issue['state'] = 'clustered';
            $index['fingerprints'][$fingerprint] = $id;
            $index['issues'][$id] = ['state' => $issue['state'], 'module_id' => $issue['module_id'], 'last_seen' => $issue['last_seen']];
            $this->writeIssue($issue);
            return $issue;
        });
    }

    public function transition(string $id, string $to, array $metadata = []): array
    {
        return $this->withLock(function (array &$index) use ($id, $to, $metadata): array {
            $issue = $this->readIssue($id) ?? throw new \RuntimeException("Issue not found: {$id}");
            $from = (string)$issue['state'];
            if (!in_array($to, self::TRANSITIONS[$from] ?? [], true)) throw new \DomainException("Invalid issue transition {$from} -> {$to}");
            $issue['state'] = $to;
            if (in_array($to, ['dismissed', 'accepted', 'flaky', 'environment_only', 'verified'], true)) {
                $issue['resolution'] = ['state' => $to, 'at' => date('c')] + $metadata;
            }
            $index['issues'][$id]['state'] = $to;
            $this->writeIssue($issue);
            return $issue;
        });
    }

    public function addDiagnosis(string $id, array $diagnosis, string $verdict = 'pending'): array
    {
        return $this->withLock(function (array &$index) use ($id, $diagnosis, $verdict): array {
            $issue = $this->readIssue($id) ?? throw new \RuntimeException("Issue not found: {$id}");
            $issue['diagnoses'][] = $diagnosis + ['id' => 'diag-' . bin2hex(random_bytes(6)), 'verdict' => $verdict, 'created_at' => date('c')];
            $this->writeIssue($issue);
            return $issue;
        });
    }

    public function promoteVerified(string $id, object $caseMemory, array $case): string
    {
        $issue = $this->readIssue($id) ?? throw new \RuntimeException("Issue not found: {$id}");
        if (($issue['state'] ?? '') !== 'verified') throw new \DomainException('Only verified issues can be promoted');
        $entryClass = 'Ikabud\\Kernel\\Workbench\\Comprehension\\Contracts\\CaseMemoryEntry';
        $caseId = 'case-' . $issue['module_id'] . '-' . bin2hex(random_bytes(8));
        $caseMemory->store(new $entryClass(
            id: $caseId, moduleId: $issue['module_id'], actionId: $issue['action_id'], summary: $issue['summary'],
            evidencePacket: ['issue_id' => $id, 'fingerprint' => $issue['fingerprint']], changedFiles: (array)($case['changed_files'] ?? []),
            testCommand: (string)($case['test_command'] ?? ''), fixSummary: (string)($case['fix_summary'] ?? ''), createdAt: date('c'), tags: ['verified', 'issue:' . $id, 'category:' . $issue['category']],
        ));
        $this->transition($id, 'promoted_to_case', ['case_id' => $caseId]);
        return $caseId;
    }

    public function verifyAndPromote(string $id, array $verification, object $caseMemory, array $case): string
    {
        $issue = $this->readIssue($id) ?? throw new \RuntimeException("Issue not found: {$id}");
        if (($issue['state'] ?? '') !== 'fixed') throw new \DomainException('Issue must be fixed before verification');
        if (($verification['outcome'] ?? '') !== 'passed' || trim((string)($verification['test_command'] ?? '')) === '') {
            throw new \DomainException('Promotion requires a passing named regression test');
        }
        $this->transition($id, 'verified', [
            'run_id' => $verification['run_id'] ?? null,
            'observation_id' => $verification['observation_id'] ?? null,
            'test_command' => $verification['test_command'],
        ]);
        $case['test_command'] ??= $verification['test_command'];
        return $this->promoteVerified($id, $caseMemory, $case);
    }

    public function get(string $id): ?array { return $this->readIssue($id); }
    public function all(): array
    {
        $index = $this->readIndex();
        $out = [];
        foreach (array_keys($index['issues'] ?? []) as $id) if ($issue = $this->readIssue($id)) $out[] = $issue;
        usort($out, fn(array $a, array $b): int => strcmp($b['last_seen'], $a['last_seen']));
        return $out;
    }

    private function normalize(array $finding): array
    {
        $summary = trim((string)($finding['summary'] ?? $finding['detail'] ?? 'Unknown issue'));
        $signature = mb_strtolower(preg_replace('/\b\d+\b|[a-f0-9]{12,}/i', '#', $summary) ?? $summary);
        return [
            'module_id' => trim((string)($finding['module_id'] ?? $finding['module'] ?? 'unknown')),
            'action_id' => trim((string)($finding['action_id'] ?? $finding['action'] ?? 'unknown')),
            'failing_node' => trim((string)($finding['failing_node'] ?? $finding['step'] ?? $finding['where'] ?? 'unknown')),
            'category' => trim((string)($finding['category'] ?? $finding['kind'] ?? 'unknown')),
            'severity' => in_array($finding['severity'] ?? '', ['critical', 'major', 'minor', 'note'], true) ? $finding['severity'] : 'major',
            'summary' => $summary, 'normalized_signature' => $signature,
            'source_fingerprint' => trim((string)($finding['source_fingerprint'] ?? '')),
        ];
    }

    private function fingerprint(array $f): string { return hash('sha256', implode('|', [$f['module_id'], $f['action_id'], $f['failing_node'], $f['category'], $f['normalized_signature'], $f['source_fingerprint']])); }
    private function issueFile(string $id): string { return $this->storagePath . '/issues/' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $id) . '.json'; }
    private function readIssue(string $id): ?array { $f = $this->issueFile($id); if (!is_file($f)) return null; $v = json_decode((string)file_get_contents($f), true); return is_array($v) ? $v : null; }
    private function writeIssue(array $issue): void { file_put_contents($this->issueFile($issue['id']), json_encode($issue, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); }
    private function readIndex(): array { $f = $this->storagePath . '/index.json'; $v = is_file($f) ? json_decode((string)file_get_contents($f), true) : []; return is_array($v) ? $v + ['fingerprints' => [], 'issues' => []] : ['fingerprints' => [], 'issues' => []]; }
    private function withLock(callable $fn): mixed { $lock = fopen($this->storagePath . '/index.lock', 'c+'); if (!$lock || !flock($lock, LOCK_EX)) throw new \RuntimeException('Issue ledger lock failed'); try { $index = $this->readIndex(); $result = $fn($index); file_put_contents($this->storagePath . '/index.json', json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX); return $result; } finally { flock($lock, LOCK_UN); fclose($lock); } }
    private function ensure(): void { if (!is_dir($this->storagePath . '/issues') && !@mkdir($this->storagePath . '/issues', 0770, true) && !is_dir($this->storagePath . '/issues')) throw new \RuntimeException('Cannot create issue ledger'); }
}
