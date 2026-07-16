<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Intelligence;

use RuntimeException;

final class FinalEvidenceAssembler
{
    public function assemble(array $sources): array
    {
        $observations = [];
        $successful = [];
        foreach ($sources as $source => $packet) {
            if (!is_array($packet)) continue;
            foreach (($packet['issues'] ?? $packet['observations'] ?? []) as $item) {
                if (!is_array($item)) continue;
                $item['source'] ??= (string)$source;
                $observations[] = $item;
            }
            foreach (($packet['successful_checks'] ?? []) as $check) $successful[] = $check;
        }
        $correlated = [];
        foreach ($observations as $item) {
            $key = $this->fingerprint($item);
            if (!isset($correlated[$key])) $correlated[$key] = $item + ['evidence' => [], 'occurrences' => 0, 'fingerprint' => $key];
            $correlated[$key]['occurrences']++;
            $correlated[$key]['evidence'][] = $item;
        }
        return [
            'schema' => 'ark.final-evidence.v1',
            'sources' => array_keys($sources),
            'issues' => array_values($correlated),
            'successful_checks' => array_values($successful),
            'contradictions' => $this->contradictions(array_values($correlated), $successful),
            'content_fingerprint' => hash('sha256', $this->stableJson([$correlated, $successful])),
        ];
    }

    private function fingerprint(array $item): string
    {
        $url = preg_replace('~/\d+(?=/|$)~', '/{id}', (string)($item['url'] ?? $item['where'] ?? ''));
        $detail = strtolower(preg_replace('/\b\d+\b/', '{n}', (string)($item['detail'] ?? $item['message'] ?? $item['actual'] ?? '')) ?? '');
        return substr(hash('sha256', ($item['kind'] ?? $item['component'] ?? 'issue') . '|' . $url . '|' . $detail), 0, 20);
    }

    private function contradictions(array $issues, array $successful): array
    {
        $out = [];
        $successText = strtolower($this->stableJson($successful));
        foreach ($issues as $issue) {
            $detail = strtolower((string)($issue['detail'] ?? $issue['message'] ?? ''));
            foreach (['add item', 'submit', 'redirect', 'persist', 'create'] as $term) {
                if (str_contains($detail, $term) && str_contains($successText, $term)) {
                    $out[] = ['issue_fingerprint' => $issue['fingerprint'], 'successful_term' => $term, 'effect' => 'reduces-risk'];
                }
            }
        }
        return $out;
    }

    private function stableJson(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) ksort($value);
            foreach ($value as $k => $v) $value[$k] = json_decode($this->stableJson($v), true);
        }
        return (string)json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

final class ClaimContract
{
    public const TYPES = ['observed', 'derived', 'retrieved', 'inferred', 'predicted', 'unknown'];
    public const STATES = ['proposed', 'retrieval-supported', 'retrieval-contradicted', 'test-scheduled', 'confirmed', 'rejected', 'inconclusive', 'expired'];

    public function validate(array $assessment, array $knownEvidenceIds = [], array $knownRoutes = []): array
    {
        $errors = [];
        foreach (($assessment['claims'] ?? []) as $i => $claim) {
            if (!in_array($claim['claim_type'] ?? '', self::TYPES, true)) $errors[] = "claims.{$i}.claim_type";
            if (trim((string)($claim['text'] ?? '')) === '') $errors[] = "claims.{$i}.text";
            if (($claim['claim_type'] ?? '') !== 'unknown' && empty($claim['evidence_ids']) && empty($claim['source_ids'])) $errors[] = "claims.{$i}.support";
            foreach (($claim['evidence_ids'] ?? []) as $id) if ($knownEvidenceIds !== [] && !in_array($id, $knownEvidenceIds, true)) $errors[] = "claims.{$i}.invalid_evidence:{$id}";
        }
        foreach (($assessment['assumptions'] ?? []) as $i => $assumption) {
            if (!in_array($assumption['verification_status'] ?? 'proposed', self::STATES, true)) $errors[] = "assumptions.{$i}.verification_status";
            foreach (($assumption['routes'] ?? []) as $route) if ($knownRoutes !== [] && !in_array($route, $knownRoutes, true)) $errors[] = "assumptions.{$i}.invented_route:{$route}";
        }
        return ['valid' => $errors === [], 'errors' => $errors];
    }
}

final class GovernedRetriever
{
    /** @param null|callable(string):array $externalFetcher */
    public function __construct(private readonly array $policy, private $externalFetcher = null) {}

    public function internal(string $id, string $path): array
    {
        $roots = array_map(fn($v) => realpath((string)$v), (array)($this->policy['internal_roots'] ?? []));
        $real = realpath($path);
        if ($real === false || !array_filter($roots, fn($root) => $root !== false && ($real === $root || str_starts_with($real, $root . DIRECTORY_SEPARATOR)))) {
            throw new RuntimeException('Retrieval path outside approved roots');
        }
        $content = (string)file_get_contents($real);
        return $this->record($id, 'internal', $real, $content, 'runtime-code');
    }

    public function external(string $url): array
    {
        if ((int)($this->policy['authority_level'] ?? 0) < 3) throw new RuntimeException('External retrieval requires authority level 3');
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if (!in_array($host, array_map('strtolower', (array)($this->policy['external_hosts'] ?? [])), true)) throw new RuntimeException('External host not approved');
        if (!is_callable($this->externalFetcher)) throw new RuntimeException('External fetcher unavailable');
        $result = ($this->externalFetcher)($url);
        return $this->record('ext-' . substr(hash('sha256', $url), 0, 12), 'external', $url, (string)($result['content'] ?? ''), (string)($result['authority'] ?? 'secondary'));
    }

    private function record(string $id, string $kind, string $uri, string $content, string $authority): array
    {
        $redacted = preg_replace('/(password|token|secret|api[_-]?key)\s*[=:]\s*\S+/i', '$1=[REDACTED]', $content) ?? $content;
        return [
            'source_id' => $id, 'kind' => $kind, 'uri' => $uri, 'authority' => $authority,
            'retrieved_at' => gmdate(DATE_ATOM), 'fingerprint' => hash('sha256', $redacted),
            'content' => mb_substr($redacted, 0, max(256, (int)($this->policy['max_chars'] ?? 12000))),
            'untrusted_content' => true,
        ];
    }
}

final class GrainSignature
{
    public function build(array $packet): array
    {
        $features = [
            'process' => $packet['process'] ?? $packet['understanding'] ?? [],
            'coverage' => $packet['coverage'] ?? [],
            'task' => array_diff_key((array)($packet['task_effort'] ?? []), array_flip(['started_at', 'finished_at'])),
            'ux_penalties' => $packet['ux_evolution']['penalties'] ?? [],
            'issue_kinds' => $this->counts($packet['issues'] ?? [], 'kind'),
            'issue_severity' => $this->counts($packet['issues'] ?? [], 'severity'),
        ];
        return ['schema' => 'ark.grain-signature.v1', 'features' => $features, 'fingerprint' => hash('sha256', $this->stable($features))];
    }

    public function distance(array $a, array $b): float
    {
        $left = $this->numericLeaves($a['features'] ?? $a);
        $right = $this->numericLeaves($b['features'] ?? $b);
        $keys = array_unique(array_merge(array_keys($left), array_keys($right)));
        if ($keys === []) return 0.0;
        $sum = 0.0;
        foreach ($keys as $key) {
            $x = (float)($left[$key] ?? 0); $y = (float)($right[$key] ?? 0);
            $sum += abs($x - $y) / max(1.0, abs($x), abs($y));
        }
        return round($sum / count($keys), 4);
    }

    private function counts(array $rows, string $key): array { $out=[]; foreach($rows as $r){$v=(string)($r[$key]??'unknown');$out[$v]=($out[$v]??0)+1;} ksort($out); return $out; }
    private function stable(array $v): string { $sort=function(&$x)use(&$sort){if(is_array($x)){if(!array_is_list($x))ksort($x);foreach($x as &$y)$sort($y);}};$sort($v);return (string)json_encode($v, JSON_UNESCAPED_SLASHES); }
    private function numericLeaves(array $v, string $prefix=''): array { $out=[];foreach($v as $k=>$x){$p=$prefix===''?(string)$k:"{$prefix}.{$k}";if(is_array($x))$out+=$this->numericLeaves($x,$p);elseif(is_numeric($x))$out[$p]=(float)$x;}return $out; }
}

final class VerifiedCaseRetriever
{
    public function retrieve(array $cases, array $scope, array $signature, int $limit = 5): array
    {
        $grain = new GrainSignature();
        $ranked = [];
        foreach ($cases as $case) {
            if (!($case['verified'] ?? false)) continue;
            if (($case['module'] ?? '') !== ($scope['module'] ?? '')) continue;
            if (!empty($scope['component']) && !empty($case['component']) && $case['component'] !== $scope['component']) continue;
            if (!empty($case['expires_at']) && strtotime((string)$case['expires_at']) < time()) continue;
            $distance = $grain->distance($signature, $case['signature'] ?? []);
            $case['similarity'] = round(1 - $distance, 4);
            $ranked[] = $case;
        }
        usort($ranked, fn($a, $b) => $b['similarity'] <=> $a['similarity']);
        return array_slice($ranked, 0, $limit);
    }
}

final class LatentRiskAssessor
{
    public function assess(array $input): array
    {
        $cases = array_values((array)($input['cases'] ?? []));
        $defectSimilarity = $cases === [] ? 0.0 : max(array_map(fn($c) => ($c['verdict'] ?? '') === 'confirmed-defect' ? (float)($c['similarity'] ?? 0) : 0.0, $cases));
        $healthySimilarity = $cases === [] ? 0.0 : max(array_map(fn($c) => ($c['verdict'] ?? '') === 'healthy' ? (float)($c['similarity'] ?? 0) : 0.0, $cases));
        $anomaly = min(1, count($input['issues'] ?? []) / 20);
        $uncertainty = min(1, count($input['unresolved_edges'] ?? []) / 10);
        $contradictionCredit = min(.5, count($input['successful_checks'] ?? []) * .03 + count($input['contradictions'] ?? []) * .08);
        $risk = max(0, min(1, .35*$defectSimilarity + .25*$anomaly + .2*$uncertainty - .25*$healthySimilarity - $contradictionCredit));
        $verdict = $risk < .2 ? 'healthy' : ($risk < .5 ? 'observe' : ($risk < .8 ? 'targeted-verification-recommended' : 'analyst-review-required'));
        return ['risk' => round($risk, 4), 'verdict' => $verdict, 'confidence' => round(min(.95, .4 + count($input['cases'] ?? [])*.08 + count($input['successful_checks'] ?? [])*.02), 4), 'conformance_unchanged' => true];
    }
}

final class TargetedTestValidator
{
    public function validate(array $plan, array $graph, int $authorityLevel): array
    {
        $errors = [];
        if ($authorityLevel < 4) $errors[] = 'authority_level';
        foreach (['hypothesis', 'preconditions', 'actions', 'expected_observations', 'cleanup', 'information_gain'] as $key) if (!isset($plan[$key])) $errors[] = $key;
        $nodes = array_column($graph['nodes'] ?? [], null, 'id');
        foreach (($plan['actions'] ?? []) as $action) if (!isset($nodes[$action])) $errors[] = "unknown_action:{$action}";
        if ((float)($plan['information_gain'] ?? -1) < 0 || (float)($plan['information_gain'] ?? 2) > 1) $errors[] = 'information_gain_range';
        return ['valid' => $errors === [], 'errors' => $errors, 'sandbox_only' => true];
    }
}

final class AiGovernancePolicy
{
    public function effective(array $settings, string $module, string $classification): array
    {
        $enabled = (bool)($settings['enabled'] ?? false);
        $modules = (array)($settings['modules'] ?? ['*']);
        $classes = (array)($settings['data_classifications'] ?? ['public', 'internal']);
        $allowed = $enabled && (in_array('*', $modules, true) || in_array($module, $modules, true)) && in_array($classification, $classes, true);
        return [
            'allowed' => $allowed, 'provider' => (string)($settings['provider'] ?? ''), 'model' => (string)($settings['model'] ?? ''),
            'authority_level' => $allowed ? max(0, min(6, (int)($settings['authority_level'] ?? 1))) : 0,
            'source_allowlist' => (array)($settings['source_allowlist'] ?? []),
            'budgets' => ['tokens' => (int)($settings['max_tokens'] ?? 2000), 'timeout_ms' => (int)($settings['timeout_ms'] ?? 15000)],
            'fallback' => $allowed ? 'deterministic-on-provider-failure' : 'deterministic-only',
        ];
    }
}

final class GoldenEvaluator
{
    /** @param callable(array):array $runner */
    public function evaluate(array $cases, callable $runner): array
    {
        $total=count($cases);$top1=0;$abstain=0;$unsupported=0;$latency=0.0;
        foreach($cases as $case){$start=microtime(true);$r=$runner($case['input']);$latency+=(microtime(true)-$start)*1000;if(($r['prediction']??'')===($case['expected']??''))$top1++;if(($r['prediction']??'')==='unknown'&&($case['expected']??'')==='unknown')$abstain++;$unsupported+=count($r['unsupported_claims']??[]);}
        return ['total'=>$total,'top1_accuracy'=>$total?round($top1/$total,4):0,'correct_abstentions'=>$abstain,'unsupported_claims'=>$unsupported,'avg_latency_ms'=>$total?round($latency/$total,2):0,'promotable'=>$total>0&&$top1/$total>=.8&&$unsupported===0];
    }

    public function promotionDecision(array $candidate, array $current): array
    {
        $allowed = ($candidate['promotable'] ?? false) && ($candidate['top1_accuracy'] ?? 0) > ($current['top1_accuracy'] ?? 0) && ($candidate['unsupported_claims'] ?? 1) <= ($current['unsupported_claims'] ?? 0);
        return ['promote' => $allowed, 'rollback_configuration_required' => true];
    }
}

final class ChangeRecommendationGate
{
    public function authorize(array $recommendation, array $context): array
    {
        $errors=[];
        if ((int)($context['authority_level']??0)<6)$errors[]='authority_level';
        if (!($context['human_approved']??false))$errors[]='human_approval';
        if (empty($recommendation['evidence_ids']))$errors[]='evidence_ids';
        if (!isset($recommendation['expected_telemetry'],$recommendation['verification'],$recommendation['rollback']))$errors[]='verification_contract';
        if (($recommendation['updates_baseline']??false)===true)$errors[]='baseline_update_forbidden';
        return ['authorized'=>$errors===[],'errors'=>$errors,'workspace'=>'isolated','production_write'=>false];
    }
}

final class PatternIntelligenceEngine
{
    public function analyze(array $sources, array $context): array
    {
        $evidence=(new FinalEvidenceAssembler())->assemble($sources);
        $signature=(new GrainSignature())->build($context['analyst_report']??$evidence);
        $cases=(new VerifiedCaseRetriever())->retrieve($context['cases']??[],['module'=>$context['module']??'','component'=>$context['component']??''],$signature);
        $latent=(new LatentRiskAssessor())->assess(['cases'=>$cases,'issues'=>$evidence['issues'],'successful_checks'=>$evidence['successful_checks'],'contradictions'=>$evidence['contradictions'],'unresolved_edges'=>$context['unresolved_edges']??[]]);
        $result=['schema'=>'ark.pattern-intelligence.v1','final_evidence'=>$evidence,'grain_signature'=>$signature,'similar_verified_cases'=>$cases,'latent_quality'=>$latent,'conformance_verdict'=>$context['conformance_verdict']??'unknown'];
        if (isset($context['ai_assessment'])) $result['ai_assessment']=$context['ai_assessment'];
        if (isset($context['effective_ai_policy'])) $result['effective_ai_policy']=$context['effective_ai_policy'];
        return $result;
    }
}
