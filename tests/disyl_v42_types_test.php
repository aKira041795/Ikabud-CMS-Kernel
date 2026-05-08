<?php

declare(strict_types=1);

/**
 * DiSyL 4.2 — Type system tests.
 *
 * Covers parser, subtype rules, utility-type resolution, and end-to-end
 * template type-checking via TypeChecker.
 */

require_once __DIR__ . '/../bootstrap.php';

use Ikabud\Kernel\DiSyL\TemplateEngine;
use Ikabud\Kernel\DiSyL\Types\ArrayType;
use Ikabud\Kernel\DiSyL\Types\LiteralType;
use Ikabud\Kernel\DiSyL\Types\ObjectType;
use Ikabud\Kernel\DiSyL\Types\PrimitiveType;
use Ikabud\Kernel\DiSyL\Types\Subtype;
use Ikabud\Kernel\DiSyL\Types\TypeChecker;
use Ikabud\Kernel\DiSyL\Types\TypeParser;
use Ikabud\Kernel\DiSyL\Types\TypeRef;
use Ikabud\Kernel\DiSyL\Types\UnionType;

$pass = 0; $fail = 0;
$assert = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
    if ($ok) { $pass++; echo "  PASS  $name\n"; }
    else     { $fail++; echo "  FAIL  $name" . ($detail !== '' ? "  → $detail" : '') . "\n"; }
};

echo "DiSyL 4.2 — Type System Tests\n";
echo str_repeat('=', 64) . "\n";

// ---------------------------------------------------------------- Parser ----
echo "\n[Parser]\n";
$p = new TypeParser();

$r = $p->parse('context: { name: string; age?: number }');
$assert('parse: context object', $r['errors'] === [] && $r['context'] instanceof ObjectType);
$ctx = $r['context'];
$assert('parse: optional prop', $ctx instanceof ObjectType && $ctx->properties['age']['optional'] === true);
$assert('parse: required prop', $ctx instanceof ObjectType && $ctx->properties['name']['optional'] === false);

$r = $p->parse('context: string | number | null');
$assert('parse: union', $r['context'] instanceof UnionType && count($r['context']->members) === 3);

$r = $p->parse('context: string[]');
$assert('parse: array', $r['context'] instanceof ArrayType);

$r = $p->parse('context: readonly string[]');
$assert('parse: readonly array', $r['context'] instanceof ArrayType && $r['context']->readonly === true);

$r = $p->parse("context: 'admin' | 'user' | 'guest'");
$assert(
    'parse: literal union',
    $r['context'] instanceof UnionType
    && $r['context']->members[0] instanceof LiteralType
    && $r['context']->members[0]->value === 'admin'
);

$r = $p->parse('type User = { id: number; name: string }; context: User');
$assert(
    'parse: type alias + ref',
    $r['errors'] === []
    && isset($r['types']['User'])
    && $r['context'] instanceof TypeRef
    && $r['context']->name === 'User'
);

$r = $p->parse('context: Pick<User, "id" | "name">');
$assert(
    'parse: utility ref',
    $r['context'] instanceof TypeRef
    && $r['context']->name === 'Pick'
    && count($r['context']->args) === 2
);

// --------------------------------------------------------------- Subtype ----
echo "\n[Subtype]\n";

$str = new PrimitiveType('string');
$num = new PrimitiveType('number');

$assert('sub: literal → primitive widen', Subtype::assignable(new LiteralType('hi'), $str, []));
$assert('sub: number-literal → number',   Subtype::assignable(new LiteralType(42), $num, []));
$assert('sub: string ≢ number',           !Subtype::assignable($str, $num, []));
$assert('sub: union member → primitive',
    Subtype::assignable(new UnionType([new LiteralType('a'), new LiteralType('b')]), $str, []));
$assert('sub: source-union all-or-nothing',
    !Subtype::assignable(new UnionType([new LiteralType('a'), new LiteralType(1)]), $str, []));
$assert('sub: → target union (any-of)',
    Subtype::assignable($str, new UnionType([$str, $num]), []));

$objSrc = new ObjectType([
    'a' => ['type' => $str, 'optional' => false, 'readonly' => false],
    'b' => ['type' => $num, 'optional' => false, 'readonly' => false],
    'c' => ['type' => $str, 'optional' => false, 'readonly' => false],
]);
$objWide = new ObjectType([
    'a' => ['type' => $str, 'optional' => false, 'readonly' => false],
]);
$objMissing = new ObjectType([
    'z' => ['type' => $str, 'optional' => false, 'readonly' => false],
]);
$objOptionalMissing = new ObjectType([
    'a' => ['type' => $str, 'optional' => false, 'readonly' => false],
    'q' => ['type' => $str, 'optional' => true,  'readonly' => false],
]);

$assert('sub: object width (extra source props ok)', Subtype::assignable($objSrc, $objWide, []));
$assert('sub: object missing required prop fails',   !Subtype::assignable($objSrc, $objMissing, []));
$assert('sub: object missing optional prop ok',      Subtype::assignable($objSrc, $objOptionalMissing, []));

$arrStr = new ArrayType($str);
$arrNum = new ArrayType($num);
$assert('sub: array element compat',     Subtype::assignable($arrStr, $arrStr, []));
$assert('sub: array element mismatch',   !Subtype::assignable($arrStr, $arrNum, []));
$assert('sub: readonly target accepts mutable', Subtype::assignable($arrStr, new ArrayType($str, true), []));
$assert('sub: mutable target rejects readonly', !Subtype::assignable(new ArrayType($str, true), $arrStr, []));

// --------------------------------------------------------- Utility types ----
echo "\n[Utility]\n";

$src = 'type U = { id: number; name: string; bio?: string }; context: Partial<U>';
$r = (new TypeParser())->parse($src);
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert(
    'utility: Partial makes all optional',
    $resolved instanceof ObjectType
    && $resolved->properties['id']['optional'] === true
    && $resolved->properties['name']['optional'] === true
);

$src = 'type U = { id: number; name?: string }; context: Required<U>';
$r = (new TypeParser())->parse($src);
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert(
    'utility: Required makes all required',
    $resolved instanceof ObjectType
    && $resolved->properties['name']['optional'] === false
);

$src = "type U = { id: number; name: string; bio: string }; context: Pick<U, 'id' | 'name'>";
$r = (new TypeParser())->parse($src);
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert(
    'utility: Pick keeps named only',
    $resolved instanceof ObjectType
    && array_keys($resolved->properties) === ['id', 'name']
);

$src = "type U = { id: number; name: string; bio: string }; context: Omit<U, 'bio'>";
$r = (new TypeParser())->parse($src);
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert(
    'utility: Omit drops named',
    $resolved instanceof ObjectType
    && array_keys($resolved->properties) === ['id', 'name']
);

$src = 'type U = { id: number; name: string }; context: Readonly<U>';
$r = (new TypeParser())->parse($src);
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert(
    'utility: Readonly marks all',
    $resolved instanceof ObjectType
    && $resolved->properties['id']['readonly'] === true
);

// ------------------------------------------------------------ TypeChecker --
echo "\n[TypeChecker]\n";
$tc = new TypeChecker();

$tpl = <<<'DSL'
{types}
type User = { id: number; name: string; email?: string }
context: User
{/types}
<h1>{name}</h1>
<p>{email}</p>
DSL;
$d = $tc->check($tpl, 'tpl1');
$assert('check: clean template (known + optional)', $d === [], json_encode($d));

$tpl = <<<'DSL'
{types}
context: { name: string }
{/types}
<h1>{name}</h1>
<p>{age}</p>
DSL;
$d = (new TypeChecker())->check($tpl, 'tpl2');
$assert('check: unknown property reported',
    count($d) === 1 && $d[0]['code'] === 'DISYL_TYPE_UNKNOWN_PROP', json_encode($d));

$tpl = <<<'DSL'
{types}
context: { items: { title: string }[] }
{/types}
{foreach items as item}
  <li>{item.title}</li>
{/foreach}
DSL;
$d = (new TypeChecker())->check($tpl, 'tpl3');
$assert('check: foreach binding clears errors', $d === [], json_encode($d));

$tpl = <<<'DSL'
{types}
context: { user: { name: string } }
{/types}
<h1>{user.name}</h1>
<p>{user.age}</p>
DSL;
$d = (new TypeChecker())->check($tpl, 'tpl4');
$assert('check: dotted unknown reported',
    count($d) === 1 && $d[0]['code'] === 'DISYL_TYPE_UNKNOWN_PROP', json_encode($d));

$tpl = '<h1>{anything}</h1>';
$d = (new TypeChecker())->check($tpl, 'tpl5');
$assert('check: no types block → no diagnostics', $d === []);

// --------------------------------------------------- Engine integration ----
echo "\n[Engine]\n";
$tmpDir = sys_get_temp_dir() . '/disyl42_' . bin2hex(random_bytes(4));
@mkdir($tmpDir . '/cache', 0777, true);
$engine = new TemplateEngine($tmpDir, $tmpDir . '/cache', false);
file_put_contents($tmpDir . '/tpl.disyl', <<<'DSL'
{types}
context: { name: string }
{/types}
Hello, {name}!
DSL);
$out = $engine->render('tpl', ['name' => 'World']);
$assert('engine: types block stripped from output',
    !str_contains($out, '{types}') && str_contains($out, 'Hello, World!'),
    trim($out));
@unlink($tmpDir . '/tpl.disyl');
@array_map('unlink', glob($tmpDir . '/cache/*') ?: []);
@rmdir($tmpDir . '/cache');
@rmdir($tmpDir);

// ---------------------------------------------------- Recursion safety -----
echo "\n[Safety]\n";
$src = 'type A = B; type B = A; context: A';
$r = (new TypeParser())->parse($src);
// Should not infinite-loop.
$resolved = Subtype::resolve($r['context'], $r['types']);
$assert('safety: cyclic alias bails (no infinite loop)', $resolved instanceof TypeRef || $resolved !== null);

echo "\n" . str_repeat('=', 64) . "\n";
echo "Total: " . ($pass + $fail) . "  PASS: $pass  FAIL: $fail\n";
exit($fail === 0 ? 0 : 1);
