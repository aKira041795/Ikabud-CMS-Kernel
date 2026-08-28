<?php

declare(strict_types=1);

namespace Harpp\Services;

use Ikabud\Kernel\Contracts\ModuleDB;
use PDO;
use Throwable;

final class HarppSettingsService
{
    public function __construct(private ?ModuleDB $database = null)
    {
    }

    private function db(): ModuleDB
    {
        if ($this->database instanceof ModuleDB) {
            return $this->database;
        }
        $db = \module('harpp')->db();
        if (!$db instanceof ModuleDB) {
            throw new \RuntimeException('HARPP module database is unavailable.');
        }
        return $this->database = $db;
    }

    public function defaults()
    {
        $manifest = json_decode((string)file_get_contents(dirname(__DIR__) . '/module.json'), true);
        $defaults = [];
        foreach ((array)($manifest['settings_fields'] ?? []) as $field) {
            if (is_array($field) && isset($field['key']) && array_key_exists('default', $field)) {
                $defaults[(string)$field['key']] = (string)$field['default'];
            }
        }
        return $defaults;
    }

    public function get(?int $tenantId = null)
    {
        if (!$this->tenantMatches($tenantId)) {
            return HarppServiceResult::failure('Tenant scope mismatch.', 403, 'tenant_scope_mismatch');
        }
        try {
            $stmt = $this->db()->query('SELECT setting_key, setting_value FROM harpp_settings');
            $stored = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $key = (string)$row['setting_key'];
                if ($this->isSecretKey($key)) continue;
                $stored[$key] = (string)($row['setting_value'] ?? '');
            }
            $defaults = array_filter($this->defaults(), fn(string $key): bool => !$this->isSecretKey($key), ARRAY_FILTER_USE_KEY);
            return HarppServiceResult::success(['settings' => array_merge($defaults, $stored)]);
        } catch (Throwable $e) {
            $this->log($e);
            return HarppServiceResult::failure('Unable to load HARPP settings.', 500);
        }
    }

    public function save(array $input, ?int $tenantId = null)
    {
        if (!$this->tenantMatches($tenantId)) {
            return HarppServiceResult::failure('Tenant scope mismatch.', 403, 'tenant_scope_mismatch');
        }
        $allowed = array_keys($this->defaults());
        $normalized = [];
        try {
            foreach ($input as $key => $value) {
                if (!in_array((string)$key, $allowed, true)) {
                    continue;
                }
                $normalized[(string)$key] = $this->normalize((string)$key, $value);
            }
        } catch (\InvalidArgumentException $e) {
            return HarppServiceResult::failure($e->getMessage());
        }
        if ($normalized === []) {
            return HarppServiceResult::failure('No valid HARPP settings were supplied.');
        }

        try {
            $this->db()->beginTransaction();
            $stmt = $this->db()->prepare(
                'INSERT INTO harpp_settings (setting_key, setting_value, updated_at) VALUES (:key, :value, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()'
            );
            foreach ($normalized as $key => $value) {
                $stmt->execute([':key' => $key, ':value' => $value]);
            }
            (new HarppFoundationService($this->db()))->recordEffect('harpp.settings.updated','settings.updated',['source'=>'system'],'harpp_settings',(string)(\app()->tenant()->current()??0),null,['keys'=>array_keys($normalized)]);
            $this->db()->commit();
            return $this->get($tenantId);
        } catch (Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            $this->log($e);
            return HarppServiceResult::failure('Unable to save HARPP settings.', 500);
        }
    }

    private function normalize(string $key, mixed $value): string
    {
        if (in_array($key, ['push_enabled', 'notify_decisions', 'notify_messages', 'conversation_archiving', 'push_important_only'], true)) {
            $value = strtolower(trim((string)$value));
            if (!in_array($value, ['0', '1', 'true', 'false', 'on', 'off'], true)) {
                throw new \InvalidArgumentException($key . ' must be boolean.');
            }
            return in_array($value, ['1', 'true', 'on'], true) ? '1' : '0';
        }
        if ($key === 'notification_channels') {
            $channels = is_array($value) ? $value : explode(',', (string)$value);
            $channels = array_values(array_unique(array_filter(array_map(static fn($item): string => strtolower(trim((string)$item)), $channels))));
            if ($channels === [] || array_diff($channels, ['push']) !== []) {
                throw new \InvalidArgumentException('Only the push notification channel is supported.');
            }
            return implode(',', $channels);
        }
        if ($key === 'country') {
            $value = strtoupper(trim((string)$value));
            if ($value !== '' && !preg_match('/^[A-Z]{2}$/', $value)) {
                throw new \InvalidArgumentException('country must be a 2-letter ISO country code (e.g. PH).');
            }
            return $value;
        }
        $value = trim(strip_tags((string)$value));
        if (strlen($value) > 255) {
            throw new \InvalidArgumentException($key . ' must be at most 255 characters.');
        }
        return $value;
    }

    private function isSecretKey(string $key): bool
    {
        return preg_match('/(?:vapid|private|secret|token|password|bridge|rate[_-]?limit|auth[_-]?rate)/i', $key) === 1;
    }

    private function tenantMatches(?int $tenantId): bool
    {
        $current = (int)(\app()->tenant()->current() ?? 0);
        return $current > 0 && ($tenantId === null || $tenantId === $current);
    }

    private function log(Throwable $e): void
    {
        if (function_exists('write_log')) {
            \write_log('HARPP settings operation failed', 'error', ['module' => 'harpp', 'error' => $e->getMessage()]);
        }
    }
}
