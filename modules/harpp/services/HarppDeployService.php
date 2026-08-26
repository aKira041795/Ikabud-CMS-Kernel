<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

/**
 * HARPP deploy capability (R-FTP MVP).
 *
 * The phone GUI queues a deploy job (package + named profile, both from the
 * inventory the local client registered). The operator's local client claims
 * the job over the bridge, resolves the real profile/artifact locally, runs the
 * FTP/SFTP upload, and reports progress + a receipt. This service never sees or
 * stores FTP credentials — the profile inventory carries only non-secret
 * metadata (name/host/transport/root/ops).
 *
 * Deploy state machine (append-only, CAS on version, claim-token ownership,
 * sliding claim lease with stale reclaim):
 *   QUEUED -> CLAIMED -> UPLOADING -> VERIFYING -> EXTRACTING -> SUCCEEDED
 *   any in-progress -> FAILED ; QUEUED|CLAIMED -> CANCELLED
 * A CLAIMED job whose lease (claim_expires_at) lapses is treated as stale and
 * may be reclaimed by a later worker pass; progress reports slide the lease.
 */
final class HarppDeployService
{
    private const PROFILE_FIELDS = ['host', 'transport', 'port', 'root_path', 'extraction_adapter', 'allowed_operations', 'health_url'];

    public function __construct(private ModuleDB $db) {}

    // ── User-facing (app) ────────────────────────────────────────────────────

    public function inventory(array $actor): HarppServiceResult
    {
        try {
            $rows = $this->db->query(
                "SELECT scope_key,item_key,payload_json,last_seen_at FROM harpp_deploy_inventory ORDER BY scope_key,item_key"
            )->fetchAll(PDO::FETCH_ASSOC);
            $packages = []; $profiles = []; $latest = null;
            foreach ($rows as $row) {
                $item = ['key' => (string)$row['item_key'], 'last_seen_at' => (string)$row['last_seen_at']];
                $payload = json_decode((string)$row['payload_json'], true);
                if (is_array($payload)) { $item = array_merge($item, $payload); unset($item['key']); $item['name'] = (string)$row['item_key']; }
                if ($row['scope_key'] === 'packages') { $packages[] = $item; } else { $profiles[] = $item; }
                $latest = (string)$row['last_seen_at'];
            }
            return HarppServiceResult::success(['packages' => $packages, 'profiles' => $profiles, 'published_at' => $latest]);
        } catch (Throwable $e) {
            return HarppServiceResult::failure('Unable to read deploy inventory.', 500, 'inventory_unavailable');
        }
    }

    public function list(array $actor, array $filters): HarppServiceResult
    {
        $limit = max(1, min(100, (int)($filters['limit'] ?? 25)));
        $after = max(0, (int)($filters['after'] ?? 0));
        $stmt = $this->db->prepare(
            "SELECT id,requested_by,package_name,profile_name,status,claimed_at,heartbeat_at,claim_expires_at,error,created_at,updated_at " .
            "FROM harpp_deploy_jobs WHERE id>:after ORDER BY id DESC LIMIT " . $limit
        );
        $stmt->execute([':after' => $after]);
        $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($jobs as &$job) {
            $job['id'] = (int)$job['id'];
            $job['requested_by'] = (int)$job['requested_by'];
            $job['can_cancel'] = in_array($job['status'], ['QUEUED', 'CLAIMED'], true);
        }
        return HarppServiceResult::success(['jobs' => $jobs, 'next_after' => $jobs ? min(array_column($jobs, 'id')) : $after]);
    }

    public function get(array $actor, int $id): HarppServiceResult
    {
        $stmt = $this->db->prepare("SELECT * FROM harpp_deploy_jobs WHERE id=:id");
        $stmt->execute([':id' => $id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($job)) {
            return HarppServiceResult::failure('Deploy job not found.', 404, 'deploy_not_found');
        }
        $job['id'] = (int)$job['id'];
        $job['requested_by'] = (int)$job['requested_by'];
        $job['version'] = (int)$job['version'];
        $job['can_cancel'] = in_array($job['status'], ['QUEUED', 'CLAIMED'], true);
        $job['receipt'] = null;
        if ($job['receipt_json'] !== null) {
            $decoded = json_decode((string)$job['receipt_json'], true);
            $job['receipt'] = is_array($decoded) ? $decoded : null;
        }
        unset($job['receipt_json'], $job['claim_token']);
        return HarppServiceResult::success(['job' => $job]);
    }

    public function request(array $actor, array $input): HarppServiceResult
    {
        $package = trim((string)($input['package'] ?? ''));
        $profile = trim((string)($input['profile'] ?? ''));
        if ($package === '' || $profile === '' || !preg_match('/^[\w.,;()\[\] _-]+$/u', $package) || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $profile)) {
            return HarppServiceResult::failure('A valid package and profile are required.', 422, 'deploy_invalid');
        }
        try {
            $found = $this->db->prepare("SELECT 1 FROM harpp_deploy_inventory WHERE scope_key=:scope AND item_key=:key");
            foreach (['packages' => $package, 'profiles' => $profile] as $scope => $key) {
                $found->execute([':scope' => $scope, ':key' => $key]);
                if ($found->fetchColumn() === false) {
                    return HarppServiceResult::failure('Unknown package or profile (not registered by the local client).', 422, 'deploy_unregistered');
                }
            }
        } catch (Throwable $e) {
            return HarppServiceResult::failure('Unable to validate deploy request.', 500, 'deploy_validation_failed');
        }

        $foundation = new HarppFoundationService($this->db);
        $idemKey = trim((string)($input['idempotency_key'] ?? ''));
        $idem = null;
        if ($idemKey !== '') {
            $idem = $foundation->claimIdempotency('harpp_deploy', $actor, 'deploy.request', $idemKey, $input);
            if ($idem['state'] === 'conflict') return HarppServiceResult::failure('Idempotency key reused with a different request.', 409, 'idempotency_conflict');
            if ($idem['state'] === 'replay') return HarppServiceResult::success($idem['response'], 'Deploy request replayed.');
            if ($idem['state'] === 'in_progress') return HarppServiceResult::failure('Deploy request already in progress.', 409, 'idempotency_in_progress');
        }

        $requestHash = hash('sha256', $package . '|' . $profile);
        $dedupKey = $package . ':' . $profile;
        $owns = !$this->db->inTransaction();
        try {
            if ($owns) $this->db->beginTransaction();
            // Fast path: return the existing in-progress job for this package+profile.
            $dup = $this->db->prepare("SELECT id FROM harpp_deploy_jobs WHERE active_dedup_key=:dk ORDER BY id DESC LIMIT 1");
            $dup->execute([':dk' => $dedupKey]);
            $existing = (int)$dup->fetchColumn();
            if ($existing > 0) {
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::success(['deploy_id' => $existing, 'status' => 'queued', 'replayed' => true], 'Deploy already queued.');
            }
            $stmt = $this->db->prepare(
                "INSERT INTO harpp_deploy_jobs (requested_by,package_name,profile_name,status,request_hash,active_dedup_key,version,created_at,updated_at) " .
                "VALUES (:actor,:package,:profile,'QUEUED',:hash,:dk,1,NOW(6),NOW(6))"
            );
            try {
                $stmt->execute([':actor' => (int)$actor['id'], ':package' => $package, ':profile' => $profile, ':hash' => $requestHash, ':dk' => $dedupKey]);
            } catch (Throwable $insertError) {
                // Atomic dedup: a concurrent request won the insert race. Re-read
                // the winner and replay it as the queued deploy.
                if ($owns && $this->db->inTransaction()) $this->db->rollBack();
                $dup->execute([':dk' => $dedupKey]);
                $existing = (int)$dup->fetchColumn();
                if ($existing > 0) {
                    return HarppServiceResult::success(['deploy_id' => $existing, 'status' => 'queued', 'replayed' => true], 'Deploy already queued.');
                }
                if (function_exists('write_log')) \write_log('HARPP deploy dedup insert failed', 'error', ['module' => 'harpp', 'error' => $insertError->getMessage()]);
                return HarppServiceResult::failure('Unable to queue deploy.', 500, 'deploy_request_failed');
            }
            $id = (int)$this->db->lastInsertId();
            $event = $foundation->recordEffect(
                'harpp.deploy.requested', 'deploy.requested', $actor, 'harpp_deploy_job', $id, null,
                ['package' => $package, 'profile' => $profile, 'status' => 'QUEUED'], 'Deploy requested from the HARPP app.'
            );
            if ($idem !== null && ($idem['state'] ?? '') === 'claimed') {
                $foundation->completeIdempotency((int)$idem['id'], ['deploy_id' => $id, 'status' => 'QUEUED']);
            }
            if ($owns) $this->db->commit();
            return HarppServiceResult::success(['deploy_id' => $id, 'status' => 'QUEUED'], 'Deploy queued; the local client will execute it shortly.', [$event], 'harpp_deploy_job', $id);
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            if (function_exists('write_log')) \write_log('HARPP deploy request failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
            return HarppServiceResult::failure('Unable to queue deploy.', 500, 'deploy_request_failed');
        }
    }

    public function cancel(array $actor, int $id): HarppServiceResult
    {
        $foundation = new HarppFoundationService($this->db);
        $owns = !$this->db->inTransaction();
        try {
            if ($owns) $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT status,version,requested_by FROM harpp_deploy_jobs WHERE id=:id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy job not found.', 404, 'deploy_not_found'); }
            if ((int)$row['requested_by'] !== (int)$actor['id'] && ($actor['role'] ?? '') !== 'owner') {
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::failure('You can only cancel your own deploy requests.', 403, 'deploy_scope');
            }
            if (!in_array($row['status'], ['QUEUED', 'CLAIMED'], true)) {
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::failure('Only queued or claimed deploys can be cancelled.', 409, 'deploy_not_cancellable');
            }
            $upd = $this->db->prepare(
                "UPDATE harpp_deploy_jobs SET status='CANCELLED',version=version+1,error=NULL,active_dedup_key=NULL,claim_expires_at=NULL WHERE id=:id AND status IN ('QUEUED','CLAIMED') AND version=:version"
            );
            $upd->execute([':id' => $id, ':version' => (int)$row['version']]);
            if ($upd->rowCount() !== 1) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy changed concurrently.', 409, 'version_conflict'); }
            $event = $foundation->recordEffect('harpp.deploy.cancelled', 'deploy.cancelled', $actor, 'harpp_deploy_job', $id, ['status' => $row['status']], ['status' => 'CANCELLED'], 'Deploy cancelled by the requester.');
            if ($owns) $this->db->commit();
            return HarppServiceResult::success(['deploy_id' => $id, 'status' => 'CANCELLED'], 'Deploy cancelled.', [$event], 'harpp_deploy_job', $id);
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to cancel deploy.', 500, 'deploy_cancel_failed');
        }
    }

    // ── Bridge (machine-authenticated local client) ─────────────────────────

    /** Replace the tenant's deploy inventory snapshot (packages + profiles, no secrets). */
    public function registerInventory(array $actor, array $input): HarppServiceResult
    {
        $packages = $input['packages'] ?? [];
        $profiles = $input['profiles'] ?? [];
        if (!is_array($packages) || !is_array($profiles) || count($packages) > 200 || count($profiles) > 100) {
            return HarppServiceResult::failure('Invalid inventory payload.', 422, 'inventory_invalid');
        }
        $owns = !$this->db->inTransaction();
        try {
            if ($owns) $this->db->beginTransaction();
            $this->db->execute("DELETE FROM harpp_deploy_inventory");
            $ins = $this->db->prepare(
                "INSERT INTO harpp_deploy_inventory (scope_key,item_key,payload_json,publisher,last_seen_at) VALUES (:scope,:key,:payload,:publisher,NOW(6))"
            );
            foreach ($packages as $pkg) {
                $name = (string)($pkg['name'] ?? '');
                if ($name === '' || !preg_match('/^[\w.,;()\[\] _-]+$/u', $name)) continue;
                $payload = ['size' => (int)($pkg['size'] ?? 0), 'modified' => (string)($pkg['modified'] ?? '')];
                $ins->execute([':scope' => 'packages', ':key' => $name, ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), ':publisher' => 'local-client']);
            }
            foreach ($profiles as $prof) {
                $name = (string)($prof['name'] ?? '');
                if ($name === '' || !preg_match('/^[A-Za-z0-9_.-]{1,128}$/', $name)) continue;
                $payload = [];
                foreach (self::PROFILE_FIELDS as $field) {
                    if (array_key_exists($field, $prof)) $payload[$field] = $prof[$field];
                }
                $ins->execute([':scope' => 'profiles', ':key' => $name, ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES), ':publisher' => 'local-client']);
            }
            if ($owns) $this->db->commit();
            return HarppServiceResult::success(['packages' => count($packages), 'profiles' => count($profiles)], 'Deploy inventory updated.');
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to update deploy inventory.', 500, 'inventory_failed');
        }
    }

    public function pending(array $actor, array $filters): HarppServiceResult
    {
        $limit = max(1, min(20, (int)($filters['limit'] ?? 10)));
        // QUEUED jobs plus CLAIMED jobs whose claim lease has lapsed (a crashed
        // worker) so a later worker pass can reclaim them.
        $stmt = $this->db->prepare(
            "SELECT id,package_name,profile_name,status,created_at FROM harpp_deploy_jobs " .
            "WHERE status='QUEUED' OR (status='CLAIMED' AND claim_expires_at IS NOT NULL AND claim_expires_at < NOW(6)) " .
            "ORDER BY id ASC LIMIT " . $limit
        );
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) { $row['id'] = (int)$row['id']; }
        return HarppServiceResult::success(['deploys' => $rows]);
    }

    /** CAS claim QUEUED -> CLAIMED (or reclaim a lapsed lease); only the returned claim_token may report. */
    public function claim(array $actor, int $id): HarppServiceResult
    {
        $token = $this->uuid();
        $foundation = new HarppFoundationService($this->db);
        $owns = !$this->db->inTransaction();
        try {
            if ($owns) $this->db->beginTransaction();
            $upd = $this->db->prepare(
                "UPDATE harpp_deploy_jobs SET status='CLAIMED',claim_token=:token,claimed_at=NOW(6),heartbeat_at=NOW(6)," .
                "claim_expires_at=DATE_ADD(NOW(6),INTERVAL 15 MINUTE),version=version+1,error=NULL " .
                "WHERE id=:id AND (status='QUEUED' OR (status='CLAIMED' AND claim_expires_at IS NOT NULL AND claim_expires_at < NOW(6)))"
            );
            $upd->execute([':token' => $token, ':id' => $id]);
            if ($upd->rowCount() !== 1) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy is not queued or its claim lease is not stale.', 409, 'deploy_already_claimed'); }
            $event = $foundation->recordEffect('harpp.deploy.claimed', 'deploy.claimed', $actor, 'harpp_deploy_job', $id, ['status' => 'QUEUED'], ['status' => 'CLAIMED'], 'Deploy claimed by the local executor.');
            if ($owns) $this->db->commit();
            return HarppServiceResult::success(['deploy_id' => $id, 'claim_token' => $token, 'status' => 'CLAIMED'], 'Deploy claimed.', [$event], 'harpp_deploy_job', $id);
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            return HarppServiceResult::failure('Unable to claim deploy.', 500, 'deploy_claim_failed');
        }
    }

    /**
     * Report progress or a final outcome. Only the holder of claim_token may
     * report, and transitions are validated (strict state machine).
     *
     * status: progress (step: uploading|verifying|extracting) | success (receipt) | failure (error)
     */
    public function report(array $actor, int $id, array $input): HarppServiceResult
    {
        $token = trim((string)($input['claim_token'] ?? ''));
        $kind = trim((string)($input['status'] ?? ''));
        if ($token === '') return HarppServiceResult::failure('claim_token is required.', 422, 'claim_token_required');
        $foundation = new HarppFoundationService($this->db);
        $owns = !$this->db->inTransaction();
        try {
            if ($owns) $this->db->beginTransaction();
            $stmt = $this->db->prepare("SELECT id,status,claim_token,version,requested_by,package_name,profile_name FROM harpp_deploy_jobs WHERE id=:id FOR UPDATE");
            $stmt->execute([':id' => $id]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($job)) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy job not found.', 404, 'deploy_not_found'); }
            if (!hash_equals((string)$job['claim_token'], $token)) {
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::failure('Claim token mismatch; only the claiming executor may report.', 403, 'deploy_claim_mismatch');
            }
            $current = (string)$job['status'];
            $inProgress = ['CLAIMED', 'UPLOADING', 'VERIFYING', 'EXTRACTING'];
            $terminal = ['SUCCEEDED', 'FAILED', 'CANCELLED'];
            if (in_array($current, $terminal, true)) {
                // Idempotent terminal reports: a retried success/failure from the
                // same claim holder is a no-op replay (worker crash/network retry),
                // never a new transition.
                $idempotent = ($kind === 'success' && $current === 'SUCCEEDED') || ($kind === 'failure' && $current === 'FAILED');
                if ($idempotent) {
                    if ($owns) $this->db->commit();
                    return HarppServiceResult::success(['deploy_id' => $id, 'status' => $current], 'Deploy already ' . $current . '.');
                }
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::failure('Deploy already ' . $current, 409, 'deploy_transition');
            }
            $next = null; $reason = '';
            if ($kind === 'progress') {
                $step = trim((string)($input['step'] ?? ''));
                $map = ['uploading' => 'UPLOADING', 'extracting' => 'EXTRACTING', 'verifying' => 'VERIFYING'];
                $next = $map[$step] ?? null;
                if ($next === null || $this->order($current) === null || $this->order($next) !== $this->order($current) + 1) {
                    if ($owns) $this->db->rollBack();
                    return HarppServiceResult::failure('Invalid progress step for current state.', 409, 'deploy_transition');
                }
                $reason = 'Deploy step: ' . $step;
            } elseif ($kind === 'success') {
                if (!in_array($current, $inProgress, true)) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy cannot succeed from ' . $current, 409, 'deploy_transition'); }
                $next = 'SUCCEEDED'; $reason = 'Deploy completed successfully.';
            } elseif ($kind === 'failure') {
                if (!in_array($current, $inProgress, true)) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy cannot fail from ' . $current, 409, 'deploy_transition'); }
                $next = 'FAILED'; $reason = trim((string)($input['error'] ?? 'Deploy failed.'));
            } else {
                if ($owns) $this->db->rollBack();
                return HarppServiceResult::failure('status must be progress, success, or failure.', 422);
            }
            $receiptJson = null;
            if (isset($input['receipt']) && is_array($input['receipt'])) {
                $receiptJson = json_encode($input['receipt'], JSON_UNESCAPED_SLASHES);
            }
            $isTerminal = in_array($next, ['SUCCEEDED', 'FAILED'], true);
            // Progress reports slide the claim lease; terminal reports clear the
            // dedup key and the lease so the package+profile slot frees up.
            $upd = $this->db->prepare(
                "UPDATE harpp_deploy_jobs SET status=:status,version=version+1,error=:error,receipt_json=COALESCE(:receipt,receipt_json),heartbeat_at=NOW(6)," .
                "claim_expires_at=:lease,active_dedup_key=:dedup " .
                "WHERE id=:id AND status=:current AND claim_token=:token"
            );
            $upd->execute([
                ':status' => $next,
                ':error' => $kind === 'failure' ? substr($reason, 0, 2000) : null,
                ':receipt' => $receiptJson,
                ':lease' => $isTerminal ? null : date('Y-m-d H:i:s', time() + 900),
                ':dedup' => $isTerminal ? null : (string)$job['package_name'] . ':' . (string)$job['profile_name'],
                ':id' => $id, ':current' => $current, ':token' => $token,
            ]);
            if ($upd->rowCount() !== 1) { if ($owns) $this->db->rollBack(); return HarppServiceResult::failure('Deploy state changed concurrently.', 409, 'version_conflict'); }

            $event = $foundation->recordEffect('harpp.deploy.' . strtolower($next), 'deploy.' . strtolower($next), $actor, 'harpp_deploy_job', $id, ['status' => $current], ['status' => $next], $reason);
            if (in_array($next, ['SUCCEEDED', 'FAILED'], true)) {
                $recipient = (int)$job['requested_by'];
                $notice = (new HarppNotificationService($this->db))->create(
                    $recipient, 'deploy',
                    ['event' => 'deploy.' . strtolower($next), 'deploy_id' => $id, 'package' => $job['package_name'], 'profile' => $job['profile_name'], 'status' => $next, 'error' => $kind === 'failure' ? $reason : null],
                    null, null, null, false
                );
                if (!empty($notice['ok'])) {
                    $event['payload']['notification_deliveries'] = [['id' => (int)($notice['data']['notification_id'] ?? 0), 'user_id' => $recipient]];
                }
            }
            if ($owns) $this->db->commit();
            return HarppServiceResult::success(['deploy_id' => $id, 'status' => $next], $reason, [$event], 'harpp_deploy_job', $id);
        } catch (Throwable $e) {
            if ($owns && $this->db->inTransaction()) $this->db->rollBack();
            if (function_exists('write_log')) \write_log('HARPP deploy report failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
            return HarppServiceResult::failure('Unable to record deploy report.', 500, 'deploy_report_failed');
        }
    }

    private function order(string $state): ?int
    {
        // upload -> verify -> extract: the transport verifies the uploaded
        // artifact's integrity before extracting it.
        return ['CLAIMED' => 1, 'UPLOADING' => 2, 'VERIFYING' => 3, 'EXTRACTING' => 4][$state] ?? null;
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
