<?php

declare(strict_types=1);

namespace Harpp\Services;

/**
 * HARPP risk-tier classification for autonomous run results (S3).
 *
 * A deterministic, case-insensitive heuristic: an explicit `risk_level` wins when
 * present and valid; otherwise the JSON-serialized result is scanned for known
 * HIGH/CRITICAL trigger words (deploy, delete, destructive, drop/truncate, rm -,
 * grant/revoke, cross-tenant, purge, refund, void, payout). The listed triggers are
 * treated as CRITICAL (the destructive/irreversible end of the ladder); LOW is the
 * fallback. MEDIUM/HIGH remain reachable only via an explicit `risk_level`.
 *
 * Sandboxing of agent execution is OUT of scope for this slice (documented as
 * opt-in future work); this gate is an owner-approval + audit control, not a sandbox.
 */
final class HarppRiskService
{
    private const TIERS = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    /** Destructive / irreversible / security-affecting actions always require approval. */
    private const CRITICAL_TRIGGERS = [
        'deploy', 'delete', 'destructive', 'drop ', 'truncate', 'rm -',
        'grant ', 'revoke ', 'cross-tenant', 'purge', 'refund', 'void', 'payout',
    ];

    /** Classify a run result into one of LOW/MEDIUM/HIGH/CRITICAL. */
    public function classifyResult(array $result): string
    {
        if (isset($result['risk_level'])) {
            $tier = strtoupper(trim((string)$result['risk_level']));
            if (in_array($tier, self::TIERS, true)) return $tier;
        }
        $haystack = strtolower((string)json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        foreach (self::CRITICAL_TRIGGERS as $trigger) {
            if (strpos($haystack, strtolower($trigger)) !== false) return 'CRITICAL';
        }
        return 'LOW';
    }

    /** HIGH and CRITICAL risk tiers require an owner approval record. */
    public function requiresApproval(string $tier): bool
    {
        return in_array($tier, ['HIGH', 'CRITICAL'], true);
    }

    /** Generate a one-time approval token (plaintext) + its SHA-256 hash for storage. */
    public function newApprovalToken(): array
    {
        $token = bin2hex(random_bytes(32));
        return ['token' => $token, 'hash' => hash('sha256', $token)];
    }
}
