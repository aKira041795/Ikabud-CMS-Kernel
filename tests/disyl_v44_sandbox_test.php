<?php

declare(strict_types=1);

/**
 * DiSyL 4.4 — Sandbox + capability scoping tests.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../kernel/DiSyL/Security/CapabilitySet.php';
require_once __DIR__ . '/../kernel/DiSyL/Security/Sandbox.php';
require_once __DIR__ . '/../kernel/DiSyL/Security/SandboxViolation.php';
require_once __DIR__ . '/../kernel/DiSyL/Cache/FragmentStore.php';
require_once __DIR__ . '/../kernel/DiSyL/Experiments/Bucketer.php';

use Ikabud\Kernel\DiSyL\Cache\FragmentStore;
use Ikabud\Kernel\DiSyL\Experiments\Bucketer;
use Ikabud\Kernel\DiSyL\Security\CapabilitySet;
use Ikabud\Kernel\DiSyL\Security\Sandbox;
use Ikabud\Kernel\DiSyL\Security\SandboxViolation;
use Ikabud\Kernel\DiSyL\TemplateEngine;

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};

echo "DiSyL 4.4 — Sandbox + Capability Scoping\n";
echo str_repeat('=', 64) . "\n";

$tmpRoot = sys_get_temp_dir() . '/disyl44_' . bin2hex(random_bytes(4));
@mkdir($tmpRoot . '/tpl', 0777, true);
@mkdir($tmpRoot . '/cache', 0777, true);
@mkdir($tmpRoot . '/audit', 0777, true);
@mkdir($tmpRoot . '/frag', 0777, true);
@mkdir($tmpRoot . '/exp', 0777, true);

// ---------------------------------------------------------- CapabilitySet --
echo "\n[CapabilitySet]\n";
$full = CapabilitySet::full();
$assert('caps: full allows raw.html',  $full->allows('raw.html'));
$assert('caps: full allows network',   $full->allows('network'));
$strict = CapabilitySet::strict();
$assert('caps: strict denies raw.html',  !$strict->allows('raw.html'));
$assert('caps: strict denies all tags',  $strict->tags() === []);

$narrowed = $full->narrow(['raw.html', 'network']);
$assert('caps: narrow removes deny set',
    !$narrowed->allows('raw.html') && !$narrowed->allows('network')
    && $narrowed->allows('db.read'));

$intersected = $full->narrow([], ['raw.html', 'experiment']);
$assert('caps: narrow with allow intersects',
    $intersected->allows('raw.html')
    && $intersected->allows('experiment')
    && !$intersected->allows('network'));

// Cannot widen — narrow(strict) returns subset
$child = $strict->narrow([], ['raw.html']);
$assert('caps: cannot widen from strict',
    !$child->allows('raw.html'));

// Hash deterministic + order-independent
$h1 = (new CapabilitySet(['raw.html', 'network']))->hash();
$h2 = (new CapabilitySet(['network', 'raw.html']))->hash();
$assert('caps: hash is order-independent', $h1 === $h2);

// ----------------------------------------------------------------- Sandbox --
echo "\n[Sandbox]\n";
$sb = new Sandbox(CapabilitySet::full(), $tmpRoot . '/audit');
$sb->clearViolations();

$assert('sandbox: depth starts at 1',  $sb->depth() === 1);
$assert('sandbox: full allows raw',    $sb->require('raw.html', 'op'));

$sb->pushSandbox(['raw.html'], []);
$assert('sandbox: after push deny → denied',
    !$sb->require('raw.html', '| raw'));

$sb->pushSandbox(['network'], []);
$assert('sandbox: child still denies parent deny',
    !$sb->require('raw.html', '| raw'));
$assert('sandbox: child also denies own deny',
    !$sb->require('network', 'fetch'));

$sb->pop();
$sb->pop();
$assert('sandbox: pop restores parent', $sb->require('raw.html', 'op'));

// Untrusted forces strict regardless
$sb->pushUntrusted();
$assert('sandbox: untrusted denies everything',
    !$sb->require('raw.html', 'attack')
    && !$sb->require('network', 'attack'));
$sb->pop();

// Strict-mode raises
$sb2 = new Sandbox(CapabilitySet::full(), $tmpRoot . '/audit');
$sb2->setStrict(true);
$sb2->pushSandbox(['raw.html'], []);
$threw = false;
try { $sb2->require('raw.html', 'op'); } catch (SandboxViolation $e) { $threw = true; }
$assert('sandbox: strict mode raises', $threw);
$sb2->pop();

// Audit log
$violations = $sb->readViolations();
$assert('sandbox: violations recorded',
    count($violations) >= 3,
    'count=' . count($violations));

// Audit redacts secrets
$sb3 = new Sandbox(CapabilitySet::strict(), $tmpRoot . '/audit3');
@mkdir($tmpRoot . '/audit3', 0777, true);
$sb3->require('raw.html', 'login', '{"password":"hunter2","other":"ok"}');
$sb3->require('network', 'api', 'Authorization: Bearer abc.def.ghi');
$rows = $sb3->readViolations();
$assert('audit: password redacted',
    str_contains($rows[0]['sample'], '***') && !str_contains($rows[0]['sample'], 'hunter2'));
$assert('audit: bearer token redacted',
    str_contains($rows[1]['sample'], 'Bearer ***') && !str_contains($rows[1]['sample'], 'abc.def.ghi'));

// ------------------------------------------------------- Engine: sandbox --
echo "\n[Engine: sandbox]\n";

$mkEngine = function () use ($tmpRoot): TemplateEngine {
    $e = new TemplateEngine($tmpRoot . '/tpl', $tmpRoot . '/cache', false);
    $e->setFragmentStore(new FragmentStore($tmpRoot . '/frag'));
    $e->setBucketer(new Bucketer($tmpRoot . '/exp'));
    $e->setSandbox(new Sandbox(CapabilitySet::full(), $tmpRoot . '/audit-engine'));
    return $e;
};
@mkdir($tmpRoot . '/audit-engine', 0777, true);

// 1. raw filter outside sandbox passes through
file_put_contents($tmpRoot . '/tpl/raw1.disyl', '{html | raw}');
$out = $mkEngine()->render('raw1', ['html' => '<b>hi</b>']);
$assert('engine: raw outside sandbox renders unescaped',
    $out === '<b>hi</b>',
    "got: $out");

// 2. raw filter inside sandbox with deny is auto-escaped + violation recorded
file_put_contents($tmpRoot . '/tpl/raw2.disyl',
    "{sandbox deny=['raw.html']}\n{html | raw}\n{/sandbox}"
);
$engine = $mkEngine();
$out = $engine->render('raw2', ['html' => '<b>hi</b>']);
$assert('engine: raw denied is auto-escaped',
    str_contains($out, '&lt;b&gt;hi&lt;/b&gt;'),
    "got: $out");
$violations = $engine->sandbox()->readViolations();
$assert('engine: violation recorded for raw',
    count($violations) >= 1 && $violations[count($violations) - 1]['tag'] === 'raw.html');

// 3. untrusted forces strict — invalidate denied
file_put_contents($tmpRoot . '/tpl/inv-utr.disyl',
    "{untrusted}{invalidate 'product:1'}OK{/untrusted}"
);
$engine = $mkEngine();
$store = $engine; // alias
$out = $engine->render('inv-utr', []);
$assert('engine: untrusted denies invalidate but body still renders',
    str_contains($out, 'OK'),
    "got: $out");

// 4. trusted re-elevates — raw allowed inside trusted nested in deny block
file_put_contents($tmpRoot . '/tpl/trust.disyl',
    "{sandbox deny=['raw.html']}{trusted}{html | raw}{/trusted}{/sandbox}"
);
$out = $mkEngine()->render('trust', ['html' => '<i>x</i>']);
$assert('engine: trusted inside sandbox re-allows raw',
    $out === '<i>x</i>',
    "got: $out");

// 5. trusted inside untrusted is rejected (forced strict)
file_put_contents($tmpRoot . '/tpl/bad.disyl',
    "{untrusted}{trusted}{html | raw}{/trusted}{/untrusted}"
);
$engine = $mkEngine();
$out = $engine->render('bad', ['html' => '<x>']);
$assert('engine: trusted inside untrusted does not re-allow raw',
    str_contains($out, '&lt;x&gt;'),
    "got: $out");

// 6. policy='strict' forces all denies
file_put_contents($tmpRoot . '/tpl/strict.disyl',
    "{sandbox policy='strict'}{html | raw}{/sandbox}"
);
$out = $mkEngine()->render('strict', ['html' => '<s>']);
$assert('engine: policy=strict denies raw',
    str_contains($out, '&lt;s&gt;'),
    "got: $out");

// 7. invalidate denied inside untrusted does not actually invalidate
$engine = $mkEngine();
$engine->fragmentStore()->put('test-key', 'STORED', ['t1'], 60, '_global');
file_put_contents($tmpRoot . '/tpl/blocked-inv.disyl',
    "{untrusted}{invalidate 't1'}{/untrusted}"
);
$engine->render('blocked-inv', []);
$still = $engine->fragmentStore()->tryGet('test-key', ['t1'], '_global');
$assert('engine: untrusted blocks invalidate side-effect',
    $still === 'STORED',
    "got: " . var_export($still, true));

// 8. Pop on exception — sandbox depth restored after violation in strict mode
$engine = $mkEngine();
$engine->sandbox()->setStrict(true);
file_put_contents($tmpRoot . '/tpl/depth.disyl',
    "{sandbox deny=['raw.html']}{html | raw}{/sandbox}"
);
$threw = false;
try { $engine->render('depth', ['html' => '<x>']); } catch (\Throwable $e) { $threw = true; }
$d = $engine->sandbox()->depth();
$assert('engine: depth restored after strict violation',
    $d === 1,
    "depth=$d threw=" . var_export($threw, true));

// ---------------------------------------------------------- Cleanup --------
foreach (['tpl','cache','frag','exp','audit','audit3','audit-engine'] as $d) {
    @array_map('unlink', glob($tmpRoot . '/' . $d . '/*') ?: []);
    @array_map('unlink', glob($tmpRoot . '/' . $d . '/*/*') ?: []);
    foreach (glob($tmpRoot . '/' . $d . '/*') ?: [] as $sub) @rmdir($sub);
    @rmdir($tmpRoot . '/' . $d);
}
@rmdir($tmpRoot);

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
