<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppBridgeAuthService
{
    private const KEY_SETTING = 'bridge_api_key_hash';
    private const ROTATED_SETTING = 'bridge_api_key_rotated_at';
    private const MAX_FAILURES = 5;
    private const WINDOW_SECONDS = 60;

    public function __construct(private ?ModuleDB $database = null) {}

    private function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) return $this->database;
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) throw new \RuntimeException('HARPP module database is unavailable.');
        return $this->database = $db;
    }

    /** Returns a raw key only when the tenant has no key yet. */
    public function generate(int $tenantId)
    {
        if (!$this->tenantMatches($tenantId)) return HarppServiceResult::failure('Tenant scope mismatch.', 403, 'tenant_scope_mismatch');
        try {
            $hash = $this->setting(self::KEY_SETTING);
            if ($hash !== '') {
                return HarppServiceResult::success(['generated' => false, 'fingerprint' => $this->fingerprint($hash), 'rotated_at' => $this->setting(self::ROTATED_SETTING)]);
            }
            return $this->storeNewKey(false);
        } catch (Throwable $e) {
            $this->logError('key generation failed', $e);
            return HarppServiceResult::failure('Unable to generate bridge key.', 500);
        }
    }

    /** Rotation always invalidates the previous key and reveals the replacement once. */
    public function rotate(int $tenantId)
    {
        if (!$this->tenantMatches($tenantId)) return HarppServiceResult::failure('Tenant scope mismatch.', 403, 'tenant_scope_mismatch');
        try {
            return $this->storeNewKey(true);
        } catch (Throwable $e) {
            $this->logError('key rotation failed', $e);
            return HarppServiceResult::failure('Unable to rotate bridge key.', 500);
        }
    }

    /**
     * Read-only key status. Never generates or mutates state — safe for GET.
     */
    public function status(int $tenantId)
    {
        if (!$this->tenantMatches($tenantId)) return HarppServiceResult::failure('Tenant scope mismatch.', 403, 'tenant_scope_mismatch');
        try {
            $hash = $this->setting(self::KEY_SETTING);
            if ($hash === '') {
                return HarppServiceResult::success(['generated' => false, 'configured' => false]);
            }
            return HarppServiceResult::success(['generated' => false, 'configured' => true, 'fingerprint' => $this->fingerprint($hash), 'rotated_at' => $this->setting(self::ROTATED_SETTING)]);
        } catch (Throwable $e) {
            $this->logError('bridge key status failed', $e);
            return HarppServiceResult::failure('Unable to read bridge key status.', 500);
        }
    }

    public function validate(string $key, int $tenantId, string $clientId = '')
    {
        $current = (int)(\app()->tenant()->current() ?? 0);
        $bucket = $this->rateKey($clientId);
        try {
            if ($this->isRateLimited($bucket)) {
                $this->logFailure($tenantId, $clientId, 'rate_limited');
                return HarppServiceResult::failure('Too many bridge authentication failures.', 429, 'bridge_rate_limited');
            }
            $stored = $this->setting(self::KEY_SETTING);
            $tenantValid = $tenantId > 0 && $tenantId === $current;
            $keyValid = $key !== '' && $stored !== '' && hash_equals($stored, hash('sha256', $key));
            if (!$tenantValid || !$keyValid) {
                $this->recordFailure($bucket);
                $this->logFailure($tenantId, $clientId, !$tenantValid ? 'tenant_mismatch' : ($key === '' ? 'missing_key' : 'key_mismatch'));
                return HarppServiceResult::failure('Bridge authentication failed.', 401, 'bridge_unauthorized');
            }
            $this->clearFailures($bucket);
            $actor = $this->bridgeActor();
            if ($actor === null) {
                $this->logFailure($tenantId, $clientId, 'owner_missing');
                return HarppServiceResult::failure('Bridge authentication failed.', 401, 'bridge_unauthorized');
            }
            return HarppServiceResult::success(['tenant_id' => $current, 'actor' => $actor]);
        } catch (Throwable $e) {
            $this->logError('authentication failed closed', $e);
            return HarppServiceResult::failure('Bridge authentication failed.', 401, 'bridge_unauthorized');
        }
    }

    private function storeNewKey(bool $rotated)
    {
        $raw = 'harpp_br_' . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $hash = hash('sha256', $raw);
        $at = gmdate('Y-m-d H:i:s');
        $oldHash = $this->setting(self::KEY_SETTING);
        $this->db()->beginTransaction();
        try {
            $this->upsert(self::KEY_SETTING, $hash);
            $this->upsert(self::ROTATED_SETTING, $at);
            $this->effects($rotated, $oldHash, $hash, $at);
            $this->db()->commit();
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) $this->db()->rollBack();
            throw $e;
        }
        return HarppServiceResult::success(['generated' => true, 'rotated' => $rotated, 'key' => $raw, 'fingerprint' => $this->fingerprint($hash), 'rotated_at' => $at], 'Store this bridge key now; it will not be shown again.');
    }

    private function effects(bool $rotated, string $oldHash, string $newHash, string $at): void
    {
        $tenantId = (int)(\app()->tenant()->current() ?? 0);
        $action = $rotated ? 'bridge.key_rotated' : 'bridge.key_generated';
        $before = $oldHash === '' ? null : ['fingerprint'=>$this->fingerprint($oldHash)];
        $after = ['fingerprint'=>$this->fingerprint($newHash),'rotated_at'=>$at];
        \app()->events()->fire('harpp.' . $action, ['tenant_id'=>$tenantId,'before'=>$before,'after'=>$after], 'harpp');
        $audit = \app()->cap()->call('kernel.audit.record@1', ['module'=>'harpp','action'=>$action,'entity_type'=>'harpp_bridge_key','entity_id'=>(string)$tenantId,'old_data'=>$before,'new_data'=>$after], ['mode'=>'first','caller_module'=>'harpp']);
        if (!is_array($audit) || empty($audit['ok'])) throw new \RuntimeException('Kernel audit recording failed.');
    }

    private function bridgeActor(): ?array
    {
        $stmt = $this->db()->query("SELECT id,email,full_name,role FROM harpp_users WHERE is_active=1 AND role IN ('owner','admin') ORDER BY FIELD(role,'owner','admin'),id LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) return null;
        $row['id'] = (int)$row['id'];
        $row['source'] = 'harpp_bridge';
        return $row;
    }

    private function setting(string $key): string
    {
        $stmt = $this->db()->prepare('SELECT setting_value FROM harpp_settings WHERE setting_key=:key');
        $stmt->execute([':key' => $key]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    private function upsert(string $key, string $value): void
    {
        $stmt = $this->db()->prepare('INSERT INTO harpp_settings (setting_key,setting_value,updated_at) VALUES (:key,:value,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()');
        $stmt->execute([':key' => $key, ':value' => $value]);
    }

    private function fingerprint(string $hash): string { return substr($hash, 0, 12); }
    private function tenantMatches(int $tenantId): bool { $current=(int)(\app()->tenant()->current()??0); return $current>0 && $tenantId===$current; }
    private function rateKey(string $clientId): string { return 'bridge_auth_rate_' . substr(hash('sha256', $clientId !== '' ? $clientId : 'unknown'), 0, 32); }

    private function isRateLimited(string $bucket): bool
    {
        $state = json_decode($this->setting($bucket), true);
        return is_array($state) && (int)($state['since'] ?? 0) > time() - self::WINDOW_SECONDS && (int)($state['count'] ?? 0) >= self::MAX_FAILURES;
    }

    private function recordFailure(string $bucket): void
    {
        $state = json_decode($this->setting($bucket), true);
        if (!is_array($state) || (int)($state['since'] ?? 0) <= time() - self::WINDOW_SECONDS) $state = ['since' => time(), 'count' => 0];
        $state['count'] = (int)$state['count'] + 1;
        $this->upsert($bucket, json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function clearFailures(string $bucket): void
    {
        $stmt = $this->db()->prepare('DELETE FROM harpp_settings WHERE setting_key=:key');
        $stmt->execute([':key' => $bucket]);
    }

    private function logFailure(int $tenantId, string $clientId, string $reason): void
    {
        if (function_exists('write_log')) \write_log('HARPP bridge auth failure', 'warning', ['module'=>'harpp','tenant_id'=>$tenantId,'client'=>substr($clientId,0,100),'reason'=>$reason]);
    }
    private function logError(string $message, Throwable $e): void
    {
        if (function_exists('write_log')) \write_log('HARPP bridge '.$message, 'error', ['module'=>'harpp','error'=>$e->getMessage()]);
    }
}
