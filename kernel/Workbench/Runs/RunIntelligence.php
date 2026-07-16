<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Runs;

final class RunIntelligence
{
    /** @param list<array<string,mixed>> $issues @return array<string,list<array<string,mixed>>> */
    public function cluster(array $issues): array
    {
        $groups = [];
        foreach ($issues as $issue) {
            $fingerprint = (string) ($issue['fingerprint'] ?? hash('sha256', strtolower(trim((string) ($issue['category'] ?? '') . '|' . (string) ($issue['message'] ?? '')))));
            $groups[$fingerprint][] = $issue;
        }
        ksort($groups); return $groups;
    }

    /** @param list<string> $outcomes @return array<string,mixed> */
    public function classifyFlake(array $outcomes): array
    {
        $failed = count(array_filter($outcomes, static fn(string $v): bool => $v === 'failed'));
        $passed = count(array_filter($outcomes, static fn(string $v): bool => $v === 'passed'));
        $transitions = 0; for ($i = 1; $i < count($outcomes); $i++) if ($outcomes[$i] !== $outcomes[$i - 1]) $transitions++;
        return ['classification' => $failed > 0 && $passed > 0 ? 'flaky' : ($failed > 0 ? 'consistently-failing' : 'stable'), 'failure_rate' => $outcomes === [] ? 0.0 : $failed / count($outcomes), 'transitions' => $transitions, 'governed_quarantine_required' => $failed > 0 && $passed > 0];
    }

    /** @param list<array<string,mixed>> $events @return list<array<string,mixed>> */
    public function timeline(array $events): array
    {
        usort($events, static fn(array $a, array $b): int => [(string) ($a['at'] ?? ''), (int) ($a['sequence'] ?? 0)] <=> [(string) ($b['at'] ?? ''), (int) ($b['sequence'] ?? 0)]); return $events;
    }

    /** @return array<string,mixed> */
    public function quarantine(string $fingerprint, string $owner, string $reason, string $expiresAt): array
    {
        if ($fingerprint === '' || $owner === '' || $reason === '' || new \DateTimeImmutable($expiresAt) <= new \DateTimeImmutable()) throw new \RuntimeException('Quarantine requires fingerprint, owner, reason, and future expiry');
        return ['schema' => 'ark.workbench-quarantine.v1', 'fingerprint' => $fingerprint, 'owner' => $owner, 'reason' => $reason, 'expires_at' => $expiresAt, 'created_at' => gmdate(DATE_ATOM)];
    }

    public function diagnosisIsTraceable(array $diagnosis): bool
    {
        return trim((string) ($diagnosis['failed_contract'] ?? '')) !== '' && (array) ($diagnosis['evidence_links'] ?? []) !== [] && trim((string) ($diagnosis['remediation'] ?? '')) !== '';
    }
}
