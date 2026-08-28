<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;

/**
 * Bounded, tenant-scoped retrieval over HARPP's APPROVED memory only.
 *
 * Lets an agent search what was previously approved/decided — ADRs + approved
 * decisions + artifact-bundle contents — and cite it, without scanning raw
 * conversation messages. Unlocks cross-session reuse and citation. Searches only
 * decisions in terminal/approved states (DECIDED/ACKNOWLEDGED/APPLIED/CLOSED),
 * never raw messages; every returned row is a short bounded snippet. HARPP is a
 * per-tenant DB, so the module DB already isolates tenants. MySQL 5.7-safe (no
 * window functions / CTEs); LIKE terms are parameterized and %/_ escaped.
 */
final class HarppMemoryService
{
    private const APPROVED_STATES = ['DECIDED', 'ACKNOWLEDGED', 'APPLIED', 'CLOSED'];
    private const SEARCH_LIMIT_MAX = 20;
    private const SEARCH_LIMIT_DEFAULT = 5;
    private const QUERY_MAX = 200;
    private const INTEGRATE_LIMIT = 5;
    private const SNIPPET_MAX = 400; // artifact payload snippet bound (bytes)
    private const TITLE_MAX = 200;
    private const DECISION_MAX = 300;
    private const VALID_ARTIFACT_TYPES = ['adr', 'decision', 'contract', 'stage_result', 'file'];

    // Authority tiers: current-authoritative > historical > unknown (fail-closed).
    private const AUTHORITY_ADR_CURRENT = 'adr_current';
    private const AUTHORITY_DECISION_CURRENT = 'decision_current';
    private const AUTHORITY_ARTIFACT = 'artifact';
    private const AUTHORITY_UNKNOWN = 'unknown';

    private const STATUS_CURRENT = 'current';
    private const STATUS_HISTORICAL = 'historical';
    private const STATUS_UNKNOWN = 'unknown';

    // Rank lower = more authoritative. Invariant: unknown/historical never outrank a current hit.
    private const STATUS_RANK = [
        self::STATUS_CURRENT => 0,
        self::STATUS_HISTORICAL => 1,
        self::STATUS_UNKNOWN => 2,
    ];

    // Shared token budget for retrieval (single source of truth: token_estimate / apply_budget).
    private const BUDGET_DEFAULT = 8000;
    private const BUDGET_MIN = 500;
    private const BUDGET_MAX = 20000;

    public function __construct(private ModuleDB $db) {}

    /**
     * Search approved memory (ADRs + approved decisions + artifact bundles).
     *
     * @return HarppServiceResult {'results'=>[], 'limit'=>N, 'truncated'=>bool}
     */
    public function search(array $actor, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->actorAllowed($actor)) return HarppServiceResult::failure('Forbidden.', 403);

        $q = trim((string)($input['q'] ?? ''));
        if ($q === '') return HarppServiceResult::failure('A search query is required.', 422);
        if (mb_strlen($q) > self::QUERY_MAX) return HarppServiceResult::failure('Search query is too long.', 422);

        $limit = max(1, min(self::SEARCH_LIMIT_MAX, (int)($input['limit'] ?? self::SEARCH_LIMIT_DEFAULT)));
        $decisionId = (int)($input['decision_id'] ?? 0);
        $artifactType = trim((string)($input['artifact_type'] ?? ''));
        if ($artifactType !== '' && !in_array($artifactType, self::VALID_ARTIFACT_TYPES, true)) {
            return HarppServiceResult::failure('Invalid artifact_type.', 422);
        }
        $includeHistorical = (bool)($input['include_historical'] ?? false);
        $budgetLimit = self::BUDGET_DEFAULT;
        if (isset($input['budget_limit'])) {
            $budgetLimit = max(self::BUDGET_MIN, min(self::BUDGET_MAX, (int)$input['budget_limit']));
        }

        $term = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';
        $results = [];
        $truncated = false;

        // 1) ADR + decision keyword search over APPROVED decisions only.
        $params = [
            ':kw1' => $term, ':kw2' => $term, ':kw3' => $term, ':kw4' => $term, ':kw5' => $term,
        ];
        $where = ["d.lifecycle_state IN ('DECIDED','ACKNOWLEDGED','APPLIED','CLOSED')",
                  '(a.title LIKE :kw1 OR a.decision LIKE :kw2 OR d.title LIKE :kw3 OR d.body LIKE :kw4 OR d.decision LIKE :kw5)'];
        if ($decisionId > 0) { $where[] = 'd.id=:decision'; $params[':decision'] = $decisionId; }
        $sql = "SELECT a.id AS adr_id, d.id AS decision_id, a.title AS title, a.decision AS decision, "
             . 'a.rationale AS rationale, d.lifecycle_state AS lifecycle_state, d.version AS version, '
             . 'a.superseded_by AS adr_superseded_by, '
             . '(SELECT COUNT(*) FROM harpp_adrs x WHERE x.superseded_by = a.id) AS adr_superseded_count '
             . 'FROM harpp_adrs a JOIN harpp_decisions d ON d.id=a.decision_ref '
             . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . $limit;
        $s = $this->db->prepare($sql);
        $s->execute($params);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $isCurrent = !self::adrSuperseded($row); // decision already filtered to approved states
            $results[] = [
                'adr_id' => (int)$row['adr_id'],
                'decision_id' => (int)$row['decision_id'],
                'title' => self::truncate((string)$row['title'], self::TITLE_MAX),
                'decision' => self::truncate((string)$row['decision'], self::DECISION_MAX),
                'rationale' => self::truncate((string)$row['rationale'], self::DECISION_MAX),
                'lifecycle_state' => $row['lifecycle_state'],
                'matched_on' => 'adr',
                'authority' => $isCurrent ? self::AUTHORITY_ADR_CURRENT : self::AUTHORITY_DECISION_CURRENT,
                'status' => $isCurrent ? self::STATUS_CURRENT : self::STATUS_HISTORICAL,
                'revision' => (string)($row['version'] ?? (int)$row['adr_id']),
            ];
        }

        // 2) Artifact-bundle payload search (decision/run bundles), bounded snippet.
        if (count($results) < $limit) {
            $aLimit = $limit - count($results);
            $aParams = [':q' => $term];
            $aSql = "SELECT a.id, a.artifact_type, a.filename, a.payload, b.aggregate_type, b.aggregate_id, "
                  . 'd2.id AS linked_decision_id, d2.lifecycle_state AS linked_lifecycle, d2.version AS linked_version, '
                  . 'a2.id AS linked_adr_id, a2.superseded_by AS adr_superseded_by, '
                  . '(SELECT COUNT(*) FROM harpp_adrs x WHERE x.superseded_by = a2.id) AS adr_superseded_count '
                  . "FROM harpp_artifacts a JOIN harpp_artifact_bundles b ON b.id=a.bundle_id "
                  . "LEFT JOIN harpp_decisions d2 ON (b.aggregate_type='decision' AND d2.id=b.aggregate_id) "
                  . 'LEFT JOIN harpp_adrs a2 ON a2.decision_ref = d2.id '
                  . "WHERE a.payload LIKE :q AND (b.aggregate_type='decision' OR b.aggregate_type='run')";
            if ($artifactType !== '') { $aSql .= ' AND a.artifact_type=:atype'; $aParams[':atype'] = $artifactType; }
            $aSql .= ' ORDER BY a.id DESC LIMIT ' . $aLimit;
            $s = $this->db->prepare($aSql);
            $s->execute($aParams);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = (string)($row['payload'] ?? '');
                if (strlen($payload) > self::SNIPPET_MAX) $truncated = true;
                [$authority, $status] = $this->artifactAuthority($row);
                $results[] = [
                    'artifact_id' => (int)$row['id'],
                    'artifact_type' => $row['artifact_type'],
                    'filename' => $row['filename'],
                    'snippet' => self::truncate($payload, self::SNIPPET_MAX),
                    'aggregate_type' => $row['aggregate_type'],
                    'aggregate_id' => (int)$row['aggregate_id'],
                    'matched_on' => 'artifact',
                    'authority' => $authority,
                    'status' => $status,
                    'revision' => (string)(int)$row['id'],
                ];
            }
        }

        // Fail-closed authority gating.
        $hasCurrent = false;
        foreach ($results as $r) {
            if (($r['status'] ?? '') === self::STATUS_CURRENT) { $hasCurrent = true; break; }
        }

        // Stale-is-worse-than-none: no current-authoritative hit -> empty + low confidence.
        if (!$hasCurrent) {
            return HarppServiceResult::success([
                'results' => [],
                'limit' => $limit,
                'truncated' => false,
                'confidence' => 'low',
                'budget' => ['limit' => $budgetLimit, 'consumed' => 0],
            ]);
        }

        // Default: only current. include_historical keeps historical/unknown but ranked last.
        $filtered = [];
        foreach ($results as $r) {
            $status = (string)($r['status'] ?? self::STATUS_UNKNOWN);
            if (!$includeHistorical && $status !== self::STATUS_CURRENT) {
                continue;
            }
            $filtered[] = $r;
        }
        // Stable sort: current first, then historical, then unknown. PHP 8 usort is stable.
        usort($filtered, static function (array $a, array $b): int {
            $ra = self::STATUS_RANK[(string)($a['status'] ?? self::STATUS_UNKNOWN)] ?? 2;
            $rb = self::STATUS_RANK[(string)($b['status'] ?? self::STATUS_UNKNOWN)] ?? 2;
            return $ra <=> $rb;
        });

        // Shared token budget.
        $budgeted = self::apply_budget($filtered, $budgetLimit);

        return HarppServiceResult::success([
            'results' => $budgeted['results'],
            'limit' => $limit,
            'truncated' => $truncated,
            'confidence' => 'high',
            'budget' => ['limit' => $budgetLimit, 'consumed' => $budgeted['consumed']],
        ]);
    }

    /**
     * Bounded "approved memory" block for a conversation (feeds the context summary).
     * Up to 5 short snippets (title + decision) for approved decisions linked to the
     * conversation, with ADR decision text preferred when present. Null when none.
     */
    public function integrate(int $conversationId): ?array
    {
        $states = implode(',', array_fill(0, count(self::APPROVED_STATES), '?'));
        $s = $this->db->prepare(
            "SELECT d.id AS decision_id, d.decision_key, d.title, d.decision, d.lifecycle_state, d.version, "
            . 'a.id AS adr_id, a.decision AS adr_decision, a.superseded_by AS adr_superseded_by, '
            . '(SELECT COUNT(*) FROM harpp_adrs x WHERE x.superseded_by = a.id) AS adr_superseded_count '
            . 'FROM harpp_decisions d LEFT JOIN harpp_adrs a ON a.decision_ref=d.id '
            . 'WHERE d.conversation_id=? AND d.lifecycle_state IN (' . $states . ') '
            . 'ORDER BY COALESCE(d.decided_at,d.applied_at,d.closed_at,d.created_at) DESC, d.id DESC LIMIT ' . self::INTEGRATE_LIMIT
        );
        $s->execute(array_merge([$conversationId], self::APPROVED_STATES));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // integrate() feeds the context summary: current + authoritative ONLY.
            if (self::adrSuperseded($row)) {
                continue; // historical — never feed stale memory to the context summary.
            }
            $adrDecision = (string)($row['adr_decision'] ?? '');
            $snippet = $adrDecision !== '' ? $adrDecision : (string)($row['decision'] ?? '');
            $adrId = (int)($row['adr_id'] ?? 0);
            $out[] = [
                'decision_id' => (int)$row['decision_id'],
                'decision_key' => (string)$row['decision_key'],
                'title' => self::truncate((string)$row['title'], self::TITLE_MAX),
                'decision' => self::truncate($snippet, self::DECISION_MAX),
                'lifecycle_state' => $row['lifecycle_state'],
                'kind' => $adrDecision !== '' ? 'adr' : 'decision',
                'authority' => $adrId > 0 ? self::AUTHORITY_ADR_CURRENT : self::AUTHORITY_DECISION_CURRENT,
                'status' => self::STATUS_CURRENT,
                'revision' => (string)($row['version'] ?? ($adrId > 0 ? $adrId : (int)$row['decision_id'])),
            ];
        }
        return $out === [] ? null : $out;
    }

    /**
     * Deterministic token estimate for budget accounting (shared with the MCP layer).
     * ceil(mb_strlen(value) / 4) — stable, order-independent.
     */
    public static function token_estimate(string $value): int
    {
        return (int)ceil(mb_strlen($value) / 4);
    }

    /**
     * Trim a result set so total estimated tokens <= limit. Adds results (snippets) one
     * at a time until the budget is consumed; returns kept results + consumed tokens.
     *
     * @param array<int,array<string,mixed>> $results
     * @return array{results:array<int,array<string,mixed>>,consumed:int}
     */
    public static function apply_budget(array $results, int $limitTokens): array
    {
        $kept = [];
        $consumed = 0;
        foreach ($results as $result) {
            $cost = self::resultTokenEstimate($result);
            if ($kept !== [] && $consumed + $cost > $limitTokens) {
                break;
            }
            $kept[] = $result;
            $consumed += $cost;
        }
        return ['results' => $kept, 'consumed' => $consumed];
    }

    /** @param array<string,mixed> $result */
    private static function resultTokenEstimate(array $result): int
    {
        $total = 0;
        foreach (['title', 'decision', 'rationale', 'snippet'] as $key) {
            if (isset($result[$key]) && is_string($result[$key])) {
                $total += self::token_estimate($result[$key]);
            }
        }
        return $total;
    }

    /**
     * Authority/status for an artifact hit based on its linked decision/ADR.
     * No linkable decision/ADR (e.g. run bundle) -> unknown (never promoted to current).
     *
     * @param array<string,mixed> $row
     * @return array{0:string,1:string} [authority, status]
     */
    private function artifactAuthority(array $row): array
    {
        $linkedDecision = (int)($row['linked_decision_id'] ?? 0);
        if ($linkedDecision <= 0) {
            return [self::AUTHORITY_UNKNOWN, self::STATUS_UNKNOWN];
        }
        $approved = in_array((string)($row['linked_lifecycle'] ?? ''), self::APPROVED_STATES, true);
        $superseded = self::adrSuperseded($row);
        if ($approved && !$superseded) {
            return [self::AUTHORITY_ARTIFACT, self::STATUS_CURRENT];
        }
        // Superseded/cancelled decision or a superseded ADR -> historical.
        return [self::AUTHORITY_ARTIFACT, self::STATUS_HISTORICAL];
    }

    /**
     * Supersession-aware current rule: an ADR is historical if it has its own
     * superseded_by set OR a newer ADR references it as superseded.
     *
     * @param array<string,mixed> $row
     */
    private static function adrSuperseded(array $row): bool
    {
        $by = $row['adr_superseded_by'] ?? null;
        $bySet = $by !== null && $by !== '' && (int)$by > 0;
        $count = (int)($row['adr_superseded_count'] ?? 0);
        return $bySet || $count > 0;
    }

    private function actorAllowed(array $actor): bool
    {
        $source = (string)($actor['source'] ?? '');
        if (!in_array($source, ['harpp', 'harpp_bridge'], true)) return false;
        return in_array((string)($actor['role'] ?? ''), ['owner', 'admin', 'member'], true);
    }

    private static function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) return $value;
        return mb_substr($value, 0, max(0, $max - 1)) . '…';
    }
}
