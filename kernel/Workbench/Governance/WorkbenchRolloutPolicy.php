<?php
declare(strict_types=1);
namespace Ikabud\Kernel\Workbench\Governance;

final class WorkbenchRolloutPolicy
{
    public function __construct(private readonly array $settings = [], private readonly array $environment = []) {}
    public function decision(string $moduleId, string $provider, string $runId = '', array $runOverrides = []): array
    {
        if ($this->boolEnv('IKABUD_WORKBENCH_KILL_SWITCH') || ($runOverrides['disabled'] ?? false)) return $this->deny('global_kill_switch');
        $disabledModules = $this->list($this->settings['workbench_disabled_modules'] ?? []);
        if (in_array($moduleId, $disabledModules, true)) return $this->deny('module_kill_switch');
        $disabledProviders = $this->list($this->settings['workbench_disabled_providers'] ?? []);
        if ($provider !== '' && in_array($provider, $disabledProviders, true)) return $this->deny('provider_kill_switch');
        $percent = max(0, min(100, (int)($runOverrides['rollout_percent'] ?? $this->settings['workbench_rollout_percent'] ?? 100)));
        $bucket = (int)(hexdec(substr(hash('sha256', $moduleId . '|' . $runId), 0, 8)) % 100);
        if ($bucket >= $percent) return $this->deny('outside_rollout');
        $mode = (string)($runOverrides['mode'] ?? $this->settings['workbench_rollout_mode'] ?? 'shadow');
        if (!in_array($mode, ['off', 'shadow', 'advisory', 'gating'], true)) $mode = 'shadow';
        if ($mode === 'off') return $this->deny('mode_off');
        return ['allowed' => true, 'mode' => $mode, 'reason' => null, 'bucket' => $bucket, 'rollout_percent' => $percent];
    }
    private function deny(string $reason): array { return ['allowed' => false, 'mode' => 'off', 'reason' => $reason, 'bucket' => null, 'rollout_percent' => 0]; }
    private function boolEnv(string $key): bool { return filter_var($this->environment[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? false, FILTER_VALIDATE_BOOL); }
    private function list(mixed $value): array { if (is_string($value)) $value = explode(',', $value); return array_values(array_filter(array_map('trim', array_map('strval', is_array($value) ? $value : [])))); }
}
