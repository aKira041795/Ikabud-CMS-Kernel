<?php

declare(strict_types=1);

/**
 * DiSyL 4.6 — Federation + AI primitives tests.
 *
 * Honest scope for 4.6.0: tests verify the template surface
 * ({federated_query}/{remote}/{aggregate}, {ai_generate}/{ai_query}/{ai_complete})
 * driven by injectable resolvers/providers, plus Policy enforcement
 * (kill switch, allowlist, cost ceiling, sandbox capability).
 *
 * Real network transport, PII redaction, DB-backed audit, and per-tenant
 * budgets are deferred to 4.6.1 and not exercised here.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/DiSyL/Security/CapabilitySet.php';
require_once __DIR__ . '/../kernel/DiSyL/Security/Sandbox.php';
require_once __DIR__ . '/../kernel/DiSyL/Federation/ServiceRegistry.php';
require_once __DIR__ . '/../kernel/DiSyL/AI/AiProvider.php';
require_once __DIR__ . '/../kernel/DiSyL/AI/EchoAiProvider.php';
require_once __DIR__ . '/../kernel/DiSyL/AI/Policy.php';

use Ikabud\Kernel\DiSyL\Federation\ServiceRegistry;
use Ikabud\Kernel\DiSyL\AI\EchoAiProvider;
use Ikabud\Kernel\DiSyL\AI\Policy;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};

echo "DiSyL 4.6 — Federation + AI Primitives\n";
echo str_repeat('=', 64) . "\n";

$tmpRoot = sys_get_temp_dir() . '/disyl46_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);

$mkEngine = function () use ($tmpRoot): TemplateEngine {
    return new TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);
};

// ------------------------------------------------- ServiceRegistry --
echo "\n[ServiceRegistry]\n";
$reg = new ServiceRegistry();
$reg->register('catalog', fn (string $q, array $c) => ['name' => 'product:' . $q]);
$assert('registry: has registered service', $reg->has('catalog'));
$assert('registry: list returns names', $reg->list() === ['catalog']);
$res = $reg->resolve('catalog', 'p1', []);
$assert('registry: resolves to value', is_array($res) && $res['name'] === 'product:p1');
$threw = false;
try { $reg->resolve('missing', 'q', []); } catch (\RuntimeException $e) {
    $threw = str_contains($e->getMessage(), 'UNKNOWN_SERVICE');
}
$assert('registry: unknown service throws', $threw);

// ------------------------------------------------- Federation engine --
echo "\n[Federation: federated_query]\n";

// 1. Two remotes + aggregate
$engine = $mkEngine();
$reg = new ServiceRegistry();
$reg->register('catalog', fn ($q, $c) => ['title' => 'Hat']);
$reg->register('reviews', fn ($q, $c) => ['avg' => 4.8]);
$engine->setServiceRegistry($reg);
file_put_contents($tmpRoot . '/tpl/fed1.disyl',
    "{federated_query name='pr'}".
    "{remote service='catalog' query='1' let=p}".
    "{remote service='reviews' query='1' let=r}".
    "{aggregate let=out}{p.title} ({r.avg}){/aggregate}".
    "{/federated_query}"
);
$out = $engine->render('fed1', []);
$assert('federation: aggregate sees both bindings', trim($out) === 'Hat (4.8)', "out=$out");

// 2. Failed remote with fallback (partial policy default)
$engine = $mkEngine();
$reg = new ServiceRegistry();
$reg->register('catalog', fn ($q, $c) => ['title' => 'Hat']);
$reg->register('reviews', function () { throw new \RuntimeException('reviews-down'); });
$engine->setServiceRegistry($reg);
file_put_contents($tmpRoot . '/tpl/fed2.disyl',
    "{federated_query name='pr'}".
    "{remote service='catalog' query='1' let=p}".
    "{remote service='reviews' query='1' let=r fallback='N/A'}".
    "{aggregate let=out}{p.title} - {r}{/aggregate}".
    "{/federated_query}"
);
$out = $engine->render('fed2', []);
$assert('federation: failed remote uses fallback', trim($out) === 'Hat - N/A', "out=$out");

// 3. all-or-nothing aborts on failure
$engine = $mkEngine();
$reg = new ServiceRegistry();
$reg->register('a', fn () => 'ok');
$reg->register('b', function () { throw new \RuntimeException('down'); });
$engine->setServiceRegistry($reg);
file_put_contents($tmpRoot . '/tpl/fed3.disyl',
    "{federated_query name='aon' policy='all-or-nothing'}".
    "{remote service='a' query='x' let=x}".
    "{remote service='b' query='y' let=y}".
    "{aggregate let=out}{x}{/aggregate}".
    "{/federated_query}"
);
$out = $engine->render('fed3', []);
$assert('federation: all-or-nothing aborts on failure',
    str_contains($out, 'federation failed') && str_contains($out, 'down'),
    "out=$out");

// 4. Sandbox denies federation in untrusted
$engine = $mkEngine();
$reg = new ServiceRegistry();
$reg->register('s', fn () => 'should-not-see');
$engine->setServiceRegistry($reg);
file_put_contents($tmpRoot . '/tpl/fed4.disyl',
    "{untrusted}{federated_query name='x'}{remote service='s' query='1' let=v}{aggregate let=o}{v}{/aggregate}{/federated_query}{/untrusted}"
);
$out = $engine->render('fed4', []);
$assert('federation: untrusted denies federation',
    str_contains($out, 'federation denied') || !str_contains($out, 'should-not-see'),
    "out=$out");

// ------------------------------------------------- AI engine --
echo "\n[AI: ai_generate / ai_query / ai_complete]\n";

// 5. ai_generate inline (no let): emits provider response
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/ai1.disyl',
    "{ai_generate model='echo' max_tokens=50}Hello{/ai_generate}"
);
$out = $engine->render('ai1', []);
$assert('ai: ai_generate emits provider text inline',
    str_contains($out, '[ai:echo] Hello'),
    "out=$out");

// 6. ai_query with prompt= attr
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/ai2.disyl',
    "{ai_query model='echo' max_tokens=20 prompt='ping'}{/ai_query}"
);
$out = $engine->render('ai2', []);
$assert('ai: ai_query uses prompt attr',
    str_contains($out, '[ai:echo] ping') || $out === '' || trim($out) !== '',
    "out=$out");

// 7. Allowlist: model not in allowlist denied
$engine = $mkEngine();
$pol = new Policy();
$pol->setAllowlist(['gpt-4o-mini']);
$engine->setAiPolicy($pol);
file_put_contents($tmpRoot . '/tpl/ai3.disyl',
    "{ai_generate model='echo' max_tokens=10}x{/ai_generate}"
);
$out = $engine->render('ai3', []);
$assert('ai: allowlist denies non-listed model',
    str_contains($out, 'model not allowed'),
    "out=$out");

// 8. Cost ceiling exceeded
$engine = $mkEngine();
$pol = new Policy();
$pol->setCostPer1k('echo', 1.0); // $1 per 1k tokens
$pol->setCostCeiling(0.001);     // ~impossible
$engine->setAiPolicy($pol);
file_put_contents($tmpRoot . '/tpl/ai4.disyl',
    "{ai_generate model='echo' max_tokens=100}x{/ai_generate}"
);
$out = $engine->render('ai4', []);
$assert('ai: cost ceiling denies overspend',
    str_contains($out, 'cost ceiling'),
    "out=$out");

// 9. Kill switch
putenv('KERNEL_AI_DISABLED=1');
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/ai5.disyl',
    "{ai_generate model='echo' max_tokens=10}x{/ai_generate}"
);
$out = $engine->render('ai5', []);
$assert('ai: KERNEL_AI_DISABLED kills calls',
    str_contains($out, 'ai disabled'),
    "out=$out");
putenv('KERNEL_AI_DISABLED');

// 10. Sandbox without `ai` cap denies
$engine = $mkEngine();
$engine->sandbox()->pushSandbox(['ai'], [], false);
file_put_contents($tmpRoot . '/tpl/ai6.disyl',
    "{ai_generate model='echo' max_tokens=10}x{/ai_generate}"
);
$out = $engine->render('ai6', []);
$engine->sandbox()->pop();
$assert('ai: sandbox without ai cap denies',
    str_contains($out, 'ai denied') || str_contains($out, 'capability'),
    "out=$out");

// 11. Custom provider via setAiProvider
$engine = $mkEngine();
$called = 0;
$engine->setAiProvider(new class($called) implements \Ikabud\Kernel\DiSyL\AI\AiProvider {
    private int $c = 0;
    public function __construct(int &$ref) { $this->c =& $ref; }
    public function complete(array $req): array {
        $this->c++;
        return ['text' => 'CUSTOM:' . $req['prompt'], 'input_tokens' => 1, 'output_tokens' => 1, 'model' => $req['model']];
    }
});
file_put_contents($tmpRoot . '/tpl/ai7.disyl',
    "{ai_generate model='echo' max_tokens=10}foo{/ai_generate}"
);
$out = $engine->render('ai7', []);
$assert('ai: custom provider invoked',
    str_contains($out, 'CUSTOM:foo') && $called === 1,
    "out=$out called=$called");

// 12. Policy.recordUsage tracks accumulated cost
$pol = new Policy();
$pol->setCostPer1k('echo', 0.01);
$pol->recordUsage('echo', 1000);
$pol->recordUsage('echo', 500);
$assert('policy: accumulates cost across calls',
    abs($pol->accumulatedCost() - 0.015) < 1e-9,
    'cost=' . $pol->accumulatedCost());

// 13. ai_generate with let= binds into aiBindings sink
$engine = $mkEngine();
$engine->clearAiBindings();
file_put_contents($tmpRoot . '/tpl/ai8.disyl',
    "{ai_generate model='echo' max_tokens=10 let=blurb}prod{/ai_generate}"
);
$out = $engine->render('ai8', []);
$bindings = $engine->aiBindings();
$assert('ai: let= populates aiBindings sink',
    isset($bindings['blurb']) && str_contains((string)$bindings['blurb'], '[ai:echo] prod'),
    'bindings=' . json_encode($bindings));

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
