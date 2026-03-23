<?php

declare(strict_types=1);

function cmsAiAutomationPlanDefaults(): array
{
    return [
        'topic' => '',
        'content_type' => 'post',
        'content_mode' => 'standard',
        'prompt_template' => 'Create a unique, publication-ready {content_type} about {topic}. Use the requested writing style, target audience, keywords, and avoid overlapping with recent generated content.',
        'writing_style' => 'Clear, specific, and useful.',
        'target_audience' => '',
        'keywords' => [],
        'summary_enabled' => true,
        'seo_enabled' => true,
        'auto_refine_policy' => 'high_severity_once',
        'auto_publish_policy' => 'off',
        'confidence_threshold' => 85,
        'visual_mode' => 'suggest_media',
        'cadence' => 'manual',
        'cadence_interval' => 1,
        'publish_offset_minutes' => 0,
        'next_run_at' => null,
        'is_active' => true,
        'search_grounding_enabled' => null,
    ];
}

function cmsAiAutomationAllowedAutoRefinePolicies(): array
{
    return ['off', 'high_severity_once', 'always_once'];
}

function cmsAiAutomationAllowedAutoPublishPolicies(): array
{
    return ['off', 'high_confidence_low_sensitivity'];
}

function cmsAiAutomationAllowedCadences(): array
{
    return ['manual', 'daily', 'weekly', 'monthly'];
}

function cmsAiAutomationAllowedContentModes(): array
{
    return ['standard', 'tutorial', 'opinion', 'comparison', 'checklist', 'expert'];
}

function cmsAiAutomationPreferredTier(array $plan, string $passType, array $groundingSources = []): ?string
{
    $mode = (string)($plan['content_mode'] ?? 'standard');
    $requiresHigherReasoning = in_array($mode, ['opinion', 'comparison', 'expert'], true);
    $researchHeavy = $groundingSources !== [] || preg_match('/\b(?:research-?backed|evidence-?based|source-?backed|credible\s+sources?|cite|citations?)\b/i', (string)($plan['prompt_template'] ?? '')) === 1;

    if ($passType === 'outline') {
        return $researchHeavy || $requiresHigherReasoning ? 'paid' : 'free';
    }

    if ($passType === 'draft') {
        return ($researchHeavy || $requiresHigherReasoning) ? 'paid' : null;
    }

    if ($passType === 'compress') {
        return ($researchHeavy || $requiresHigherReasoning) ? 'paid' : 'free';
    }

    return null;
}

function cmsAiAutomationAllowedVisualModes(): array
{
    return ['none', 'suggest_media'];
}

function cmsAiAutomationTableExists(): bool
{
    static $checked = null;
    if ($checked !== null) {
        return $checked;
    }

    try {
        $stmt = cmsDb()->query("SHOW TABLES LIKE 'cms_ai_content_plans'");
        $checked = $stmt && $stmt->fetchColumn() !== false;
    } catch (\Throwable $e) {
        $checked = false;
    }

    return $checked;
}

function cmsAiAutomationNormalizeInput(array $input, bool $partial = false): array
{
    $defaults = cmsAiAutomationPlanDefaults();
    $base = $partial ? [] : $defaults;
    $out = $base;

    foreach (['topic', 'content_type', 'content_mode', 'prompt_template', 'writing_style', 'target_audience', 'cadence', 'visual_mode', 'auto_refine_policy', 'auto_publish_policy'] as $field) {
        if (array_key_exists($field, $input)) {
            $out[$field] = trim((string)$input[$field]);
        }
    }

    if (array_key_exists('keywords', $input)) {
        $keywords = $input['keywords'];
        if (is_string($keywords)) {
            $keywords = array_filter(array_map('trim', explode(',', $keywords)));
        }
        $out['keywords'] = is_array($keywords)
            ? array_values(array_slice(array_filter(array_map(static fn($keyword) => trim((string)$keyword), $keywords)), 0, 12))
            : [];
    }

    foreach (['summary_enabled', 'seo_enabled', 'is_active'] as $field) {
        if (array_key_exists($field, $input)) {
            $out[$field] = (bool)$input[$field];
        }
    }

    // search_grounding_enabled: null = defer to global, true/false = plan override
    if (array_key_exists('search_grounding_enabled', $input)) {
        $sgVal = $input['search_grounding_enabled'];
        $out['search_grounding_enabled'] = ($sgVal === null || $sgVal === '') ? null : (bool)$sgVal;
    }

    foreach (['cadence_interval', 'publish_offset_minutes', 'confidence_threshold'] as $field) {
        if (array_key_exists($field, $input)) {
            $out[$field] = max(0, (int)$input[$field]);
        }
    }

    if (array_key_exists('next_run_at', $input)) {
        $out['next_run_at'] = cmsNormalizePublishAt($input['next_run_at']);
    }

    if (array_key_exists('content_type', $out)) {
        $out['content_type'] = $out['content_type'] !== '' ? $out['content_type'] : 'post';
    }
    if (array_key_exists('prompt_template', $out) && $out['prompt_template'] === '') {
        $out['prompt_template'] = $defaults['prompt_template'];
    }
    if (array_key_exists('writing_style', $out) && $out['writing_style'] === '') {
        $out['writing_style'] = $defaults['writing_style'];
    }
    if (array_key_exists('content_mode', $out) && !in_array($out['content_mode'], cmsAiAutomationAllowedContentModes(), true)) {
        $out['content_mode'] = $defaults['content_mode'];
    }
    if (array_key_exists('auto_refine_policy', $out) && !in_array($out['auto_refine_policy'], cmsAiAutomationAllowedAutoRefinePolicies(), true)) {
        $out['auto_refine_policy'] = $defaults['auto_refine_policy'];
    }
    if (array_key_exists('auto_publish_policy', $out) && !in_array($out['auto_publish_policy'], cmsAiAutomationAllowedAutoPublishPolicies(), true)) {
        $out['auto_publish_policy'] = $defaults['auto_publish_policy'];
    }
    if (array_key_exists('visual_mode', $out) && !in_array($out['visual_mode'], cmsAiAutomationAllowedVisualModes(), true)) {
        $out['visual_mode'] = $defaults['visual_mode'];
    }
    if (array_key_exists('cadence', $out) && !in_array($out['cadence'], cmsAiAutomationAllowedCadences(), true)) {
        $out['cadence'] = $defaults['cadence'];
    }
    if (array_key_exists('cadence_interval', $out)) {
        $out['cadence_interval'] = max(1, min(365, (int)$out['cadence_interval']));
    }
    if (array_key_exists('publish_offset_minutes', $out)) {
        $out['publish_offset_minutes'] = max(0, min(525600, (int)$out['publish_offset_minutes']));
    }
    if (array_key_exists('confidence_threshold', $out)) {
        $out['confidence_threshold'] = max(50, min(100, (int)$out['confidence_threshold']));
    }

    return $out;
}

function cmsAiAutomationListPlans(array $filters = []): array
{
    if (!cmsAiAutomationTableExists()) {
        return [];
    }

    $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
    $where = [];
    $bind = [];
    if (array_key_exists('is_active', $filters)) {
        $where[] = 'p.is_active = :active';
        $bind[':active'] = (int)(bool)$filters['is_active'];
    }

    $sql = "SELECT p.*, c.title AS last_generated_title
            FROM cms_ai_content_plans p
            LEFT JOIN cms_content c ON c.id = p.last_generated_content_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.updated_at DESC, p.id DESC LIMIT ' . $limit;

    $stmt = cmsDb()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return array_map('cmsAiAutomationHydratePlanRow', $rows);
}

function cmsAiAutomationHydratePlanRow(array $row): array
{
    $row['keywords'] = [];
    $decoded = json_decode((string)($row['keywords_json'] ?? '[]'), true);
    if (is_array($decoded)) {
        $row['keywords'] = array_values(array_filter(array_map('strval', $decoded)));
    }
    unset($row['keywords_json']);
    $row['summary_enabled'] = !empty($row['summary_enabled']);
    $row['seo_enabled'] = !empty($row['seo_enabled']);
    $row['content_mode'] = (string)($row['content_mode'] ?? 'standard');
    if (!in_array($row['content_mode'], cmsAiAutomationAllowedContentModes(), true)) {
        $row['content_mode'] = 'standard';
    }
    $row['auto_refine_policy'] = (string)($row['auto_refine_policy'] ?? 'high_severity_once');
    if (!in_array($row['auto_refine_policy'], cmsAiAutomationAllowedAutoRefinePolicies(), true)) {
        $row['auto_refine_policy'] = 'high_severity_once';
    }
    $row['auto_publish_policy'] = (string)($row['auto_publish_policy'] ?? 'off');
    if (!in_array($row['auto_publish_policy'], cmsAiAutomationAllowedAutoPublishPolicies(), true)) {
        $row['auto_publish_policy'] = 'off';
    }
    $row['confidence_threshold'] = max(50, min(100, (int)($row['confidence_threshold'] ?? 85)));
    $row['search_grounding_enabled'] = array_key_exists('search_grounding_enabled', $row)
        ? ($row['search_grounding_enabled'] === null ? null : !empty($row['search_grounding_enabled']))
        : null;
    $row['is_active'] = !empty($row['is_active']);
    $row['cadence_interval'] = (int)($row['cadence_interval'] ?? 1);
    $row['publish_offset_minutes'] = (int)($row['publish_offset_minutes'] ?? 0);
    $row['id'] = (int)($row['id'] ?? 0);
    $row['last_generated_content_id'] = isset($row['last_generated_content_id']) ? (int)$row['last_generated_content_id'] : null;
    return $row;
}

function cmsAiAutomationGetPlan(int $id): ?array
{
    if ($id <= 0 || !cmsAiAutomationTableExists()) {
        return null;
    }

    $stmt = cmsDb()->prepare("SELECT p.*, c.title AS last_generated_title
        FROM cms_ai_content_plans p
        LEFT JOIN cms_content c ON c.id = p.last_generated_content_id
        WHERE p.id = :id LIMIT 1");
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? cmsAiAutomationHydratePlanRow($row) : null;
}

function cmsAiAutomationNextRunAt(array $plan, ?string $from = null): ?string
{
    $cadence = (string)($plan['cadence'] ?? 'manual');
    if ($cadence === 'manual') {
        return null;
    }

    $interval = max(1, (int)($plan['cadence_interval'] ?? 1));
    $baseTs = $from !== null ? strtotime($from) : time();
    if ($baseTs === false) {
        $baseTs = time();
    }

    $modifier = match ($cadence) {
        'daily' => '+' . $interval . ' day',
        'weekly' => '+' . $interval . ' week',
        'monthly' => '+' . $interval . ' month',
        default => null,
    };

    if ($modifier === null) {
        return null;
    }

    $nextTs = strtotime($modifier, $baseTs);
    return $nextTs !== false ? date('Y-m-d H:i:s', $nextTs) : null;
}

function cmsAiAutomationSavePlan(array $input, ?int $id = null, ?array $actor = null): array
{
    if (!cmsAiAutomationTableExists()) {
        return ['ok' => false, 'error' => 'AI automation tables are not available'];
    }

    $existing = $id !== null ? cmsAiAutomationGetPlan($id) : null;
    if ($id !== null && $existing === null) {
        return ['ok' => false, 'error' => 'Plan not found'];
    }

    $normalized = cmsAiAutomationNormalizeInput($input, $existing !== null);
    $plan = $existing !== null ? array_merge($existing, $normalized) : $normalized;

    if (trim((string)($plan['topic'] ?? '')) === '') {
        return ['ok' => false, 'error' => 'topic is required'];
    }

    $actorId = (int)($actor['id'] ?? 0);
    $db = cmsDb();

    if ($existing === null) {
        if (($plan['next_run_at'] ?? null) === null && !empty($plan['is_active']) && ($plan['cadence'] ?? 'manual') !== 'manual') {
            $plan['next_run_at'] = date('Y-m-d H:i:s');
        }

        $stmt = $db->prepare(
            "INSERT INTO cms_ai_content_plans
                (topic, content_type, content_mode, prompt_template, writing_style, target_audience, keywords_json,
                 summary_enabled, seo_enabled, search_grounding_enabled, visual_mode, cadence, cadence_interval, publish_offset_minutes,
                 auto_refine_policy, auto_publish_policy, confidence_threshold, next_run_at, is_active, created_by, updated_by, created_at, updated_at)
             VALUES
                (:topic, :content_type, :content_mode, :prompt_template, :writing_style, :target_audience, :keywords_json,
                 :summary_enabled, :seo_enabled, :search_grounding_enabled, :visual_mode, :cadence, :cadence_interval, :publish_offset_minutes,
                 :auto_refine_policy, :auto_publish_policy, :confidence_threshold, :next_run_at, :is_active, :created_by, :updated_by, NOW(), NOW())"
        );
        $stmt->execute([
            ':topic' => $plan['topic'],
            ':content_type' => $plan['content_type'],
            ':content_mode' => $plan['content_mode'] ?? 'standard',
            ':prompt_template' => $plan['prompt_template'],
            ':writing_style' => $plan['writing_style'],
            ':target_audience' => $plan['target_audience'] !== '' ? $plan['target_audience'] : null,
            ':keywords_json' => json_encode($plan['keywords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':summary_enabled' => !empty($plan['summary_enabled']) ? 1 : 0,
            ':seo_enabled' => !empty($plan['seo_enabled']) ? 1 : 0,
            ':search_grounding_enabled' => array_key_exists('search_grounding_enabled', $plan)
                ? ($plan['search_grounding_enabled'] === null ? null : (!empty($plan['search_grounding_enabled']) ? 1 : 0))
                : null,
            ':auto_refine_policy' => $plan['auto_refine_policy'] ?? 'high_severity_once',
            ':auto_publish_policy' => $plan['auto_publish_policy'] ?? 'off',
            ':confidence_threshold' => (int)($plan['confidence_threshold'] ?? 85),
            ':visual_mode' => $plan['visual_mode'],
            ':cadence' => $plan['cadence'],
            ':cadence_interval' => $plan['cadence_interval'],
            ':publish_offset_minutes' => $plan['publish_offset_minutes'],
            ':next_run_at' => $plan['next_run_at'],
            ':is_active' => !empty($plan['is_active']) ? 1 : 0,
            ':created_by' => $actorId > 0 ? $actorId : null,
            ':updated_by' => $actorId > 0 ? $actorId : null,
        ]);

        $id = (int)$db->lastInsertId();
    } else {
        $stmt = $db->prepare(
            "UPDATE cms_ai_content_plans SET
                topic = :topic,
                content_type = :content_type,
                content_mode = :content_mode,
                prompt_template = :prompt_template,
                writing_style = :writing_style,
                target_audience = :target_audience,
                keywords_json = :keywords_json,
                summary_enabled = :summary_enabled,
                seo_enabled = :seo_enabled,
                search_grounding_enabled = :search_grounding_enabled,
                auto_refine_policy = :auto_refine_policy,
                auto_publish_policy = :auto_publish_policy,
                confidence_threshold = :confidence_threshold,
                visual_mode = :visual_mode,
                cadence = :cadence,
                cadence_interval = :cadence_interval,
                publish_offset_minutes = :publish_offset_minutes,
                next_run_at = :next_run_at,
                is_active = :is_active,
                updated_by = :updated_by,
                updated_at = NOW()
             WHERE id = :id LIMIT 1"
        );
        $stmt->execute([
            ':id' => $id,
            ':topic' => $plan['topic'],
            ':content_type' => $plan['content_type'],
            ':content_mode' => $plan['content_mode'] ?? 'standard',
            ':prompt_template' => $plan['prompt_template'],
            ':writing_style' => $plan['writing_style'],
            ':target_audience' => $plan['target_audience'] !== '' ? $plan['target_audience'] : null,
            ':keywords_json' => json_encode($plan['keywords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':summary_enabled' => !empty($plan['summary_enabled']) ? 1 : 0,
            ':seo_enabled' => !empty($plan['seo_enabled']) ? 1 : 0,
            ':search_grounding_enabled' => array_key_exists('search_grounding_enabled', $plan)
                ? ($plan['search_grounding_enabled'] === null ? null : (!empty($plan['search_grounding_enabled']) ? 1 : 0))
                : null,
            ':auto_refine_policy' => $plan['auto_refine_policy'] ?? 'high_severity_once',
            ':auto_publish_policy' => $plan['auto_publish_policy'] ?? 'off',
            ':confidence_threshold' => (int)($plan['confidence_threshold'] ?? 85),
            ':visual_mode' => $plan['visual_mode'],
            ':cadence' => $plan['cadence'],
            ':cadence_interval' => $plan['cadence_interval'],
            ':publish_offset_minutes' => $plan['publish_offset_minutes'],
            ':next_run_at' => $plan['next_run_at'],
            ':is_active' => !empty($plan['is_active']) ? 1 : 0,
            ':updated_by' => $actorId > 0 ? $actorId : null,
        ]);
    }

    return ['ok' => true, 'plan' => cmsAiAutomationGetPlan((int)$id)];
}

function cmsAiAutomationTogglePlan(int $id, ?array $actor = null): array
{
    $plan = cmsAiAutomationGetPlan($id);
    if ($plan === null) {
        return ['ok' => false, 'error' => 'Plan not found'];
    }

    $nextActive = empty($plan['is_active']);
    $nextRunAt = $plan['next_run_at'];
    if ($nextActive && ($plan['cadence'] ?? 'manual') !== 'manual') {
        $nextRunAt = $plan['next_run_at'] ?? date('Y-m-d H:i:s');
    } elseif (($plan['cadence'] ?? 'manual') === 'manual') {
        $nextRunAt = null;
    }

    return cmsAiAutomationSavePlan([
        'is_active' => $nextActive,
        'next_run_at' => $nextRunAt,
    ], $id, $actor);
}

function cmsAiAutomationDeletePlan(int $id): array
{
    if (!cmsAiAutomationTableExists()) {
        return ['ok' => false, 'error' => 'AI automation tables are not available'];
    }
    $plan = cmsAiAutomationGetPlan($id);
    if ($plan === null) {
        return ['ok' => false, 'error' => 'Plan not found'];
    }
    $db = cmsDb();
    $db->prepare('DELETE FROM cms_ai_content_runs WHERE plan_id = :pid')->execute([':pid' => $id]);
    $db->prepare('DELETE FROM cms_ai_content_plans WHERE id = :id LIMIT 1')->execute([':id' => $id]);
    return ['ok' => true];
}

function cmsAiAutomationListRuns(array $filters = []): array
{
    if (!cmsAiAutomationTableExists()) {
        return [];
    }

    $limit = max(1, min(100, (int)($filters['limit'] ?? 50)));
    $where = [];
    $bind = [];
    if (!empty($filters['plan_id'])) {
        $where[] = 'r.plan_id = :plan_id';
        $bind[':plan_id'] = (int)$filters['plan_id'];
    }

    $sql = "SELECT r.*, c.title AS content_title
            FROM cms_ai_content_runs r
            LEFT JOIN cms_content c ON c.id = r.content_id";
    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY r.started_at DESC, r.id DESC LIMIT ' . $limit;

    $stmt = cmsDb()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $contentIds = [];
    foreach ($rows as $row) {
        $contentId = (int)($row['content_id'] ?? 0);
        if ($contentId > 0) {
            $contentIds[$contentId] = true;
        }
    }
    $metaByContentId = cmsAiAutomationLoadRunContentMeta(array_keys($contentIds));

    return array_map(static function (array $row) use ($metaByContentId): array {
        foreach (['keywords_json', 'response_json', 'visual_suggestions_json'] as $field) {
            $decoded = json_decode((string)($row[$field] ?? 'null'), true);
            $row[str_replace('_json', '', $field)] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            unset($row[$field]);
        }
        $row['id'] = (int)($row['id'] ?? 0);
        $row['plan_id'] = (int)($row['plan_id'] ?? 0);
        $row['content_id'] = isset($row['content_id']) ? (int)$row['content_id'] : null;
        $row['attempt_count'] = (int)($row['attempt_count'] ?? 1);

        $meta = ($row['content_id'] ?? 0) > 0 ? ($metaByContentId[(int)$row['content_id']] ?? []) : [];
        $row['qa_warnings'] = cmsAiAutomationDecodeMetaJson($meta, '_ai_qa_warnings');
        $row['quality_assessment'] = cmsAiAutomationDecodeMetaJson($meta, '_ai_quality_assessment');
        $row['critique_history'] = cmsAiAutomationDecodeMetaJson($meta, '_ai_critique_history');
        $row['refine_history'] = cmsAiAutomationDecodeMetaJson($meta, '_ai_refine_history');
        $row['latest_critique'] = trim((string)($meta['_ai_latest_critique'] ?? ''));
        $row['last_refined_at'] = trim((string)($meta['_ai_last_refined_at'] ?? ''));
        $row['last_refine_run_id'] = (int)($meta['_ai_last_refine_run_id'] ?? 0);
        $row['refine_attempt_count'] = max(0, (int)($meta['_ai_refine_attempt_count'] ?? 0));
        $row['quality_score'] = (int)($row['quality_assessment']['overall'] ?? 0);
        $row['approval_confidence'] = (string)($row['quality_assessment']['approval_confidence'] ?? '');
        $row['qa_warning_count'] = count($row['qa_warnings']);
        $row['qa_high_warning_count'] = count(array_filter($row['qa_warnings'], static function ($warning): bool {
            return is_array($warning) && (string)($warning['severity'] ?? '') === 'high';
        }));
        $row['auto_refine_count'] = count(array_filter($row['critique_history'], static function ($entry): bool {
            return is_array($entry) && str_starts_with((string)($entry['source'] ?? ''), 'auto_');
        }));
        $row['auto_refine_unresolved'] = $row['auto_refine_count'] > 0 && $row['qa_high_warning_count'] > 0;

        return $row;
    }, $rows);
}

function cmsAiAutomationLoadRunContentMeta(array $contentIds): array
{
    $contentIds = array_values(array_filter(array_map('intval', $contentIds), static fn(int $id): bool => $id > 0));
    if ($contentIds === []) {
        return [];
    }

    $keys = [
        '_ai_qa_warnings',
        '_ai_quality_assessment',
        '_ai_critique_history',
        '_ai_refine_history',
        '_ai_latest_critique',
        '_ai_last_refined_at',
        '_ai_last_refine_run_id',
        '_ai_refine_attempt_count',
    ];

    $contentPlaceholders = [];
    $bind = [];
    foreach ($contentIds as $index => $contentId) {
        $placeholder = ':cid' . $index;
        $contentPlaceholders[] = $placeholder;
        $bind[$placeholder] = $contentId;
    }

    $keyPlaceholders = [];
    foreach ($keys as $index => $key) {
        $placeholder = ':key' . $index;
        $keyPlaceholders[] = $placeholder;
        $bind[$placeholder] = $key;
    }

    $sql = 'SELECT content_id, meta_key, meta_value FROM cms_content_meta WHERE content_id IN (' . implode(', ', $contentPlaceholders) . ') AND meta_key IN (' . implode(', ', $keyPlaceholders) . ')';
    $stmt = cmsDb()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $metaByContentId = [];
    foreach ($rows as $row) {
        $contentId = (int)($row['content_id'] ?? 0);
        if ($contentId <= 0) {
            continue;
        }
        if (!isset($metaByContentId[$contentId])) {
            $metaByContentId[$contentId] = [];
        }
        $metaByContentId[$contentId][(string)$row['meta_key']] = (string)$row['meta_value'];
    }

    return $metaByContentId;
}

function cmsAiAutomationCreateRun(int $planId, array $fields): int
{
    $stmt = cmsDb()->prepare(
        "INSERT INTO cms_ai_content_runs
            (plan_id, content_id, status, topic_snapshot, prompt_snapshot, keywords_json,
             response_json, summary_text, seo_title, seo_description, visual_suggestions_json,
             desired_publish_at, error_message, attempt_count, started_at, completed_at, created_at)
         VALUES
            (:plan_id, :content_id, :status, :topic_snapshot, :prompt_snapshot, :keywords_json,
             :response_json, :summary_text, :seo_title, :seo_description, :visual_suggestions_json,
             :desired_publish_at, :error_message, :attempt_count, :started_at, :completed_at, NOW())"
    );
    $stmt->execute([
        ':plan_id' => $planId,
        ':content_id' => $fields['content_id'] ?? null,
        ':status' => (string)($fields['status'] ?? 'queued'),
        ':topic_snapshot' => (string)($fields['topic_snapshot'] ?? ''),
        ':prompt_snapshot' => (string)($fields['prompt_snapshot'] ?? ''),
        ':keywords_json' => isset($fields['keywords']) ? json_encode($fields['keywords'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':response_json' => isset($fields['response']) ? json_encode($fields['response'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':summary_text' => $fields['summary_text'] ?? null,
        ':seo_title' => $fields['seo_title'] ?? null,
        ':seo_description' => $fields['seo_description'] ?? null,
        ':visual_suggestions_json' => isset($fields['visual_suggestions']) ? json_encode($fields['visual_suggestions'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ':desired_publish_at' => $fields['desired_publish_at'] ?? null,
        ':error_message' => $fields['error_message'] ?? null,
        ':attempt_count' => max(1, (int)($fields['attempt_count'] ?? 1)),
        ':started_at' => $fields['started_at'] ?? date('Y-m-d H:i:s'),
        ':completed_at' => $fields['completed_at'] ?? null,
    ]);

    return (int)cmsDb()->lastInsertId();
}

function cmsAiAutomationUpdateRun(int $runId, array $fields): void
{
    if ($runId <= 0 || $fields === []) {
        return;
    }

    $map = [
        'content_id' => 'content_id',
        'status' => 'status',
        'response' => 'response_json',
        'summary_text' => 'summary_text',
        'seo_title' => 'seo_title',
        'seo_description' => 'seo_description',
        'visual_suggestions' => 'visual_suggestions_json',
        'desired_publish_at' => 'desired_publish_at',
        'error_message' => 'error_message',
        'attempt_count' => 'attempt_count',
        'completed_at' => 'completed_at',
    ];

    $sets = [];
    $bind = [':id' => $runId];
    foreach ($map as $inputKey => $column) {
        if (!array_key_exists($inputKey, $fields)) {
            continue;
        }
        $sets[] = $column . ' = :' . $inputKey;
        $value = $fields[$inputKey];
        if (in_array($inputKey, ['response', 'visual_suggestions'], true)) {
            $value = $value !== null ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        }
        $bind[':' . $inputKey] = $value;
    }

    if ($sets === []) {
        return;
    }

    $sql = 'UPDATE cms_ai_content_runs SET ' . implode(', ', $sets) . ' WHERE id = :id LIMIT 1';
    cmsDb()->prepare($sql)->execute($bind);
}

function cmsAiAutomationRecentContext(int $planId, int $limit = 5): array
{
    if ($planId <= 0 || !cmsAiAutomationTableExists()) {
        return [];
    }

    $stmt = cmsDb()->prepare(
        "SELECT c.id, c.title, c.excerpt
         FROM cms_ai_content_runs r
         INNER JOIN cms_content c ON c.id = r.content_id
         WHERE r.plan_id = :plan_id AND r.content_id IS NOT NULL
         ORDER BY r.started_at DESC, r.id DESC
         LIMIT {$limit}"
    );
    $stmt->execute([':plan_id' => $planId]);
    return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
}

// ─── Content Intelligence Layer ─────────────────────────────────────────────

function cmsAiAutomationTopicDomain(string $text): string
{
    $text = mb_strtolower(trim($text));
    if ($text === '') {
        return 'general';
    }

    $domains = [
        'health'     => ['health', 'medical', 'medicine', 'disease', 'symptom', 'treatment', 'therapy', 'vitamin', 'supplement', 'dosage', 'vaccine', 'illness', 'allergy', 'first aid', 'injury', 'mental health', 'anxiety', 'depression', 'diagnosis'],
        'pet_care'   => ['pet', 'cat', 'dog', 'kitten', 'puppy', 'animal', 'breed', 'grooming', 'veterinary', 'vet', 'stray', 'litter', 'feline', 'canine', 'aquarium', 'hamster', 'rabbit', 'newborn kitten', 'newborn puppy', 'pet care', 'pet owner'],
        'child_care' => ['baby care', 'infant care', 'newborn baby', 'toddler', 'child care', 'parenting', 'pregnancy', 'breastfeeding', 'formula feeding', 'pediatric'],
        'finance'    => ['finance', 'investment', 'invest', 'stock', 'crypto', 'tax', 'insurance', 'loan', 'mortgage', 'banking', 'retirement', 'savings', 'credit', 'debt', 'trading', 'budget', 'interest rate'],
        'safety'     => ['safety', 'emergency', 'hazard', 'danger', 'fire safety', 'accident', 'disaster', 'survival', 'self-defense', 'poison', 'choking'],
        'legal'      => ['legal', 'law', 'regulation', 'compliance', 'contract', 'liability', 'rights', 'court', 'lawsuit', 'attorney'],
        'food'       => ['recipe', 'baking', 'cooking', 'food', 'ingredient', 'kitchen', 'dessert', 'bread', 'pastry', 'cake', 'cuisine', 'meal', 'snack', 'baker', 'home baker'],
        'technology' => ['software', 'programming', 'developer', 'tech', 'app', 'website', 'computer', 'database', 'cloud', 'api', 'server'],
        'education'  => ['education', 'learning', 'school', 'university', 'course', 'student', 'teaching', 'tutorial'],
    ];

    $scores = [];
    foreach ($domains as $domain => $keywords) {
        $score = 0;
        foreach ($keywords as $kw) {
            if (str_contains($text, $kw)) {
                $score += mb_strlen($kw);
            }
        }
        if ($score > 0) {
            $scores[$domain] = $score;
        }
    }

    if ($scores === []) {
        return 'general';
    }
    arsort($scores);
    return (string)array_key_first($scores);
}

function cmsAiAutomationResolveSensitivity(array $plan): string
{
    $domain = cmsAiAutomationTopicDomain((string)($plan['topic'] ?? ''));
    return match ($domain) {
        'health', 'pet_care', 'child_care', 'safety' => 'high',
        'finance', 'legal' => 'elevated',
        default => 'standard',
    };
}

function cmsAiAutomationDetectAudienceTopicMismatch(array $plan): ?string
{
    $audience = mb_strtolower(trim((string)($plan['target_audience'] ?? '')));
    $topic = mb_strtolower(trim((string)($plan['topic'] ?? '')));
    if ($audience === '' || $topic === '') {
        return null;
    }

    $audienceDomain = cmsAiAutomationTopicDomain($audience);
    $topicDomain = cmsAiAutomationTopicDomain($topic);

    if ($audienceDomain === 'general' || $topicDomain === 'general') {
        return null;
    }

    $related = [
        'health'     => ['pet_care', 'child_care', 'safety', 'food'],
        'pet_care'   => ['health', 'safety', 'child_care'],
        'child_care' => ['health', 'safety', 'education', 'pet_care'],
        'finance'    => ['legal', 'education'],
        'legal'      => ['finance'],
        'food'       => ['health'],
        'safety'     => ['health', 'child_care', 'pet_care'],
        'technology' => ['education'],
        'education'  => ['technology', 'child_care'],
    ];

    if ($audienceDomain === $topicDomain) {
        return null;
    }
    if (in_array($topicDomain, $related[$audienceDomain] ?? [], true)) {
        return null;
    }

    return sprintf(
        'Target audience "%s" (domain: %s) does not align with topic "%s" (domain: %s). Content may miss the intended audience.',
        $plan['target_audience'] ?? '',
        $audienceDomain,
        $plan['topic'] ?? '',
        $topicDomain
    );
}

function cmsAiAutomationNeedsLocalContext(array $plan): bool
{
    $parts = [
        (string)($plan['topic'] ?? ''),
        (string)($plan['target_audience'] ?? ''),
        (string)($plan['prompt_template'] ?? ''),
        implode(' ', is_array($plan['keywords'] ?? null) ? $plan['keywords'] : []),
    ];

    $text = mb_strtolower(trim(implode(' ', $parts)));
    if ($text === '') {
        return false;
    }

    $markers = [
        'philippine', 'philippines', 'filipino', 'pinoy', 'local',
        'manila', 'cebu', 'davao', 'barangay', 'rural', 'province',
    ];

    foreach ($markers as $marker) {
        if (str_contains($text, $marker)) {
            return true;
        }
    }

    return false;
}

function cmsAiAutomationCountLocalInsights(string $bodyText): int
{
    $text = mb_strtolower($bodyText);
    if ($text === '') {
        return 0;
    }

    $signals = [
        'hot and humid', 'humid climate', 'tropical climate',
        'power outage', 'brownout', 'no electricity',
        'rural area', 'province', 'barangay',
        'limited vet', 'limited clinic', 'transport distance',
        'stray rescue', 'abandoned animal',
        'local shelter', 'community vet',
        'philippines', 'philippine', 'filipino',
    ];

    $count = 0;
    foreach ($signals as $signal) {
        if (str_contains($text, $signal)) {
            $count++;
        }
    }

    // Count climate/access pairings as additional practical local insights.
    if (
        preg_match('/\b(?:hot|humid|tropical)\b/i', $bodyText)
        && preg_match('/\b(?:heat|temperature|ventilation|hydration)\b/i', $bodyText)
    ) {
        $count++;
    }
    if (
        preg_match('/\b(?:rural|remote|province|barangay)\b/i', $bodyText)
        && preg_match('/\b(?:vet|clinic|doctor|pharmacy|supplies|transport)\b/i', $bodyText)
    ) {
        $count++;
    }

    return $count;
}

function cmsAiAutomationHasScenarioBlock(string $bodyText): bool
{
    if (trim($bodyText) === '') {
        return false;
    }

    $patterns = [
        '/\bwhat if\b/i',
        '/\bif you (?:find|found|have|are|cannot|can\'t|do not have)\b/i',
        '/\bif there is no\b/i',
        '/\bin case of\b/i',
        '/\bfor example\b/i',
        '/\bavoid this\b/i',
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $bodyText)) {
            return true;
        }
    }

    return false;
}

function cmsAiAutomationFindRepeatedSentenceStem(string $bodyText): ?array
{
    $sentences = preg_split('/(?<=[.!?])\s+/u', trim($bodyText)) ?: [];
    if (count($sentences) < 4) {
        return null;
    }

    $stems = [];
    foreach ($sentences as $sentence) {
        $clean = mb_strtolower(trim((string)$sentence));
        $clean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $clean);
        $clean = preg_replace('/\s+/', ' ', (string)$clean);
        if ($clean === '') {
            continue;
        }

        $words = preg_split('/\s+/', $clean) ?: [];
        if (count($words) < 6) {
            continue;
        }

        $stem = implode(' ', array_slice($words, 0, 6));
        $stems[] = $stem;
    }

    if ($stems === []) {
        return null;
    }

    $counts = array_count_values($stems);
    arsort($counts);
    $topStem = array_key_first($counts);
    $topCount = (int)($topStem !== null ? $counts[$topStem] : 0);

    if ($topStem !== null && $topCount >= 2) {
        return ['stem' => $topStem, 'count' => $topCount];
    }

    return null;
}

function cmsAiAutomationCriticalStepsForPlan(array $plan): array
{
    $topic = mb_strtolower(trim((string)($plan['topic'] ?? '')));
    $keywords = mb_strtolower(trim(implode(' ', is_array($plan['keywords'] ?? null) ? $plan['keywords'] : [])));
    $promptTemplate = mb_strtolower(trim((string)($plan['prompt_template'] ?? '')));
    $text = trim($topic . ' ' . $keywords . ' ' . $promptTemplate);
    $domain = cmsAiAutomationTopicDomain((string)($plan['topic'] ?? ''));

    if ($text === '') {
        return [];
    }

    $steps = [];
    $isEarlyLifeCare = preg_match('/\b(?:newborn|neonate|infant|early\s*weeks?|first\s*weeks?)\b/i', $text) === 1;
    $isRescueScenario = preg_match('/\b(?:orphaned|abandoned|stray|rescue|found\s+alone)\b/i', $text) === 1;

    if ($domain === 'pet_care' && ($isEarlyLifeCare || $isRescueScenario)) {
        $steps[] = [
            'name' => 'elimination support for dependent neonates',
            'pattern' => '/\b(?:stimulat(?:e|ing|ion)|assist|help)\b.{0,90}\b(?:urinat(?:e|ion)|defecat(?:e|ion)|pee|poop|bowel)\b/i',
        ];
        $steps[] = [
            'name' => 'safe warmth/environment management',
            'pattern' => '/\b(?:warmth|temperature|heating|insulation|hypothermia|overheating|warm\s+environment)\b/i',
        ];
        $steps[] = [
            'name' => 'structured feeding cadence guidance',
            'pattern' => '/\b(?:every\s+\d+\s*(?:hours?|hrs?)|feeding\s+schedule|feeding\s+frequency|night\s+feeds?)\b/i',
        ];
    }

    if (($domain === 'health' || $domain === 'child_care') && $isEarlyLifeCare) {
        $steps[] = [
            'name' => 'stage-based care progression',
            'pattern' => '/\b(?:0\s*[-to]{1,3}\s*\d+\s*weeks?|\d+\s*[-to]{1,3}\s*\d+\s*weeks?|age\s+group|developmental\s+stage)\b/i',
        ];
    }

    if (($domain === 'health' || $domain === 'pet_care' || $domain === 'child_care') && cmsAiAutomationResolveSensitivity($plan) === 'high') {
        $steps[] = [
            'name' => 'explicit emergency or escalation signs',
            'pattern' => '/\b(?:warning\s+signs?|seek\s+urgent|emergency|contact\s+(?:a\s+)?(?:doctor|vet|professional)|go\s+to\s+(?:a\s+)?(?:clinic|hospital))\b/i',
        ];
    }

    return $steps;
}

function cmsAiAutomationMissingCriticalSteps(string $bodyText, array $steps): array
{
    if (trim($bodyText) === '' || $steps === []) {
        return [];
    }

    $missing = [];
    foreach ($steps as $step) {
        $name = (string)($step['name'] ?? 'critical step');
        $pattern = (string)($step['pattern'] ?? '');
        if ($pattern === '') {
            continue;
        }
        if (preg_match($pattern, $bodyText) !== 1) {
            $missing[] = $name;
        }
    }

    return $missing;
}

function cmsAiAutomationHasConfidenceCalibration(string $bodyText): bool
{
    if (trim($bodyText) === '') {
        return false;
    }

    // Accept either uncertainty framing or explicit product-instruction caveats.
    $hasQualifier = preg_match('/\b(?:typically|generally|often|may vary|start with|adjust based on|monitor and adjust|depends on)\b/i', $bodyText) === 1;
    $hasLabelCaveat = preg_match('/\b(?:follow|check)\b.{0,40}\b(?:product|label|packaging|manufacturer)\b.{0,40}\b(?:instructions?|guidelines?)\b/i', $bodyText) === 1;

    return $hasQualifier || $hasLabelCaveat;
}

function cmsAiAutomationLocalizationRealityScore(string $bodyText): int
{
    $text = mb_strtolower($bodyText);
    if ($text === '') {
        return 0;
    }

    $score = 0;
    $dimensions = [
        '/\b(?:climate|weather|humid|heat|cold|rainy|dry\s+season|hot\s+season|temperature)\b/i',
        '/\b(?:power\s+outage|electricity|internet\s+outage|water\s+supply|transport|road\s+access|signal\s+coverage)\b/i',
        '/\b(?:clinic\s+access|vet\s+access|hospital\s+access|pharmacy\s+access|limited\s+services?|remote\s+area|rural\s+area)\b/i',
        '/\b(?:cost|budget|afford|supply\s+shortage|limited\s+supplies?|availability|out\s+of\s+stock)\b/i',
    ];

    foreach ($dimensions as $pattern) {
        if (preg_match($pattern, $bodyText) === 1) {
            $score++;
        }
    }

    return $score;
}

function cmsAiAutomationContentModeDirectives(array $plan): array
{
    $mode = (string)($plan['content_mode'] ?? 'standard');
    $directives = [];

    // ─── Universal decision-layer directives (all modes) ─────────────
    $directives[] = 'DECISION LAYER: When recommending tools, approaches, or methods, answer three questions: (1) What should the reader choose? (2) Why this over alternatives? (3) When is this a bad idea?';
    $directives[] = 'Do not list options without a recommendation. Every recommendation must include a reason and a counter-case.';
    $directives[] = 'Avoid weak generic closings like "With practice and patience…", "By following these tips…", or "In conclusion…". End with a specific actionable takeaway or a strong opinion.';
    $directives[] = 'PATTERN LIMITER: Do not open more than 2 sections with the same rhetorical phrase. Vary your openers—acceptable patterns include: "A common mistake is…", "Beginners often overlook…", "Here\'s where things usually go wrong…", "The better approach is…", "What most guides skip is…". Never repeat the exact same opener in consecutive sections.';
    $directives[] = 'WHY-LAYER: For every named tool, version number, plugin, library, service, or method you recommend, include one sentence stating WHY it matters—specifically what breaks, slows down, or fails if you skip or ignore it. Version numbers or tool names stated without consequences provide no reader value.';
    $directives[] = 'TRADE-OFF MANDATE: Any named tool, plugin, service, or library recommendation MUST include: (a) its primary named alternative by name, (b) a one-sentence distinction of when each wins over the other, (c) a "skip both if…" case—use that exact phrase. Do not substitute "When is this a bad idea?" for the "skip both if" case—they are different questions. Never recommend a single tool in isolation without naming alternatives.';
    $directives[] = 'DECISION FRAME: Do not open scenario blocks with "Imagine you are…" or "Picture this…" unless the scenario immediately leads to a concrete decision or reveals a non-obvious insight the reader cannot reach from plain explanation. Replace flat scenario openers with direct decision frames: "If you are building X, do Y because Z."';
    $directives[] = 'SCOPE DISCIPLINE: Any named recommendation must state who it is for and who should skip it. Avoid universal claims like "must-have", "best for everyone", or "great choice" unless you immediately narrow the condition.';
    $directives[] = 'CATEGORY DISCIPLINE: Do not present different categories as interchangeable. If you name a tool, method, framework, service, plugin, theme, or platform, explain its role before comparing it to something else.';
    $directives[] = 'EVIDENCE DISCIPLINE: If the brief asks for research-backed, evidence-based, source-backed, or factual guidance, attribute material claims to a credible source or cite them inline instead of stating them as universal truth.';
    $directives[] = 'ANTI-LISTICLE DISCIPLINE: Do not fill a section with a spray of loosely justified recommendations. Each major section should make one primary recommendation, then defend it. Only mention secondary options when contrasting them directly against the primary choice.';
    $directives[] = 'NO EMPTY PRAISE: Avoid praise-only claims like "powerful", "flexible", "great choice", "robust", or "easy to use" unless the next sentence explains the practical consequence for the reader.';
    $directives[] = 'NO FENCE-SITTING BY TEMPLATE: Avoid weak fallback phrasing like "for advanced users, consider…", "another option is…", or "you may also want…" unless you immediately explain why that option wins for a specific reader condition.';

    // ─── Mode-specific directives ────────────────────────────────────
    switch ($mode) {
        case 'tutorial':
            $directives[] = 'CONTENT MODE: TUTORIAL. Structure this as a progressive walkthrough from setup to completion.';
            $directives[] = 'REQUIRED FIRST SECTION — POSITION CRITICAL: The very first section in body_html (before any <h2> body section) MUST be the "Quick Start (Do This First)" block. DO NOT place it at the end. Document structure must be: [intro paragraph if any] → [Quick Start h2] → [body section h2s in order]. If Quick Start appears after any body section, the output will be rejected.';
            $directives[] = 'QUICK START FORMAT: The Quick Start block must use the exact heading text "Quick Start (Do This First)" and contain a numbered <ol> of 4–7 concrete dependency-ordered steps. Each step must be specific and measurable (include temperatures, timing, quantities, or named items where applicable). Steps must be distinct from each other—do NOT include two steps that cover the same concept (e.g., do not have both "prepare a space" and "provide a comfortable environment" as separate steps). Do not use the same wording as the opening sentence of any body section. Steps appear exactly once inside the <ol> and nowhere else.';
            $directives[] = 'SECTION VARIETY: Each body section must open with a different rhetorical device. Do NOT use the same sentence structure across sections—specifically, the pattern "X requires Y. Use Z." is banned from appearing in more than one section. Vary your openers: lead with a surprising fact, a common failure, a decision frame, a specific number, or an expert observation.';
            $directives[] = 'MISTAKE CALLOUT VARIETY: Include one mistake callout per major section but VARY the phrasing across sections. Do NOT use the formula "Common mistake: Not [X] can lead to [Y]" in every section. Use different structures: a short warning sentence, a before/after contrast, a "most owners do X but the consequence is Y" frame, or a direct imperative. No two mistake callouts may start with the same phrase.';
            $directives[] = 'STRONG CLOSING REQUIRED: The article must end with the strong_closing from structural_contract rendered as a final paragraph or <blockquote>. It must be a specific actionable decision or expert opinion—not a generic summary like "follow these steps" or "prioritize proper care and attention".';
            $directives[] = 'At each major step, note what the user must decide and what factors affect that decision.';
            break;

        case 'opinion':
            $directives[] = 'CONTENT MODE: OPINION. Take a clear editorial position on the topic—do not sit on the fence.';
            $directives[] = 'Use authoritative framing: "Most beginners make the mistake of…", "The better approach is…", "Here\'s what actually matters…".';
            $directives[] = 'Include at least one contrarian or non-obvious insight that challenges common advice.';
            $directives[] = 'State your recommendation clearly and early, then spend the article defending it with evidence.';
            $directives[] = 'Acknowledge the strongest counter-argument and explain why your position still holds.';
            $directives[] = 'OPENING THESIS: In the first 2 paragraphs, state the core judgment in plain language. The reader should know your position before the article becomes analytical.';
            $directives[] = 'COUNTERARGUMENT DISCIPLINE: Include one section or paragraph that fairly states the strongest opposing view, then defeat it with a specific reason, trade-off, or real-world constraint.';
            $directives[] = 'WHO THIS IS FOR / NOT FOR: State at least one case where your recommendation is correct and one case where the reader should ignore it.';
            $directives[] = 'Do not hedge everything. Confident claims backed by reasoning are more useful than neutral summaries.';
            break;

        case 'comparison':
            $directives[] = 'CONTENT MODE: COMPARISON. Structure the content around direct comparisons of the main options or approaches.';
            $directives[] = 'Include at least one structured comparison block with clear pros, cons, and a "best for" recommendation for each option.';
            $directives[] = 'Use decision criteria relevant to the target audience (e.g., budget, skill level, time, scale).';
            $directives[] = 'Do not just list features—explain trade-offs and when each option wins or loses.';
            $directives[] = 'End each major comparison with a clear "Choose X if…, Choose Y if…" recommendation.';
            $directives[] = 'DECISION CRITERIA FIRST: Define 2-4 criteria before comparing options (for example: budget, maintenance, risk, speed, learning curve). Evaluate all main options against the same criteria.';
            $directives[] = 'NO TIE ENDINGS: The article must produce a final recommendation or ranking. "It depends" is acceptable only after naming the exact condition that changes the answer.';
            $directives[] = 'SKIP-BOTH CASE REQUIRED: Include one explicit "skip both if…" or "use an alternative approach if…" scenario when the compared options are wrong for the reader.';
            $directives[] = 'Include at least one "when to skip both" or "alternative approach" scenario.';
            break;

        case 'checklist':
            $directives[] = 'CONTENT MODE: CHECKLIST. Structure the entire article as an actionable, numbered checklist.';
            $directives[] = 'Each step must be concrete and completable—not abstract advice like "plan carefully".';
            $directives[] = 'Include common mistakes to avoid alongside each relevant step.';
            $directives[] = 'Order steps by dependency (what must come first) and priority (what matters most).';
            $directives[] = 'Include a "done" criteria for each step so the reader knows when to move on.';
            $directives[] = 'IMPERATIVE STEPS: Each checklist item must begin with a direct action verb (for example: Check, Prepare, Measure, Confirm, Remove, Compare, Write, Test).';
            $directives[] = 'DONE-CRITERIA VISIBILITY: Every major step must include a visible completion signal such as "Done when…", "Check that…", or "You can move on once…".';
            $directives[] = 'CHECKLIST ECONOMY: Keep each item compact, but add one short consequence or rationale when skipping the step creates a real failure mode.';
            $directives[] = 'Keep explanations tight—checklists should be scannable, not essay-length.';
            break;

        case 'expert':
            $directives[] = 'CONTENT MODE: EXPERT. Write with senior-practitioner judgment, not beginner-survey neutrality.';
            $directives[] = 'Take a defensible position, but keep it grounded in real constraints, trade-offs, and failure modes rather than hot takes.';
            $directives[] = 'Each major section should contain at least one expert distinction, hidden cost, or contrarian insight that basic guides usually miss.';
            $directives[] = 'Do not explain obvious basics at length. Prioritize decision quality, maintenance consequences, and what experienced operators watch for.';
            $directives[] = 'Name the default choice, the condition where it wins, and the narrow cases where an expert would break that default.';
            $directives[] = 'Close with a strong operating recommendation, not a neutral recap.';
            break;

        default: // standard
            $directives[] = 'CONTENT MODE: STANDARD. Balance depth with readability. Include at least one expert insight that goes beyond what any beginner guide covers.';
            $directives[] = 'Include at least one "most people get this wrong" observation to add editorial value.';
            $directives[] = 'OPENING VALUE: The first 2 paragraphs must establish a clear reader problem, a concrete takeaway, or a surprising insight—not generic scene-setting.';
            $directives[] = 'PRACTICAL DECISION SECTION: Include at least one section that tells the reader what to do, what to avoid, and why the trade-off matters.';
            $directives[] = 'EDITORIAL EDGE: Include at least one counterintuitive point, hidden cost, or expert distinction that basic search results usually miss.';
            break;
    }

    return $directives;
}

function cmsAiAutomationEvidenceChunks(string $bodyHtml): array
{
    if (trim($bodyHtml) === '') {
        return [];
    }

    $normalized = preg_replace('/<\/(?:p|li|blockquote|h[2-4]|div|section)>/i', "$0\n", $bodyHtml);
    $parts = preg_split('/\n+/u', (string)$normalized) ?: [];
    $chunks = [];

    foreach ($parts as $part) {
        $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$part)));
        if ($text === '') {
            continue;
        }

        $chunks[] = [
            'html' => (string)$part,
            'text' => $text,
        ];
    }

    return $chunks;
}

function cmsAiAutomationSampleClaimText(string $text): string
{
    $text = trim(preg_replace('/\s+/u', ' ', $text));
    return mb_strlen($text) > 180 ? (mb_substr($text, 0, 177) . '...') : $text;
}

function cmsAiAutomationUnsupportedAuthorityClaims(string $bodyHtml): array
{
    $chunks = cmsAiAutomationEvidenceChunks($bodyHtml);
    if ($chunks === []) {
        return [];
    }

    $pattern = '/\b(?:according to|as noted by|as reported by|a study by|studies by|research by|survey by|data from|figures from|case study by|report from|analysis by)\b/i';
    $samples = [];

    foreach ($chunks as $chunk) {
        $hasAuthorityFraming = preg_match($pattern, (string)($chunk['text'] ?? '')) === 1;
        $hasCitation = preg_match('/<a\b[^>]*href=/i', (string)($chunk['html'] ?? '')) === 1;
        if (!$hasAuthorityFraming || $hasCitation) {
            continue;
        }

        $sample = cmsAiAutomationSampleClaimText((string)($chunk['text'] ?? ''));
        if ($sample !== '') {
            $samples[$sample] = true;
        }
    }

    return array_slice(array_keys($samples), 0, 5);
}

function cmsAiAutomationUncitedQuantitativeClaims(string $bodyHtml): array
{
    $chunks = cmsAiAutomationEvidenceChunks($bodyHtml);
    if ($chunks === []) {
        return [];
    }

    $pattern = '/\b(?:\d+(?:\.\d+)?\s*%|\d+(?:\.\d+)?\s*percent|(?:one|two|three|four|five|six|seven|eight|nine|ten)\s+in\s+(?:\d+|one|two|three|four|five|six|seven|eight|nine|ten)|\d+\s+(?:out of|in)\s+\d+)\b/i';
    $samples = [];

    foreach ($chunks as $chunk) {
        $hasQuantitativeClaim = preg_match($pattern, (string)($chunk['text'] ?? '')) === 1;
        $hasCitation = preg_match('/<a\b[^>]*href=/i', (string)($chunk['html'] ?? '')) === 1;
        if (!$hasQuantitativeClaim || $hasCitation) {
            continue;
        }

        $sample = cmsAiAutomationSampleClaimText((string)($chunk['text'] ?? ''));
        if ($sample !== '') {
            $samples[$sample] = true;
        }
    }

    return array_slice(array_keys($samples), 0, 5);
}

function cmsAiAutomationUnsupportedConsensusClaims(string $bodyHtml): array
{
    $chunks = cmsAiAutomationEvidenceChunks($bodyHtml);
    if ($chunks === []) {
        return [];
    }

    $pattern = '/\b(?:widely\s+recommended|widely\s+accepted|generally\s+recommended|commonly\s+recommended|commonly\s+advised|best\s+practice|standard\s+practice|industry\s+standard|accepted\s+benchmark|gold\s+standard|experts\s+agree|commonly\s+understood)\b/i';
    $softGuidancePattern = '/\b(?:practical\s+starting\s+point|many\s+caregivers\s+begin|many\s+people\s+start|often\s+used\s+in\s+practice|common\s+starting\s+point)\b/i';
    $samples = [];

    foreach ($chunks as $chunk) {
        $text = (string)($chunk['text'] ?? '');
        $html = (string)($chunk['html'] ?? '');
        $hasConsensusFraming = preg_match($pattern, $text) === 1;
        $hasCitation = preg_match('/<a\b[^>]*href=/i', $html) === 1;
        $isSoftGuidance = preg_match($softGuidancePattern, $text) === 1;
        if (!$hasConsensusFraming || $hasCitation || $isSoftGuidance) {
            continue;
        }

        $sample = cmsAiAutomationSampleClaimText($text);
        if ($sample !== '') {
            $samples[$sample] = true;
        }
    }

    return array_slice(array_keys($samples), 0, 5);
}

function cmsAiAutomationUnsupportedComparativeClaims(string $bodyHtml): array
{
    $chunks = cmsAiAutomationEvidenceChunks($bodyHtml);
    if ($chunks === []) {
        return [];
    }

    $pattern = '/\b(?:better|best|worse|superior|inferior|faster|slower|safer|more\s+reliable|more\s+secure|less\s+secure|recommended\s+starting\s+point|recommended\s+option|recommended\s+alternative|stronger\s+choice|different\s+trade-?off|trade-?off\s+that\s+rarely\s+pays\s+off|out\s+of\s+the\s+box|paired\s+with|better\s+than|compared?\s+to|versus|vs\.?|choose\s+\S+\s+if|pick\s+\S+\s+if|skip\s+both\s+if)\b/i';
    $samples = [];

    foreach ($chunks as $chunk) {
        $text = (string)($chunk['text'] ?? '');
        $html = (string)($chunk['html'] ?? '');
        $hasComparativeClaim = preg_match($pattern, $text) === 1;
        $hasCitation = preg_match('/<a\b[^>]*href=/i', $html) === 1;
        if (!$hasComparativeClaim || $hasCitation) {
            continue;
        }

        $sample = cmsAiAutomationSampleClaimText($text);
        if ($sample !== '') {
            $samples[$sample] = true;
        }
    }

    return array_slice(array_keys($samples), 0, 5);
}

function cmsAiAutomationExpertConstraints(array $plan, string $sensitivity): array
{
    $domain = cmsAiAutomationTopicDomain((string)($plan['topic'] ?? ''));
    $constraints = [];

    $constraints[] = 'Include practical, actionable advice that goes beyond surface-level information.';
    $constraints[] = 'Avoid vague phrases like "high-quality", "essential", "very important", or "valuable" without concrete specifics.';
    $constraints[] = 'Include at least one common mistake or misconception to avoid.';
    $constraints[] = 'Every claim should be backed by a specific detail, number, range, or example.';

    // Only enforce real-world constraint scenarios for domains where they materially
    // change the advice (health, pet_care, child_care, safety). For general/tech/food
    // topics, forced constraint scenarios feel artificial.
    $scenarioRelevantDomains = ['health', 'pet_care', 'child_care', 'safety'];
    if (in_array($domain, $scenarioRelevantDomains, true)) {
        $constraints[] = 'Include at least one real-world constraint scenario and one actionable workaround (for example: limited supplies, no electricity, or delayed professional access).';
    }

    if ($sensitivity === 'elevated' || $sensitivity === 'high') {
        $constraints[] = 'Use precise figures, ranges, or timelines instead of qualitative language.';
        $constraints[] = 'Include a clear disclaimer recommending professional consultation where applicable.';
    }

    if ($sensitivity === 'high') {
        $constraints[] = 'Break down advice by stage, age group, or severity level where applicable—do NOT give one-size-fits-all advice.';
        $constraints[] = 'Include warning signs that require professional or emergency attention.';
        $constraints[] = 'Use conservative, qualified claims (e.g., "typically", "consult a vet/doctor") rather than absolute statements.';
        $constraints[] = 'Do NOT provide specific dosage or treatment instructions—direct readers to professionals instead.';
    }

    switch ($domain) {
        case 'health':
        case 'pet_care':
        case 'child_care':
            $constraints[] = 'Structure care advice by developmental stage or age bracket (e.g., 0-2 weeks, 2-4 weeks, etc.).';
            $constraints[] = 'Distinguish between normal behavior and signs that need professional attention.';
            $constraints[] = 'Include temperature, environment, and feeding technique specifics where relevant.';
            $constraints[] = 'Avoid overconfident precision: use qualified language (e.g., "typically", "start with", "adjust based on") and remind readers to follow product instructions where applicable.';
            $constraints[] = 'If the topic is about a newborn, first-week, or first-weeks stage, do NOT mix in later-stage guidance (for example: water dishes, weaning, or solid food) unless you clearly label the later age threshold and separate it from the immediate first-response actions.';
            break;
        case 'finance':
            $constraints[] = 'Acknowledge that financial situations vary and recommend consulting a professional advisor.';
            $constraints[] = 'Cite general principles rather than specific investment advice.';
            break;
        case 'food':
            $constraints[] = 'Include exact measurements, temperatures, and timing where relevant.';
            $constraints[] = 'Mention common substitutions and allergen notes.';
            break;
    }

    if (cmsAiAutomationNeedsLocalContext($plan)) {
        $constraints[] = 'This brief requests local context. Include at least 2 location-specific insights tied to local climate, infrastructure, or access constraints.';
        $constraints[] = 'Do not use location labels without substance. If a region is named, explain practical implications for care decisions.';
    }

    $criticalSteps = cmsAiAutomationCriticalStepsForPlan($plan);
    if ($criticalSteps !== []) {
        $stepNames = array_map(static fn(array $step): string => (string)($step['name'] ?? 'critical step'), $criticalSteps);
        $constraints[] = 'CRITICAL COMPLETENESS: cover these scenario-critical steps where applicable: ' . implode(', ', $stepNames) . '.';
        $constraints[] = 'Include at least one immediate do/avoid checklist for first-response actions in constrained real-world conditions.';
    }

    return $constraints;
}

function cmsAiAutomationHumanizationDirectives(array $plan): array
{
    $audience = trim((string)($plan['target_audience'] ?? ''));
    $directives = [];

    $directives[] = 'Write as if advising a real person, not producing an encyclopedia entry.';
    $directives[] = 'Include at least one real-world scenario or "what-if" example the reader can relate to.';
    $directives[] = 'Add context relevant to the reader\'s likely environment and practical constraints.';

    if ($audience !== '') {
        $directives[] = 'Tailor language, examples, and assumptions specifically to this audience: ' . $audience . '.';
    }

    return $directives;
}

function cmsAiAutomationHasRiskyPrescriptiveClaim(string $bodyText): bool
{
    if (trim($bodyText) === '') {
        return false;
    }

    $doseOrTreatmentPatterns = [
        '/\b(?:give|administer|dose|medicate|apply|take)\b.{0,60}\b\d+(?:\.\d+)?\s*(?:mg|g|grams?|ml|mL|drops?|tablets?|pills?|capsules?|teaspoons?|tablespoons?|cc)\b/i',
        '/\b(?:always|never|must)\s+(?:give|administer|dose|medicate|apply|take)\b/i',
    ];

    foreach ($doseOrTreatmentPatterns as $pattern) {
        if (preg_match($pattern, $bodyText) === 1) {
            return true;
        }
    }

    return false;
}

function cmsAiAutomationQualitySeverityMultiplier(string $severity): float
{
    return match (strtolower(trim($severity))) {
        'high' => 1.0,
        'medium' => 0.65,
        default => 0.35,
    };
}

function cmsAiAutomationClampScore(float $score): int
{
    return (int)max(0, min(100, round($score)));
}

function cmsAiAutomationQualityPenaltyMap(): array
{
    return [
        'critical_missing_step' => ['completeness' => 35, 'actionable_depth' => 25],
        'insufficient_depth' => ['completeness' => 20, 'actionable_depth' => 25],
        'weak_structure' => ['completeness' => 10],
        'risky_claim' => ['accuracy_signals' => 40],
        'missing_disclaimer' => ['accuracy_signals' => 20],
        'confidence_calibration' => ['accuracy_signals' => 20],
        'missing_attribution' => ['accuracy_signals' => 30],
        'sparse_attribution' => ['accuracy_signals' => 10],
        'research_without_citations' => ['accuracy_signals' => 30],
        'low_citation_density' => ['accuracy_signals' => 10],
        'unsupported_authority_framing' => ['accuracy_signals' => 25, 'realism' => 10],
        'uncited_quantitative_claim' => ['accuracy_signals' => 25],
        'unsupported_consensus_claim' => ['accuracy_signals' => 20, 'realism' => 20],
        'unsupported_comparative_claim' => ['accuracy_signals' => 25, 'realism' => 15],
        'repetition' => ['repetition' => 20],
        'repetition_pattern' => ['repetition' => 20],
        'vague_language' => ['repetition' => 10, 'realism' => 10],
        'low_value_pattern' => ['actionable_depth' => 10, 'repetition' => 10],
        'weak_closing' => ['actionable_depth' => 10, 'repetition' => 5],
        'audience_mismatch' => ['realism' => 30],
        'reality_gap' => ['realism' => 20, 'actionable_depth' => 10],
        'fake_specificity' => ['completeness' => 20, 'realism' => 20],
        'localization_gap' => ['completeness' => 20, 'realism' => 20],
        'overbroad_claim' => ['realism' => 15],
        'unsupported_praise' => ['realism' => 10],
    ];
}

function cmsAiAutomationQualityAssessment(array $generated, array $plan, array $qaWarnings): array
{
    $dimensions = [
        'completeness' => 100.0,
        'accuracy_signals' => 100.0,
        'actionable_depth' => 100.0,
        'repetition' => 100.0,
        'realism' => 100.0,
    ];
    $penaltyMap = cmsAiAutomationQualityPenaltyMap();
    $warningTypes = [];
    $highCount = 0;
    $mediumCount = 0;
    $lowCount = 0;
    $blockingReasons = [];

    foreach ($qaWarnings as $warning) {
        if (!is_array($warning)) {
            continue;
        }
        $type = trim((string)($warning['type'] ?? ''));
        $severity = trim((string)($warning['severity'] ?? 'low'));
        if ($type !== '') {
            $warningTypes[$type] = true;
        }
        switch ($severity) {
            case 'high':
                $highCount++;
                break;
            case 'medium':
                $mediumCount++;
                break;
            default:
                $lowCount++;
                break;
        }

        $multiplier = cmsAiAutomationQualitySeverityMultiplier($severity);
        foreach ($penaltyMap[$type] ?? [] as $dimension => $points) {
            $dimensions[$dimension] -= ($points * $multiplier);
        }
    }

    $bodyHtml = (string)($generated['body_html'] ?? '');
    $bodyText = trim(strip_tags($bodyHtml));
    $wordCount = str_word_count($bodyText);
    $headingCount = (int)preg_match_all('/<h[2-4]\b/i', $bodyHtml);
    $citations = is_array($generated['citations'] ?? null) ? $generated['citations'] : [];
    $searchSources = is_array($generated['search_sources'] ?? null) ? $generated['search_sources'] : [];
    $citationsCount = count($citations);
    $searchSourcesCount = count($searchSources);
    $sensitivity = cmsAiAutomationResolveSensitivity($plan);
    $contentMode = (string)($plan['content_mode'] ?? 'standard');
    $evidenceRequested = preg_match('/\b(?:research-?backed|evidence-?based|source-?backed|credible\s+sources?|cite|citations?|factual\s+claims)\b/i', (string)($plan['prompt_template'] ?? '')) === 1;

    if (!isset($warningTypes['critical_missing_step'])) {
        $dimensions['completeness'] += 5;
    }
    if ($wordCount >= 300 && $headingCount >= 2) {
        $dimensions['completeness'] += 3;
    }
    if (($sensitivity === 'high' && $wordCount >= 400) || ($sensitivity === 'elevated' && $wordCount >= 300)) {
        $dimensions['completeness'] += 5;
    }
    if ($searchSourcesCount > 0 && $citationsCount > 0) {
        $dimensions['accuracy_signals'] += 5;
    }
    if ($evidenceRequested) {
        $minimumCitations = $wordCount >= 900 ? 3 : ($wordCount >= 450 ? 2 : 1);
        if ($citationsCount >= $minimumCitations) {
            $dimensions['accuracy_signals'] += 8;
        }
    }
    if (($sensitivity === 'high' || $sensitivity === 'elevated')
        && !isset($warningTypes['missing_disclaimer'])
        && !isset($warningTypes['confidence_calibration'])) {
        $dimensions['accuracy_signals'] += 5;
    }
    if (($sensitivity === 'high' || $sensitivity === 'elevated') && cmsAiAutomationHasScenarioBlock($bodyText)) {
        $dimensions['actionable_depth'] += 8;
        $dimensions['realism'] += 5;
    }
    if ($headingCount >= 3 || preg_match('/<(?:ul|ol)\b/i', $bodyHtml) === 1) {
        $dimensions['actionable_depth'] += 6;
    }
    if (preg_match('/\b(?:limited\s+supplies|no\s+electricity|delayed\s+professional\s+access|budget|supply|cost|availability|power\s+outage)\b/i', $bodyText) === 1) {
        $dimensions['actionable_depth'] += 6;
        $dimensions['realism'] += 6;
    }
    if (!isset($warningTypes['repetition'])
        && !isset($warningTypes['repetition_pattern'])
        && !isset($warningTypes['vague_language'])
        && !isset($warningTypes['low_value_pattern'])) {
        $dimensions['repetition'] += 5;
    }

    foreach ($dimensions as $dimension => $score) {
        $dimensions[$dimension] = cmsAiAutomationClampScore($score);
    }

    $overall = cmsAiAutomationClampScore(
        ($dimensions['completeness'] * 0.25)
        + ($dimensions['accuracy_signals'] * 0.30)
        + ($dimensions['actionable_depth'] * 0.20)
        + ($dimensions['repetition'] * 0.10)
        + ($dimensions['realism'] * 0.15)
    );

    $trustCriticalTypes = [
        'risky_claim',
        'missing_attribution',
        'research_without_citations',
        'unsupported_authority_framing',
        'uncited_quantitative_claim',
        'missing_disclaimer',
        'unsupported_consensus_claim',
        'unsupported_comparative_claim',
    ];
    foreach ($qaWarnings as $warning) {
        if (!is_array($warning)) {
            continue;
        }
        $type = trim((string)($warning['type'] ?? ''));
        $severity = trim((string)($warning['severity'] ?? 'low'));
        if ($severity === 'high' && in_array($type, $trustCriticalTypes, true)) {
            $blockingReasons[] = (string)($warning['message'] ?? $type);
        }
    }
    if ($overall < 60) {
        $blockingReasons[] = 'Overall quality score is below the approval threshold.';
    }
    if ($dimensions['accuracy_signals'] < 60) {
        $blockingReasons[] = 'Accuracy signals score is below the approval threshold.';
    }
    if (in_array($contentMode, ['opinion', 'comparison'], true) && $citationsCount === 0) {
        $blockingReasons[] = 'Opinion and comparison pieces require inline citations before they can be auto-approved or auto-published.';
    }
    $blockingReasons = array_values(array_slice(array_unique(array_filter($blockingReasons)), 0, 5));

    $approvalConfidence = 'low';
    if ($blockingReasons !== []) {
        $approvalConfidence = 'blocked';
    } elseif ($highCount > 0 || $overall < 75) {
        $approvalConfidence = 'low';
    } elseif ($overall >= 85
        && $dimensions['accuracy_signals'] >= 85
        && $dimensions['completeness'] >= 80
        && $dimensions['realism'] >= 75) {
        $approvalConfidence = 'high';
    } else {
        $approvalConfidence = 'medium';
    }

    $approvalRecommendation = 'editor_review';
    if ($approvalConfidence === 'blocked') {
        $approvalRecommendation = 'manual_review_required';
    } elseif ($approvalConfidence === 'high') {
        $approvalRecommendation = (in_array($sensitivity, ['high', 'elevated'], true) || in_array($contentMode, ['opinion', 'comparison'], true))
            ? 'ready_for_approval'
            : 'auto_publish_candidate';
    }

    return [
        'version' => 2,
        'dimensions' => $dimensions,
        'overall' => $overall,
        'approval_confidence' => $approvalConfidence,
        'approval_recommendation' => $approvalRecommendation,
        'blocking_reasons' => $blockingReasons,
        'summary' => [
            'high_warning_count' => $highCount,
            'medium_warning_count' => $mediumCount,
            'low_warning_count' => $lowCount,
            'citations_count' => $citationsCount,
            'search_sources_count' => $searchSourcesCount,
            'word_count' => $wordCount,
        ],
    ];
}

function cmsAiAutomationQualityCheck(array $generated, array $plan): array
{
    $warnings = [];
    $bodyHtml = (string)($generated['body_html'] ?? '');
    $body = strip_tags($bodyHtml);
    $bodyLower = mb_strtolower($body);

    // 1. Vague phrase detection
    $vaguePatterns = [
        'high-quality', 'high quality', 'very important', 'extremely important',
        'it is essential', 'this is essential', 'play a vital role', 'plays a vital role',
        'this is crucial', 'it is crucial', 'invaluable resource', 'indispensable',
        'a must-have', 'game-changer', 'unlock the secrets', 'hidden gems',
        'take it to the next level', 'in today\'s world', 'in this day and age',
        'look no further', 'without further ado', 'majestic appearance',
        'beloved companion', 'furry friend', 'best friend', 'trusted companion',
    ];
    $vagueFound = [];
    foreach ($vaguePatterns as $phrase) {
        if (str_contains($bodyLower, $phrase)) {
            $vagueFound[] = $phrase;
        }
    }
    if ($vagueFound !== []) {
        $warnings[] = [
            'type' => 'vague_language',
            'severity' => count($vagueFound) >= 3 ? 'high' : 'medium',
            'message' => 'Contains vague filler phrases: "' . implode('", "', array_slice($vagueFound, 0, 5)) . '".',
        ];
    }

    // 1b. Low-value pattern detection
    $lowValuePatterns = [
        'did you know',
        'by following these guidelines',
        'in conclusion',
        'to sum up',
        'overall,',
    ];
    $lowValueFound = [];
    foreach ($lowValuePatterns as $pattern) {
        if (str_contains($bodyLower, $pattern)) {
            $lowValueFound[] = $pattern;
        }
    }
    if ($lowValueFound !== []) {
        $warnings[] = [
            'type' => 'low_value_pattern',
            'severity' => 'medium',
            'message' => 'Contains low-value phrasing: "' . implode('", "', array_slice($lowValueFound, 0, 4)) . '".',
        ];
    }

    // 2. Audience-topic mismatch
    $audienceMismatch = cmsAiAutomationDetectAudienceTopicMismatch($plan);
    if ($audienceMismatch !== null) {
        $warnings[] = [
            'type' => 'audience_mismatch',
            'severity' => 'high',
            'message' => $audienceMismatch,
        ];
    }

    // 3. Risky claims detection (prescriptive dosing/treatment claims in sensitive topics)
    $sensitivity = cmsAiAutomationResolveSensitivity($plan);
    if ($sensitivity === 'high' || $sensitivity === 'elevated') {
        if (cmsAiAutomationHasRiskyPrescriptiveClaim($body)) {
            $warnings[] = [
                'type' => 'risky_claim',
                'severity' => 'high',
                'message' => 'Contains prescriptive dosing or treatment claims in a sensitive topic. Verify accuracy before publishing.',
            ];
        }

        // Missing disclaimer check
        $disclaimerPhrases = ['consult', 'professional', 'veterinar', 'doctor', 'medical advice', 'seek advice', 'talk to your', 'speak with', 'healthcare', 'specialist'];
        $hasDisclaimer = false;
        foreach ($disclaimerPhrases as $dp) {
            if (str_contains($bodyLower, $dp)) {
                $hasDisclaimer = true;
                break;
            }
        }
        if (!$hasDisclaimer) {
            $warnings[] = [
                'type' => 'missing_disclaimer',
                'severity' => 'high',
                'message' => 'High-sensitivity content lacks a professional consultation disclaimer.',
            ];
        }

        // Confidence calibration check for quantified instructions in sensitive content
        $hasQuantifiedInstruction = preg_match('/\b(?:feed|give|administer)\b.{0,80}\b\d+(?:\.\d+)?\s*(?:%|ml|mg|g|grams?|times?)\b/i', $body) === 1;
        if ($hasQuantifiedInstruction && !cmsAiAutomationHasConfidenceCalibration($body)) {
            $warnings[] = [
                'type' => 'confidence_calibration',
                'severity' => 'high',
                'message' => 'Quantified sensitive-care guidance lacks uncertainty framing or product-instruction caveat.',
            ];
        }
    }

    // 4. Reality-layer scenario check
    if (($sensitivity === 'high' || $sensitivity === 'elevated') && !cmsAiAutomationHasScenarioBlock($body)) {
        $warnings[] = [
            'type' => 'reality_gap',
            'severity' => 'medium',
            'message' => 'Sensitive content lacks a practical scenario block (e.g., first-response steps when key resources are limited).',
        ];
    }

    // 5. Anti-fake-specificity local context check
    $needsLocalContext = cmsAiAutomationNeedsLocalContext($plan);
    if ($needsLocalContext) {
        $localInsightCount = cmsAiAutomationCountLocalInsights($body);
        if ($localInsightCount < 2) {
            $warnings[] = [
                'type' => 'fake_specificity',
                'severity' => 'high',
                'message' => sprintf('Local context was requested but only %d location-specific insight(s) were detected. Add at least 2 concrete local realities.', $localInsightCount),
            ];
        }
    }

    // 5b. Adaptive localization completeness
    if ($needsLocalContext) {
        $localRealityScore = cmsAiAutomationLocalizationRealityScore($body);
        if ($localRealityScore < 2) {
            $warnings[] = [
                'type' => 'localization_gap',
                'severity' => 'high',
                'message' => sprintf('Local context requested but only %d operational-localization signal(s) detected (need at least 2 across climate, infrastructure, service access, or supply/cost constraints).', $localRealityScore),
            ];
        }
    }

    // 5c. Adaptive critical-step completeness
    $missingCriticalSteps = cmsAiAutomationMissingCriticalSteps($body, cmsAiAutomationCriticalStepsForPlan($plan));
    if ($missingCriticalSteps !== []) {
        $warnings[] = [
            'type' => 'critical_missing_step',
            'severity' => 'high',
            'message' => 'Missing critical scenario steps: ' . implode(', ', array_slice($missingCriticalSteps, 0, 4)) . '.',
        ];
    }

    // 6. Depth check
    $wordCount = str_word_count($body);
    if ($sensitivity === 'high' && $wordCount < 400) {
        $warnings[] = [
            'type' => 'insufficient_depth',
            'severity' => 'medium',
            'message' => sprintf('Article is only %d words for a high-sensitivity topic. May lack necessary detail.', $wordCount),
        ];
    }

    // 7. Structure check
    $headingCount = (int)preg_match_all('/<h[2-4]/i', $bodyHtml);
    if ($wordCount > 300 && $headingCount < 2) {
        $warnings[] = [
            'type' => 'weak_structure',
            'severity' => 'low',
            'message' => 'Long article has few subheadings. May lack organized structure.',
        ];
    }

    // 8. Repetitive paragraph starts
    $paragraphs = preg_split('/<\/p>\s*<p/i', $bodyHtml);
    if (is_array($paragraphs) && count($paragraphs) >= 4) {
        $starts = [];
        foreach ($paragraphs as $p) {
            $text = trim(strip_tags($p));
            if ($text === '') {
                continue;
            }
            $firstWords = implode(' ', array_slice(str_word_count($text, 1) ?: [], 0, 3));
            $starts[] = mb_strtolower($firstWords);
        }
        $counted = array_count_values(array_filter($starts));
        foreach ($counted as $phrase => $count) {
            if ($count >= 3) {
                $warnings[] = [
                    'type' => 'repetition',
                    'severity' => 'low',
                    'message' => sprintf('Multiple paragraphs start with similar phrasing: "%s" (%d times).', $phrase, $count),
                ];
                break;
            }
        }
    }

    // 9. Repeated sentence stem detection
    $repeatedStem = cmsAiAutomationFindRepeatedSentenceStem($body);
    if (is_array($repeatedStem)) {
        $warnings[] = [
            'type' => 'repetition_pattern',
            'severity' => $repeatedStem['count'] >= 3 ? 'medium' : 'low',
            'message' => sprintf('Repeated sentence pattern detected: "%s" (%d times).', $repeatedStem['stem'], (int)$repeatedStem['count']),
        ];
    }

    // 10. Search grounding attribution check
    $searchSources = $generated['search_sources'] ?? [];
    $citations = $generated['citations'] ?? [];
    if ($searchSources !== []) {
        if ($citations === []) {
            $warnings[] = [
                'type'     => 'missing_attribution',
                'severity' => 'high',
                'message'  => sprintf(
                    '%d web source(s) were pre-fetched for grounding but no citations were found in the body. Add inline links to cited sources.',
                    count($searchSources)
                ),
            ];
        } elseif (count($citations) < min(2, count($searchSources))) {
            $warnings[] = [
                'type'     => 'sparse_attribution',
                'severity' => 'medium',
                'message'  => sprintf(
                    'Only %d citation(s) found from %d available source(s). Consider citing more sources to improve credibility.',
                    count($citations),
                    count($searchSources)
                ),
            ];
        }
    }

    $promptTemplateLower = mb_strtolower((string)($plan['prompt_template'] ?? ''));
    $evidenceRequested = preg_match('/\b(?:research-?backed|evidence-?based|source-?backed|credible\s+sources?|cite|citations?|factual\s+claims)\b/i', $promptTemplateLower) === 1;
    if ($evidenceRequested && $citations === []) {
        $warnings[] = [
            'type' => 'research_without_citations',
            'severity' => 'high',
            'message' => 'The brief requests research-backed or evidence-based writing, but no citations were found in the article. Attribute factual claims to sources or add inline citations.',
        ];
    }
    if ($evidenceRequested && $citations !== []) {
        $minimumCitations = $wordCount >= 900 ? 3 : ($wordCount >= 450 ? 2 : 1);
        if (count($citations) < $minimumCitations) {
            $warnings[] = [
                'type' => 'low_citation_density',
                'severity' => 'medium',
                'message' => sprintf('Research-backed brief produced only %d citation(s) for a %d-word article. Add more attribution so factual claims are supported throughout the piece.', count($citations), $wordCount),
            ];
        }
    }

    if ($evidenceRequested || $searchSources !== [] || $citations !== []) {
        $unsupportedAuthorityClaims = cmsAiAutomationUnsupportedAuthorityClaims($bodyHtml);
        if ($unsupportedAuthorityClaims !== []) {
            $warnings[] = [
                'type' => 'unsupported_authority_framing',
                'severity' => 'high',
                'message' => 'Authority-style claim framing was found without inline citation links in the same paragraph (for example: "' . implode('", "', array_slice($unsupportedAuthorityClaims, 0, 3)) . '"). Remove synthetic authority phrasing or add real citations directly where those claims appear.',
            ];
        }

        $uncitedQuantitativeClaims = cmsAiAutomationUncitedQuantitativeClaims($bodyHtml);
        if ($uncitedQuantitativeClaims !== []) {
            $warnings[] = [
                'type' => 'uncited_quantitative_claim',
                'severity' => 'high',
                'message' => 'Quantitative or statistical claims were found without inline citation links in the same paragraph (for example: "' . implode('", "', array_slice($uncitedQuantitativeClaims, 0, 3)) . '"). Percentages and ratio-style claims must be cited or rewritten more conservatively.',
            ];
        }
    }

    $unsupportedConsensusClaims = cmsAiAutomationUnsupportedConsensusClaims($bodyHtml);
    if ($unsupportedConsensusClaims !== []) {
        $warnings[] = [
            'type' => 'unsupported_consensus_claim',
            'severity' => 'high',
            'message' => 'Consensus or benchmark language was used without an inline citation link in the same paragraph (for example: "' . implode('", "', array_slice($unsupportedConsensusClaims, 0, 3)) . '"). Remove unsupported consensus framing or add a real citation directly where the claim appears.',
        ];
    }

    $unsupportedComparativeClaims = cmsAiAutomationUnsupportedComparativeClaims($bodyHtml);
    if ($unsupportedComparativeClaims !== []) {
        $warnings[] = [
            'type' => 'unsupported_comparative_claim',
            'severity' => 'high',
            'message' => 'Comparative or recommendation-style claims were used without an inline citation link in the same paragraph (for example: "' . implode('", "', array_slice($unsupportedComparativeClaims, 0, 3)) . '"). Vendor comparisons, ranking language, and recommendation claims must be cited or rewritten as narrower conditional guidance.',
        ];
    }

    // 11. Generic closing detection (weak endings)
    $genericClosings = [
        'with practice and patience',
        'by following these tips',
        'by following these guidelines',
        'by following these steps',
        'with the right approach',
        'with dedication and hard work',
        'the possibilities are endless',
        'the sky is the limit',
        'you\'re well on your way',
        'you are well on your way',
        'happy learning',
        'happy coding',
        'good luck on your journey',
    ];
    foreach ($genericClosings as $closing) {
        if (str_contains($bodyLower, $closing)) {
            $warnings[] = [
                'type' => 'weak_closing',
                'severity' => 'medium',
                'message' => sprintf('Generic closing detected: "%s". Replace with a specific actionable takeaway or strong opinion.', $closing),
            ];
            break;
        }
    }

    // 11b. Over-broad universal claim detection
    $hasUniversalClaim = preg_match('/\b(?:must-?have|everyone\s+should\s+use|best\s+(?:choice|option|tool|plugin|theme|service)\s+for\s+(?:everyone|anyone)|great\s+choice\s+for\s+(?:everyone|anyone|all\s+users)|perfect\s+for\s+(?:everyone|anyone)|essential\s+for\s+every\w*|for\s+any\s+(?:site|project|business|team|user))\b/i', $body) === 1;
    if ($hasUniversalClaim) {
        $warnings[] = [
            'type' => 'overbroad_claim',
            'severity' => 'medium',
            'message' => 'Over-broad claim detected (for example: "must-have", "everyone should use", "great choice for everyone"). Narrow recommendations to specific reader conditions instead of universal advice.',
        ];
    }

    // 11c. Unsupported praise / marketing-language detection
    $praiseCount = (int)preg_match_all('/\b(?:powerful|flexible|robust|seamless|user-friendly|easy\s+to\s+use|great\s+choice|excellent\s+choice|ideal\s+for|perfect\s+for|best-in-class|feature-rich)\b/i', $body);
    if ($praiseCount >= 3) {
        $hasPracticalJustification = preg_match('/\b(?:because|which\s+means|this\s+matters\s+because|so\s+that|lets\s+you|allows\s+you\s+to|saves\s+you|reduces|prevents|avoids|works\s+best\s+when)\b/i', $body) === 1;
        if (!$hasPracticalJustification) {
            $warnings[] = [
                'type' => 'unsupported_praise',
                'severity' => 'medium',
                'message' => sprintf('Marketing-style praise language detected %d times (for example: "powerful", "flexible", "great choice") without enough practical justification. Replace praise with concrete consequences, constraints, or user outcomes.', $praiseCount),
            ];
        }
    }

    // 12. Content mode compliance checks
    $contentMode = (string)($plan['content_mode'] ?? 'standard');
    if ($contentMode === 'tutorial') {
        // Check for numbered steps
        $hasNumberedSteps = preg_match('/(?:<ol|<li>\s*(?:Step\s+)?\d)/i', $bodyHtml) === 1;
        if (!$hasNumberedSteps) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Tutorial mode: no numbered steps or ordered list detected. Tutorials should include a step-by-step guide.',
            ];
        }
    }

    if ($contentMode === 'comparison') {
        // Check for comparison structure (vs, compared to, pros/cons, option A/B)
        $hasComparison = preg_match('/\b(?:vs\.?|versus|compared?\s+to|pros?\s+(?:and|&)\s+cons?|trade-?offs?|choose\s+\S+\s+if|option\s+[ab12]|free\s+vs|alternative)\b/i', $body) === 1;
        if (!$hasComparison) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Comparison mode: no comparison structure detected (e.g., "vs.", "pros and cons", "choose X if"). Content should compare alternatives directly.',
            ];
        }

        $hasFinalVerdict = preg_match('/\b(?:overall\s+winner|best\s+choice|final\s+verdict|choose\s+\S+\s+if|pick\s+\S+\s+if|skip\s+both\s+if)\b/i', $body) === 1;
        if (!$hasFinalVerdict) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Comparison mode: missing a final verdict or explicit decision rule (for example: "Choose X if…", "Skip both if…"). Comparisons should close with a clear recommendation.',
            ];
        }
    }

    if ($contentMode === 'opinion') {
        // Check for opinionated language
        $hasOpinion = preg_match('/\b(?:most\s+(?:beginners|people|developers)\s+(?:make|get|miss)|the\s+better\s+approach|here\'?s?\s+what\s+(?:actually|really)\s+matters|the\s+real\s+(?:issue|problem|question)|(?:I|we)\s+recommend|the\s+(?:best|worst)\s+(?:choice|option|approach))\b/i', $body) === 1;
        if (!$hasOpinion) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Opinion mode: no opinionated framing detected. Content should take a clear editorial position with authoritative language.',
            ];
        }

        $hasCounterargument = preg_match('/\b(?:counter-?argument|the\s+objection|you\s+could\s+argue|the\s+other\s+side|however[,\s]+the\s+strongest\s+case|critics\s+would\s+say)\b/i', $body) === 1;
        if (!$hasCounterargument) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Opinion mode: missing a clear counter-argument. Strong opinion pieces should acknowledge the best opposing case and explain why it still loses.',
            ];
        }

        $hedgingCount = (int)preg_match_all('/\b(?:it\s+depends|in\s+some\s+cases|may\s+be\s+(?:better|useful|helpful)|might\s+be\s+(?:better|useful|helpful)|could\s+be\s+(?:better|useful|helpful)|another\s+option\s+is|for\s+advanced\s+users,?\s+consider|you\s+may\s+(?:want|prefer|choose)\s+to\s+use|consider(?:\s+using)?\s+\S+)/i', $body);
        if ($hedgingCount >= 4) {
            $warnings[] = [
                'type' => 'opinion_hedging',
                'severity' => 'medium',
                'message' => sprintf('Opinion mode: detected %d hedge or fallback phrases (for example: "it depends", "another option is", "for advanced users, consider"). Opinion pieces should defend a position, not drift into neutral option lists.', $hedgingCount),
            ];
        }
    }

    if ($contentMode === 'expert') {
        $hasExpertJudgment = preg_match('/\b(?:default\s+(?:choice|setup|path)|hidden\s+cost|maintenance\s+(?:cost|burden|overhead)|the\s+safer\s+default|experienced\s+(?:teams|operators|developers|editors)|in\s+practice|what\s+breaks\s+later|the\s+real\s+cost|the\s+operational\s+risk)\b/i', $body) === 1;
        if (!$hasExpertJudgment) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Expert mode: missing practitioner-level judgment cues such as default choice logic, hidden costs, maintenance consequences, or operational risk framing.',
            ];
        }

        $hasExpertDeviation = preg_match('/\b(?:unless\s+you\s+(?:need|have|run|manage)|break\s+that\s+default|deviate\s+from\s+that|the\s+exception\s+is|only\s+if\s+you\s+(?:need|have|run|manage)|narrow\s+case|expert\s+would\s+choose)\b/i', $body) === 1;
        if (!$hasExpertDeviation) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Expert mode: missing the narrow case where the default recommendation should be overridden. Expert writing should explain when to break the rule, not just state the rule.',
            ];
        }
    }

    if ($contentMode === 'checklist') {
        // Check for checklist structure
        $listItemCount = (int)preg_match_all('/<li/i', $bodyHtml);
        if ($listItemCount < 3) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => sprintf('Checklist mode: only %d list items detected. Checklists should have at least 3 actionable steps.', $listItemCount),
            ];
        }

        $hasDoneCriteria = preg_match('/\b(?:done\s+when|check\s+that|you\s+can\s+move\s+on\s+once|confirm\s+that|verify\s+that)\b/i', $body) === 1;
        if (!$hasDoneCriteria) {
            $warnings[] = [
                'type' => 'mode_compliance',
                'severity' => 'medium',
                'message' => 'Checklist mode: no visible done-criteria detected (for example: "Done when…", "Check that…"). Readers need a clear signal for when each step is complete.',
            ];
        }
    }

    // 13. Decision-layer check (all modes): detect pure listing without recommendations
    $hasDecisionLanguage = preg_match('/\b(?:recommend|choose\s+\S+\s+(?:if|when|because)|avoid\s+\S+\s+(?:if|when|unless)|(?:best|better)\s+(?:for|when|if)|(?:don\'t|do\s+not)\s+use\s+\S+\s+(?:if|when|unless)|instead\s+of|rather\s+than|a\s+bad\s+idea\s+(?:if|when))\b/i', $body) === 1;
    if (!$hasDecisionLanguage && $wordCount > 200) {
        $warnings[] = [
            'type' => 'missing_decisions',
            'severity' => 'low',
            'message' => 'Content lists options but lacks decision guidance (e.g., "choose X if…", "avoid Y when…"). Add recommendations to help readers decide.',
        ];
    }

    // 14. Phrase repetition: same rhetorical opener used 3+ times
    $rhetoricalPatterns = [
        'most people get this wrong',
        'a common mistake',
        'beginners often',
        'here\'s where .{0,25} (?:go|goes|went) wrong',
        'the better approach',
        "here's what (?:actually|really) matters",
        'what most (?:guides|tutorials|people) skip',
        'most (?:beginners|developers|people) (?:make|get|miss)',
    ];
    foreach ($rhetoricalPatterns as $rxPattern) {
        $count = (int)preg_match_all('/' . $rxPattern . '/i', $bodyLower);
        if ($count >= 3) {
            $warnings[] = [
                'type' => 'phrase_repetition',
                'severity' => 'medium',
                'message' => sprintf('Rhetorical opener used %d times: "%s". Use varied openers—"A common mistake is…", "Beginners often overlook…", "The better approach is…"—no single pattern more than twice.', $count, $rxPattern),
            ];
            break; // one warning is enough; reviewer understands the issue
        }
    }

    // 15. Missing "why" layer: named things mentioned but no explanatory connectives
    $namedThingCount = (int)preg_match_all('/\b(?:PHP|MySQL|WordPress|Apache|Nginx|Node\.?js|React|Vue|Laravel|jQuery|Bootstrap|Composer|npm|Yarn|Git|Docker|Redis|Tailwind|Next\.?js|Nuxt|v\d+\.\d+|\d+\.\d+\+)\b/i', $body);
    if ($namedThingCount >= 5) {
        $hasBecauseConnective = preg_match('/\b(?:because|which\s+means|this\s+matters\s+because|the\s+reason\s+is|this\s+is\s+why|that\'?s\s+why|so\s+that|in\s+order\s+to\s+(?:avoid|prevent|ensure))\b/i', $body) === 1;
        if (!$hasBecauseConnective) {
            $warnings[] = [
                'type' => 'missing_why',
                'severity' => 'medium',
                'message' => sprintf('%d named tools/versions detected but no explanatory connectives ("because", "which means", "this matters because") found. Every recommendation should explain why it matters.', $namedThingCount),
            ];
        }
    }

    // 16. No trade-off language despite multiple named tools
    $toolMentionCount = (int)preg_match_all('/\b(?:plugin|library|framework|package|tool|service|platform|provider|extension|module|theme|CMS|CDN)\b/i', $body);
    if ($toolMentionCount >= 3) {
        $hasTradeoff = preg_match('/\b(?:vs\.?|versus|compared?\s+to|alternative(?:s)?|instead\s+of|rather\s+than|on\s+the\s+other\s+hand|better\s+for|worse\s+for|not\s+ideal\s+for|downside|trade-?off)\b/i', $body) === 1;
        if (!$hasTradeoff) {
            $warnings[] = [
                'type' => 'no_tradeoff_for_tools',
                'severity' => 'low',
                'message' => sprintf('%d tool/plugin/service mentions found but no trade-off language ("vs.", "alternative", "instead of"). Add comparison framing so readers can decide.', $toolMentionCount),
            ];
        }

        $hasScopingLanguage = preg_match('/\b(?:for\s+(?:beginners|advanced\s+users|small\s+teams|large\s+teams|small\s+sites|large\s+sites|simple\s+projects|complex\s+projects|personal\s+use|enterprise\s+use)|if\s+you\s+(?:need|want|have|are|run|manage|prefer|value)|works\s+best\s+(?:for|when)|not\s+(?:ideal|best)\s+for|skip\s+(?:this|it|both)\s+if|avoid\s+\S+\s+if|choose\s+\S+\s+if|use\s+\S+\s+if)\b/i', $body) === 1;
        if (!$hasScopingLanguage) {
            $warnings[] = [
                'type' => 'unscoped_recommendations',
                'severity' => 'medium',
                'message' => sprintf('%d named tool/plugin/service mentions found but little reader-scoping language (for example: "use X if…", "not ideal for…", "works best when…"). Tie recommendations to specific conditions.', $toolMentionCount),
            ];
        }

        $recommendationSprayCount = (int)preg_match_all('/\b(?:consider(?:\s+using)?|you\s+can\s+use|another\s+option\s+is|alternatively|for\s+advanced\s+users,?\s+consider|for\s+beginners,?\s+consider)\b/i', $body);
        if ($recommendationSprayCount >= 4 && !$hasTradeoff) {
            $warnings[] = [
                'type' => 'recommendation_spray',
                'severity' => 'medium',
                'message' => sprintf('Recommendation spray detected: %d fallback or "consider X" phrases without enough direct comparison. Reduce option-dumping and defend one primary recommendation per section.', $recommendationSprayCount),
            ];
        }

        $majorSections = preg_split('/<h2[^>]*>/i', $bodyHtml);
        if (is_array($majorSections) && count($majorSections) > 1) {
            $spraySections = 0;
            foreach ($majorSections as $sectionHtml) {
                $sectionText = trim(strip_tags($sectionHtml));
                if ($sectionText === '') {
                    continue;
                }
                $sectionSprayCount = (int)preg_match_all('/\b(?:consider(?:\s+using)?|you\s+can\s+use|another\s+option\s+is|alternatively|for\s+advanced\s+users,?\s+consider|for\s+beginners,?\s+consider)\b/i', $sectionText);
                $sectionHasTradeoff = preg_match('/\b(?:vs\.?|versus|compared?\s+to|alternative(?:s)?|instead\s+of|rather\s+than|better\s+for|worse\s+for|not\s+ideal\s+for|downside|trade-?off|choose\s+\S+\s+if|skip\s+both\s+if)\b/i', $sectionText) === 1;
                if ($sectionSprayCount >= 2 && !$sectionHasTradeoff) {
                    $spraySections++;
                }
            }
            if ($spraySections >= 2) {
                $warnings[] = [
                    'type' => 'section_recommendation_spray',
                    'severity' => 'medium',
                    'message' => sprintf('%d major section(s) contain multiple fallback recommendations without clear comparison framing. Keep each section centered on one defended recommendation.', $spraySections),
                ];
            }
        }
    }

    // 17. Tutorial missing quick-start section in first half of body
    if ((string)($plan['content_mode'] ?? 'standard') === 'tutorial') {
        // Check for a Quick Start heading in the first 40% of the HTML body
        $bodyLen = mb_strlen($bodyHtml);
        $firstHalf = mb_substr($bodyHtml, 0, (int)($bodyLen * 0.4));
        $hasQuickStart = preg_match('/<h[23][^>]*>\s*(?:quick\s*start|do\s+this\s+first|getting\s+started\s+fast|minimum\s+viable)\b/i', $firstHalf) === 1;
        if (!$hasQuickStart) {
            $warnings[] = [
                'type' => 'tutorial_no_quickstart',
                'severity' => 'medium',
                'message' => 'Tutorial mode: no "Quick Start" heading found in the first 40% of the article. Tutorials should open with a minimum-viable numbered path so readers can act immediately.',
            ];
        }
    }

    // 18. Forbidden structural labels rendered in output
    $forbiddenLabels = [
        'why it matters',
        'alternative',
        'why choose this over alternatives',
        'when is this a bad idea',
        'strong closing',
    ];
    foreach ($forbiddenLabels as $label) {
        if (preg_match('/\b' . preg_quote($label, '/') . '\b\s*:?/i', $body) === 1) {
            $warnings[] = [
                'type' => 'structural_label_rendered',
                'severity' => 'medium',
                'message' => sprintf('Draft rendered a structural guidance label as visible copy: "%s". These fields must be integrated into prose, not printed as headings or prefixes.', $label),
            ];
            break;
        }
    }

    // 19. Missing explicit skip-both-if trade-off framing
    $hasAlternativeLanguage = preg_match('/\b(?:alternative|instead\s+of|rather\s+than|however,?\s+if|if\s+you\s+(?:cannot|can\'t|do\s+not\s+have|don\'t\s+have|are\s+unable))\b/i', $body) === 1;
    $hasSkipBothIf = preg_match('/\bskip\s+both\s+if\b/i', $body) === 1;
    if ($hasAlternativeLanguage && !$hasSkipBothIf) {
        $warnings[] = [
            'type' => 'missing_skip_both_if',
            'severity' => 'medium',
            'message' => 'Alternative or fallback framing is present, but the required "skip both if" trade-off case is missing. Add a clear scenario where the reader should avoid both options.',
        ];
    }

    // 20. Stage-mixing check for newborn / first-week care topics
    $topicLower = mb_strtolower((string)($plan['topic'] ?? ''));
    $isNewbornTopic = preg_match('/\b(?:newborn|first\s+week|first\s+weeks|0\s*-\s*2\s+weeks?)\b/i', $topicLower) === 1;
    $hasLaterStageGuidance = preg_match('/\b(?:wean(?:ing)?|solid\s+food|shallow\s+dish\s+of\s+water|drink\s+from\s+a\s+dish)\b/i', $body) === 1;
    if ($isNewbornTopic && $hasLaterStageGuidance) {
        $warnings[] = [
            'type' => 'stage_mixing',
            'severity' => 'high',
            'message' => 'Newborn/first-week topic includes later-stage guidance (e.g., water dish, weaning, or solid food). Separate later milestones from immediate newborn actions and label the age threshold clearly.',
        ];
    }

    return $warnings;
}

// ─── Outline Pass (Pass 1 of 2-pass generation) ─────────────────────────────

function cmsAiAutomationBuildOutlinePrompt(array $plan, array $groundingSources): array
{
    $topic = trim((string)($plan['topic'] ?? ''));
    $mode = (string)($plan['content_mode'] ?? 'standard');
    $audience = trim((string)($plan['target_audience'] ?? ''));
    $style = trim((string)($plan['writing_style'] ?? ''));
    $keywords = is_array($plan['keywords'] ?? null) ? $plan['keywords'] : [];
    $recent = cmsAiAutomationRecentContext((int)($plan['id'] ?? 0));
    $recentTitles = array_values(array_filter(array_map(static fn(array $item) => trim((string)($item['title'] ?? '')), $recent)));

    $sectionSchema = [
        'heading' => 'The exact section heading to use in the article',
        'key_point' => 'The one specific claim or takeaway this section must establish',
        'recommendation' => 'The specific thing to recommend—named, not generic (e.g., plugin name, step, method)',
        'alternative' => 'Primary alternative + one sentence: when to choose it over the recommendation',
        'why_it_matters' => 'One sentence: what breaks, slows, or fails if the reader ignores this section',
        'common_mistake' => 'The one mistake beginners most often make at this stage (optional)',
    ];

    $outputSchema = [
        'title' => 'Proposed article title—specific, non-generic, audience-aware',
        'hook' => 'The specific tension, mistake, or insight that opens the article and creates curiosity',
        'sections' => [$sectionSchema],
        'strong_closing' => 'A specific actionable takeaway or strong opinion to end the article—not a generic summary',
    ];

    if ($mode === 'tutorial') {
        $outputSchema['quick_start_steps'] = [
            'description' => 'Ordered list of 4-7 concrete steps: the minimum viable path. Each step 3-15 words, in dependency order.',
            'type' => 'array of strings',
        ];
    }

    $instruction = [
        'task' => 'generate_content_outline',
        'topic' => $topic,
        'content_mode' => $mode,
        'target_audience' => $audience,
        'writing_style' => $style,
        'keywords' => $keywords,
        'constraints' => [
            'Return valid JSON only matching output_schema.',
            'No prose, no markdown, no explanation—only structured JSON.',
            'Each section heading must be unique and specific to the topic.',
            'Each recommendation must name a specific tool, step, or approach—not a generic category.',
            'Each named recommendation must include the reader condition where it wins and who should skip it.',
            'Do not repeat the same rhetorical device across multiple key_point or hook fields.',
            'strong_closing must be a specific decision or action, not a generic encouragement.',
            'Do not use universal claims like "must-have" or "best for everyone" unless the condition is narrowed in the same section.',
        ],
        'recent_titles_to_avoid' => $recentTitles,
        'output_schema' => $outputSchema,
    ];

    switch ($mode) {
        case 'opinion':
            $instruction['constraints'][] = 'Outline must include an early thesis section, one contrarian or non-obvious section, and one explicit counter-argument section.';
            $instruction['constraints'][] = 'strong_closing must end with a concrete recommendation or hard judgment, not a balanced summary.';
            break;
        case 'expert':
            $instruction['constraints'][] = 'Outline must include at least one section focused on hidden costs, maintenance consequences, or expert-only distinctions.';
            $instruction['constraints'][] = 'Every major section should name a default recommendation and the narrow condition where an expert would deviate from it.';
            $instruction['constraints'][] = 'strong_closing must end with an operating recommendation, not a neutral summary.';
            break;
        case 'comparison':
            $instruction['constraints'][] = 'Outline must compare at least 2 named options across shared decision criteria and include a final verdict section.';
            $instruction['constraints'][] = 'At least one section must cover a "skip both if" or alternative-approach scenario.';
            break;
        case 'checklist':
            $instruction['constraints'][] = 'Outline sections should map to checklist phases or high-priority steps, not generic themes.';
            $instruction['constraints'][] = 'Each section heading should imply a concrete action or checkpoint rather than an abstract topic label.';
            break;
        default:
            $instruction['constraints'][] = 'Outline should include at least one section that captures an expert distinction, hidden cost, or counterintuitive insight.';
            break;
    }

    if ($groundingSources !== []) {
        $sourceContext = [];
        foreach ($groundingSources as $i => $src) {
            $sourceContext[] = [
                'index'   => $i + 1,
                'title'   => (string)($src['title'] ?? ''),
                'url'     => (string)($src['url'] ?? ''),
                'excerpt' => (string)($src['snippet'] ?? ''),
            ];
        }
        $instruction['source_context'] = $sourceContext;
        $instruction['constraints'][] = 'You may incorporate insights from source_context into section key_points.';
    }

    $system = 'You are a content architect. Your only job is to produce an article outline—no prose, no paragraphs, only structure. The outline will be handed to a separate writer. Return valid JSON only.';

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode($instruction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    ];
}

function cmsAiAutomationGenerateOutline(array $plan, array $groundingSources): array
{
    $messages = cmsAiAutomationBuildOutlinePrompt($plan, $groundingSources);

    try {
        $response = app()->cap()->call('ai.text.generate@1', [
            'messages'   => $messages,
            'temperature' => 0.4,
            'json'       => true,
            'timeout_ms' => 20000,
            'max_tokens' => 1200,
            'preferred_tier' => cmsAiAutomationPreferredTier($plan, 'outline', $groundingSources),
        ], ['caller_module' => 'cms', 'timeout_ms' => 20000]);
    } catch (\Throwable $e) {
        write_log('cms ai outline pass: exception — ' . $e->getMessage(), 'warning', ['topic' => (string)($plan['topic'] ?? '')]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }

    if (empty($response['ok'])) {
        write_log('cms ai outline pass: provider error — ' . (string)($response['error'] ?? 'unknown'), 'warning', ['topic' => (string)($plan['topic'] ?? '')]);
        return ['ok' => false, 'error' => (string)($response['error'] ?? 'outline provider error')];
    }

    $content = trim((string)($response['content'] ?? ''));
    // Strip markdown code fences if present
    if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $content, $m)) {
        $content = trim($m[1]);
    }
    $decoded = json_decode($content, true);
    if (!is_array($decoded) || empty($decoded['sections'])) {
        write_log('cms ai outline pass: invalid JSON or empty sections', 'warning', ['topic' => (string)($plan['topic'] ?? ''), 'raw' => mb_substr($content, 0, 300)]);
        return ['ok' => false, 'error' => 'outline returned invalid structure'];
    }

    write_log('cms ai outline pass: ok — ' . count($decoded['sections']) . ' section(s)', 'info', [
        'plan_id' => (int)($plan['id'] ?? 0),
        'topic'   => (string)($plan['topic'] ?? ''),
    ]);

    return ['ok' => true, 'outline' => $decoded];
}

// ─── Prompt Builder ─────────────────────────────────────────────────────────

function cmsAiAutomationBuildPrompt(array $plan, array $recentContext = [], array $groundingSources = [], array $outline = []): array
{
    $topic = trim((string)($plan['topic'] ?? ''));
    $contentType = trim((string)($plan['content_type'] ?? 'post')) ?: 'post';
    $keywords = is_array($plan['keywords'] ?? null) ? $plan['keywords'] : [];
    $visualMode = (string)($plan['visual_mode'] ?? 'suggest_media');
    $summaryEnabled = !empty($plan['summary_enabled']);
    $seoEnabled = !empty($plan['seo_enabled']);
    $promptTemplate = strtr((string)($plan['prompt_template'] ?? ''), [
        '{topic}' => $topic,
        '{content_type}' => $contentType,
        '{writing_style}' => (string)($plan['writing_style'] ?? ''),
        '{target_audience}' => (string)($plan['target_audience'] ?? ''),
        '{keywords}' => implode(', ', $keywords),
    ]);

    $schema = [
        'type' => 'object',
        'required' => ['title', 'body_html'],
        'properties' => [
            'title' => ['type' => 'string'],
            'body_html' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'seo_title' => ['type' => 'string'],
            'seo_description' => ['type' => 'string'],
            'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
            'visual_queries' => ['type' => 'array', 'items' => ['type' => 'string']],
            'citations' => [
                'type' => 'array',
                'description' => 'Sources cited in the body, each with a title and url.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'title' => ['type' => 'string'],
                        'url'   => ['type' => 'string'],
                    ],
                ],
            ],
        ],
    ];

    $recentTitles = array_values(array_filter(array_map(static fn(array $item) => trim((string)($item['title'] ?? '')), $recentContext)));

    // ─── Content Intelligence Layer ─────────────────────────────────
    $sensitivity = cmsAiAutomationResolveSensitivity($plan);
    $expertConstraints = cmsAiAutomationExpertConstraints($plan, $sensitivity);
    $humanization = cmsAiAutomationHumanizationDirectives($plan);
    $contentModeDirectives = cmsAiAutomationContentModeDirectives($plan);
    $expertMode = $sensitivity === 'high' || $sensitivity === 'elevated';
    $contentMode = (string)($plan['content_mode'] ?? 'standard');

    $requirements = [
        'Return valid JSON only.',
        'Body must be clean, semantic HTML suitable for a CMS article body. Use <h2>, <h3>, <p>, <ul>, <ol>, <li>, <strong>, <em>, <a>, <blockquote> tags. Do NOT use markdown syntax like **bold**, *italic*, # headings, or - lists. Every piece of formatting must use HTML tags.',
        'Do NOT wrap the JSON in markdown code fences (```). Return raw JSON only.',
        'Do NOT include <html>, <head>, or <body> wrapper tags.',
        'Content must be unique in framing, examples, and section structure compared to recent generated items.',
        'Do not mention that the content was AI-generated.',
        'Remove low-value filler patterns such as "Did you know" and generic closings like "By following these guidelines" unless they add concrete actionable value.',
        'Do not write like a listicle that keeps spraying options with phrases like "consider X", "another option is", or "for advanced users, consider". Prefer one defended recommendation per section unless the section is explicitly a comparison.',
        'Do not use praise-only product language. If you call something powerful, flexible, easy, robust, or ideal, immediately explain the practical consequence for the reader.',
        'If the article is opinionated, defend a position instead of repeatedly falling back to neutral hedges like "it depends" or "another option is". Counter-cases are allowed, but they must strengthen the argument rather than replace it.',
        'Do not use synthetic authority framing such as "according to", "a study found", "research shows", or "data from" unless the same paragraph includes a real inline citation link. If you cannot cite it, rewrite the claim without fake authority language.',
        'Do not state percentages, ratio statistics, or survey-style quantitative claims unless the same paragraph includes a supporting inline citation link. If the claim cannot be cited, rewrite it as a conservative qualitative observation or remove it.',
        'Do not use consensus framing such as "widely recommended", "best practice", "standard practice", "accepted benchmark", or "experts agree" unless the same paragraph includes a real inline citation link.',
        'If a consensus-style claim cannot be cited, rewrite it as cautious operational guidance grounded in observation rather than borrowed authority.',
        'Do not make vendor, product, or alternative-comparison claims such as "better", "superior", "recommended starting point", "choose X if", or "skip both" unless the same paragraph includes a real inline citation link. Otherwise, rewrite them as narrower conditional guidance tied to a clearly stated use case.',
        'In sensitive topics, prefer language like "typically", "common starting point", "often used in practice", or "verify with a local professional" over unsupported universal claims.',
    ];

    // ─── Search grounding citation requirements ──────────────────────
    if ($groundingSources !== []) {
        $requirements[] = 'SOURCE-GROUNDED CONTENT: you have been provided with pre-retrieved web sources in source_context. Use them to inform factual claims and cite them inline using HTML anchor tags: <a href="URL" rel="nofollow noopener noreferrer" target="_blank">Source Name</a>. Cite at least 2 sources if they are relevant.';
        $requirements[] = 'Do not cite sources that are not listed in source_context. Do not fabricate URLs.';
        $requirements[] = 'If you make multiple factual or comparative claims across the article, distribute citations across the body instead of attaching a single source at the end. Longer research-backed pieces should cite multiple sections, not just one paragraph.';
    }

    $requirements = array_merge($requirements, $contentModeDirectives, $expertConstraints, $humanization);

    $instruction = [
        'task' => 'generate_cms_content',
        'topic' => $topic,
        'content_type' => $contentType,
        'content_mode' => $contentMode,
        'writing_style' => (string)($plan['writing_style'] ?? ''),
        'target_audience' => (string)($plan['target_audience'] ?? ''),
        'keywords' => $keywords,
        'content_sensitivity' => $sensitivity,
        'topic_domain' => cmsAiAutomationTopicDomain($topic),
        'requirements' => $requirements,
        'feature_flags' => [
            'summary_enabled' => $summaryEnabled,
            'seo_enabled' => $seoEnabled,
            'visual_mode' => $visualMode,
            'expert_mode' => $expertMode,
        ],
        'recent_titles_to_avoid' => $recentTitles,
        'prompt_template' => $promptTemplate,
        'output_schema' => $schema,
    ];

    // ─── Structural contract from outline pass ───────────────────────
    if (!empty($outline['sections'])) {
        $sectionCount = count($outline['sections']);
        $instruction['requirements'][] = 'STRUCTURAL CONTRACT: An outline has been pre-planned for you in structural_contract. Follow the section headings in order. For each section, address its key_point and include its recommendation with trade-off framing (including the named alternative and a "skip both if…" case). You may add examples, prose, and depth—but do not omit or reorder sections, and do not substitute vague headings for the specific ones provided.';
        $instruction['requirements'][] = 'COMPLETENESS REQUIREMENT: Your body_html MUST contain all ' . $sectionCount . ' sections listed in structural_contract.sections, each rendered as a full <h2> heading followed by at least 2 substantive paragraphs of body prose. The Quick Start block is the OPENING section—it must come BEFORE all body <h2> sections, not after them. It does NOT count toward the ' . $sectionCount . ' required body sections. Output order: intro → Quick Start → body sections.';
        $instruction['requirements'][] = 'PROSE INTEGRATION: The fields in structural_contract.sections (why_it_matters, recommendation, alternative, common_mistake) are WRITING GUIDES for you, not output labels. NEVER render them as visible headings, bold prefixes, or labeled bullets in your HTML. Do NOT output any of the following strings literally in your content: "Why it matters:", "Alternative:", "Why choose this over alternatives?:", "When is this a bad idea?", "Strong closing:". Weave the substance of each field into natural editorial prose. Trade-off framing must use the phrase "skip both if" and must read as a confident written argument—not a filled-in template. Each section must read like expert writing, not a structured report.';
        $instruction['requirements'][] = 'SECTION OPENING VARIETY: Do NOT open two or more body sections with the same sentence structure. The pattern "[Topic] requires [generic noun]. Use [thing]." is banned from being repeated. Each section must begin differently—vary with specific data, a failure example, a decision frame, or a direct expert claim.';
        $instruction['structural_contract'] = [
            'title_suggestion'   => (string)($outline['title'] ?? ''),
            'hook'               => (string)($outline['hook'] ?? ''),
            'quick_start_steps'  => is_array($outline['quick_start_steps'] ?? null) ? $outline['quick_start_steps'] : [],
            'sections'           => array_map(static function (array $s): array {
                return [
                    'heading'        => (string)($s['heading'] ?? ''),
                    'key_point'      => (string)($s['key_point'] ?? ''),
                    'recommendation' => (string)($s['recommendation'] ?? ''),
                    'alternative'    => (string)($s['alternative'] ?? ''),
                    'why_it_matters' => (string)($s['why_it_matters'] ?? ''),
                    'common_mistake' => (string)($s['common_mistake'] ?? ''),
                ];
            }, $outline['sections']),
            'strong_closing'     => (string)($outline['strong_closing'] ?? ''),
        ];
    }

    // ─── Inject grounding context ────────────────────────────────────
    if ($groundingSources !== []) {
        $sourceContext = [];
        foreach ($groundingSources as $i => $src) {
            $sourceContext[] = [
                'index'   => $i + 1,
                'title'   => (string)($src['title'] ?? ''),
                'url'     => (string)($src['url'] ?? ''),
                'excerpt' => (string)($src['snippet'] ?? ''),
            ];
        }
        $instruction['source_context'] = $sourceContext;
    }

    $systemParts = [
        'You are a domain-aware editorial assistant for a CMS.',
        'Your goal is to produce content that a human expert in the topic would find useful, specific, and trustworthy.',
        'Never produce generic filler. Every paragraph must earn its place with concrete detail.',
        'Return only valid JSON matching the requested schema.',
    ];

    // Content mode system-level framing
    switch ($contentMode) {
        case 'tutorial':
            $systemParts[] = 'CONTENT MODE: TUTORIAL. You are writing a hands-on guide. Prioritize actionable steps, decision points, and practical completeness over theory. CRITICAL STRUCTURE: body_html must open with the Quick Start section FIRST, then body sections in order. Quick Start at the end is wrong and will be rejected.';
            break;
        case 'opinion':
            $systemParts[] = 'CONTENT MODE: OPINION. You are writing an expert editorial. Prioritize strong positions, evidence-backed arguments, and non-obvious insights over balanced neutrality.';
            break;
        case 'comparison':
            $systemParts[] = 'CONTENT MODE: COMPARISON. You are writing a decision-support article. Prioritize structured trade-offs, clear recommendations, and contextual "choose X if" guidance.';
            break;
        case 'checklist':
            $systemParts[] = 'CONTENT MODE: CHECKLIST. You are writing an actionable checklist. Prioritize concrete completable steps, dependency order, and done-criteria over lengthy explanations.';
            break;
        default:
            $systemParts[] = 'CONTENT MODE: STANDARD. Balance depth with readability. Ensure every section adds editorial value beyond what a surface-level search would reveal.';
            break;
    }

    if ($sensitivity === 'high') {
        $systemParts[] = 'CONTENT SENSITIVITY: HIGH. Be precise, include stage-by-stage breakdowns where applicable, use qualified language, and always recommend professional guidance for critical decisions. Do not give one-size-fits-all advice for topics that require age/stage/severity distinctions.';
    } elseif ($sensitivity === 'elevated') {
        $systemParts[] = 'CONTENT SENSITIVITY: ELEVATED. Use precise language, cite general principles rather than specific advice, and include appropriate disclaimers.';
    }

    if ($expertMode) {
        $systemParts[] = 'EXPERT MODE: ON. Prefer precise timelines, clearly scoped claims, explicit caveats, and practical decision paths over generic advice.';
        $systemParts[] = 'When using numbers for sensitive guidance, calibrate confidence: use qualified phrasing and include instruction-label caveats where relevant.';
    }

    if (cmsAiAutomationNeedsLocalContext($plan)) {
        $systemParts[] = 'LOCAL CONTEXT REQUIRED: include at least 2 concrete location-specific realities and explain how they change practical recommendations.';
    }

    if ($groundingSources !== []) {
        $systemParts[] = 'GROUNDED GENERATION: real web sources have been pre-fetched and are provided in the source_context field. Base factual claims on those sources and cite them as inline HTML links. Do not invent sources or URLs not present in source_context.';
    }

    $system = implode(' ', $systemParts);

    return [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user', 'content' => json_encode($instruction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
    ];
}

function cmsAiAutomationParseResponse(string $content): ?array
{
    // Strip markdown code fences if the AI wrapped the JSON in them
    $content = trim($content);
    if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $content, $m)) {
        $content = trim($m[1]);
    }

    $decoded = json_decode($content, true);
    if (!is_array($decoded)) {
        return null;
    }

    $title = trim((string)($decoded['title'] ?? ''));
    $bodyHtml = trim((string)($decoded['body_html'] ?? ''));
    if ($title === '' || $bodyHtml === '') {
        return null;
    }

    $summary = trim((string)($decoded['summary'] ?? ''));
    $seoTitle = trim((string)($decoded['seo_title'] ?? ''));
    $seoDescription = trim((string)($decoded['seo_description'] ?? ''));
    $tags = is_array($decoded['tags'] ?? null) ? array_values(array_slice(array_filter(array_map('strval', $decoded['tags'])), 0, 8)) : [];
    $visualQueries = is_array($decoded['visual_queries'] ?? null) ? array_values(array_slice(array_filter(array_map('strval', $decoded['visual_queries'])), 0, 5)) : [];

    // Extract citations declared by the AI (best-effort; HTML extraction is authoritative)
    $rawCitations = is_array($decoded['citations'] ?? null) ? $decoded['citations'] : [];
    $parsedCitations = [];
    foreach ($rawCitations as $c) {
        if (!is_array($c)) {
            continue;
        }
        $cUrl = trim((string)($c['url'] ?? ''));
        if (!preg_match('#^https?://#i', $cUrl)) {
            continue;
        }
        $cHost = (string)parse_url($cUrl, PHP_URL_HOST);
        if ($cHost === '' || (function_exists('cmsAiAutomationIsPrivateHost') && cmsAiAutomationIsPrivateHost($cHost))) {
            continue;
        }
        $parsedCitations[] = [
            'title' => mb_substr(trim((string)($c['title'] ?? $cHost)), 0, 200),
            'url'   => $cUrl,
        ];
    }

    return [
        'title' => $title,
        'body_html' => $bodyHtml,
        'summary' => $summary,
        'seo_title' => $seoTitle,
        'seo_description' => $seoDescription,
        'tags' => $tags,
        'visual_queries' => $visualQueries,
        'citations_declared' => $parsedCitations,
    ];
}

/**
 * Convert residual markdown formatting in AI-generated body to HTML.
 * Handles the common case where the AI returns HTML mixed with markdown.
 */
function cmsAiAutomationMarkdownToHtml(string $html): string
{
    // Convert markdown headings that aren't already inside HTML tags
    $html = preg_replace('/^#{4}\s+(.+)$/m', '<h4>$1</h4>', $html);
    $html = preg_replace('/^#{3}\s+(.+)$/m', '<h3>$1</h3>', $html);
    $html = preg_replace('/^#{2}\s+(.+)$/m', '<h2>$1</h2>', $html);
    $html = preg_replace('/^#{1}\s+(.+)$/m', '<h2>$1</h2>', $html);

    // Bold: **text** or __text__ → <strong>text</strong> (but not inside HTML tags)
    $html = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
    $html = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $html);

    // Italic: *text* or _text_ → <em>text</em> (avoid matching inside URLs/attributes)
    $html = preg_replace('/(?<![<\w\/])(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)(?![>\w])/', '<em>$1</em>', $html);

    // Unordered list blocks: consecutive lines starting with - or *
    $html = preg_replace_callback('/(?:^[\t ]*[-*]\s+.+$\n?)+/m', static function (array $m): string {
        $items = preg_split('/\n/', trim($m[0]));
        $lis = '';
        foreach ($items as $item) {
            $text = preg_replace('/^[\t ]*[-*]\s+/', '', $item);
            $lis .= '<li>' . trim($text) . '</li>';
        }
        return '<ul>' . $lis . '</ul>';
    }, $html);

    // Ordered list blocks: consecutive lines starting with 1. 2. etc.
    $html = preg_replace_callback('/(?:^[\t ]*\d+\.\s+.+$\n?)+/m', static function (array $m): string {
        $items = preg_split('/\n/', trim($m[0]));
        $lis = '';
        foreach ($items as $item) {
            $text = preg_replace('/^[\t ]*\d+\.\s+/', '', $item);
            $lis .= '<li>' . trim($text) . '</li>';
        }
        return '<ol>' . $lis . '</ol>';
    }, $html);

    // Wrap orphaned text blocks in <p> tags
    $lines = preg_split('/\n{2,}/', $html);
    $result = [];
    $blockTags = 'h[1-6]|p|ul|ol|blockquote|div|table|figure|hr|pre';
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if ($trimmed === '') continue;
        if (preg_match('/^<(?:' . $blockTags . ')[\s>]/i', $trimmed)) {
            $result[] = $trimmed;
        } else {
            $result[] = '<p>' . $trimmed . '</p>';
        }
    }

    return implode("\n", $result);
}

function cmsAiAutomationFindSuggestedMedia(array $plan, array $generated): array
{
    if (($plan['visual_mode'] ?? 'suggest_media') !== 'suggest_media') {
        return [];
    }

    $terms = [];
    foreach (array_merge([$plan['topic'] ?? ''], $generated['visual_queries'] ?? [], $plan['keywords'] ?? []) as $term) {
        $term = trim((string)$term);
        if ($term === '') {
            continue;
        }
        foreach (preg_split('/\s+/', mb_strtolower($term)) ?: [] as $chunk) {
            $chunk = trim($chunk);
            if ($chunk !== '' && mb_strlen($chunk) >= 3) {
                $terms[$chunk] = true;
            }
        }
    }

    $terms = array_slice(array_keys($terms), 0, 6);
    if ($terms === []) {
        return [];
    }

    $clauses = [];
    $bind = [];
    foreach ($terms as $index => $term) {
        $like = '%' . $term . '%';
        $clauses[] = "(LOWER(COALESCE(title, '')) LIKE :t{$index} OR LOWER(original_name) LIKE :n{$index} OR LOWER(COALESCE(alt_text, '')) LIKE :a{$index})";
        $bind[":t{$index}"] = $like;
        $bind[":n{$index}"] = $like;
        $bind[":a{$index}"] = $like;
    }

        $sql = "SELECT id, filename, original_name, file_path, alt_text, title
            FROM cms_media
            WHERE mime_type LIKE 'image/%' AND (" . implode(' OR ', $clauses) . ")
            ORDER BY created_at DESC
            LIMIT 5";
    $stmt = cmsDb()->prepare($sql);
    $stmt->execute($bind);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    return array_map(static function (array $row): array {
        return [
            'id' => (int)($row['id'] ?? 0),
            'title' => (string)($row['title'] ?? $row['original_name'] ?? ''),
            'alt_text' => (string)($row['alt_text'] ?? ''),
            'url' => cmsUploadsUrl((string)($row['file_path'] ?? '')),
            'file_path' => (string)($row['file_path'] ?? ''),
        ];
    }, $rows);
}

function cmsAiAutomationAuthorId(array $plan): int
{
    $createdBy = (int)($plan['created_by'] ?? 0);
    if ($createdBy > 0) {
        return $createdBy;
    }

    $stmt = cmsDb()->query("SELECT id FROM cms_users WHERE is_active = 1 AND role IN ('administrator','editor','author') ORDER BY FIELD(role,'administrator','editor','author'), id ASC LIMIT 1");
    $authorId = (int)($stmt ? $stmt->fetchColumn() : 0);
    return $authorId > 0 ? $authorId : 1;
}

function cmsAiAutomationSaveContentMeta(object $db, int $contentId, array $meta): void
{
    foreach ($meta as $key => $value) {
        $key = trim((string)$key);
        if ($key === '') {
            continue;
        }

        $db->prepare(
            "INSERT INTO cms_content_meta (content_id, meta_key, meta_value) VALUES (:cid, :key, :value)
             ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)"
        )->execute([
            ':cid' => $contentId,
            ':key' => $key,
            ':value' => is_scalar($value) || $value === null ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}

function cmsAiAutomationLoadContentMeta(object $db, int $contentId): array
{
    $stmt = $db->prepare('SELECT meta_key, meta_value FROM cms_content_meta WHERE content_id = :cid');
    $stmt->execute([':cid' => $contentId]);
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    $meta = [];
    foreach ($rows as $row) {
        $meta[(string)$row['meta_key']] = (string)$row['meta_value'];
    }
    return $meta;
}

function cmsAiAutomationLoadContentRecord(int $contentId): ?array
{
    if ($contentId <= 0) {
        return null;
    }

    $stmt = cmsDb()->prepare('SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $contentId]);
    $row = $stmt->fetch(\PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function cmsAiAutomationDecodeMetaJson(array $meta, string $key): array
{
    $raw = trim((string)($meta[$key] ?? ''));
    if ($raw === '') {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function cmsAiAutomationContentStateFromMeta(array $meta): array
{
    $qaWarnings = cmsAiAutomationDecodeMetaJson($meta, '_ai_qa_warnings');
    $critiqueHistory = cmsAiAutomationDecodeMetaJson($meta, '_ai_critique_history');
    $refineHistory = cmsAiAutomationDecodeMetaJson($meta, '_ai_refine_history');
    $citations = cmsAiAutomationDecodeMetaJson($meta, '_ai_citations');
    $qualityAssessment = cmsAiAutomationDecodeMetaJson($meta, '_ai_quality_assessment');

    $autoRefineCount = count(array_filter($critiqueHistory, static function ($entry): bool {
        return is_array($entry) && str_starts_with((string)($entry['source'] ?? ''), 'auto_');
    }));
    $hasHighWarnings = count(array_filter($qaWarnings, static function ($warning): bool {
        return is_array($warning) && (string)($warning['severity'] ?? '') === 'high';
    })) > 0;

    return [
        'enabled' => ($meta['_ai_generated'] ?? '') === '1',
        'topic' => (string)($meta['_ai_topic'] ?? ''),
        'content_mode' => (string)($meta['_ai_content_mode'] ?? 'standard'),
        'plan_id' => (int)($meta['_ai_plan_id'] ?? 0),
        'run_id' => (int)($meta['_ai_run_id'] ?? 0),
        'desired_publish_at' => trim((string)($meta['_ai_desired_publish_at'] ?? '')),
        'latest_critique' => trim((string)($meta['_ai_latest_critique'] ?? '')),
        'last_refined_at' => trim((string)($meta['_ai_last_refined_at'] ?? '')),
        'refine_attempt_count' => max(0, (int)($meta['_ai_refine_attempt_count'] ?? 0)),
        'last_refine_run_id' => (int)($meta['_ai_last_refine_run_id'] ?? 0),
        'qa_warnings' => $qaWarnings,
        'critique_history' => $critiqueHistory,
        'refine_history' => $refineHistory,
        'citations' => $citations,
        'quality_assessment' => $qualityAssessment,
        'quality_score' => (int)($qualityAssessment['overall'] ?? 0),
        'approval_confidence' => (string)($qualityAssessment['approval_confidence'] ?? ''),
        'approval_recommendation' => (string)($qualityAssessment['approval_recommendation'] ?? ''),
        'blocking_reasons' => is_array($qualityAssessment['blocking_reasons'] ?? null) ? $qualityAssessment['blocking_reasons'] : [],
        'auto_refine_count' => $autoRefineCount,
        'auto_refine_unresolved' => $autoRefineCount > 0 && $hasHighWarnings,
    ];
}

function cmsAiAutomationAppendHistoryEntry(array $meta, string $key, array $entry, int $limit = 10): array
{
    $history = cmsAiAutomationDecodeMetaJson($meta, $key);
    $history[] = $entry;
    if (count($history) > $limit) {
        $history = array_slice($history, -$limit);
    }
    return $history;
}

function cmsAiAutomationQaPlanContext(array $meta, ?array $plan, ?array $content = null): array
{
    if (is_array($plan)) {
        return $plan;
    }

    return [
        'id' => (int)($meta['_ai_plan_id'] ?? 0),
        'topic' => (string)($meta['_ai_topic'] ?? ($content['title'] ?? '')),
        'content_mode' => (string)($meta['_ai_content_mode'] ?? 'standard'),
        'prompt_template' => '',
        'target_audience' => '',
        'keywords' => [],
    ];
}

function cmsAiAutomationRecomputeContentSignals(array $content, array $meta): array
{
    $planId = (int)($meta['_ai_plan_id'] ?? 0);
    $plan = $planId > 0 ? cmsAiAutomationGetPlan($planId) : null;
    $qaPlan = cmsAiAutomationQaPlanContext($meta, $plan, $content);
    $bodyHtml = (string)($content['body'] ?? '');
    $searchSources = cmsAiAutomationDecodeMetaJson($meta, '_ai_search_sources');
    $citations = cmsAiAutomationExtractCitationsFromHtml($bodyHtml);
    $qaWarnings = cmsAiAutomationQualityCheck([
        'body_html' => $bodyHtml,
        'citations' => $citations,
        'search_sources' => $searchSources,
    ], $qaPlan);
    $qualityAssessment = cmsAiAutomationQualityAssessment([
        'body_html' => $bodyHtml,
        'citations' => $citations,
        'search_sources' => $searchSources,
    ], $qaPlan, $qaWarnings);

    return [
        'plan' => $qaPlan,
        'citations' => $citations,
        'qa_warnings' => $qaWarnings,
        'quality_assessment' => $qualityAssessment,
        'search_sources' => $searchSources,
    ];
}

function cmsAiAutomationHasHighSeverityWarnings(array $qaWarnings): bool
{
    foreach ($qaWarnings as $warning) {
        if (is_array($warning) && (string)($warning['severity'] ?? '') === 'high') {
            return true;
        }
    }

    return false;
}

function cmsAiAutomationShouldAttemptAutoRefine(array $plan, array $qaWarnings): bool
{
    $policy = (string)($plan['auto_refine_policy'] ?? 'high_severity_once');

    return match ($policy) {
        'always_once' => true,
        'high_severity_once' => cmsAiAutomationHasHighSeverityWarnings($qaWarnings),
        default => false,
    };
}

function cmsAiAutomationBuildAutoQaRetryFeedback(array $plan, array $qaWarnings): string
{
    $highSeverityMessages = [];
    foreach ($qaWarnings as $warning) {
        if (!is_array($warning) || (string)($warning['severity'] ?? '') !== 'high') {
            continue;
        }
        $message = trim((string)($warning['message'] ?? ''));
        if ($message !== '') {
            $highSeverityMessages[] = '- ' . $message;
        }
    }

    $feedback = [
        'Automated QA retry. Rewrite this draft to resolve the high-severity quality warnings below while preserving the article’s structure, core recommendations, and any real citations already present.',
        'Priorities:',
    ];

    if ($highSeverityMessages !== []) {
        $feedback = array_merge($feedback, array_slice($highSeverityMessages, 0, 5));
    }

    $feedback[] = 'Do not leave unsupported authority framing, uncited statistics, or other high-severity QA issues in place.';
    $feedback[] = 'If a claim cannot be supported, rewrite it conservatively instead of keeping synthetic certainty.';

    if ($highSeverityMessages === []) {
        $feedback[] = 'Even if no high-severity warnings are present, tighten specificity, reduce filler, and keep any real citations intact.';
    }

    return implode("\n", $feedback);
}

function cmsAiAutomationAttemptAutoQaRetry(int $contentId, array $plan, array $qaWarnings, int $parentRunId): array
{
    if ($contentId <= 0 || !cmsAiAutomationHasHighSeverityWarnings($qaWarnings)) {
        return ['attempted' => false, 'applied' => false];
    }

    $feedback = cmsAiAutomationBuildAutoQaRetryFeedback($plan, $qaWarnings);
    $result = cmsAiAutomationRefineContent($contentId, $feedback, null, [
        'source' => 'auto_refine',
        'trigger' => (string)($plan['auto_refine_policy'] ?? 'high_severity_once'),
        'parent_run_id' => $parentRunId,
        'label' => 'Automated refine retry',
    ]);

    return [
        'attempted' => true,
        'applied' => !empty($result['ok']),
        'ok' => !empty($result['ok']),
        'run_id' => (int)($result['run_id'] ?? 0),
        'error' => (string)($result['error'] ?? ''),
        'feedback' => $feedback,
    ];
}

function cmsAiAutomationBuildRefinePrompt(array $content, array $meta, string $feedback): array
{
    $outline = cmsAiAutomationDecodeMetaJson($meta, '_ai_outline');
    $qaWarnings = cmsAiAutomationDecodeMetaJson($meta, '_ai_qa_warnings');
    $citations = cmsAiAutomationDecodeMetaJson($meta, '_ai_citations');
    $searchSources = cmsAiAutomationDecodeMetaJson($meta, '_ai_search_sources');

    $instruction = [
        'task' => 'refine_cms_content',
        'topic' => (string)($meta['_ai_topic'] ?? $content['title'] ?? ''),
        'content_type' => (string)($content['type'] ?? 'post'),
        'content_mode' => (string)($meta['_ai_content_mode'] ?? 'standard'),
        'editor_feedback' => $feedback,
        'current_content' => [
            'title' => (string)($content['title'] ?? ''),
            'body_html' => (string)($content['body'] ?? ''),
            'summary' => (string)($content['excerpt'] ?? ''),
            'seo_title' => (string)($meta['seo_title'] ?? ''),
            'seo_description' => (string)($meta['seo_description'] ?? ''),
        ],
        'constraints' => [
            'Return valid JSON only matching output_schema.',
            'Apply the editor feedback directly and concretely. Do not explain what you changed.',
            'Preserve existing HTML structure and section order unless the feedback explicitly asks for restructuring.',
            'Keep citations, links, and grounded claims intact unless the feedback explicitly asks to remove or replace them.',
            'Keep the same title unless the feedback clearly requests a better title.',
            'Do not regress into generic filler, repeated fallback phrasing, or option-dumping.',
            'Do not leave synthetic authority phrasing (for example: "according to", "a study found", "research shows") in any paragraph unless that same paragraph includes a real inline citation link.',
            'Do not leave percentages, ratio-style statistics, or survey-style quantitative claims in any paragraph unless that same paragraph includes a supporting inline citation link.',
            'Do not leave consensus framing such as "widely recommended", "best practice", "standard practice", "accepted benchmark", or "experts agree" in any paragraph unless that same paragraph includes a real inline citation link.',
            'If a consensus-style claim cannot be cited, rewrite it into cautious operational guidance rather than borrowed authority.',
            'Do not leave vendor, product, or alternative-comparison claims such as "better", "superior", "recommended starting point", "choose X if", or "skip both" in any paragraph unless that same paragraph includes a real inline citation link. Otherwise rewrite them as conditional guidance tied to a named use case.',
            'For sensitive topics, prefer language like "typically", "common starting point", "often used in practice", or "verify with a local professional" over unsupported universal claims.',
        ],
        'output_schema' => [
            'type' => 'object',
            'required' => ['body_html'],
            'properties' => [
                'title' => ['type' => 'string'],
                'body_html' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'seo_title' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
            ],
        ],
    ];

    if ($outline !== []) {
        $instruction['outline_contract'] = $outline;
        $instruction['constraints'][] = 'Respect the outline_contract unless the editor feedback explicitly requests a structural change.';
    }
    if ($qaWarnings !== []) {
        $instruction['qa_warnings'] = $qaWarnings;
        $instruction['constraints'][] = 'Where possible, reduce the existing QA warnings while applying the editor feedback.';
    }
    if ($citations !== []) {
        $instruction['existing_citations'] = $citations;
    }
    if ($searchSources !== []) {
        $instruction['source_context'] = $searchSources;
    }

    return [
        [
            'role' => 'system',
            'content' => 'You are a senior editorial refinement assistant for a CMS. Rewrite only to address the editor feedback while preserving trust signals, structure, and publishable quality. Return valid JSON only.',
        ],
        [
            'role' => 'user',
            'content' => json_encode($instruction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
    ];
}

function cmsAiAutomationApplyRefinement(int $contentId, array $content, array $meta, array $refined, ?array $actor = null, array $context = []): array
{
    $db = cmsDb();
    $actorId = (int)($actor['id'] ?? 0);
    $newTitle = trim((string)($refined['title'] ?? ''));
    $newBody = trim((string)($refined['body_html'] ?? ''));
    if ($newBody === '') {
        return ['ok' => false, 'error' => 'Refinement returned an empty body'];
    }

    $currentTitle = (string)($content['title'] ?? '');
    $currentBody = (string)($content['body'] ?? '');
    $currentBlocks = $content['blocks_json'] ?? null;
    cmsSaveRevision($contentId, $actorId, $currentTitle, $currentBody, $currentBlocks, null);

    $bodyHtml = cmsAiAutomationMarkdownToHtml($newBody);
    $bodyHtml = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($bodyHtml, 'cms.content'), 'cms.content');
    $title = $newTitle !== '' ? $newTitle : $currentTitle;
    $summary = trim((string)($refined['summary'] ?? ''));
    if ($summary === '') {
        $summary = mb_substr(trim(strip_tags($bodyHtml)), 0, 220);
    }

    $wordCount = cmsCalculateWordCount($bodyHtml, $currentBlocks);
    $cmsSettings = readCmsSettings();
    $readingTime = ($cmsSettings['reading_time_enabled'] ?? '1') === '1' ? cmsCalculateReadingTime($wordCount) : 0;

    $stmt = $db->prepare('UPDATE cms_content SET title = :title, body = :body, excerpt = :excerpt, updated_at = NOW(), word_count = :wc, reading_time = :rt WHERE id = :id LIMIT 1');
    $stmt->execute([
        ':id' => $contentId,
        ':title' => $title,
        ':body' => $bodyHtml,
        ':excerpt' => $summary,
        ':wc' => $wordCount,
        ':rt' => $readingTime,
    ]);

    cmsSyncMediaUsage($contentId, ['featured_image_id' => $content['featured_image_id'] ?? null], $currentBlocks);

    $existingAttempts = max(0, (int)($meta['_ai_refine_attempt_count'] ?? 0));
    $historyEntry = [
        'refined_at' => date('Y-m-d H:i:s'),
        'actor_user_id' => $actorId > 0 ? $actorId : null,
        'title_changed' => $title !== $currentTitle,
        'source' => trim((string)($context['source'] ?? ($actorId > 0 ? 'manual' : 'system'))),
        'trigger' => trim((string)($context['trigger'] ?? 'manual')),
        'run_id' => isset($context['run_id']) ? (int)$context['run_id'] : null,
    ];
    $history = cmsAiAutomationAppendHistoryEntry($meta, '_ai_refine_history', $historyEntry);

    $recomputed = cmsAiAutomationRecomputeContentSignals([
        'title' => $title,
        'body' => $bodyHtml,
    ], $meta);

    cmsAiAutomationSaveContentMeta($db, $contentId, [
        'seo_title' => trim((string)($refined['seo_title'] ?? ($meta['seo_title'] ?? ''))),
        'seo_description' => trim((string)($refined['seo_description'] ?? ($meta['seo_description'] ?? ''))),
        '_ai_body_hash' => sha1(trim(strip_tags($bodyHtml))),
        '_ai_content_mode' => (string)($meta['_ai_content_mode'] ?? 'standard'),
        '_ai_last_refined_at' => date('Y-m-d H:i:s'),
        '_ai_refine_attempt_count' => (string)($existingAttempts + 1),
        '_ai_refine_history' => json_encode($history, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_ai_qa_warnings' => json_encode($recomputed['qa_warnings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_ai_quality_assessment' => json_encode($recomputed['quality_assessment'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_ai_citations' => json_encode($recomputed['citations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    return [
        'ok' => true,
        'content_id' => $contentId,
        'title' => $title,
        'body_html' => $bodyHtml,
        'summary' => $summary,
        'qa_warnings' => $recomputed['qa_warnings'],
        'quality_assessment' => $recomputed['quality_assessment'],
        'citations' => $recomputed['citations'],
    ];
}

function cmsAiAutomationRefineContent(int $contentId, string $feedback, ?array $actor = null, array $context = []): array
{
    $feedback = trim($feedback);
    if ($contentId <= 0) {
        return ['ok' => false, 'error' => 'Content not found'];
    }
    if ($feedback === '') {
        return ['ok' => false, 'error' => 'Feedback is required'];
    }

    $content = cmsAiAutomationLoadContentRecord($contentId);
    if ($content === null) {
        return ['ok' => false, 'error' => 'Content not found'];
    }

    $meta = function_exists('cmsLoadContentMeta') ? cmsLoadContentMeta(cmsDb(), $contentId) : cmsAiAutomationLoadContentMeta(cmsDb(), $contentId);
    if (($meta['_ai_generated'] ?? '') !== '1') {
        return ['ok' => false, 'error' => 'Only AI-generated content can be refined here'];
    }

    $planId = (int)($meta['_ai_plan_id'] ?? 0);
    $refineSource = trim((string)($context['source'] ?? ($actor !== null ? 'manual' : 'system')));
    $runId = cmsAiAutomationCreateRun($planId, [
        'content_id' => $contentId,
        'status' => 'generating',
        'topic_snapshot' => (string)($meta['_ai_topic'] ?? $content['title'] ?? ''),
        'prompt_snapshot' => $feedback,
        'keywords' => [],
        'attempt_count' => max(1, ((int)($meta['_ai_refine_attempt_count'] ?? 0)) + 1),
        'started_at' => date('Y-m-d H:i:s'),
    ]);

    $critiqueHistory = cmsAiAutomationAppendHistoryEntry($meta, '_ai_critique_history', [
        'created_at' => date('Y-m-d H:i:s'),
        'actor_user_id' => (int)($actor['id'] ?? 0) ?: null,
        'feedback' => $feedback,
        'run_id' => $runId,
        'source' => $refineSource,
        'trigger' => trim((string)($context['trigger'] ?? 'manual')),
        'label' => trim((string)($context['label'] ?? 'Refine request')),
    ]);
    cmsAiAutomationSaveContentMeta(cmsDb(), $contentId, [
        '_ai_latest_critique' => $feedback,
        '_ai_critique_history' => json_encode($critiqueHistory, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $messages = cmsAiAutomationBuildRefinePrompt($content, $meta, $feedback);

    try {
        $response = app()->cap()->call('ai.text.generate@1', [
            'messages' => $messages,
            'temperature' => 0.3,
            'json' => true,
            'timeout_ms' => 55000,
            'max_tokens' => 5000,
            'preferred_tier' => 'paid',
        ], ['caller_module' => 'cms', 'timeout_ms' => 55000]);
    } catch (\Throwable $e) {
        cmsAiAutomationUpdateRun($runId, [
            'status' => 'failed',
            'error_message' => $e->getMessage(),
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => false, 'error' => $e->getMessage(), 'run_id' => $runId];
    }

    if (empty($response['ok'])) {
        cmsAiAutomationUpdateRun($runId, [
            'status' => 'failed',
            'error_message' => (string)($response['error'] ?? 'Refine provider error'),
            'response' => $response,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => false, 'error' => (string)($response['error'] ?? 'Refine provider error'), 'run_id' => $runId];
    }

    $raw = trim((string)($response['content'] ?? ''));
    if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $raw, $m)) {
        $raw = trim($m[1]);
    }
    $refined = json_decode($raw, true);
    if (!is_array($refined) || trim((string)($refined['body_html'] ?? '')) === '') {
        cmsAiAutomationUpdateRun($runId, [
            'status' => 'failed',
            'error_message' => 'Refine returned invalid structure',
            'response' => ['raw' => $raw],
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => false, 'error' => 'Refine returned invalid structure', 'run_id' => $runId];
    }

    $applied = cmsAiAutomationApplyRefinement($contentId, $content, $meta, $refined, $actor, array_merge($context, [
        'run_id' => $runId,
        'source' => $refineSource,
    ]));
    if (empty($applied['ok'])) {
        cmsAiAutomationUpdateRun($runId, [
            'status' => 'failed',
            'error_message' => (string)($applied['error'] ?? 'Failed to apply refinement'),
            'response' => ['refined' => $refined],
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => false, 'error' => (string)($applied['error'] ?? 'Failed to apply refinement'), 'run_id' => $runId];
    }

    cmsAiAutomationUpdateRun($runId, [
        'status' => 'review',
        'response' => ['feedback' => $feedback, 'refined' => $refined, 'source' => $refineSource, 'qa_warnings' => $applied['qa_warnings'] ?? [], 'quality_assessment' => $applied['quality_assessment'] ?? []],
        'summary_text' => $applied['summary'] ?? null,
        'content_id' => $contentId,
        'completed_at' => date('Y-m-d H:i:s'),
    ]);

    cmsAiAutomationSaveContentMeta(cmsDb(), $contentId, [
        '_ai_last_refine_run_id' => (string)$runId,
    ]);

    $updatedMeta = function_exists('cmsLoadContentMeta') ? cmsLoadContentMeta(cmsDb(), $contentId) : cmsAiAutomationLoadContentMeta(cmsDb(), $contentId);

    return [
        'ok' => true,
        'content_id' => $contentId,
        'run_id' => $runId,
        'title' => $applied['title'] ?? ($content['title'] ?? ''),
        'body_html' => $applied['body_html'] ?? ($content['body'] ?? ''),
        'qa_warnings' => $applied['qa_warnings'] ?? [],
        'quality_assessment' => $applied['quality_assessment'] ?? [],
        'citations' => $applied['citations'] ?? [],
        'summary' => $applied['summary'] ?? '',
        'automation' => cmsAiAutomationContentStateFromMeta($updatedMeta),
    ];
}

function cmsAiAutomationContentHashExists(string $hash): bool
{
    $stmt = cmsDb()->prepare(
        "SELECT COUNT(*)
         FROM cms_content_meta
         WHERE meta_key = '_ai_body_hash' AND meta_value = :hash"
    );
    $stmt->execute([':hash' => $hash]);
    return (int)$stmt->fetchColumn() > 0;
}

function cmsAiAutomationTitleExists(string $title): bool
{
    $stmt = cmsDb()->prepare(
        "SELECT COUNT(*) FROM cms_content WHERE LOWER(title) = LOWER(:title) AND deleted_at IS NULL"
    );
    $stmt->execute([':title' => $title]);
    return (int)$stmt->fetchColumn() > 0;
}

function cmsAiAutomationShouldCompress(array $plan, array $generated): bool
{
    $contentMode = (string)($plan['content_mode'] ?? 'standard');
    if ($contentMode === 'checklist') {
        return false;
    }

    $bodyText = trim(strip_tags((string)($generated['body_html'] ?? '')));
    if ($bodyText === '') {
        return false;
    }

    $wordCount = str_word_count($bodyText);
    return $wordCount >= 650;
}

function cmsAiAutomationCompressGenerated(array $plan, array $generated, array $outlineData = [], array $groundingSources = []): array
{
    if (!cmsAiAutomationShouldCompress($plan, $generated)) {
        return ['ok' => true, 'applied' => false, 'generated' => $generated, 'meta' => ['reason' => 'below-threshold']];
    }

    $bodyHtml = (string)($generated['body_html'] ?? '');
    if (trim($bodyHtml) === '') {
        return ['ok' => true, 'applied' => false, 'generated' => $generated, 'meta' => ['reason' => 'empty-body']];
    }

    $beforeWordCount = max(1, str_word_count(trim(strip_tags($bodyHtml))));
    $instruction = [
        'task' => 'compress_cms_content',
        'topic' => (string)($plan['topic'] ?? ''),
        'content_mode' => (string)($plan['content_mode'] ?? 'standard'),
        'target_audience' => (string)($plan['target_audience'] ?? ''),
        'constraints' => [
            'Return valid JSON only matching output_schema.',
            'Reduce redundancy by approximately 10-20 percent without changing the title or core meaning.',
            'Preserve the existing section order, major headings, anchor tags, citations, and trade-off logic.',
            'Do not remove the Quick Start block for tutorial mode.',
            'Do not simplify the article into bullet spam or summary-only prose.',
            'Keep the same tone, point of view, and recommendations, but remove repeated reasoning and filler.',
            'If a paragraph uses unsupported authority phrasing or uncited percentage/stat language, rewrite it into conservative plain prose unless that paragraph already includes a real inline citation link.',
        ],
        'existing_content' => [
            'title' => (string)($generated['title'] ?? ''),
            'body_html' => $bodyHtml,
            'summary' => (string)($generated['summary'] ?? ''),
            'seo_description' => (string)($generated['seo_description'] ?? ''),
        ],
        'output_schema' => [
            'type' => 'object',
            'required' => ['body_html'],
            'properties' => [
                'body_html' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'seo_description' => ['type' => 'string'],
            ],
        ],
    ];

    if ($outlineData !== []) {
        $instruction['constraints'][] = 'Preserve the structural contract implied by the planned outline; do not merge or drop required sections.';
    }
    if ($groundingSources !== []) {
        $instruction['constraints'][] = 'Preserve inline citations and linked sources already present in the HTML.';
    }

    $messages = [
        [
            'role' => 'system',
            'content' => 'You are an editorial compression assistant. Tighten content without changing its meaning, HTML structure, or trust signals. Return valid JSON only.',
        ],
        [
            'role' => 'user',
            'content' => json_encode($instruction, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ],
    ];

    try {
        $response = app()->cap()->call('ai.text.generate@1', [
            'messages' => $messages,
            'temperature' => 0.2,
            'json' => true,
            'timeout_ms' => 30000,
            'max_tokens' => 3500,
            'preferred_tier' => cmsAiAutomationPreferredTier($plan, 'compress', $groundingSources),
        ], ['caller_module' => 'cms', 'timeout_ms' => 30000]);
    } catch (\Throwable $e) {
        write_log('cms ai compression pass: exception — ' . $e->getMessage(), 'warning', ['topic' => (string)($plan['topic'] ?? '')]);
        return ['ok' => false, 'error' => $e->getMessage(), 'generated' => $generated];
    }

    if (empty($response['ok'])) {
        write_log('cms ai compression pass: provider error — ' . (string)($response['error'] ?? 'unknown'), 'warning', ['topic' => (string)($plan['topic'] ?? '')]);
        return ['ok' => false, 'error' => (string)($response['error'] ?? 'compression provider error'), 'generated' => $generated];
    }

    $compressedRaw = trim((string)($response['content'] ?? ''));
    if (preg_match('/^```(?:json)?\s*\n(.*)\n```$/s', $compressedRaw, $m)) {
        $compressedRaw = trim($m[1]);
    }
    $compressed = json_decode($compressedRaw, true);
    if (!is_array($compressed) || trim((string)($compressed['body_html'] ?? '')) === '') {
        write_log('cms ai compression pass: invalid JSON', 'warning', ['topic' => (string)($plan['topic'] ?? '')]);
        return ['ok' => false, 'error' => 'compression returned invalid structure', 'generated' => $generated];
    }

    $compressedBodyHtml = cmsAiAutomationMarkdownToHtml((string)$compressed['body_html']);
    $compressedBodyHtml = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($compressedBodyHtml, 'cms.content'), 'cms.content');
    $afterWordCount = max(1, str_word_count(trim(strip_tags($compressedBodyHtml))));
    $reductionPct = (int)round((($beforeWordCount - $afterWordCount) / $beforeWordCount) * 100);

    if ($afterWordCount >= $beforeWordCount || $reductionPct < 5 || $reductionPct > 30) {
        return ['ok' => true, 'applied' => false, 'generated' => $generated, 'meta' => [
            'reason' => 'outside-target-range',
            'before_words' => $beforeWordCount,
            'after_words' => $afterWordCount,
            'reduction_pct' => $reductionPct,
        ]];
    }

    $updated = $generated;
    $updated['body_html'] = $compressedBodyHtml;
    if (trim((string)($compressed['summary'] ?? '')) !== '') {
        $updated['summary'] = trim((string)$compressed['summary']);
    }
    if (trim((string)($compressed['seo_description'] ?? '')) !== '') {
        $updated['seo_description'] = trim((string)$compressed['seo_description']);
    }
    $updated['compression'] = [
        'applied' => true,
        'before_words' => $beforeWordCount,
        'after_words' => $afterWordCount,
        'reduction_pct' => $reductionPct,
    ];

    write_log('cms ai compression pass: applied', 'info', [
        'plan_id' => (int)($plan['id'] ?? 0),
        'topic' => (string)($plan['topic'] ?? ''),
        'before_words' => $beforeWordCount,
        'after_words' => $afterWordCount,
        'reduction_pct' => $reductionPct,
    ]);

    return ['ok' => true, 'applied' => true, 'generated' => $updated, 'meta' => $updated['compression']];
}

function cmsAiAutomationGenerateStructuredContent(array $plan, int $attempt = 1): array
{
    $recent = cmsAiAutomationRecentContext((int)($plan['id'] ?? 0));

    // ─── Search grounding: fetch live sources before building the prompt ─────
    $groundingSources = [];
    if (function_exists('cmsAiAutomationSearchGroundingEnabled') && cmsAiAutomationSearchGroundingEnabled($plan)) {
        $keywords = is_array($plan['keywords'] ?? null) ? $plan['keywords'] : [];
        $groundingSources = cmsAiAutomationFetchSearchGrounding((string)($plan['topic'] ?? ''), $keywords);
        if ($groundingSources !== []) {
            write_log(
                'cms ai search grounding: fetched ' . count($groundingSources) . ' source(s)',
                'info',
                ['plan_id' => (int)($plan['id'] ?? 0), 'topic' => (string)($plan['topic'] ?? '')]
            );
        }
    }

    // ─── Pass 1: Outline (non-fatal — draft runs even if outline fails) ──────
    $outlineData = [];
    if ($attempt === 1) {
        $outlineResult = cmsAiAutomationGenerateOutline($plan, $groundingSources);
        if (!empty($outlineResult['ok'])) {
            $outlineData = $outlineResult['outline'] ?? [];
        }
    }

    // ─── Pass 2: Draft ────────────────────────────────────────────────────────
    $messages = cmsAiAutomationBuildPrompt($plan, $recent, $groundingSources, $outlineData);
    if ($attempt > 1) {
        $messages[] = [
            'role' => 'user',
            'content' => json_encode([
                'retry_instruction' => 'The last draft was too similar to an existing generated item. Use a substantially different headline, section structure, examples, and phrasing.',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }

    $response = app()->cap()->call('ai.text.generate@1', [
        'messages'   => $messages,
        'temperature' => 0.7,
        'json'       => true,
        'timeout_ms' => 55000,
        'max_tokens' => 6000,
        'preferred_tier' => cmsAiAutomationPreferredTier($plan, 'draft', $groundingSources),
    ], ['caller_module' => 'cms', 'timeout_ms' => 55000]);

    if (empty($response['ok'])) {
        return ['ok' => false, 'error' => (string)($response['error'] ?? 'AI provider error'), 'prompt' => $messages];
    }

    $generated = cmsAiAutomationParseResponse((string)($response['content'] ?? ''));
    if ($generated === null) {
        return ['ok' => false, 'error' => 'AI returned invalid generation JSON', 'prompt' => $messages, 'raw' => (string)($response['content'] ?? '')];
    }

    $generated['body_html'] = cmsAiAutomationMarkdownToHtml($generated['body_html']);
    $generated['body_html'] = cmsEditorSanitizeHtml(cmsEditorNormalizeHtml($generated['body_html'], 'cms.content'), 'cms.content');
    if ($generated['summary'] === '') {
        $summarySource = trim(strip_tags($generated['body_html']));
        $generated['summary'] = mb_substr($summarySource, 0, 220);
    }
    if (!empty($plan['seo_enabled'])) {
        if ($generated['seo_title'] === '') {
            $generated['seo_title'] = mb_substr($generated['title'], 0, 60);
        }
        if ($generated['seo_description'] === '') {
            $generated['seo_description'] = mb_substr($generated['summary'], 0, 160);
        }
    } else {
        $generated['seo_title'] = '';
        $generated['seo_description'] = '';
    }
    if (empty($plan['summary_enabled'])) {
        $generated['summary'] = '';
    }

    $compressionResult = cmsAiAutomationCompressGenerated($plan, $generated, $outlineData, $groundingSources);
    if (!empty($compressionResult['ok']) && !empty($compressionResult['applied'])) {
        $generated = $compressionResult['generated'];
        if ($generated['summary'] === '') {
            $summarySource = trim(strip_tags($generated['body_html']));
            $generated['summary'] = mb_substr($summarySource, 0, 220);
        }
        if (!empty($plan['seo_enabled']) && $generated['seo_description'] === '') {
            $generated['seo_description'] = mb_substr($generated['summary'], 0, 160);
        }
        if (empty($plan['summary_enabled'])) {
            $generated['summary'] = '';
        }
        if (empty($plan['seo_enabled'])) {
            $generated['seo_title'] = '';
            $generated['seo_description'] = '';
        }
    }

    $bodyHash = sha1(trim(strip_tags($generated['body_html'])));
    if (cmsAiAutomationTitleExists($generated['title']) || cmsAiAutomationContentHashExists($bodyHash)) {
        if ($attempt < 2) {
            return cmsAiAutomationGenerateStructuredContent($plan, $attempt + 1);
        }
        return ['ok' => false, 'error' => 'Generated content duplicated an existing title or body', 'prompt' => $messages];
    }

    $generated['body_hash'] = $bodyHash;
    $generated['visual_suggestions'] = cmsAiAutomationFindSuggestedMedia($plan, $generated);

    // ─── Citation extraction (authoritative: parsed from final HTML) ─────────
    $generated['search_sources'] = $groundingSources;
    if ($groundingSources !== [] && function_exists('cmsAiAutomationExtractCitationsFromHtml')) {
        $generated['citations'] = cmsAiAutomationExtractCitationsFromHtml($generated['body_html']);
        // Merge with AI-declared citations as a fallback for sources the AI named
        // but did not yet produce an anchor for (deduplication by URL)
        $citedUrls = array_flip(array_column($generated['citations'], 'url'));
        foreach ($generated['citations_declared'] ?? [] as $dc) {
            if (!isset($citedUrls[$dc['url']])) {
                $generated['citations'][] = $dc;
            }
        }
    } else {
        $generated['citations'] = [];
    }

    // ─── Post-Generation QA ─────────────────────────────────────────
    $generated['qa_warnings'] = cmsAiAutomationQualityCheck($generated, $plan);
    $generated['quality_assessment'] = cmsAiAutomationQualityAssessment($generated, $plan, $generated['qa_warnings']);

    return [
        'ok' => true,
        'generated' => $generated,
        'outline' => $outlineData,
        'compression' => $compressionResult['meta'] ?? [],
        'prompt' => $messages,
        'response' => $response,
    ];
}

function cmsAiAutomationCreateContent(array $plan, array $generated, int $runId): array
{
    $db = cmsDb();
    $settings = readCmsSettings();
    $authorId = cmsAiAutomationAuthorId($plan);
    $slug = cmsEnsureUniqueSlug(cmsSlugify($generated['title']), (string)($plan['content_type'] ?? 'post'));
    $blocksJson = null;
    $excerpt = trim((string)($generated['summary'] ?? ''));
    $wordCount = cmsCalculateWordCount($generated['body_html'], $blocksJson);
    $readingTime = ($settings['reading_time_enabled'] ?? '1') === '1' ? cmsCalculateReadingTime($wordCount) : 0;
    $featuredImageId = (int)($generated['visual_suggestions'][0]['id'] ?? 0);
    $desiredPublishAt = null;
    $offsetMinutes = max(0, (int)($plan['publish_offset_minutes'] ?? 0));
    if ($offsetMinutes > 0) {
        $desiredPublishAt = date('Y-m-d H:i:s', time() + ($offsetMinutes * 60));
    }

    $stmt = $db->prepare(
        "INSERT INTO cms_content
            (uuid, title, slug, body, blocks_json, excerpt, type, status, author_id, featured_image_id,
             comment_status, word_count, reading_time, published_at, created_at)
         VALUES
            (:uuid, :title, :slug, :body, :blocks_json, :excerpt, :type, 'draft', :author_id, :featured_image_id,
             :comment_status, :word_count, :reading_time, NULL, NOW())"
    );
    $stmt->execute([
        ':uuid' => cmsUuid(),
        ':title' => $generated['title'],
        ':slug' => $slug,
        ':body' => $generated['body_html'],
        ':blocks_json' => $blocksJson,
        ':excerpt' => $excerpt,
        ':type' => (string)($plan['content_type'] ?? 'post'),
        ':author_id' => $authorId,
        ':featured_image_id' => $featuredImageId > 0 ? $featuredImageId : null,
        ':comment_status' => ($settings['default_comment_status'] ?? 'open') === 'closed' ? 'closed' : 'open',
        ':word_count' => $wordCount,
        ':reading_time' => $readingTime,
    ]);
    $contentId = (int)$db->lastInsertId();

    cmsAiAutomationSaveContentMeta($db, $contentId, [
        'seo_title' => (string)($generated['seo_title'] ?? ''),
        'seo_description' => (string)($generated['seo_description'] ?? ''),
        '_ai_generated' => '1',
        '_ai_plan_id' => (string)((int)($plan['id'] ?? 0)),
        '_ai_run_id' => (string)$runId,
        '_ai_topic' => (string)($plan['topic'] ?? ''),
        '_ai_content_mode' => (string)($plan['content_mode'] ?? 'standard'),
        '_ai_body_hash' => (string)($generated['body_hash'] ?? ''),
        '_ai_visual_suggestions' => json_encode($generated['visual_suggestions'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        '_ai_desired_publish_at' => $desiredPublishAt ?? '',
    ]);

    if (!empty($generated['tags']) && function_exists('cmsSyncContentTags')) {
        cmsSyncContentTags($contentId, $generated['tags']);
    }

    if (function_exists('cmsSyncMediaUsage')) {
        cmsSyncMediaUsage($contentId, ['featured_image_id' => $featuredImageId > 0 ? $featuredImageId : null], $blocksJson);
    }

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.created', [
            'content_id' => $contentId,
            'title' => $generated['title'],
            'type' => (string)($plan['content_type'] ?? 'post'),
            'author_id' => $authorId,
        ]);
    }

    return [
        'id' => $contentId,
        'slug' => $slug,
        'desired_publish_at' => $desiredPublishAt,
    ];
}

function cmsAiAutomationSubmitForReview(int $contentId, ?int $actorUserId = null): array
{
    return app()->cap()->call('workflow.transition@1', [
        'workflow_key' => 'cms.content',
        'module' => 'cms',
        'entity_type' => 'cms_content',
        'entity_id' => (string)$contentId,
        'action' => 'submit',
        'actor_user_id' => $actorUserId,
        'meta' => ['source' => 'ai_automation'],
    ], ['caller_module' => 'cms']);
}

function cmsAiAutomationFindWorkflowActor(array $roles, ?int $preferredUserId = null): ?array
{
    $allowedRoles = array_values(array_filter(array_map(static function ($role) {
        return trim((string)$role);
    }, $roles)));
    if ($allowedRoles === []) {
        return null;
    }

    $db = cmsDb();
    if ($preferredUserId !== null && $preferredUserId > 0) {
        $stmt = $db->prepare('SELECT id, role FROM cms_users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $preferredUserId]);
        $preferred = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($preferred) && in_array((string)($preferred['role'] ?? ''), $allowedRoles, true)) {
            return [
                'id' => (int)($preferred['id'] ?? 0),
                'role' => (string)($preferred['role'] ?? ''),
                'source' => 'cms',
            ];
        }
    }

    $quotedRoles = implode(',', array_map(static function ($role) use ($db) {
        return $db->quote($role);
    }, $allowedRoles));
    $orderExpr = implode(',', array_map(static function ($role) {
        return "'" . str_replace("'", "''", $role) . "'";
    }, $allowedRoles));

    $stmt = $db->query(
        "SELECT id, role
         FROM cms_users
         WHERE is_active = 1 AND role IN ({$quotedRoles})
         ORDER BY FIELD(role, {$orderExpr}), id ASC
         LIMIT 1"
    );
    $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'role' => (string)($row['role'] ?? ''),
        'source' => 'cms',
    ];
}

function cmsAiAutomationWorkflowTransition(int $contentId, string $action, array $callerUser, ?int $actorUserId = null, array $meta = []): array
{
    return app()->cap()->call('workflow.transition@1', [
        'workflow_key' => 'cms.content',
        'module' => 'cms',
        'entity_type' => 'cms_content',
        'entity_id' => (string)$contentId,
        'action' => $action,
        'actor_user_id' => $actorUserId ?: (int)($callerUser['id'] ?? 0),
        'meta' => $meta + ['source' => 'ai_automation'],
    ], [
        'caller_module' => 'cms',
        'caller_user' => $callerUser,
    ]);
}

function cmsAiAutomationAutoPublishDecision(array $plan, array $qualityAssessment): array
{
    $policy = (string)($plan['auto_publish_policy'] ?? 'off');
    $threshold = max(50, min(100, (int)($plan['confidence_threshold'] ?? 85)));
    $overall = (int)($qualityAssessment['overall'] ?? 0);
    $confidence = trim((string)($qualityAssessment['approval_confidence'] ?? ''));
    $recommendation = trim((string)($qualityAssessment['approval_recommendation'] ?? ''));
    $blockingReasons = is_array($qualityAssessment['blocking_reasons'] ?? null) ? $qualityAssessment['blocking_reasons'] : [];
    $sensitivity = cmsAiAutomationResolveSensitivity($plan);

    $decision = [
        'eligible' => false,
        'policy' => $policy,
        'confidence_threshold' => $threshold,
        'overall' => $overall,
        'approval_confidence' => $confidence,
        'approval_recommendation' => $recommendation,
        'content_sensitivity' => $sensitivity,
        'reason' => '',
    ];

    if ($policy === 'off') {
        $decision['reason'] = 'Auto-publish policy is disabled for this plan.';
        return $decision;
    }

    if ($policy !== 'high_confidence_low_sensitivity') {
        $decision['reason'] = 'Auto-publish policy is not supported by this runtime.';
        return $decision;
    }

    if ($blockingReasons !== []) {
        $decision['reason'] = (string)$blockingReasons[0];
        return $decision;
    }

    if ($sensitivity !== 'standard') {
        $decision['reason'] = 'Only standard-sensitivity content is eligible for auto-publish.';
        return $decision;
    }

    if ($recommendation !== 'auto_publish_candidate') {
        $decision['reason'] = 'Quality assessment did not mark this content as an auto-publish candidate.';
        return $decision;
    }

    if ($confidence !== 'high') {
        $decision['reason'] = 'Approval confidence is below high.';
        return $decision;
    }

    if ($overall < $threshold) {
        $decision['reason'] = sprintf('Overall quality score %d is below the configured threshold %d.', $overall, $threshold);
        return $decision;
    }

    $decision['eligible'] = true;
    $decision['reason'] = 'Eligible for score-based auto-publish.';
    return $decision;
}

function cmsAiAutomationFinalizePublishedContent(int $contentId, array $callerUser): array
{
    $db = cmsDb();
    $stmt = $db->prepare('SELECT * FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1');
    $stmt->execute([':id' => $contentId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($existing)) {
        return ['ok' => false, 'error' => 'Content not found'];
    }
    if (!cmsCanPublish($callerUser)) {
        return ['ok' => false, 'error' => 'Selected actor cannot publish content'];
    }
    if (!cmsCanEditContent($callerUser, $existing)) {
        return ['ok' => false, 'error' => 'Selected actor cannot edit this content'];
    }

    $db->prepare("UPDATE cms_content SET status = 'published', published_at = COALESCE(published_at, NOW()), updated_at = NOW() WHERE id = :id")
        ->execute([':id' => $contentId]);

    $meta = function_exists('cmsLoadContentMeta') ? cmsLoadContentMeta($db, $contentId) : cmsAiAutomationLoadContentMeta($db, $contentId);
    if (cmsPageBuilderEnabled($meta)) {
        $draft = cmsBuilderLoadDocumentRow($contentId, 'draft');
        if ($draft && !empty($draft['document_json'])) {
            $document = cmsBuilderNormalizeDocument((string)$draft['document_json']);
            $actorId = (int)($callerUser['id'] ?? 0);
            $title = trim((string)($existing['title'] ?? 'Untitled'));
            try {
                $publishedId = cmsBuilderPersistDocument($contentId, $document, 'published', $title, $actorId);
                cmsBuilderCreateRevision($publishedId, $document, $actorId, 'Auto-published with content');
                $db->prepare('UPDATE cms_content SET builder_document_id = :doc_id WHERE id = :id')
                    ->execute([':doc_id' => $publishedId, ':id' => $contentId]);
            } catch (\Throwable $e) {
                if (function_exists('app')) {
                    app()->log('warning', 'Auto-publish builder document failed for content ' . $contentId . ': ' . $e->getMessage());
                }
            }
        }
    }

    if ($ctx = module('cms')) {
        $ctx->fireEvent('cms.content.published', [
            'content_id' => $contentId,
            'title' => $existing['title'],
            'slug' => $existing['slug'],
            'type' => $existing['type'],
        ]);
    }
    cmsCacheInvalidateContent($existing);

    return [
        'ok' => true,
        'content_status' => 'published',
        'published_at' => (string)($existing['published_at'] ?? ''),
    ];
}

function cmsAiAutomationPublishApprovedContent(int $contentId, array $callerUser): array
{
    $transition = cmsAiAutomationWorkflowTransition($contentId, 'publish', $callerUser, (int)($callerUser['id'] ?? 0), [
        'mode' => 'auto_publish',
    ]);
    if (empty($transition['ok'])) {
        return ['ok' => false, 'error' => (string)($transition['error'] ?? 'Publish transition failed')];
    }

    $db = cmsDb();
    $meta = function_exists('cmsLoadContentMeta') ? cmsLoadContentMeta($db, $contentId) : cmsAiAutomationLoadContentMeta($db, $contentId);
    $desiredPublishAt = trim((string)($meta['_ai_desired_publish_at'] ?? ''));
    $normalizedDesiredPublishAt = cmsNormalizePublishAt($desiredPublishAt);
    if ($normalizedDesiredPublishAt !== null && strtotime($normalizedDesiredPublishAt) > time()) {
        $db->prepare(
            "UPDATE cms_content SET status = 'scheduled', published_at = :pub, updated_at = NOW() WHERE id = :id LIMIT 1"
        )->execute([':pub' => $normalizedDesiredPublishAt, ':id' => $contentId]);

        try {
            app()->cap()->call('kernel.audit.record@1', [
                'module' => 'cms',
                'action' => 'content.schedule_from_ai_approval',
                'entity_type' => 'cms_content',
                'entity_id' => (string)$contentId,
                'new_data' => ['status' => 'scheduled', 'published_at' => $normalizedDesiredPublishAt],
            ]);
        } catch (Throwable $ignored) {
        }

        return [
            'ok' => true,
            'transition' => $transition,
            'content_status' => 'scheduled',
            'published_at' => $normalizedDesiredPublishAt,
        ];
    }

    $published = cmsAiAutomationFinalizePublishedContent($contentId, $callerUser);
    if (empty($published['ok'])) {
        return $published;
    }

    return [
        'ok' => true,
        'transition' => $transition,
        'content_status' => 'published',
        'published_at' => $published['published_at'] ?? null,
    ];
}

function cmsAiAutomationFindApprovalRecipients(): array
{
    $stmt = cmsDb()->query(
        "SELECT email
         FROM cms_users
         WHERE is_active = 1 AND role IN ('editor', 'administrator') AND email <> ''
         ORDER BY FIELD(role, 'administrator', 'editor'), id ASC"
    );
    $emails = $stmt ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];
    $emails = array_values(array_unique(array_filter(array_map(static function ($email) {
        $value = trim((string)$email);
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }, is_array($emails) ? $emails : []))));
    return $emails;
}

function cmsAiAutomationSendApprovalNotification(int $contentId): bool
{
    $db = cmsDb();
    $stmt = $db->prepare("SELECT id, title, type, excerpt FROM cms_content WHERE id = :id AND deleted_at IS NULL LIMIT 1");
    $stmt->execute([':id' => $contentId]);
    $content = $stmt->fetch(\PDO::FETCH_ASSOC);
    if (!is_array($content)) {
        return false;
    }

    $meta = function_exists('cmsLoadContentMeta') ? cmsLoadContentMeta($db, $contentId) : cmsAiAutomationLoadContentMeta($db, $contentId);
    if (($meta['_ai_generated'] ?? '') !== '1') {
        return false;
    }
    if (trim((string)($meta['_ai_review_email_sent_at'] ?? '')) !== '') {
        return true;
    }

    $recipients = cmsAiAutomationFindApprovalRecipients();
    if ($recipients === []) {
        write_log('AI approval email skipped: no valid recipients (editors/admins with valid email)', 'warning', ['content_id' => $contentId]);
        return false;
    }

    $appUrl = '';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host !== '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $appUrl = $scheme . '://' . $host;
    }
    if ($appUrl === '') {
        $appUrl = trim((string)config('app.url', ''));
    }
    $baseUrl = rtrim($appUrl, '/');
    $reviewUrl = $baseUrl . '/cms/admin/content/edit/' . $contentId;
    $desiredPublishAt = trim((string)($meta['_ai_desired_publish_at'] ?? ''));
    $summary = trim((string)($content['excerpt'] ?? ''));
    $topic = trim((string)($meta['_ai_topic'] ?? ''));
    $qualityAssessment = cmsAiAutomationDecodeMetaJson($meta, '_ai_quality_assessment');

    $contentHtml = '<p style="margin:0 0 16px;color:#4b5563;font-size:16px;line-height:1.6;">An AI-generated CMS item is ready for editorial review.</p>'
        . '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;"><strong>Title:</strong> ' . htmlspecialchars((string)$content['title'], ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;"><strong>Topic:</strong> ' . htmlspecialchars($topic !== '' ? $topic : 'Not specified', ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;"><strong>Type:</strong> ' . htmlspecialchars((string)$content['type'], ENT_QUOTES, 'UTF-8') . '</p>';

    $sensitivity = trim((string)($meta['_ai_content_sensitivity'] ?? ''));
    $topicDomain = trim((string)($meta['_ai_topic_domain'] ?? ''));
    if ($sensitivity !== '' || $topicDomain !== '') {
        $contentHtml .= '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;">';
        if ($topicDomain !== '') {
            $contentHtml .= '<strong>Topic Domain:</strong> ' . htmlspecialchars($topicDomain, ENT_QUOTES, 'UTF-8') . ' &nbsp; ';
        }
        if ($sensitivity !== '') {
            $badgeColor = match ($sensitivity) {
                'high' => '#dc2626',
                'elevated' => '#d97706',
                default => '#6b7280',
            };
            $contentHtml .= '<strong>Sensitivity:</strong> <span style="color:' . $badgeColor . ';font-weight:700;text-transform:uppercase;">' . htmlspecialchars($sensitivity, ENT_QUOTES, 'UTF-8') . '</span>';
        }
        $contentHtml .= '</p>';
    }

    if ($desiredPublishAt !== '') {
        $contentHtml .= '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;"><strong>Requested Publish Time:</strong> ' . htmlspecialchars($desiredPublishAt, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($summary !== '') {
        $contentHtml .= '<p style="margin:0 0 12px;color:#111827;font-size:15px;line-height:1.6;"><strong>Summary:</strong> ' . htmlspecialchars($summary, ENT_QUOTES, 'UTF-8') . '</p>';
    }
    if ($qualityAssessment !== []) {
        $overall = (int)($qualityAssessment['overall'] ?? 0);
        $confidence = trim((string)($qualityAssessment['approval_confidence'] ?? ''));
        $recommendation = trim((string)($qualityAssessment['approval_recommendation'] ?? ''));
        $contentHtml .= '<div style="margin:16px 0 12px;padding:12px 16px;background:#eff6ff;border:1px solid #93c5fd;border-radius:6px;">'
            . '<p style="margin:0 0 8px;font-weight:700;color:#1d4ed8;font-size:14px;">Quality Assessment</p>'
            . '<p style="margin:0 0 6px;color:#1e3a8a;font-size:13px;line-height:1.5;"><strong>Score:</strong> ' . $overall . '/100</p>'
            . '<p style="margin:0 0 6px;color:#1e3a8a;font-size:13px;line-height:1.5;"><strong>Approval Confidence:</strong> ' . htmlspecialchars($confidence !== '' ? strtoupper($confidence) : 'N/A', ENT_QUOTES, 'UTF-8') . '</p>'
            . '<p style="margin:0;color:#1e3a8a;font-size:13px;line-height:1.5;"><strong>Recommendation:</strong> ' . htmlspecialchars($recommendation !== '' ? $recommendation : 'editor_review', ENT_QUOTES, 'UTF-8') . '</p>'
            . '</div>';
        $blockingReasons = is_array($qualityAssessment['blocking_reasons'] ?? null) ? $qualityAssessment['blocking_reasons'] : [];
        if ($blockingReasons !== []) {
            $contentHtml .= '<div style="margin:12px 0 12px;padding:12px 16px;background:#fff7ed;border:1px solid #fdba74;border-radius:6px;">'
                . '<p style="margin:0 0 8px;font-weight:700;color:#9a3412;font-size:14px;">Blocking Reasons</p>';
            foreach (array_slice($blockingReasons, 0, 4) as $reason) {
                $contentHtml .= '<p style="margin:0 0 6px;color:#7c2d12;font-size:13px;line-height:1.5;">• ' . htmlspecialchars((string)$reason, ENT_QUOTES, 'UTF-8') . '</p>';
            }
            $contentHtml .= '</div>';
        }
    }

    // ─── QA Warnings ────────────────────────────────────────────────
    $qaWarningsRaw = trim((string)($meta['_ai_qa_warnings'] ?? ''));
    $qaWarnings = ($qaWarningsRaw !== '') ? (json_decode($qaWarningsRaw, true) ?: []) : [];
    if ($qaWarnings !== []) {
        $severityIcons = ['high' => '🔴', 'medium' => '🟡', 'low' => '🔵'];
        $contentHtml .= '<div style="margin:16px 0 12px;padding:12px 16px;background:#fef3c7;border:1px solid #f59e0b;border-radius:6px;">'
            . '<p style="margin:0 0 8px;font-weight:700;color:#92400e;font-size:14px;">⚠️ Automated Quality Warnings</p>';
        foreach ($qaWarnings as $warning) {
            if (!is_array($warning)) {
                continue;
            }
            $icon = $severityIcons[(string)($warning['severity'] ?? 'low')] ?? '🔵';
            $contentHtml .= '<p style="margin:0 0 6px;color:#78350f;font-size:13px;line-height:1.5;">'
                . $icon . ' '
                . htmlspecialchars((string)($warning['message'] ?? ''), ENT_QUOTES, 'UTF-8')
                . '</p>';
        }
        $contentHtml .= '</div>';
    }

    $hasHighSeverity = false;
    foreach ($qaWarnings as $w) {
        if (is_array($w) && ($w['severity'] ?? '') === 'high') {
            $hasHighSeverity = true;
            break;
        }
    }
    $emailSubject = ($hasHighSeverity ? '⚠️ ' : '') . 'AI Content Awaiting Approval: ' . (string)$content['title'];
    if (($qualityAssessment['approval_confidence'] ?? '') === 'blocked') {
        $emailSubject = '⛔ ' . $emailSubject;
    }

    $body = buildEmailTemplate('AI Content Awaiting Approval', $contentHtml, 'Review Content', $reviewUrl);
    $primary = array_shift($recipients);
    $sent = sendEmail((string)$primary, $emailSubject, $body, [
        'cc' => $recipients,
    ]);

    if ($sent) {
        cmsAiAutomationSaveContentMeta($db, $contentId, [
            '_ai_review_email_sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    return $sent;
}

function cmsAiAutomationExecutePlan(array $plan): array
{
    $planId = (int)($plan['id'] ?? 0);
    if ($planId <= 0) {
        return ['ok' => false, 'error' => 'Invalid plan'];
    }

    $generation = cmsAiAutomationGenerateStructuredContent($plan);
    $runId = cmsAiAutomationCreateRun($planId, [
        'status' => $generation['ok'] ? 'generating' : 'failed',
        'topic_snapshot' => (string)($plan['topic'] ?? ''),
        'prompt_snapshot' => json_encode($generation['prompt'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'keywords' => $plan['keywords'] ?? [],
        'error_message' => $generation['ok'] ? null : (string)($generation['error'] ?? 'Generation failed'),
        'attempt_count' => 1,
    ]);

    if (empty($generation['ok'])) {
        cmsDb()->prepare(
            'UPDATE cms_ai_content_plans SET last_error = :error, last_run_at = NOW(), next_run_at = :next_run_at, updated_at = NOW() WHERE id = :id LIMIT 1'
        )->execute([
            ':id' => $planId,
            ':error' => (string)($generation['error'] ?? 'Generation failed'),
            ':next_run_at' => cmsAiAutomationNextRunAt($plan),
        ]);

        return ['ok' => false, 'error' => (string)($generation['error'] ?? 'Generation failed'), 'run_id' => $runId];
    }

    $generated = $generation['generated'];
    $created = cmsAiAutomationCreateContent($plan, $generated, $runId);
    $contentId = (int)($created['id'] ?? 0);

    // ─── Store QA warnings in content meta ──────────────────────────
    $qaWarnings = $generated['qa_warnings'] ?? [];
    $qualityAssessment = is_array($generated['quality_assessment'] ?? null) ? $generated['quality_assessment'] : [];
    $metaToSave = [
        '_ai_content_sensitivity' => cmsAiAutomationResolveSensitivity($plan),
        '_ai_topic_domain'        => cmsAiAutomationTopicDomain((string)($plan['topic'] ?? '')),
    ];
    if ($qaWarnings !== []) {
        $metaToSave['_ai_qa_warnings'] = json_encode($qaWarnings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($qualityAssessment !== []) {
        $metaToSave['_ai_quality_assessment'] = json_encode($qualityAssessment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    // Persist search grounding data so reviewers can verify sources
    $searchSources = $generated['search_sources'] ?? [];
    if ($searchSources !== []) {
        $metaToSave['_ai_search_sources'] = json_encode($searchSources, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $citations = $generated['citations'] ?? [];
    if ($citations !== []) {
        $metaToSave['_ai_citations'] = json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $outlineData = $generation['outline'] ?? [];
    if ($outlineData !== []) {
        $metaToSave['_ai_outline'] = json_encode($outlineData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    $compressionData = $generation['compression'] ?? [];
    if (is_array($compressionData) && !empty($compressionData['reduction_pct'])) {
        $metaToSave['_ai_compression_applied'] = '1';
        $metaToSave['_ai_compression_pct'] = (string)((int)$compressionData['reduction_pct']);
        $metaToSave['_ai_compression_meta'] = json_encode($compressionData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    if ($contentId > 0) {
        cmsAiAutomationSaveContentMeta(cmsDb(), $contentId, $metaToSave);
    }

    $autoRefine = ['attempted' => false, 'applied' => false];
    if ($contentId > 0 && cmsAiAutomationShouldAttemptAutoRefine($plan, $qaWarnings)) {
        $autoRefine = cmsAiAutomationAttemptAutoQaRetry($contentId, $plan, $qaWarnings, $runId);
        $updatedMeta = cmsAiAutomationLoadContentMeta(cmsDb(), $contentId);
        $updatedContent = cmsAiAutomationLoadContentRecord($contentId);
        if (is_array($updatedContent)) {
            $created['slug'] = (string)($updatedContent['slug'] ?? ($created['slug'] ?? ''));
        }

        $qaWarnings = cmsAiAutomationDecodeMetaJson($updatedMeta, '_ai_qa_warnings');
        $qualityAssessment = cmsAiAutomationDecodeMetaJson($updatedMeta, '_ai_quality_assessment');
        $citations = cmsAiAutomationDecodeMetaJson($updatedMeta, '_ai_citations');
        $metaToSave = $updatedMeta;
    }

    $autoPublishDecision = cmsAiAutomationAutoPublishDecision($plan, $qualityAssessment);

    $finalContent = $contentId > 0 ? cmsAiAutomationLoadContentRecord($contentId) : null;
    $finalMeta = $contentId > 0 ? cmsAiAutomationLoadContentMeta(cmsDb(), $contentId) : [];

    $workflowResult = [
        'submitted' => false,
        'auto_publish' => [
            'decision' => $autoPublishDecision,
            'attempted' => false,
            'result' => null,
        ],
    ];
    $runStatus = 'review';
    $sendApprovalNotification = true;

    $transition = cmsAiAutomationSubmitForReview($contentId, cmsAiAutomationAuthorId($plan));
    if (empty($transition['ok'])) {
        $error = (string)($transition['error'] ?? 'Workflow review submission failed');
        cmsAiAutomationUpdateRun($runId, [
            'status' => 'failed',
            'error_message' => $error,
            'completed_at' => date('Y-m-d H:i:s'),
        ]);
        cmsDb()->prepare(
            'UPDATE cms_ai_content_plans SET last_error = :error, last_run_at = NOW(), next_run_at = :next_run_at, updated_at = NOW() WHERE id = :id LIMIT 1'
        )->execute([
            ':id' => $planId,
            ':error' => $error,
            ':next_run_at' => cmsAiAutomationNextRunAt($plan),
        ]);

        return ['ok' => false, 'error' => $error, 'run_id' => $runId, 'content_id' => $contentId];
    }
    $workflowResult['submitted'] = true;

    if (!empty($autoPublishDecision['eligible'])) {
        $workflowResult['auto_publish']['attempted'] = true;
        $autoPublishActor = cmsAiAutomationFindWorkflowActor(['administrator', 'editor'], (int)($plan['created_by'] ?? 0));
        if ($autoPublishActor === null) {
            $workflowResult['auto_publish']['result'] = [
                'ok' => false,
                'error' => 'No active editor or administrator is available for auto-publish.',
            ];
        } else {
            try {
                $approveTransition = cmsAiAutomationWorkflowTransition(
                    $contentId,
                    'approve',
                    $autoPublishActor,
                    (int)($autoPublishActor['id'] ?? 0),
                    ['mode' => 'auto_publish']
                );
                if (empty($approveTransition['ok'])) {
                    $workflowResult['auto_publish']['result'] = [
                        'ok' => false,
                        'error' => (string)($approveTransition['error'] ?? 'Approval transition failed'),
                    ];
                } else {
                    $publishResult = cmsAiAutomationPublishApprovedContent($contentId, $autoPublishActor);
                    $workflowResult['auto_publish']['result'] = $publishResult + ['approve_transition' => $approveTransition];
                    if (!empty($publishResult['ok'])) {
                        $runStatus = ($publishResult['content_status'] ?? '') === 'scheduled' ? 'scheduled' : 'published';
                        $sendApprovalNotification = false;
                    }
                }
            } catch (\Throwable $e) {
                $workflowResult['auto_publish']['result'] = [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ];
            }

            if (empty($workflowResult['auto_publish']['result']['ok'])) {
                write_log('cms ai automation auto-publish fallback to manual review: ' . (string)($workflowResult['auto_publish']['result']['error'] ?? 'unknown error'), 'warning', [
                    'content_id' => $contentId,
                    'plan_id' => $planId,
                ]);
            }
        }
    }

    cmsAiAutomationUpdateRun($runId, [
        'content_id' => $contentId,
        'status' => $runStatus,
        'response' => [
            'generation' => $generation['response'] ?? [],
            'auto_refine' => $autoRefine,
            'qa_warnings' => $qaWarnings,
            'quality_assessment' => $qualityAssessment,
            'workflow' => $workflowResult,
        ],
        'summary_text' => is_array($finalContent) ? (string)($finalContent['excerpt'] ?? ($generated['summary'] ?? '')) : ($generated['summary'] ?? null),
        'seo_title' => (string)($finalMeta['seo_title'] ?? ($generated['seo_title'] ?? '')),
        'seo_description' => (string)($finalMeta['seo_description'] ?? ($generated['seo_description'] ?? '')),
        'visual_suggestions' => $generated['visual_suggestions'] ?? [],
        'desired_publish_at' => $created['desired_publish_at'] ?? null,
        'completed_at' => date('Y-m-d H:i:s'),
    ]);

    if ($sendApprovalNotification) {
        try {
            cmsAiAutomationSendApprovalNotification($contentId);
        } catch (\Throwable $e) {
            write_log('cms ai automation approval email failed: ' . $e->getMessage(), 'error', ['content_id' => $contentId]);
        }
    }

    cmsDb()->prepare(
        'UPDATE cms_ai_content_plans SET last_run_at = NOW(), last_generated_content_id = :content_id, last_error = NULL, next_run_at = :next_run_at, updated_at = NOW() WHERE id = :id LIMIT 1'
    )->execute([
        ':id' => $planId,
        ':content_id' => $contentId,
        ':next_run_at' => cmsAiAutomationNextRunAt($plan),
    ]);

    return [
        'ok' => true,
        'run_id' => $runId,
        'content_id' => $contentId,
        'slug' => (string)($created['slug'] ?? ''),
        'desired_publish_at' => $created['desired_publish_at'] ?? null,
        'qa_warnings' => $qaWarnings,
        'quality_assessment' => $qualityAssessment,
        'content_sensitivity' => cmsAiAutomationResolveSensitivity($plan),
        'topic_domain' => cmsAiAutomationTopicDomain((string)($plan['topic'] ?? '')),
        'search_sources_count' => count($generated['search_sources'] ?? []),
        'citations_count' => count($citations),
        'auto_refine' => $autoRefine,
        'workflow' => $workflowResult,
        'run_status' => $runStatus,
    ];
}

function cmsAiAutomationExecuteDuePlans(int $limit = 5): array
{
    if (!cmsAiAutomationTableExists()) {
        return [];
    }

    $stmt = cmsDb()->prepare(
        "SELECT *
         FROM cms_ai_content_plans
         WHERE is_active = 1 AND next_run_at IS NOT NULL AND next_run_at <= NOW()
         ORDER BY next_run_at ASC, id ASC
         LIMIT {$limit}"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

    $results = [];
    foreach ($rows as $row) {
        $results[] = cmsAiAutomationExecutePlan(cmsAiAutomationHydratePlanRow($row));
    }

    return $results;
}