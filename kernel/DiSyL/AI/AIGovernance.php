<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\AI;

/**
 * AI Governance — 5.4 Governance Release
 *
 * Manages AI provider configuration, tenant-level settings, per-capability
 * policies, prompt templates, redaction rules, review queues, audit trails,
 * and provider fallback behavior.
 */
final class AIGovernance
{
    private const SETTINGS_FILE = 'ai-governance.json';
    private const PROMPTS_DIR = 'ai-prompts';
    private const REDACTION_DIR = 'ai-redaction';
    private const REVIEW_DIR = 'ai-review-queue';
    private const AUDIT_DIR = 'ai-audit';

    // ── Provider Configuration ──

    /** @return array<string, mixed> */
    public static function getProviderConfig(): array
    {
        $path = STORAGE_PATH . '/' . self::SETTINGS_FILE;
        if (!is_file($path)) return self::defaultConfig();

        $data = json_decode(file_get_contents($path), true);
        return is_array($data) ? array_merge(self::defaultConfig(), $data) : self::defaultConfig();
    }

    public static function saveProviderConfig(array $config): bool
    {
        $path = STORAGE_PATH . '/' . self::SETTINGS_FILE;
        $current = self::getProviderConfig();
        $merged = array_merge($current, $config);
        $merged['updated_at'] = date('c');

        return file_put_contents(
            $path,
            json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    private static function defaultConfig(): array
    {
        return [
            'kill_switch' => false,
            'default_provider' => 'openai',
            'providers' => [
                'openai' => [
                    'enabled' => true,
                    'model' => 'gpt-4o-mini',
                    'base_url' => 'https://api.openai.com/v1',
                    'timeout_ms' => 30000,
                ],
                'echo' => [
                    'enabled' => true,
                    'model' => 'echo',
                ],
            ],
            'global_policy' => [
                'max_tokens_per_call' => 4096,
                'daily_cost_ceiling_usd' => 5.0,
                'model_allowlist' => ['gpt-4o-mini', 'claude-haiku', 'echo'],
            ],
            'fallback_chain' => ['openai', 'echo'],
        ];
    }

    // ── Tenant-Level AI Settings ──

    public static function getTenantSettings(int $tenantId): array
    {
        $path = STORAGE_PATH . '/tenant-ai-settings.json';
        if (!is_file($path)) return self::defaultTenantSettings();

        $all = json_decode(file_get_contents($path), true);
        $key = 'tenant_' . $tenantId;
        return is_array($all[$key] ?? null) ? array_merge(self::defaultTenantSettings(), $all[$key]) : self::defaultTenantSettings();
    }

    public static function saveTenantSettings(int $tenantId, array $settings): bool
    {
        $path = STORAGE_PATH . '/tenant-ai-settings.json';
        $all = is_file($path) ? (json_decode(file_get_contents($path), true) ?: []) : [];
        $key = 'tenant_' . $tenantId;
        $all[$key] = array_merge($all[$key] ?? self::defaultTenantSettings(), $settings);
        $all[$key]['updated_at'] = date('c');

        return file_put_contents(
            $path,
            json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    private static function defaultTenantSettings(): array
    {
        return [
            'ai_enabled' => true,
            'monthly_budget_usd' => 50.0,
            'allowed_capabilities' => ['ai.summarize@1', 'ai.draft@1', 'ai.complete@1'],
            'require_review' => true,
            'auto_publish' => false,
        ];
    }

    // ── Per-Capability AI Policy ──

    public static function getCapabilityPolicy(string $capabilityId): array
    {
        $config = self::getProviderConfig();
        $policies = $config['capability_policies'] ?? [];
        return is_array($policies[$capabilityId] ?? null)
            ? $policies[$capabilityId]
            : self::defaultCapabilityPolicy($capabilityId);
    }

    public static function saveCapabilityPolicy(string $capabilityId, array $policy): bool
    {
        $config = self::getProviderConfig();
        $config['capability_policies'] = $config['capability_policies'] ?? [];
        $config['capability_policies'][$capabilityId] = $policy;
        return self::saveProviderConfig($config);
    }

    private static function defaultCapabilityPolicy(string $capabilityId): array
    {
        $isSummary = str_contains($capabilityId, 'summarize');
        return [
            'capability_id' => $capabilityId,
            'enabled' => true,
            'max_tokens' => $isSummary ? 1024 : 2048,
            'temperature' => $isSummary ? 0.3 : 0.7,
            'require_human_review' => true,
            'redaction_required' => false,
            'allowed_roles' => ['superadmin', 'administrator', 'editor'],
            'model' => null, // null = use default
        ];
    }

    // ── Token / Cost Dashboard ──

    /** @return array<string, mixed> */
    public static function getUsageStats(): array
    {
        $auditDir = STORAGE_PATH . '/' . self::AUDIT_DIR;
        if (!is_dir($auditDir)) return self::emptyStats();

        $today = date('Y-m-d');
        $month = date('Y-m');
        $stats = ['today' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
                   'month' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
                   'total' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
                   'by_model' => [], 'by_capability' => []];

        foreach (glob($auditDir . '/*.json') as $file) {
            $entry = json_decode(file_get_contents($file), true);
            if (!is_array($entry)) continue;

            $date = substr($entry['timestamp'] ?? '', 0, 10);
            $monthKey = substr($entry['timestamp'] ?? '', 0, 7);
            $model = (string)($entry['model'] ?? 'unknown');
            $cap = (string)($entry['capability_id'] ?? 'unknown');
            $tokens = (int)($entry['output_tokens'] ?? 0) + (int)($entry['input_tokens'] ?? 0);
            $cost = (float)($entry['cost_usd'] ?? 0);

            $stats['total']['calls']++;
            $stats['total']['tokens'] += $tokens;
            $stats['total']['cost_usd'] += $cost;

            if ($date === $today) {
                $stats['today']['calls']++;
                $stats['today']['tokens'] += $tokens;
                $stats['today']['cost_usd'] += $cost;
            }

            if ($monthKey === $month) {
                $stats['month']['calls']++;
                $stats['month']['tokens'] += $tokens;
                $stats['month']['cost_usd'] += $cost;
            }

            $stats['by_model'][$model] = ($stats['by_model'][$model] ?? 0) + $tokens;
            $stats['by_capability'][$cap] = ($stats['by_capability'][$cap] ?? 0) + 1;
        }

        return $stats;
    }

    private static function emptyStats(): array
    {
        return [
            'today' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
            'month' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
            'total' => ['calls' => 0, 'tokens' => 0, 'cost_usd' => 0.0],
            'by_model' => [],
            'by_capability' => [],
        ];
    }

    // ── Prompt Template Registry ──

    /** @return array<int, array<string, mixed>> */
    public static function listPromptTemplates(): array
    {
        $dir = STORAGE_PATH . '/' . self::PROMPTS_DIR;
        if (!is_dir($dir)) return [];

        $templates = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $data['id'] = basename($file, '.json');
                $templates[] = $data;
            }
        }
        return $templates;
    }

    public static function savePromptTemplate(string $id, array $template): bool
    {
        $dir = STORAGE_PATH . '/' . self::PROMPTS_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        $template['updated_at'] = date('c');
        return file_put_contents(
            $dir . '/' . preg_replace('/[^a-z0-9\-]/', '_', $id) . '.json',
            json_encode($template, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    public static function deletePromptTemplate(string $id): bool
    {
        $file = STORAGE_PATH . '/' . self::PROMPTS_DIR . '/' . preg_replace('/[^a-z0-9\-]/', '_', $id) . '.json';
        return is_file($file) && unlink($file);
    }

    // ── Redaction Rules ──

    /** @return array<int, array<string, mixed>> */
    public static function listRedactionRules(): array
    {
        $dir = STORAGE_PATH . '/' . self::REDACTION_DIR;
        if (!is_dir($dir)) return self::defaultRedactionRules();

        $rules = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) $rules[] = $data;
        }
        return !empty($rules) ? $rules : self::defaultRedactionRules();
    }

    public static function saveRedactionRule(string $id, array $rule): bool
    {
        $dir = STORAGE_PATH . '/' . self::REDACTION_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        return file_put_contents(
            $dir . '/' . preg_replace('/[^a-z0-9\-]/', '_', $id) . '.json',
            json_encode($rule, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        ) !== false;
    }

    private static function defaultRedactionRules(): array
    {
        return [
            ['id' => 'email', 'label' => 'Email addresses', 'pattern' => '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/', 'replacement' => '[EMAIL]', 'enabled' => true],
            ['id' => 'phone', 'label' => 'Phone numbers', 'pattern' => '/(?:\+?\d{1,3}[-.\s]?)?\(?\d{3}\)?[-.\s]?\d{3}[-.\s]?\d{4}/', 'replacement' => '[PHONE]', 'enabled' => false],
            ['id' => 'ssn', 'label' => 'SSN / National ID', 'pattern' => '/\b\d{3}-\d{2}-\d{4}\b/', 'replacement' => '[SSN]', 'enabled' => true],
            ['id' => 'credit_card', 'label' => 'Credit card numbers', 'pattern' => '/\b(?:\d{4}[-\s]?){3}\d{4}\b/', 'replacement' => '[CARD]', 'enabled' => true],
            ['id' => 'ip', 'label' => 'IP addresses', 'pattern' => '/\b(?:\d{1,3}\.){3}\d{1,3}\b/', 'replacement' => '[IP]', 'enabled' => false],
        ];
    }

    public static function redact(string $text, array $ruleIds = []): string
    {
        $rules = self::listRedactionRules();
        foreach ($rules as $rule) {
            if (!($rule['enabled'] ?? false)) continue;
            if (!empty($ruleIds) && !in_array(($rule['id'] ?? ''), $ruleIds, true)) continue;
            $pattern = $rule['pattern'] ?? null;
            $replacement = $rule['replacement'] ?? '[REDACTED]';
            if (is_string($pattern) && $pattern !== '') {
                $text = preg_replace($pattern, $replacement, $text);
            }
        }
        return $text;
    }

    // ── Review Queue ──

    /** @return array<int, array<string, mixed>> */
    public static function listReviewQueue(): array
    {
        $dir = STORAGE_PATH . '/' . self::REVIEW_DIR;
        if (!is_dir($dir)) return [];

        $queue = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) {
                $data['id'] = basename($file, '.json');
                $queue[] = $data;
            }
        }

        usort($queue, fn($a, $b) => ($b['created_at'] ?? '') <=> ($a['created_at'] ?? ''));
        return $queue;
    }

    public static function addToReviewQueue(array $draft): string
    {
        $dir = STORAGE_PATH . '/' . self::REVIEW_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $id = uniqid('rev_', true);
        $draft['id'] = $id;
        $draft['status'] = 'pending_review';
        $draft['created_at'] = date('c');

        file_put_contents(
            $dir . '/' . $id . '.json',
            json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        return $id;
    }

    public static function approveReview(string $id): bool
    {
        $file = STORAGE_PATH . '/' . self::REVIEW_DIR . '/' . $id . '.json';
        if (!is_file($file)) return false;

        $draft = json_decode(file_get_contents($file), true);
        $draft['status'] = 'approved';
        $draft['approved_at'] = date('c');

        return file_put_contents($file, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    public static function rejectReview(string $id, string $reason = ''): bool
    {
        $file = STORAGE_PATH . '/' . self::REVIEW_DIR . '/' . $id . '.json';
        if (!is_file($file)) return false;

        $draft = json_decode(file_get_contents($file), true);
        $draft['status'] = 'rejected';
        $draft['rejected_at'] = date('c');
        $draft['rejection_reason'] = $reason;

        return file_put_contents($file, json_encode($draft, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) !== false;
    }

    // ── AI Output Audit Trail ──

    public static function auditAiCall(string $capabilityId, string $model, string $prompt, string $output,
        int $inputTokens, int $outputTokens, float $costUsd, ?string $requestId, ?int $userId): void
    {
        $dir = STORAGE_PATH . '/' . self::AUDIT_DIR;
        if (!is_dir($dir)) mkdir($dir, 0775, true);

        $id = uniqid('ai_', true);
        file_put_contents(
            $dir . '/' . $id . '.json',
            json_encode([
                'id' => $id,
                'timestamp' => date('c'),
                'capability_id' => $capabilityId,
                'model' => $model,
                'prompt_hash' => sha1($prompt),
                'prompt_preview' => substr($prompt, 0, 200),
                'output_hash' => sha1($output),
                'output_preview' => substr($output, 0, 200),
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => round($costUsd, 6),
                'request_id' => $requestId,
                'user_id' => $userId,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        if (\function_exists('write_log')) {
            \write_log('ai.call', 'info', [
                'capability_id' => $capabilityId,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost_usd' => round($costUsd, 6),
                'request_id' => $requestId,
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public static function listAuditTrail(int $limit = 50): array
    {
        $dir = STORAGE_PATH . '/' . self::AUDIT_DIR;
        if (!is_dir($dir)) return [];

        $entries = [];
        foreach (glob($dir . '/*.json') as $file) {
            $data = json_decode(file_get_contents($file), true);
            if (is_array($data)) $entries[] = $data;
        }

        usort($entries, fn($a, $b) => ($b['timestamp'] ?? '') <=> ($a['timestamp'] ?? ''));
        return array_slice($entries, 0, $limit);
    }

    // ── Provider Fallback Behavior ──

    /** @return string[] Ordered list of provider IDs to try */
    public static function getFallbackChain(): array
    {
        $config = self::getProviderConfig();
        return $config['fallback_chain'] ?? ['openai', 'echo'];
    }

    public static function isProviderAvailable(string $providerId): bool
    {
        $config = self::getProviderConfig();
        $providers = $config['providers'] ?? [];

        if (!isset($providers[$providerId])) return false;
        if (empty($providers[$providerId]['enabled'])) return false;

        // Echo is always available
        if ($providerId === 'echo') return true;

        // Check if API key is configured for OpenAI
        if ($providerId === 'openai') {
            return (getenv('OPENAI_API_KEY') ?: '') !== '';
        }

        return true;
    }

    // ── AI Capability Certification ──

    /** @return array<string, mixed> */
    public static function certifyAiCapability(string $capabilityId): array
    {
        $checks = [];
        $total = 5; $passed = 0;

        // C1: Provider configured
        $providerAvailable = false;
        foreach (self::getFallbackChain() as $pid) {
            if (self::isProviderAvailable($pid)) { $providerAvailable = true; break; }
        }
        $checks[] = ['check' => 'C1: AI provider available', 'passed' => $providerAvailable];
        if ($providerAvailable) $passed++;

        // C2: Policy defined
        $policy = self::getCapabilityPolicy($capabilityId);
        $checks[] = ['check' => 'C2: Capability policy exists', 'passed' => $policy['enabled'] ?? false];
        if ($policy['enabled'] ?? false) $passed++;

        // C3: Prompt template exists
        $templates = self::listPromptTemplates();
        $hasTemplate = !empty(array_filter($templates, fn($t) => ($t['capability_id'] ?? '') === $capabilityId));
        $checks[] = ['check' => 'C3: Prompt template registered', 'passed' => $hasTemplate, 'detail' => $hasTemplate ? 'Found' : 'No template — using default'];
        // Template is optional

        // C4: Redaction configured (if needed)
        $redactionRequired = $policy['redaction_required'] ?? false;
        if ($redactionRequired) {
            $rules = self::listRedactionRules();
            $hasRedaction = !empty(array_filter($rules, fn($r) => $r['enabled'] ?? false));
            $checks[] = ['check' => 'C4: Redaction rules active', 'passed' => $hasRedaction];
            if ($hasRedaction) $passed++;
        } else {
            $checks[] = ['check' => 'C4: Redaction not required', 'passed' => true];
            $passed++;
        }

        // C5: Review queue configured (if needed)
        $reviewRequired = $policy['require_human_review'] ?? true;
        if ($reviewRequired) {
            $checks[] = ['check' => 'C5: Human review enabled', 'passed' => true, 'detail' => 'Drafts go to review queue'];
            $passed++;
        } else {
            $checks[] = ['check' => 'C5: Auto-publish mode', 'passed' => true, 'detail' => '⚠️ No human review — ensure audit trail'];
            $passed++;
        }

        $total = count($checks);
        return [
            'capability_id' => $capabilityId,
            'certified' => $passed === $total,
            'checks' => $checks,
            'score' => $passed,
            'max' => $total,
            'fallback_chain' => self::getFallbackChain(),
            'policy' => $policy,
        ];
    }
}
