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
             . 'a.rationale AS rationale, d.lifecycle_state AS lifecycle_state '
             . 'FROM harpp_adrs a JOIN harpp_decisions d ON d.id=a.decision_ref '
             . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY d.id DESC LIMIT ' . $limit;
        $s = $this->db->prepare($sql);
        $s->execute($params);
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $results[] = [
                'adr_id' => (int)$row['adr_id'],
                'decision_id' => (int)$row['decision_id'],
                'title' => self::truncate((string)$row['title'], self::TITLE_MAX),
                'decision' => self::truncate((string)$row['decision'], self::DECISION_MAX),
                'rationale' => self::truncate((string)$row['rationale'], self::DECISION_MAX),
                'lifecycle_state' => $row['lifecycle_state'],
                'matched_on' => 'adr',
            ];
        }

        // 2) Artifact-bundle payload search (decision/run bundles), bounded snippet.
        if (count($results) < $limit) {
            $aLimit = $limit - count($results);
            $aParams = [':q' => $term];
            $aSql = "SELECT a.id, a.artifact_type, a.filename, a.payload, b.aggregate_type, b.aggregate_id "
                  . "FROM harpp_artifacts a JOIN harpp_artifact_bundles b ON b.id=a.bundle_id "
                  . "WHERE a.payload LIKE :q AND (b.aggregate_type='decision' OR b.aggregate_type='run')";
            if ($artifactType !== '') { $aSql .= ' AND a.artifact_type=:atype'; $aParams[':atype'] = $artifactType; }
            $aSql .= ' ORDER BY a.id DESC LIMIT ' . $aLimit;
            $s = $this->db->prepare($aSql);
            $s->execute($aParams);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $payload = (string)($row['payload'] ?? '');
                if (strlen($payload) > self::SNIPPET_MAX) $truncated = true;
                $results[] = [
                    'artifact_id' => (int)$row['id'],
                    'artifact_type' => $row['artifact_type'],
                    'filename' => $row['filename'],
                    'snippet' => self::truncate($payload, self::SNIPPET_MAX),
                    'aggregate_type' => $row['aggregate_type'],
                    'aggregate_id' => (int)$row['aggregate_id'],
                    'matched_on' => 'artifact',
                ];
            }
        }

        return HarppServiceResult::success(['results' => $results, 'limit' => $limit, 'truncated' => $truncated]);
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
            "SELECT d.id AS decision_id, d.decision_key, d.title, d.decision, d.lifecycle_state, "
            . 'a.decision AS adr_decision '
            . 'FROM harpp_decisions d LEFT JOIN harpp_adrs a ON a.decision_ref=d.id '
            . 'WHERE d.conversation_id=? AND d.lifecycle_state IN (' . $states . ') '
            . 'ORDER BY COALESCE(d.decided_at,d.applied_at,d.closed_at,d.created_at) DESC, d.id DESC LIMIT ' . self::INTEGRATE_LIMIT
        );
        $s->execute(array_merge([$conversationId], self::APPROVED_STATES));
        $out = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $adrDecision = (string)($row['adr_decision'] ?? '');
            $snippet = $adrDecision !== '' ? $adrDecision : (string)($row['decision'] ?? '');
            $out[] = [
                'decision_id' => (int)$row['decision_id'],
                'decision_key' => (string)$row['decision_key'],
                'title' => self::truncate((string)$row['title'], self::TITLE_MAX),
                'decision' => self::truncate($snippet, self::DECISION_MAX),
                'lifecycle_state' => $row['lifecycle_state'],
                'kind' => $adrDecision !== '' ? 'adr' : 'decision',
            ];
        }
        return $out === [] ? null : $out;
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
