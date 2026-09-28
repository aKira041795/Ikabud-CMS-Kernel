<?php

declare(strict_types=1);

require_once __DIR__ . '/../kernel/Workbench/Governance/GovernanceCensus.php';

use Ikabud\Kernel\Workbench\Governance\GovernanceCensus;

$root = sys_get_temp_dir() . '/governance-census-' . bin2hex(random_bytes(4));
$module = $root . '/modules/probe';
mkdir($module, 0777, true);
$manifest = ['id' => 'probe','routes' => 'routes.php'];
file_put_contents($module.'/module.json', json_encode($manifest));
file_put_contents($module.'/routes.php', <<<'PHP'
<?php return ['POST'=>['/governed'=>'probe:governedHandler','/plain'=>'probe:plainHandler','/exempt'=>'probe:plainHandler'],'GET'=>['/read-plain'=>'probe:plainHandler','/read-governed'=>'probe:governedHandler']];
PHP);
file_put_contents($module.'/handlers.php', <<<'PHP'
<?php
function governedHandler(array $p): void { governedHelper($p); }
function governedHelper(array $p): void { app()->cap()->call('probe.write@1', $p); }
function plainHandler(array $p): void { $x = "app()->cap()->call('fake')"; /* app()->cap()->call('fake') */ }
PHP);

$transportFixtures = [
    'event-handler.php' => ['EVENT', 'event'],
    'workflow-step.php' => ['SERVICE', 'workflow'],
    'cli-command.php' => ['CLI', 'cli'],
    'workbench-command.php' => ['WORKBENCH', 'workbench'],
    'service-handler.php' => ['SERVICE', 'service'],
    'queue-worker.php' => ['QUEUE', 'worker'],
];
foreach ($transportFixtures as $file => [$constant, $transport]) {
    file_put_contents($module . '/' . $file, "<?php\nfunction fixture_" . str_replace('-', '_', $transport) . "(): void { AuthorityScopeResolver::withScope(1, AuthorityScopeResolver::{$constant}, static function (): void { app()->cap()->call('probe.{$transport}@1', []); }); }\n");
}
file_put_contents($module . '/unresolved-service.php', <<<'PHP'
<?php
function unresolved_service(string $capability, array $options): void {
    app()->cap()->call($capability, [], $options);
    app()->cap()->call('probe.provider@1', [], ['provider' => $options['provider']]);
}
PHP);

/**
 * @param array<string, mixed> $result
 * @return array<string, array{dispatch: string, reach: string}>
 */
function censusIndex(array $result): array
{
    $index = [];
    foreach ($result['modules'][0]['operations'] as $op) {
        $index[$op['route']] = ['dispatch' => $op['dispatch'], 'reach' => $op['reach']];
    }
    return $index;
}

try {
    $result = (new GovernanceCensus($root))->scan('probe');
    $index = censusIndex($result);

    $nonHttp = $result['modules'][0]['non_http'] ?? [];
    $declaredTransports = [];
    $unresolvedReasons = [];
    foreach ($nonHttp as $entry) {
        if (($entry['scope'] ?? '') === 'declared') {
            $declaredTransports[] = $entry['transport'] ?? '';
        }
        if (($entry['scope'] ?? '') === 'unresolved') {
            $unresolvedReasons[] = $entry['how'] ?? '';
        }
    }
    sort($declaredTransports);
    $expectedTransports = ['cli', 'event', 'service', 'workbench', 'worker', 'workflow'];
    if ($declaredTransports !== $expectedTransports) {
        throw new RuntimeException('non-HTTP transport discovery mismatch: ' . json_encode($declaredTransports));
    }
    if (count($unresolvedReasons) !== 2
        || !in_array('capability id is held in a variable or computed expression', $unresolvedReasons, true)
        || !in_array('provider routing is selected dynamically', $unresolvedReasons, true)) {
        throw new RuntimeException('dynamic non-HTTP calls were not honestly unresolved: ' . json_encode($unresolvedReasons));
    }

    // reach: a real (transitive) bus call is detected; a string/comment is not.
    if (($index['/governed']['reach'] ?? null) !== 'bus-reachable') {
        throw new RuntimeException('real transitive bus call was not detected as bus-reachable');
    }
    if (($index['/plain']['reach'] ?? null) !== 'no-bus-call') {
        throw new RuntimeException('string/comment made plain route bus-reachable');
    }

    // dispatch: reaching the bus inside a handler is NOT request authority.
    if ($index['/governed']['dispatch'] !== 'undeclared') {
        throw new RuntimeException('a handler bus call was misreported as dispatch-enforced');
    }

    $summary = $result['summary'][0];
    if (($summary['dispatch_enforced'] ?? null) !== 0 || ($summary['bus_reachable'] ?? null) !== 1) {
        throw new RuntimeException('summary conflated dispatch enforcement with bus reachability: ' . json_encode($summary));
    }

    // Reads are summarised under their own denominator and never merged into the write
    // figure. Regression guard: reads were classified but dropped before summarising, so a
    // read declaration moved no published number and the write ratio read as total
    // authority coverage. These assertions fail if that hiding returns.
    $writeTotal = (int) ($summary['write_total'] ?? -1);
    $readTotal = (int) ($summary['read_total'] ?? -1);
    if ($writeTotal !== 3) {
        throw new RuntimeException('write_total must count only POST/PUT/PATCH/DELETE, got ' . $writeTotal);
    }
    if ($readTotal !== 2) {
        throw new RuntimeException('read_total must count only GET/HEAD/OPTIONS, got ' . $readTotal);
    }
    if ((int) ($summary['total'] ?? -1) !== $writeTotal) {
        throw new RuntimeException('the legacy total must remain the write-scoped figure, not a merged one');
    }
    if ((int) ($summary['read_dispatch_enforced'] ?? -1) !== 0) {
        throw new RuntimeException('an undeclared read must not count as enforced');
    }

    // dispatch: declaring authority makes the operation enforced.
    $manifest['capabilities'] = ['routes' => ['POST /governed' => 'probe.write@1']];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $declared = censusIndex((new GovernanceCensus($root))->scan('probe'));
    if (($declared['/governed']['dispatch'] ?? null) !== 'enforced') {
        throw new RuntimeException('a declared route was not reported as dispatch-enforced');
    }
    if (($declared['/plain']['dispatch'] ?? null) !== 'undeclared') {
        throw new RuntimeException('an undeclared route was misreported');
    }

    // A read declaration must move the READ figure and leave the WRITE figure untouched.
    // This is the invariant that makes the two measures independently meaningful.
    $manifest['capabilities'] = ['routes' => [
        'POST /governed' => 'probe.write@1',
        'GET /read-plain' => 'probe.read@1',
    ]];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $withRead = (new GovernanceCensus($root))->scan('probe')['summary'][0];
    if ((int) ($withRead['read_dispatch_enforced'] ?? -1) !== 1) {
        throw new RuntimeException('a declared read was not counted as read-enforced: ' . json_encode($withRead));
    }
    if ((int) ($withRead['read_total'] ?? -1) !== 2) {
        throw new RuntimeException('a read declaration altered the read denominator');
    }
    if ((int) ($withRead['write_total'] ?? -1) !== 3 || (int) ($withRead['write_dispatch_enforced'] ?? -1) !== 1) {
        throw new RuntimeException('a read declaration altered the write figure: ' . json_encode($withRead));
    }
    if ((int) ($withRead['dispatch_enforced'] ?? -1) !== (int) ($withRead['write_dispatch_enforced'] ?? -2)) {
        throw new RuntimeException('the legacy dispatch_enforced must remain write-scoped');
    }

    // dispatch: an exemption is its own state, never merged with enforcement.
    $manifest['governance']['exemptions'] = [['method' => 'POST', 'route' => '/exempt', 'reason' => 'session establishment']];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $exempted = censusIndex((new GovernanceCensus($root))->scan('probe'));
    if (($exempted['/exempt']['dispatch'] ?? null) !== 'exempt') {
        throw new RuntimeException('a reasoned exemption was not reported as exempt');
    }

    // An exemption without a reason is rejected, not silently accepted.
    $manifest['governance']['exemptions'] = [['method' => 'POST', 'route' => '/plain', 'reason' => '']];
    file_put_contents($module.'/module.json', json_encode($manifest));
    try {
        (new GovernanceCensus($root))->scan('probe');
        throw new RuntimeException('reasonless exemption was accepted');
    } catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(), 'requires a non-empty reason')) {
            throw $e;
        }
    }

    echo "PASS: routed dispatch/reach remain separate; read and write authority carry independent denominators; six non-HTTP transports found; dynamic capability/provider calls unresolved\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    } rmdir($root);
}
