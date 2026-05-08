<?php

declare(strict_types=1);

/**
 * DiSyL 4.3 — Cache + Experiments tests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/DiSyL/Cache/FragmentStore.php';
require_once __DIR__ . '/../kernel/DiSyL/Experiments/Bucketer.php';

use Ikabud\Kernel\DiSyL\Cache\FragmentStore;
use Ikabud\Kernel\DiSyL\Experiments\Bucketer;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};

echo "DiSyL 4.3 — Cache + Experiments\n";
echo str_repeat('=', 64) . "\n";

$tmpRoot = sys_get_temp_dir() . '/disyl43_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);
@mkdir($tmpRoot . '/frag', 0777, true);
@mkdir($tmpRoot . '/exp', 0777, true);

$mkEngine = function () use ($tmpRoot): TemplateEngine {
    $e = new TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);
    $e->setFragmentStore(new FragmentStore($tmpRoot . '/frag'));
    $e->setBucketer(new Bucketer($tmpRoot . '/exp'));
    return $e;
};

// ------------------------------------------------------------------ Cache --
echo "\n[FragmentStore]\n";
$store = new FragmentStore($tmpRoot . '/frag');

$assert('store: miss returns null', $store->tryGet('k1', [], '_t') === null);
$store->put('k1', 'BODY-A', [], 60, '_t');
$assert('store: hit after put',     $store->tryGet('k1', [], '_t') === 'BODY-A');

// Dependency invalidation
$store->put('k2', 'BODY-B', ['product:1'], 60, '_t');
$assert('store: hit with deps',     $store->tryGet('k2', ['product:1'], '_t') === 'BODY-B');
$store->invalidate(['product:1'], '_t');
$assert('store: miss after invalidate', $store->tryGet('k2', ['product:1'], '_t') === null);

// Tenant isolation
$store->put('k3', 'TENANT-A', [], 60, 'tenantA');
$store->put('k3', 'TENANT-B', [], 60, 'tenantB');
$assert('store: tenant A',          $store->tryGet('k3', [], 'tenantA') === 'TENANT-A');
$assert('store: tenant B',          $store->tryGet('k3', [], 'tenantB') === 'TENANT-B');
$store->invalidate(['x'], 'tenantA');
$assert('store: invalidate tenant A unrelated tag does not affect tenant B',
    $store->tryGet('k3', [], 'tenantB') === 'TENANT-B');

// TTL expiration
$store->put('k4', 'TEMP', [], 1, '_t');
$assert('store: TTL not yet expired', $store->tryGet('k4', [], '_t') === 'TEMP');

// ------------------------------------------------------------ Engine cache -
echo "\n[Engine: cache]\n";
$counter = 0;
$tplFile = $tmpRoot . '/tpl/c.disyl';
file_put_contents($tplFile, <<<'DSL'
{cache key='card:1' ttl=60}
  {depends_on 'product:1'}
  RENDERED-{nonce}
{/cache}
DSL);

$engine = $mkEngine();
$out1 = trim($engine->render('c', ['nonce' => 'v1']));
$out2 = trim($engine->render('c', ['nonce' => 'v2']));
$assert('cache: second render serves cached body',
    str_contains($out1, 'RENDERED-v1') && str_contains($out2, 'RENDERED-v1'),
    "out1=$out1 out2=$out2");

// Invalidate then re-render → fresh.
file_put_contents($tmpRoot . '/tpl/inv.disyl', "{invalidate 'product:1'}OK");
$engine->render('inv', []);
$out3 = trim($engine->render('c', ['nonce' => 'v3']));
$assert('cache: invalidate forces refresh',
    str_contains($out3, 'RENDERED-v3'),
    "out3=$out3");

// -------------------------------------------------------------- Bucketer ---
echo "\n[Bucketer]\n";
$b = new Bucketer($tmpRoot . '/exp');
$b->reset();

$v1 = $b->assign('exp1', 'subj-A', ['control' => 50, 'urgent' => 50]);
$v2 = $b->assign('exp1', 'subj-A', ['control' => 50, 'urgent' => 50]);
$assert('bucketer: sticky assignment', $v1 === $v2);

// Distribution within reasonable bounds
$b->reset();
$counts = ['control' => 0, 'urgent' => 0];
for ($i = 0; $i < 2000; $i++) {
    $v = $b->assign('exp2', 'subj-' . $i, ['control' => 50, 'urgent' => 50]);
    $counts[$v]++;
}
$ratio = $counts['control'] / 2000;
$assert('bucketer: 50/50 within ±10% over 2k subjects',
    $ratio > 0.40 && $ratio < 0.60,
    'ratio=' . $ratio);

// Zero weight rejected
$threw = false;
try { $b->assign('exp3', 's', []); } catch (\InvalidArgumentException $e) { $threw = true; }
$assert('bucketer: zero weight rejected', $threw);

// Conversion: requires prior assignment
$b->reset();
$b->assign('exp4', 's1', ['a' => 1, 'b' => 1]);
$assert('bucketer: convert with assignment returns true', $b->convert('exp4', 's1', 'goal') === true);
$assert('bucketer: convert without assignment returns false', $b->convert('exp4', 'never-seen', 'goal') === false);

// Exposure dedupe per request
$b->reset();
$b->expose('exp5', 's', 'req1', 'a');
$b->expose('exp5', 's', 'req1', 'a');
$b->expose('exp5', 's', 'req2', 'a');
$exposures = $b->readEvents('exposures');
$assert('bucketer: exposure deduped per request',
    count($exposures) === 2,
    'got ' . count($exposures));

// ----------------------------------------------------------- Engine: exp ---
echo "\n[Engine: experiment]\n";
file_put_contents($tmpRoot . '/tpl/x.disyl', <<<'DSL'
{experiment 'cta-copy'}
  {variant 'control' weight=1}A
  {variant 'urgent' weight=1}B
{/experiment}
DSL);

$engine = $mkEngine();
$engine->setSubjectId('user-42');
$engine->setRequestId('req-1');
$out = trim($engine->render('x', []));
$assert('engine: experiment emits one variant',
    in_array(trim($out), ['A', 'B'], true),
    "out=$out");

$engine2 = $mkEngine();
$engine2->setSubjectId('user-42');
$engine2->setRequestId('req-2');
$out2 = trim($engine2->render('x', []));
$assert('engine: same subject = same variant (sticky across renders)',
    trim($out) === trim($out2),
    "out=$out out2=$out2");

// Convert tag
file_put_contents($tmpRoot . '/tpl/conv.disyl', "{convert 'cta-copy' goal='clicked'}DONE");
$engine3 = $mkEngine();
$engine3->setSubjectId('user-42');
$out = $engine3->render('conv', []);
$assert('engine: convert tag is side-effect only (no output noise)',
    trim($out) === 'DONE',
    "got: " . trim($out));
$conversions = (new Bucketer($tmpRoot . '/exp'))->readEvents('conversions');
$assert('engine: convert recorded',
    count($conversions) >= 1
    && $conversions[0]['experiment'] === 'cta-copy'
    && $conversions[0]['goal'] === 'clicked');

// ------------------------------------------------------------------ Cleanup
@array_map('unlink', glob($tmpRoot . '/frag/_t/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/frag/tenantA/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/frag/tenantB/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/frag/_global/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/exp/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/tpl/*') ?: []);
@array_map('unlink', glob($tmpRoot . '/cache/*') ?: []);
foreach (['_t','tenantA','tenantB','_global'] as $d) @rmdir($tmpRoot . '/frag/' . $d);
@rmdir($tmpRoot . '/frag');
@rmdir($tmpRoot . '/exp');
@rmdir($tmpRoot . '/tpl');
@rmdir($tmpRoot . '/cache');
@rmdir($tmpRoot);

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
