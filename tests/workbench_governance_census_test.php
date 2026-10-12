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

$emptyRoutesModule = $root . '/modules/empty-routes';
mkdir($emptyRoutesModule, 0777, true);
file_put_contents($emptyRoutesModule . '/module.json', json_encode(['id' => 'empty-routes', 'routes' => []]));
file_put_contents($emptyRoutesModule . '/event-handler.php', <<<'PHP'
<?php
function empty_routes_event(): void {
    AuthorityScopeResolver::withScope(1, AuthorityScopeResolver::EVENT, static function (): void {
        app()->cap()->call('empty-routes.event@1', []);
    });
}
PHP);

foreach (['integer-routes' => 5, 'list-routes' => ['a']] as $id => $routes) {
    $malformedModule = $root . '/modules/' . $id;
    mkdir($malformedModule, 0777, true);
    file_put_contents($malformedModule . '/module.json', json_encode(['id' => $id, 'routes' => $routes]));
}
// An empty JSON OBJECT is not the documented "no routes" shape (that is []); associative
// decoding collapses both to [], so this fixture must be written as literal JSON (Sol review).
$objectRoutesModule = $root . '/modules/object-routes';
mkdir($objectRoutesModule, 0777, true);
file_put_contents($objectRoutesModule . '/module.json', '{"id":"object-routes","routes":{}}');

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
    $census = new GovernanceCensus($root);
    $emptyRoutes = $census->scan('empty-routes');
    $emptyReport = $emptyRoutes['modules'][0] ?? null;
    if (!is_array($emptyReport) || ($emptyReport['module'] ?? null) !== 'empty-routes') {
        throw new RuntimeException('empty routes module report was not emitted');
    }
    if (($emptyReport['operations'] ?? null) !== []) {
        throw new RuntimeException('empty routes module reported routed operations');
    }
    if (count($emptyReport['non_http'] ?? []) !== 1
        || (($emptyReport['non_http'][0]['scope'] ?? null) !== 'declared')) {
        throw new RuntimeException('empty routes module did not receive its non-HTTP scan');
    }

    foreach (['integer-routes', 'list-routes', 'object-routes'] as $malformedId) {
        try {
            $census->scan($malformedId);
            throw new RuntimeException($malformedId . ' malformed routes declaration was accepted');
        } catch (RuntimeException $e) {
            if (!str_contains($e->getMessage(), 'routes must reference a static PHP route file')) {
                throw $e;
            }
        }
    }

    $result = $census->scan('probe');
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

    // Delegated mode (module-decides): the module predicate is the authority. A
    // declaration in this mode must NEVER count as dispatch-enforced; it reports
    // separately, and it only counts at all with static binding evidence — the
    // capability literal reachable from THIS handler (chair ruling 2026-10-13).
    $manifest['capabilities'] = ['routes' => [
        'POST /governed' => ['mode' => 'module-decides', 'capability' => 'probe.write@1'],
        'GET /read-plain' => 'probe.read@1',
    ]];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $delegatedResult = (new GovernanceCensus($root))->scan('probe');
    $delegated = censusIndex($delegatedResult);
    if (($delegated['/governed']['dispatch'] ?? null) !== 'delegated-bound') {
        throw new RuntimeException('a module-decides route with a bound capability was not classified delegated-bound: ' . json_encode($delegated['/governed'] ?? null));
    }
    $delegatedSummary = $delegatedResult['summary'][0];
    if ((int) ($delegatedSummary['dispatch_enforced'] ?? -1) !== 0
        || (int) ($delegatedSummary['write_dispatch_enforced'] ?? -1) !== 0) {
        throw new RuntimeException('delegated coverage leaked into dispatch enforcement: ' . json_encode($delegatedSummary));
    }
    if ((int) ($delegatedSummary['delegated_bound'] ?? -1) !== 1 || (int) ($delegatedSummary['delegated_unbound'] ?? -1) !== 0) {
        throw new RuntimeException('delegated_bound was not reported separately: ' . json_encode($delegatedSummary));
    }
    if ((int) ($delegatedSummary['read_dispatch_enforced'] ?? -1) !== 1) {
        throw new RuntimeException('a coexisting role-mode read declaration was lost when delegated mode was added');
    }

    // Unbound: the declared capability never appears in the handler's reachable graph.
    $manifest['capabilities'] = ['routes' => [
        'POST /plain' => ['mode' => 'module-decides', 'capability' => 'probe.never.used@1'],
    ]];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $unboundResult = (new GovernanceCensus($root))->scan('probe');
    if ((censusIndex($unboundResult)['/plain']['dispatch'] ?? null) !== 'delegated-unbound') {
        throw new RuntimeException('an unbound module-decides route was not classified delegated-unbound');
    }
    if ((int) ($unboundResult['summary'][0]['delegated_unbound'] ?? -1) !== 1
        || (int) ($unboundResult['summary'][0]['delegated_bound'] ?? -1) !== 0) {
        throw new RuntimeException('delegated_unbound was not reported separately');
    }

    // Malformed delegated declarations: unsupported mode, missing capability, or
    // allowed_roles present (not permitted in delegated mode) — all zero credit.
    $manifest['capabilities'] = ['routes' => [
        'POST /governed' => ['mode' => 'trust-me', 'capability' => 'probe.write@1'],
        'POST /plain' => ['mode' => 'module-decides'],
        'POST /exempt' => ['mode' => 'module-decides', 'capability' => 'probe.write@1', 'allowed_roles' => ['admin']],
    ]];
    file_put_contents($module.'/module.json', json_encode($manifest));
    $invalidSummary = (new GovernanceCensus($root))->scan('probe')['summary'][0];
    if ((int) ($invalidSummary['delegated_invalid'] ?? -1) !== 3) {
        throw new RuntimeException('malformed delegated declarations were not counted invalid: ' . json_encode($invalidSummary));
    }
    if ((int) ($invalidSummary['delegated_bound'] ?? -1) !== 0 || (int) ($invalidSummary['delegated_unbound'] ?? -1) !== 0
        || (int) ($invalidSummary['dispatch_enforced'] ?? -1) !== 0) {
        throw new RuntimeException('invalid delegated declarations received credit: ' . json_encode($invalidSummary));
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

    echo "PASS: empty routes allowed with non-HTTP scan; malformed routes refused; routed dispatch/reach remain separate; read and write authority carry independent denominators; six non-HTTP transports found; dynamic capability/provider calls unresolved\n";
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    } rmdir($root);
}
