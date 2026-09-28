<?php
/** Deterministic regression tests for the declared capability authority audit. */

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Workbench/Audit/CapabilityAuthorityAuditor.php';

use Ikabud\Kernel\Workbench\Audit\CapabilityAuthorityAuditor;

$pass = 0;
$fail = 0;

function caa_test(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  ✓ ' : '  ✗ ') . $label . (!$ok && $detail !== '' ? " — {$detail}" : '') . "\n";
}

/**
 * @param array<string, mixed> $capabilities
 * @param array<string, mixed> $manifestOverrides
 */
function caa_module(string $root, string $id, array $capabilities, string $php, array $manifestOverrides = []): string
{
    $path = $root . '/modules/' . $id;
    if (!mkdir($path, 0700, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create fixture module: ' . $id);
    }
    file_put_contents($path . '/module.json', json_encode(array_merge([
        'id' => $id,
        'capabilities' => $capabilities,
    ], $manifestOverrides), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    file_put_contents($path . '/helpers.php', $php);
    return $path;
}

function caa_line(string $source, string $needle): int
{
    $offset = strpos($source, $needle);
    if ($offset === false) {
        throw new RuntimeException('Fixture line anchor not found: ' . $needle);
    }
    return 1 + substr_count(substr($source, 0, $offset), "\n");
}

function caa_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
        $child = $path . '/' . $entry;
        is_dir($child) ? caa_remove_tree($child) : @unlink($child);
    }
    @rmdir($path);
}

function caa_kernel_file(string $root, string $source): void
{
    if (!mkdir($root . '/kernel', 0700, true) && !is_dir($root . '/kernel')) {
        throw new RuntimeException('Unable to create fixture kernel root');
    }
    file_put_contents($root . '/kernel/consumer.php', $source);
}

/** @param list<array<string, mixed>> $findings */
function caa_assert_no_code(string $label, array $findings, string $code): void
{
    $matches = array_filter($findings, static fn (array $finding): bool => ($finding['code'] ?? null) === $code);
    caa_test($label, $matches === [], json_encode($findings, JSON_UNESCAPED_SLASHES));
}

/** @param list<array<string, mixed>> $findings */
function caa_assert_finding(
    string $label,
    array $findings,
    string $code,
    string $file,
    int $line,
    string $severity = 'critical'
): void {
    $matches = array_values(array_filter(
        $findings,
        static fn (array $finding): bool => ($finding['code'] ?? null) === $code
    ));
    $actual = $matches[0] ?? null;
    caa_test(
        "{$label} emits exact {$code}/{$severity}/{$file}:{$line}",
        count($matches) === 1
            && ($actual['severity'] ?? null) === $severity
            && ($actual['file'] ?? null) === $file
            && ($actual['line'] ?? null) === $line,
        json_encode($findings, JSON_UNESCAPED_SLASHES)
    );
}

echo "=== Capability Authority Auditor ===\n";
// The real fleet must have NO unimplemented exposes. This assertion previously
// pinned 'security.audit@1' as the sole expected gap - that was a FALSE POSITIVE
// in the auditor, not a module defect. modules/security/helpers.php:111 declares
// the handler as an inline closure, and parseReturnedHandlerArray() only parsed
// string ('function') and array ('Class::method') values, silently dropping the
// closure. module-manager.php C3b resolves the same map at RUNTIME and accepts
// any is_callable() value, so C3b passed while this static check failed - the two
// instruments disagreed and the static one was wrong.
// Fixed by teaching the parser a 'closure' type. Do NOT add an expected id back
// here to make this pass: a non-empty list means a real module gap.
$real = (new CapabilityAuthorityAuditor(__DIR__ . '/../modules'))->audit();
$realExposeIds = [];
foreach ($real as $finding) {
    if (($finding['code'] ?? null) !== 'CAPABILITY_EXPOSE_UNIMPLEMENTED') {
        continue;
    }
    if (preg_match("/exposes '([^']+)'/", (string)($finding['message'] ?? ''), $match) === 1) {
        $realExposeIds[$match[1]] = true;
    }
}
$expectedRealExposeIds = [];
ksort($realExposeIds);
ksort($expectedRealExposeIds);
caa_test(
    'real repository CAPABILITY_EXPOSE_UNIMPLEMENTED is empty',
    array_keys($realExposeIds) === array_keys($expectedRealExposeIds),
    json_encode(array_keys($realExposeIds), JSON_UNESCAPED_SLASHES)
);

$temp = sys_get_temp_dir() . '/ikabud-capability-authority-' . bin2hex(random_bytes(6));
if (!mkdir($temp, 0700, true) && !is_dir($temp)) {
    throw new RuntimeException('Unable to create fixture root');
}

try {
    $providerPhp = <<<'PHP'
<?php
function provider_capability_handlers(): array
{
    return ['orders.read@1' => 'provider_orders_read_1'];
}
function provider_orders_read_1(): array { return []; }
PHP;
    caa_module($temp, 'provider', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
        'policy' => ['capabilities' => ['orders.read@1' => ['allow_callers' => ['provider', 'consumer']]]],
    ], $providerPhp);
    $cleanConsumerPhp = <<<'PHP'
<?php
function clean_calls($app): void
{
    app()->cap()->call('orders.read', []);
    $app->cap()->call("orders.read@1", []);
    app()->capabilities()->call('kernel.audit.record', []);
    // app()->cap()->call('comment.decoy@1', []);
    app()->cap()->call($dynamicCapability, []);
}
PHP;
    caa_module($temp, 'consumer', [
        'exposes' => [],
        'depends' => ['orders.read', 'kernel.audit.record'],
    ], $cleanConsumerPhp);
    $cleanFindings = (new CapabilityAuthorityAuditor($temp . '/modules'))->audit();
    caa_test(
        'clean fixture accepts quote styles, call spellings, version resolution, inventoried kernel capability, and non-literals',
        $cleanFindings === [],
        json_encode($cleanFindings, JSON_UNESCAPED_SLASHES)
    );

    $cases = [];

    $aRoot = $temp . '/a';
    $aPhp = <<<'PHP'
<?php
app()->cap()->call('missing.orders@1', []);
PHP;
    caa_module($aRoot, 'consumer', ['exposes' => [], 'depends' => []], $aPhp);
    caa_module(
        $aRoot,
        'nested/disabled-consumer',
        ['exposes' => [], 'depends' => []],
        $aPhp,
        ['enabled' => false]
    );
    $cases[] = [
        'A same unexposed call is found in enabled module and skipped in nested disabled module',
        $aRoot,
        'CAPABILITY_CALL_UNEXPOSED',
        'modules/consumer/helpers.php',
        caa_line($aPhp, "'missing.orders@1'")
    ];

    $bRoot = $temp . '/b';
    caa_module($bRoot, 'provider', [
        'exposes' => [['id' => 'orders.read@1']], 'depends' => [],
    ], $providerPhp);
    $bPhp = <<<'PHP'
<?php
app()->cap()->call("orders.read@2", []);
PHP;
    caa_module($bRoot, 'consumer', ['exposes' => [], 'depends' => ['orders.read@2']], $bPhp);
    $cases[] = ['B incompatible major', $bRoot, 'CAPABILITY_VERSION_UNEXPOSED', 'modules/consumer/helpers.php', caa_line($bPhp, '"orders.read@2"')];

    $cRoot = $temp . '/c';
    caa_module($cRoot, 'provider', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
        'policy' => ['capabilities' => ['orders.read@1' => ['allow_callers' => ['consumer']]]],
    ], $providerPhp);
    $cPhp = <<<'PHP'
<?php
app()->cap()->call('orders.read@1', []);
PHP;
    caa_module($cRoot, 'consumer', ['exposes' => [], 'depends' => []], $cPhp);
    $cases[] = ['C undeclared consumer relationship', $cRoot, 'CAPABILITY_DEPENDENCY_UNDECLARED', 'modules/consumer/helpers.php', caa_line($cPhp, "'orders.read@1'")];

    $dRoot = $temp . '/d';
    caa_module($dRoot, 'provider', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
        'policy' => ['capabilities' => ['orders.read@1' => ['allow_callers' => ['provider']]]],
    ], $providerPhp);
    $dPhp = <<<'PHP'
<?php
$app->cap()->call('orders.read@1', []);
PHP;
    caa_module($dRoot, 'consumer', ['exposes' => [], 'depends' => ['orders.read@1']], $dPhp);
    $cases[] = ['D provider policy denial', $dRoot, 'CAPABILITY_CALLER_DENIED', 'modules/consumer/helpers.php', caa_line($dPhp, "'orders.read@1'")];

    $eRoot = $temp . '/e';
    $eManifest = [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ];
    caa_module($eRoot, 'provider', $eManifest, "<?php\nfunction provider_capability_handlers(): array { return []; }\n");
    $eManifestSource = (string)file_get_contents($eRoot . '/modules/provider/module.json');
    $cases[] = ['E declared expose without callable', $eRoot, 'CAPABILITY_EXPOSE_UNIMPLEMENTED', 'modules/provider/module.json', caa_line($eManifestSource, '"orders.read@1"')];

    $unknownKernelRoot = $temp . '/unknown-kernel';
    $unknownKernelPhp = "<?php\napp()->cap()->call('kernel.unknown@1', []);\n";
    caa_module($unknownKernelRoot, 'consumer', ['exposes' => [], 'depends' => []], $unknownKernelPhp);
    $cases[] = [
        'A unknown kernel namespace literal',
        $unknownKernelRoot,
        'CAPABILITY_CALL_UNEXPOSED',
        'modules/consumer/helpers.php',
        caa_line($unknownKernelPhp, "'kernel.unknown@1'")
    ];

    $unknownKernelDependencyRoot = $temp . '/unknown-kernel-dependency';
    caa_module(
        $unknownKernelDependencyRoot,
        'consumer',
        ['exposes' => [], 'depends' => ['kernel.unknown@1']],
        "<?php\n"
    );
    $unknownKernelManifest = (string)file_get_contents(
        $unknownKernelDependencyRoot . '/modules/consumer/module.json'
    );
    $cases[] = [
        'unknown kernel dependency without a call site',
        $unknownKernelDependencyRoot,
        'CAPABILITY_DEPENDENCY_UNEXPOSED',
        'modules/consumer/module.json',
        caa_line($unknownKernelManifest, '"kernel.unknown@1"')
    ];

    $newKernelCallRoot = $temp . '/new-kernel-call';
    $newKernelCallPhp = "<?php\napp()->cap()->call('kernel.new_unknown@1', []);\n";
    caa_module($newKernelCallRoot, 'consumer', ['exposes' => [], 'depends' => []], $newKernelCallPhp);
    $cases[] = [
        'A new unknown kernel call still fails closed-world inventory',
        $newKernelCallRoot,
        'CAPABILITY_CALL_UNEXPOSED',
        'modules/consumer/helpers.php',
        caa_line($newKernelCallPhp, "'kernel.new_unknown@1'")
    ];

    $kernelCRoot = $temp . '/kernel-c';
    caa_module($kernelCRoot, 'provider', ['exposes' => [['id' => 'orders.read@1']], 'depends' => []], $providerPhp);
    $kernelCPhp = "<?php\napp()->cap()->call('orders.read@1', []);\n";
    caa_kernel_file($kernelCRoot, $kernelCPhp);
    $cases[] = [
        'C unclassified kernel consumer',
        $kernelCRoot,
        'CAPABILITY_DEPENDENCY_UNDECLARED',
        'kernel/consumer.php',
        caa_line($kernelCPhp, "'orders.read@1'")
    ];

    $kernelDRoot = $temp . '/kernel-d';
    $aiProviderPhp = <<<'PHP'
<?php
function ai_provider_capability_handlers(): array
{
    return ['ai.text.generate@1' => 'ai_provider_generate'];
}
function ai_provider_generate(): array { return []; }
PHP;
    caa_module($kernelDRoot, 'ai-provider', [
        'exposes' => [['id' => 'ai.text.generate@1']],
        'depends' => [],
        'policy' => ['capabilities' => ['ai.text.generate@1' => ['allow_callers' => ['someone-else']]]],
    ], $aiProviderPhp);
    $kernelDPhp = "<?php\napp()->cap()->call('ai.text.generate@1', []);\n";
    caa_kernel_file($kernelDRoot, $kernelDPhp);
    $cases[] = [
        'D provider policy applies to classified kernel caller',
        $kernelDRoot,
        'CAPABILITY_CALLER_DENIED',
        'kernel/consumer.php',
        caa_line($kernelDPhp, "'ai.text.generate@1'")
    ];

    $eAdversarial = [
        'E commented map entry' => <<<'PHP'
<?php
function provider_capability_handlers(): array
{
    // return ['orders.read@1' => 'provider_orders_read_1'];
    return [];
}
function provider_orders_read_1(): array { return []; }
PHP,
        'E unrelated array entry' => <<<'PHP'
<?php
$unrelated = ['orders.read@1' => 'provider_orders_read_1'];
function provider_capability_handlers(): array { return []; }
function provider_orders_read_1(): array { return []; }
PHP,
        'E class method is not a bare global function' => <<<'PHP'
<?php
function provider_capability_handlers(): array
{
    return ['orders.read@1' => 'handle'];
}
class ProviderHandler { public static function handle(): array { return []; } }
PHP,
    ];
    foreach ($eAdversarial as $label => $php) {
        $root = $temp . '/e-' . md5($label);
        caa_module($root, 'provider', $eManifest, $php);
        $manifest = (string)file_get_contents($root . '/modules/provider/module.json');
        $cases[] = [
            $label,
            $root,
            'CAPABILITY_EXPOSE_UNIMPLEMENTED',
            'modules/provider/module.json',
            caa_line($manifest, '"orders.read@1"')
        ];
    }

    foreach ($cases as [$label, $root, $code, $file, $line]) {
        $findings = (new CapabilityAuthorityAuditor($root . '/modules'))->audit();
        caa_assert_finding($label, $findings, $code, $file, $line);
    }

    // ── Guard proof: MUST-REFUSE plus BOTH MUST-ALLOW paths ──────
    // MUST-REFUSE: declares an expose with neither a handler-map entry nor a
    // convention function, so the corrected auditor must still emit.
    $mustRefuseRoot = $temp . '/must-refuse';
    caa_module($mustRefuseRoot, 'refuse', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ], "<?php\nfunction refuse_unrelated(): array { return []; }\n");
    $mustRefuseManifest = (string)file_get_contents($mustRefuseRoot . '/modules/refuse/module.json');
    caa_assert_finding(
        'MUST-REFUSE: expose with neither map entry nor convention function is still flagged',
        (new CapabilityAuthorityAuditor($mustRefuseRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED',
        'modules/refuse/module.json',
        caa_line($mustRefuseManifest, '"orders.read@1"')
    );

    // Service-module implementation is its binding, with both fields required.
    $serviceMissingEndpointRoot = $temp . '/service-missing-endpoint';
    caa_module($serviceMissingEndpointRoot, 'remote', [
        'exposes' => [['id' => 'remote.read@1']],
        'depends' => [],
    ], "<?php\n", [
        'type' => 'service-module',
        'service' => ['protocol' => 'http+json'],
    ]);
    $serviceMissingEndpointManifest = (string)file_get_contents(
        $serviceMissingEndpointRoot . '/modules/remote/module.json'
    );
    caa_assert_finding(
        'MUST-REFUSE: service-module expose without endpoint is still flagged',
        (new CapabilityAuthorityAuditor($serviceMissingEndpointRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED',
        'modules/remote/module.json',
        caa_line($serviceMissingEndpointManifest, '"remote.read@1"')
    );

    $serviceMissingProtocolRoot = $temp . '/service-missing-protocol';
    caa_module($serviceMissingProtocolRoot, 'remote', [
        'exposes' => [['id' => 'remote.read@1']],
        'depends' => [],
    ], "<?php\n", [
        'type' => 'service-module',
        'service' => ['endpoint' => 'http://127.0.0.1:9998'],
    ]);
    $serviceMissingProtocolManifest = (string)file_get_contents(
        $serviceMissingProtocolRoot . '/modules/remote/module.json'
    );
    caa_assert_finding(
        'MUST-REFUSE: service-module expose without protocol is still flagged',
        (new CapabilityAuthorityAuditor($serviceMissingProtocolRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED',
        'modules/remote/module.json',
        caa_line($serviceMissingProtocolManifest, '"remote.read@1"')
    );

    $validServiceRoot = $temp . '/service-valid-binding';
    caa_module($validServiceRoot, 'remote', [
        'exposes' => [['id' => 'remote.read@1']],
        'depends' => [],
    ], "<?php\n", [
        'type' => 'service-module',
        'service' => [
            'endpoint' => 'http://127.0.0.1:9999',
            'protocol' => 'http+json',
        ],
    ]);
    caa_assert_no_code(
        'MUST-ALLOW: service-module expose with endpoint and protocol is implemented',
        (new CapabilityAuthorityAuditor($validServiceRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED'
    );

    // MUST-ALLOW (map only): the exported map resolves the handler while no
    // convention function exists.
    $mustAllowMapRoot = $temp . '/must-allow-map';
    caa_module($mustAllowMapRoot, 'maponly', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ], <<<'PHP'
<?php
function maponly_capability_handlers(): array
{
    return ['orders.read@1' => 'maponly_orders_read_1'];
}
function maponly_orders_read_1(): array { return []; }
PHP);
    caa_assert_no_code(
        'MUST-ALLOW: handler resolved via exported map only emits no finding',
        (new CapabilityAuthorityAuditor($mustAllowMapRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED'
    );

    // MUST-ALLOW (convention only): no map function is defined at all; only the
    // runtime convention symbol {prefix}_cap_{sanitised-capability} exists.
    $mustAllowConventionRoot = $temp . '/must-allow-convention';
    caa_module($mustAllowConventionRoot, 'convonly', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ], <<<'PHP'
<?php
function convonly_cap_orders_read_1(mixed $payload): array { return []; }
PHP);
    caa_assert_no_code(
        'MUST-ALLOW: handler resolved via convention function only emits no finding',
        (new CapabilityAuthorityAuditor($mustAllowConventionRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED'
    );

    // MUST-ALLOW (inline closure): an anonymous closure in the exported map is a
    // legitimate handler. module-manager.php C3b resolves the map at RUNTIME and
    // accepts any is_callable() value, so a closure passes there; the static
    // parser must agree or the two instruments disagree (real case:
    // modules/security/helpers.php:111 'security.audit@1').
    $mustAllowClosureRoot = $temp . '/must-allow-closure';
    caa_module($mustAllowClosureRoot, 'closureonly', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ], <<<'PHP'
<?php
function closureonly_capability_handlers(): array
{
    return [
        'orders.read@1' => function (array $input): array { return ['ok' => true]; },
    ];
}
PHP);
    caa_assert_no_code(
        'MUST-ALLOW: inline closure handler emits no finding',
        (new CapabilityAuthorityAuditor($mustAllowClosureRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED'
    );

    // MUST-REFUSE (map entry present but unresolvable): proves accepting closures
    // did NOT turn the map into "any entry counts". A string naming a function
    // that does not exist must still be flagged, because it is not callable.
    $mustRefuseDanglingRoot = $temp . '/must-refuse-dangling';
    caa_module($mustRefuseDanglingRoot, 'dangling', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
    ], <<<'PHP'
<?php
function dangling_capability_handlers(): array
{
    return ['orders.read@1' => 'dangling_missing_function'];
}
PHP);
    $mustRefuseDanglingManifest = (string)file_get_contents(
        $mustRefuseDanglingRoot . '/modules/dangling/module.json'
    );
    caa_assert_finding(
        'MUST-REFUSE: map entry naming a missing function is still flagged',
        (new CapabilityAuthorityAuditor($mustRefuseDanglingRoot . '/modules'))->audit(),
        'CAPABILITY_EXPOSE_UNIMPLEMENTED',
        'modules/dangling/module.json',
        caa_line($mustRefuseDanglingManifest, '"orders.read@1"')
    );

    $methodRoot = $temp . '/e-method-positive';
    $methodPhp = <<<'PHP'
<?php
function provider_capability_handlers(): array
{
    return ['orders.read@1' => [ProviderHandler::class, 'handle']];
}
class ProviderHandler { public static function handle(): array { return []; } }
PHP;
    caa_module($methodRoot, 'provider', $eManifest, $methodPhp);
    $methodFindings = (new CapabilityAuthorityAuditor($methodRoot . '/modules'))->audit();
    caa_assert_no_code('E accepts a declared Class::class/method callable pair', $methodFindings, 'CAPABILITY_EXPOSE_UNIMPLEMENTED');

    $policyCombineRoot = $temp . '/policy-combine';
    caa_module($policyCombineRoot, 'provider', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
        'policy' => [
            'default' => ['allow_callers' => ['consumer']],
            'capabilities' => ['orders.read@1' => ['allow_callers' => ['other']]],
        ],
    ], $providerPhp);
    caa_module($policyCombineRoot, 'consumer', ['exposes' => [], 'depends' => ['orders.read@1']], $cPhp);
    $combineFindings = (new CapabilityAuthorityAuditor($policyCombineRoot . '/modules'))->audit();
    caa_assert_no_code(
        'policy combines default and per-capability allow_callers like CapabilityBus',
        $combineFindings,
        'CAPABILITY_CALLER_DENIED'
    );

    $policyEmptyRoot = $temp . '/policy-empty';
    caa_module($policyEmptyRoot, 'provider', [
        'exposes' => [['id' => 'orders.read@1']],
        'depends' => [],
        'policy' => ['capabilities' => ['orders.read@1' => ['allow_callers' => []]]],
    ], $providerPhp);
    caa_module($policyEmptyRoot, 'consumer', ['exposes' => [], 'depends' => ['orders.read@1']], $cPhp);
    $emptyFindings = (new CapabilityAuthorityAuditor($policyEmptyRoot . '/modules'))->audit();
    caa_assert_no_code(
        'explicit empty allow_callers is no whitelist like CapabilityBus',
        $emptyFindings,
        'CAPABILITY_CALLER_DENIED'
    );
} finally {
    caa_remove_tree($temp);
}

echo "\n════════════════════════════════════════════\n";
echo "  Capability authority audit tests: {$pass} passed, {$fail} failed\n";
echo "════════════════════════════════════════════\n";

function caa_exit_code(): int
{
    global $fail;
    return $fail > 0 ? 1 : 0;
}

exit(caa_exit_code());
