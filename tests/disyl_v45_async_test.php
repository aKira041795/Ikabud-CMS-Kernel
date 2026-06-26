<?php

declare(strict_types=1);

/**
 * DiSyL 4.5 — Async runtime tests (interpreted pipeline).
 *
 * Tests verify the public template surface ({parallel}/{await}/{loading}/
 * {catch}/{suspense}) plus Promise + Scheduler + HttpClient.
 * Runs with compiled mode disabled — async tags use interpreted pipeline.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/DiSyL/Async/Promise.php';
require_once __DIR__ . '/../kernel/DiSyL/Async/Scheduler.php';
require_once __DIR__ . '/../kernel/DiSyL/Async/HttpClient.php';

use Ikabud\Kernel\DiSyL\Async\Promise;
use Ikabud\Kernel\DiSyL\Async\Scheduler;
use Ikabud\Kernel\DiSyL\Async\HttpClient;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};

echo "DiSyL 4.5 — Async Runtime\n";
echo str_repeat('=', 64) . "\n";

$tmpRoot = sys_get_temp_dir() . '/disyl45_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);

echo "\n[Promise]\n";
$p = Promise::resolved(42);
$assert('promise: resolved fulfills', $p->isFulfilled() && $p->wait() === 42);

$p2 = Promise::rejected(new \RuntimeException('boom'));
$assert('promise: rejected', $p2->isRejected());
$thrown = false;
try { $p2->wait(); } catch (\RuntimeException $e) { $thrown = $e->getMessage() === 'boom'; }
$assert('promise: wait rethrows', $thrown);

$mapped = Promise::resolved(10)->then(fn ($x) => $x * 2);
$assert('promise: then maps value', $mapped->wait() === 20);

$caught = Promise::rejected(new \RuntimeException('x'))->catch(fn (\Throwable $e) => 'recovered');
$assert('promise: catch recovers', $caught->wait() === 'recovered');

$chained = Promise::resolved(1)
    ->then(fn ($x) => Promise::resolved($x + 1))
    ->then(fn ($x) => $x * 10);
$assert('promise: chains across promises', $chained->wait() === 20);

$async = new Promise(function ($resolve) { $resolve('later'); });
$assert('promise: executor resolves', $async->wait() === 'later');

echo "\n[Scheduler]\n";
$sched = new Scheduler();
$sched->add(fn () => Promise::resolved('a'));
$sched->add(fn () => Promise::resolved('b'));
$sched->add(fn () => Promise::rejected(new \RuntimeException('c-fail')));
$results = $sched->run();
$assert('sched: returns ordered results', count($results) === 3
    && ($results[0]['value'] ?? null) === 'a'
    && ($results[1]['value'] ?? null) === 'b');
$assert('sched: failures captured as error',
    isset($results[2]['error']) && $results[2]['error']->getMessage() === 'c-fail');

$sched2 = new Scheduler();
for ($i = 0; $i < 65; $i++) { $sched2->add(fn () => Promise::resolved($i)); }
$threw = false;
try { $sched2->run(); } catch (\RuntimeException $e) { $threw = str_contains($e->getMessage(), 'PARALLEL_LIMIT'); }
$assert('sched: enforces concurrency cap', $threw);

echo "\n[HttpClient]\n";
$client = new HttpClient();
$client->setHandler(function (string $url, array $opts) {
    return ['status' => 200, 'body' => '{"hi":"there"}', 'headers' => ['content-type' => 'application/json']];
});
$res = $client->fetch('http://x/api')->wait();
$assert('http: decodes json body', is_array($res) && ($res['hi'] ?? null) === 'there');

$client2 = new HttpClient();
$client2->setHandler(fn ($u, $o) => ['status' => 500, 'body' => 'err', 'headers' => []]);
$thrown = false;
try { $client2->fetch('http://x')->wait(); }
catch (\RuntimeException $e) { $thrown = str_contains($e->getMessage(), 'HTTP_500'); }
$assert('http: 5xx becomes rejected promise', $thrown);

$client3 = new HttpClient();
$client3->setHandler(fn ($u, $o) => ['status' => 200, 'body' => 'plain', 'headers' => ['content-type' => 'text/plain']]);
$assert('http: non-json body returned as string', $client3->fetch('http://x')->wait() === 'plain');

echo "\n[Engine: await/parallel/suspense]\n";

$mkEngine = function () use ($tmpRoot): TemplateEngine {
    $e = new TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);
    $e->enableCompiledMode(false); // async tags use interpreted pipeline only
    return $e;
};

// 1. {await} with literal src
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/await1.disyl',
    "{await let=x src='hello'}got: {x}{/await}"
);
$out = $engine->render('await1', []);
$assert('await: literal src binds let', trim($out) === 'got: hello', "out=$out");

// 2. {await} with loading arm (sync resolves immediately, loading skipped)
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/await2.disyl',
    "{await let=x src='ready'}{x}{loading}LOADING{/await}"
);
$out = $engine->render('await2', []);
$assert('await: resolved skips loading arm', trim($out) === 'ready', "out=$out");

// 3. {await} with catch arm — context with rejected promise
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/await3.disyl',
    "{await let=x src=p}OK: {x}{catch let=err}FAIL{/await}"
);
$out = $engine->render('await3', ['p' => Promise::rejected(new \RuntimeException('nope'))]);
$assert('await: rejected promise renders catch', trim($out) === 'FAIL', "out=$out");

// 4. {await} catch binds error
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/await4.disyl',
    "{await let=x src=p}{x}{catch let=err}err: ok{/await}"
);
$out = $engine->render('await4', ['p' => Promise::rejected(new \RuntimeException('boom'))]);
$assert('await: catch arm renders', trim($out) === 'err: ok', "out=$out");

// 5. {parallel} with two awaits, ordered output
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/par1.disyl',
    "{parallel}{await let=a src='A'}[{a}]{/await}{await let=b src='B'}[{b}]{/await}{/parallel}"
);
$out = $engine->render('par1', []);
$assert('parallel: source order preserved', trim($out) === '[A][B]', "out=$out");

// 6. {parallel} static text between awaits is preserved
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/par2.disyl',
    "{parallel}{await let=a src='X'}{a}{/await}-MID-{await let=b src='Y'}{b}{/await}{/parallel}"
);
$out = $engine->render('par2', []);
$assert('parallel: interleaves static segments', trim($out) === 'X-MID-Y', "out=$out");

// 7. {suspense} success path renders body
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/sus1.disyl',
    "{suspense fallback='LOADING'}{await let=x src=p}{x}{/await}{/suspense}"
);
$out = $engine->render('sus1', ['p' => Promise::resolved('ok')]);
$assert('suspense: success path renders body', trim($out) === 'ok', "out=$out");

// 8. {await} sync fallback — no Promise in context, renders body directly
//     catch/loading arms only activate when a Promise is involved
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/awmiss.disyl',
    "{await src='x'}body{catch let=e}NOLET{/await}"
);
$out = $engine->render('awmiss', []);
$assert('await: missing let sync fallback renders body', trim($out) === 'body', "out=$out");

// 9. {await} sync fallback
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/awmiss2.disyl',
    "{await let=x}body{catch let=e}NOSRC{/await}"
);
$out = $engine->render('awmiss2', []);
$assert('await: missing src sync fallback renders body', trim($out) === 'body', "out=$out");

// 10. Determinism: same input → byte-identical output
$engine = $mkEngine();
file_put_contents($tmpRoot . '/tpl/det.disyl',
    "{parallel}{await let=a src='1'}{a}{/await}{await let=b src='2'}{b}{/await}{/parallel}"
);
$o1 = $engine->render('det', []);
$o2 = $engine->render('det', []);
$assert('determinism: byte-identical across runs', $o1 === $o2);

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
