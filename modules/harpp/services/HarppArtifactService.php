<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

/**
 * HARPP reviewable artifact bundles (chair-approved contract).
 *
 * Auto-derives a review bundle at approval time from canonical records so the owner
 * can read approved-task detail (ADR + decision + contract + stage results) instead
 * of only a summary, and share it for review via an addressed, expiring, revocable,
 * view-only token. Also supports owner-attached downloadable `file` artifacts.
 *
 * Scope: HARPP-owned tables only, tenant-scoped (HARPP is a per-tenant DB, so the
 * module DB already isolates tenants); owner/admin manage; an addressed reviewer
 * resolves a share view-only (idempotent, no side effects). Never stores bridge
 * keys/secrets; per-artifact payloads are bounded.
 */
final class HarppArtifactService
{
    private const ARTIFACT_MAX_BYTES = 65536; // 64 KiB per artifact payload
    private const SHARE_DEFAULT_TTL_HOURS = 720; // 30 days
    private const TERMINAL_DECISION = ['DECIDED', 'ACKNOWLEDGED', 'APPLIED', 'CLOSED'];
    private const RUN_SUCCEEDED = 'SUCCEEDED';

    public function __construct(private ModuleDB $db) {}

    /** Get an existing bundle id for an aggregate, or null. */
    private function bundleIdFor(string $type, int $id): ?int
    {
        $s = $this->db->prepare('SELECT id FROM harpp_artifact_bundles WHERE aggregate_type=:t AND aggregate_id=:id');
        $s->execute([':t' => $type, ':id' => $id]);
        $row = $s->fetchColumn();
        return $row === false ? null : (int)$row;
    }

    /** Ensure a bundle exists for a decision; build from canonical ADR + decision text. */
    public function buildForDecision(int $decisionId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $existing = $this->bundleIdFor('decision', $decisionId);
        if ($existing !== null) return HarppServiceResult::success(['bundle_id' => $existing, 'built' => false]);
        $s = $this->db->prepare("SELECT workspace_id,title,body,decision,lifecycle_state FROM harpp_decisions WHERE id=:id AND lifecycle_state IN ('DECIDED','ACKNOWLEDGED','APPLIED','CLOSED')");
        $s->execute([':id' => $decisionId]);
        $decision = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($decision)) return HarppServiceResult::failure('A terminal decision is required.', 409, 'not_terminal');
        try {
            $this->db->beginTransaction();
            $bundleId = $this->insertBundle('decision', $decisionId, (int)($decision['workspace_id'] ?? 0), $actor);
            $a = $this->db->prepare("SELECT title,context,body,decision,rationale FROM harpp_adrs WHERE decision_ref=:id ORDER BY id DESC LIMIT 1");
            $a->execute([':id' => $decisionId]);
            $adr = $a->fetch(PDO::FETCH_ASSOC);
            if (is_array($adr)) {
                $this->insertArtifact($bundleId, 'adr', (string)($adr['decision_ref'] ?? ''), null, 'text/markdown', $this->formatAdr($adr), $actor);
            }
            $this->insertArtifact($bundleId, 'decision', '', null, 'text/plain', $this->formatDecision($decision), $actor);
            $this->markReady($bundleId);
            $this->db->commit();
            return HarppServiceResult::success(['bundle_id' => $bundleId, 'built' => true]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to build artifact bundle.', 500);
        }
    }

    /** Ensure a bundle exists for a SUCCEEDED run; build from result + optional contract. */
    public function buildForRun(int $runId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $existing = $this->bundleIdFor('run', $runId);
        if ($existing !== null) return HarppServiceResult::success(['bundle_id' => $existing, 'built' => false]);
        $s = $this->db->prepare("SELECT wr.conversation_id,c.workspace_id,wr.result_json,wr.last_status FROM harpp_work_runs wr LEFT JOIN harpp_conversations c ON c.id=wr.conversation_id WHERE wr.id=:id AND wr.state='SUCCEEDED'");
        $s->execute([':id' => $runId]);
        $run = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($run)) return HarppServiceResult::failure('A succeeded run is required.', 409, 'not_terminal');
        try {
            $this->db->beginTransaction();
            $bundleId = $this->insertBundle('run', $runId, (int)($run['workspace_id'] ?? 0), $actor);
            $result = json_decode((string)($run['result_json'] ?? ''), true);
            $result = is_array($result) ? $result : ['raw' => (string)($run['result_json'] ?? '')];
            $this->insertArtifact($bundleId, 'stage_result', '', null, 'application/json', $this->json($result), $actor);
            if (isset($result['contract']) && is_string($result['contract']) && $result['contract'] !== '') {
                $this->insertArtifact($bundleId, 'contract', '', null, 'text/plain', $result['contract'], $actor);
            }
            $this->markReady($bundleId);
            $this->db->commit();
            return HarppServiceResult::success(['bundle_id' => $bundleId, 'built' => true]);
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to build artifact bundle.', 500);
        }
    }

    /** Owner/admin full view of a bundle + its artifacts. */
    public function view(int $bundleId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $bundle = $this->loadBundle($bundleId);
        if ($bundle === null) return HarppServiceResult::failure('Bundle not found.', 404);
        return HarppServiceResult::success(['bundle' => $bundle, 'artifacts' => $this->artifacts($bundleId)]);
    }

    /** Owner attaches a downloadable file artifact to a bundle. */
    public function attachFile(int $bundleId, array $actor, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if ($this->loadBundle($bundleId) === null) return HarppServiceResult::failure('Bundle not found.', 404);
        $filename = trim((string)($input['filename'] ?? ''));
        $mime = trim((string)($input['mime'] ?? 'text/plain'));
        $content = (string)($input['content'] ?? '');
        if ($filename === '' || $filename === '.' || str_contains($filename, '/') || str_contains($filename, '\\') || $content === '') {
            return HarppServiceResult::failure('A simple filename and non-empty content are required.', 422);
        }
        if (strlen($content) > self::ARTIFACT_MAX_BYTES) return HarppServiceResult::failure('Artifact payload exceeds the 64 KiB bound.', 422, 'payload_too_large');
        $id = $this->insertArtifact($bundleId, 'file', '', $filename, substr($mime, 0, 120), $content, $actor);
        return HarppServiceResult::success(['artifact_id' => $id, 'filename' => $filename, 'mime' => $mime, 'file_size' => strlen($content)]);
    }

    /** Owner/admin download of a file artifact. Returns {filename,mime,content}. */
    public function downloadFile(int $artifactId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $a = $this->db->prepare('SELECT bundle_id,artifact_type,filename,mime,payload FROM harpp_artifacts WHERE id=:id');
        $a->execute([':id' => $artifactId]);
        $row = $a->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['artifact_type'] !== 'file') return HarppServiceResult::failure('File artifact not found.', 404);
        return HarppServiceResult::success(['filename' => $row['filename'], 'mime' => $row['mime'], 'content' => $row['payload']]);
    }

    /** Owner creates an addressed, expiring share. Returns the plaintext token once. */
    public function createShare(int $bundleId, array $actor, array $input, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        if ($this->loadBundle($bundleId) === null) return HarppServiceResult::failure('Bundle not found.', 404);
        $reviewerId = (int)($input['reviewer_user_id'] ?? 0);
        if ($reviewerId <= 0) return HarppServiceResult::failure('reviewer_user_id is required.', 422);
        $ttlHours = max(1, min(8760, (int)($input['ttl_hours'] ?? self::SHARE_DEFAULT_TTL_HOURS)));
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expires = date('Y-m-d H:i:s', time() + $ttlHours * 3600);
        $s = $this->db->prepare('INSERT INTO harpp_artifact_shares (bundle_id,reviewer_user_id,token_hash,expires_at,created_by,created_at) VALUES (:b,:r,:h,:e,:u,NOW(6))');
        $s->execute([':b' => $bundleId, ':r' => $reviewerId, ':h' => $hash, ':e' => $expires, ':u' => (int)($actor['id'] ?? 0)]);
        return HarppServiceResult::success(['share_id' => (int)$this->db->lastInsertId(), 'token' => $token, 'expires_at' => $expires, 'reviewer_user_id' => $reviewerId]);
    }

    /** Addressed reviewer opens a share view-only (idempotent, no side effects). */
    public function resolveShare(string $token, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (trim($token) === '' || ($actor['role'] ?? '') === '') return HarppServiceResult::failure('Invalid share.', 404);
        $s = $this->db->prepare('SELECT id,bundle_id,reviewer_user_id,expires_at,revoked_at FROM harpp_artifact_shares WHERE token_hash=:h');
        $s->execute([':h' => hash('sha256', trim($token))]);
        $share = $s->fetch(PDO::FETCH_ASSOC);
        if (!is_array($share)) return HarppServiceResult::failure('Invalid share.', 404);
        if ($share['revoked_at'] !== null) return HarppServiceResult::failure('Share revoked.', 403, 'share_revoked');
        if ($share['expires_at'] !== null && strtotime((string)$share['expires_at']) < time()) return HarppServiceResult::failure('Share expired.', 403, 'share_expired');
        if ((int)$share['reviewer_user_id'] !== (int)($actor['id'] ?? 0)) return HarppServiceResult::failure('Share not addressed to this user.', 403, 'share_not_addressed');
        $bundle = $this->loadBundle((int)$share['bundle_id']);
        if ($bundle === null) return HarppServiceResult::failure('Bundle not found.', 404);
        $artifacts = [];
        foreach ($this->artifacts((int)$share['bundle_id']) as $artifact) {
            unset($artifact['payload']); // view-only digest: list metadata, hide full payload unless file download
            $artifact['has_payload'] = true;
            $artifacts[] = $artifact;
        }
        return HarppServiceResult::success(['bundle' => $bundle, 'artifacts' => $artifacts, 'share_id' => (int)$share['id']]);
    }

    /** Addressed reviewer downloads a file artifact from a share (view-only). */
    public function shareDownloadFile(string $token, int $artifactId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        $resolved = $this->resolveShare($token, $actor, $tenantId);
        if (!$resolved['ok']) return $resolved;
        $a = $this->db->prepare('SELECT artifact_type,filename,mime,payload,bundle_id FROM harpp_artifacts WHERE id=:id');
        $a->execute([':id' => $artifactId]);
        $row = $a->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['artifact_type'] !== 'file') return HarppServiceResult::failure('File artifact not found.', 404);
        if ((int)$row['bundle_id'] !== (int)$resolved['data']['bundle']['id']) {
            return HarppServiceResult::failure('File not in this share.', 404);
        }
        return HarppServiceResult::success(['filename' => $row['filename'], 'mime' => $row['mime'], 'content' => $row['payload']]);
    }

    /** Owner revokes a share. */
    public function revokeShare(int $shareId, array $actor, ?int $tenantId = null): HarppServiceResult
    {
        if (!$this->manage($actor)) return HarppServiceResult::failure('Forbidden.', 403);
        $u = $this->db->prepare('UPDATE harpp_artifact_shares SET revoked_at=COALESCE(revoked_at,NOW(6)) WHERE id=:id');
        $u->execute([':id' => $shareId]);
        if ($u->rowCount() !== 1) return HarppServiceResult::failure('Share not found.', 404);
        return HarppServiceResult::success(['share_id' => $shareId, 'revoked' => true]);
    }

    // ── internals ──────────────────────────────────────────────────────────
    private function manage(array $actor): bool
    {
        return in_array((string)($actor['role'] ?? ''), ['owner', 'admin'], true);
    }

    private function insertBundle(string $type, int $id, int $workspaceId, array $actor): int
    {
        $s = $this->db->prepare('INSERT INTO harpp_artifact_bundles (aggregate_type,aggregate_id,workspace_id,status,created_by,created_at) VALUES (:t,:id,:w,\'pending\',:u,NOW(6))');
        $s->execute([':t' => $type, ':id' => $id, ':w' => $workspaceId > 0 ? $workspaceId : null, ':u' => (int)($actor['id'] ?? 0)]);
        return (int)$this->db->lastInsertId();
    }

    private function insertArtifact(int $bundleId, string $type, string $sourceRef, ?string $filename, string $mime, string $payload, array $actor): int
    {
        $s = $this->db->prepare('INSERT INTO harpp_artifacts (bundle_id,artifact_type,source_ref,filename,mime,payload,file_size,created_by,created_at) VALUES (:b,:t,:s,:f,:m,:p,:sz,:u,NOW(6))');
        $s->execute([':b' => $bundleId, ':t' => $type, ':s' => $sourceRef !== '' ? $sourceRef : null, ':f' => $filename, ':m' => $mime, ':p' => $payload, ':sz' => strlen($payload), ':u' => (int)($actor['id'] ?? 0)]);
        return (int)$this->db->lastInsertId();
    }

    private function markReady(int $bundleId): void
    {
        $this->db->prepare("UPDATE harpp_artifact_bundles SET status='ready' WHERE id=:id")->execute([':id' => $bundleId]);
    }

    private function loadBundle(int $bundleId): ?array
    {
        $s = $this->db->prepare('SELECT id,aggregate_type,aggregate_id,workspace_id,status,created_by,created_at FROM harpp_artifact_bundles WHERE id=:id');
        $s->execute([':id' => $bundleId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function artifacts(int $bundleId): array
    {
        $s = $this->db->prepare('SELECT id,artifact_type,source_ref,filename,mime,file_size,created_at,payload FROM harpp_artifacts WHERE bundle_id=:b ORDER BY id');
        $s->execute([':b' => $bundleId]);
        return $s->fetchAll(PDO::FETCH_ASSOC);
    }

    private function formatAdr(array $adr): string
    {
        return "ADR: {$adr['title']}\nContext: {$adr['context']}\nBody: {$adr['body']}\nDecision: {$adr['decision']}\nRationale: {$adr['rationale']}\n";
    }

    private function formatDecision(array $d): string
    {
        return "Title: {$d['title']}\nBody: {$d['body']}\nDecision: {$d['decision']}\n";
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
