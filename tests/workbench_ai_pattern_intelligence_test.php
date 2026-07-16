<?php

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Workbench/Intelligence/PatternIntelligence.php';

use Ikabud\Kernel\Workbench\Intelligence\AiGovernancePolicy;
use Ikabud\Kernel\Workbench\Intelligence\ChangeRecommendationGate;
use Ikabud\Kernel\Workbench\Intelligence\ClaimContract;
use Ikabud\Kernel\Workbench\Intelligence\FinalEvidenceAssembler;
use Ikabud\Kernel\Workbench\Intelligence\GoldenEvaluator;
use Ikabud\Kernel\Workbench\Intelligence\GovernedRetriever;
use Ikabud\Kernel\Workbench\Intelligence\GrainSignature;
use Ikabud\Kernel\Workbench\Intelligence\LatentRiskAssessor;
use Ikabud\Kernel\Workbench\Intelligence\PatternIntelligenceEngine;
use Ikabud\Kernel\Workbench\Intelligence\TargetedTestValidator;
use Ikabud\Kernel\Workbench\Intelligence\VerifiedCaseRetriever;

$passed = 0; $failed = 0; $phase = -1;
$check = function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) { $passed++; echo "  ✅ {$message}\n"; }
    else { $failed++; echo "  ❌ {$message}\n"; }
};
$start = function (int $n, string $name) use (&$phase): void { $phase=$n; echo "\n── Phase {$n}: {$name} ──\n"; };

$start(0, 'Final evidence ordering');
$assembled=(new FinalEvidenceAssembler())->assemble([
    'diagnostic'=>['issues'=>[['kind'=>'http-error','detail'=>'HTTP 404','url'=>'/orders/12']]],
    'reporter'=>['issues'=>[['kind'=>'http-error','detail'=>'HTTP 404','url'=>'/orders/13']], 'successful_checks'=>[['id'=>'ok-add-item','text'=>'Add Item persisted']]],
]);
$check(count($assembled['issues'])===1,'changing entity IDs correlate into one final issue');
$check(in_array('reporter',$assembled['sources'],true),'final reporter evidence is included before intelligence');

$start(1, 'Claims and assumptions');
$claims=new ClaimContract();
$valid=$claims->validate(['claims'=>[['claim_type'=>'observed','text'=>'request failed','evidence_ids'=>['obs-1']]],'assumptions'=>[['verification_status'=>'proposed','routes'=>['/orders']]]],['obs-1'],['/orders']);
$invalid=$claims->validate(['claims'=>[['claim_type'=>'inferred','text'=>'invented','evidence_ids'=>[]]],'assumptions'=>[['verification_status'=>'confirmed','routes'=>['/invented']]]],['obs-1'],['/orders']);
$check($valid['valid']&&!$invalid['valid'],'unsupported claims and invented routes are rejected');

$start(2, 'Governed retrieval');
$retriever=new GovernedRetriever(['internal_roots'=>[dirname(__DIR__).'/docs'],'authority_level'=>3,'external_hosts'=>['docs.example.test'],'max_chars'=>5000],fn($url)=>['content'=>'Official reference','authority'=>'official']);
$internal=$retriever->internal('roadmap',dirname(__DIR__).'/docs/workbench/ai-pattern-intelligence-roadmap.md');
$external=$retriever->external('https://docs.example.test/standard');
$check($internal['untrusted_content']&&$external['authority']==='official','retrieval records provenance and treats content as untrusted');
try{$retriever->external('https://evil.example/');$blocked=false;}catch(Throwable){$blocked=true;}
$check($blocked,'non-allowlisted external sources are blocked');

$start(3, 'Software grain signatures');
$grain=new GrainSignature();
$sig1=$grain->build(['coverage'=>['runtime_pages'=>4],'task_effort'=>['duration_ms'=>100,'started_at'=>'a'],'issues'=>[]]);
$sig2=$grain->build(['coverage'=>['runtime_pages'=>4],'task_effort'=>['duration_ms'=>100,'started_at'=>'b'],'issues'=>[]]);
$sig3=$grain->build(['coverage'=>['runtime_pages'=>2],'task_effort'=>['duration_ms'=>300],'issues'=>[['severity'=>'major']]]);
$check($sig1['fingerprint']===$sig2['fingerprint'],'volatile timestamps do not change grain signature');
$check($grain->distance($sig1,$sig3)>0,'material behavior changes produce measurable drift');

$start(4, 'Verified case retrieval');
$cases=[
 ['id'=>'verified','module'=>'demo','verified'=>true,'verdict'=>'healthy','signature'=>$sig1],
 ['id'=>'unverified','module'=>'demo','verified'=>false,'verdict'=>'confirmed-defect','signature'=>$sig3],
 ['id'=>'other','module'=>'other','verified'=>true,'verdict'=>'healthy','signature'=>$sig1],
];
$similar=(new VerifiedCaseRetriever())->retrieve($cases,['module'=>'demo'],$sig1);
$check(count($similar)===1&&$similar[0]['id']==='verified','only scoped verified cases are retrieved');

$start(5, 'Latent risk');
$emptyRisk=(new LatentRiskAssessor())->assess(['cases'=>[],'issues'=>[]]);
$check($emptyRisk['risk']===0.0 && $emptyRisk['verdict']==='healthy','empty evidence is handled without crashing or inventing risk');
$risk=(new LatentRiskAssessor())->assess(['cases'=>$similar,'issues'=>[],'successful_checks'=>[['id'=>'ok']], 'contradictions'=>[], 'unresolved_edges'=>[]]);
$check($risk['verdict']==='healthy'&&$risk['conformance_unchanged'],'latent risk remains independent from conformance');

$start(6, 'Targeted tests');
$graph=['nodes'=>[['id'=>'order.create'],['id'=>'order.submit']]];
$plan=['hypothesis'=>'state loss','preconditions'=>[],'actions'=>['order.create','order.submit'],'expected_observations'=>[],'cleanup'=>['entity'=>'order'],'information_gain'=>.8];
$planResult=(new TargetedTestValidator())->validate($plan,$graph,4);
$badPlan=(new TargetedTestValidator())->validate($plan+['actions'=>['invented']],$graph,3);
$check($planResult['valid']&&$planResult['sandbox_only'],'graph-bound plans validate for sandbox execution');
$check(!$badPlan['valid'],'low-authority or invented test actions are rejected');

$start(7, 'Superadmin governance');
$effective=(new AiGovernancePolicy())->effective(['enabled'=>true,'provider'=>'configured','model'=>'model-x','authority_level'=>5,'modules'=>['demo'],'data_classifications'=>['internal']],'demo','internal');
$denied=(new AiGovernancePolicy())->effective(['enabled'=>true,'modules'=>['demo'],'data_classifications'=>['public']],'demo','restricted');
$check($effective['allowed']&&$effective['authority_level']===5,'effective provider and authority follow superadmin policy');
$check(!$denied['allowed']&&$denied['authority_level']===0,'disallowed data classification falls back to deterministic only');

$start(8, 'Golden evaluation');
$golden=[['input'=>['x'=>1],'expected'=>'defect'],['input'=>['x'=>2],'expected'=>'healthy'],['input'=>['x'=>3],'expected'=>'unknown']];
$evaluation=(new GoldenEvaluator())->evaluate($golden,fn($input)=>['prediction'=>$input['x']===1?'defect':($input['x']===2?'healthy':'unknown'),'unsupported_claims'=>[]]);
$promotion=(new GoldenEvaluator())->promotionDecision($evaluation,['top1_accuracy'=>.6,'unsupported_claims'=>0]);
$check($evaluation['promotable']&&$evaluation['correct_abstentions']===1,'golden evaluation rewards accuracy and correct abstention');
$check($promotion['promote'],'better evaluated configuration is promotable with rollback metadata');

$start(9, 'Controlled change recommendations');
$recommendation=['evidence_ids'=>['obs-1'],'expected_telemetry'=>['score'=>90],'verification'=>['command'=>'test'],'rollback'=>['strategy'=>'revert'],'updates_baseline'=>false];
$gate=new ChangeRecommendationGate();
$notApproved=$gate->authorize($recommendation,['authority_level'=>6,'human_approved'=>false]);
$approved=$gate->authorize($recommendation,['authority_level'=>6,'human_approved'=>true]);
$check(!$notApproved['authorized']&&$approved['authorized']&&!$approved['production_write'],'source recommendations require approval and stay isolated');

echo "\n── Integrated intelligence engine ──\n";
$integrated=(new PatternIntelligenceEngine())->analyze(['final'=>$assembled],['module'=>'demo','analyst_report'=>['coverage'=>['runtime_pages'=>4],'issues'=>[]],'cases'=>$cases,'conformance_verdict'=>'pass']);
$check($integrated['schema']==='ark.pattern-intelligence.v1'&&$integrated['conformance_verdict']==='pass','all phases compose without changing conformance');

$result=['suite'=>'workbench-ai-pattern-intelligence','passed'=>$passed,'failed'=>$failed,'phase'=>$phase,'finished_at'=>gmdate(DATE_ATOM)];
file_put_contents(dirname(__DIR__).'/test_results/workbench-ai-pattern-intelligence.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "\nRESULTS: {$passed} passed, {$failed} failed\n";
exit($failed===0?0:1);
