<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Governance;

use RuntimeException;

/** Static route-to-capability census. Module PHP is tokenized, never loaded. */
final class GovernanceCensus
{
    public function __construct(private readonly string $root)
    {
    }

    /** @return array<string, mixed> */
    public function scanAll(): array
    {
        $files = glob($this->root . '/modules/*/module.json') ?: [];
        $files = array_merge($files, glob($this->root . '/modules/*/*/module.json') ?: []);
        $reports = [];
        foreach ($files as $file) {
            $reports[] = $this->scanManifest($file);
        }
        $reports[] = $this->scanPlatform();
        usort($reports, static fn ($a, $b) => strcmp($a['module'], $b['module']));
        return $this->result($reports);
    }

    /** @return array<string, mixed> */
    public function scan(string $module): array
    {
        foreach (array_merge(glob($this->root . '/modules/*/module.json') ?: [], glob($this->root . '/modules/*/*/module.json') ?: []) as $file) {
            $json = json_decode((string) file_get_contents($file), true);
            if (($json['id'] ?? '') === $module) {
                return $this->result([$this->scanManifest($file)]);
            }
        }
        throw new RuntimeException("Module not found: {$module}");
    }

    /**
     * @param list<array<string, mixed>> $modules
     * @return array<string, mixed>
     */
    private function result(array $modules): array
    {
        $routedModules = array_values(array_filter($modules, static fn (array $module): bool => ($module['routed'] ?? true) === true));
        $summary = array_map(static fn ($m) => $m['summary'], $routedModules);
        $nonHttpSummary = array_map(static fn ($m) => $m['non_http_summary'], $modules);
        return [
            'rule' => 'Three separate coverage measures, never merged. WRITE authority coverage = declared-and-enforced / routed business operations (POST/PUT/PATCH/DELETE). READ authority coverage = declared-and-enforced / routed read operations (GET/HEAD/OPTIONS). NON-HTTP authority coverage = declared / non-HTTP capability call sites. auth/session infrastructure is excluded from both routed denominators. The measures have different scales and semantics, so no merged percentage is published: a single figure would read as total authority coverage when it is only the write figure.',
            'detection' => 'PHP token analysis follows calls between named functions. A bus call requires executable app()->cap()->call(...) tokens; comments, strings and docblocks are ignored.',
            'metric' => 'dispatch_enforced counts operations whose authority is declared in capabilities.routes and established before the handler body. bus_reachable counts handlers that themselves reach the bus and is reported separately: a bus call inside a handler is not request authority, so it does not make the route governed.',
            'non_http_rule' => 'Non-HTTP authority is a separate measure and is never added to the routed denominator. Declared means a statically traceable non-HTTP entry path establishes AuthorityScopeResolver::withScope before reaching the capability call.',
            'non_http_detection' => 'Static PHP tokens identify executable capability calls, authority-scope establishment and named-function call paths. Dynamic capability ids, dynamic provider routing and paths whose transport or call edge cannot be proved are unresolved, never governed.',
            'modules' => $modules,
            'summary' => $summary,
            'non_http_summary' => $nonHttpSummary,
        ];
    }

    /** @return array<string, mixed> */
    private function scanManifest(string $manifestFile): array
    {
        $manifest = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
        $id = (string) ($manifest['id'] ?? '');
        $dir = dirname($manifestFile);
        $routeRef = $manifest['routes'] ?? 'routes.php';
        if ($routeRef === true) {
            $routeRef = 'routes.php';
        }
        if ($routeRef === false) {
            $routeRef = '';
        }
        if (!is_string($routeRef)) {
            throw new RuntimeException("{$id}: routes must reference a static PHP route file");
        }
        $routesFile = $routeRef === '' ? '' : $dir . '/' . $routeRef;
        $routes = $routesFile !== '' && is_file($routesFile) ? $this->parseRoutes($routesFile) : [];
        $functions = $this->parseFunctions($dir);
        $exemptions = [];
        foreach (($manifest['governance']['exemptions'] ?? []) as $i => $item) {
            if (!is_array($item) || trim((string) ($item['reason'] ?? '')) === '') {
                throw new RuntimeException("{$id}: governance exemption #{$i} requires a non-empty reason");
            }
            $method = strtoupper((string) ($item['method'] ?? ''));
            $route = (string) ($item['route'] ?? '');
            if ($method === '' || $route === '') {
                throw new RuntimeException("{$id}: governance exemption #{$i} requires method and route");
            }
            $exemptions[$method . ' ' . $route] = trim((string) $item['reason']);
        }
        // Declared route authority (P2). The declaration lives in the manifest,
        // so the instrument stays domain-neutral: it reads a declaration, it does
        // not know what the capability means.
        $declarations = [];
        foreach (($manifest['capabilities']['routes'] ?? []) as $routeKey => $capabilityId) {
            $routeKey = trim((string) $routeKey);
            if ($routeKey === '' || !str_contains($routeKey, ' ')) {
                continue;
            }
            [$method, $route] = explode(' ', $routeKey, 2);
            $method = strtoupper(trim($method));
            $route = trim($route);
            if ($method === '' || $route === '' || !is_string($capabilityId)) {
                continue;
            }
            $declarations[$method . ' ' . $route] = $capabilityId;
        }
        $operations = [];
        foreach ($routes as $route) {
            $handler = str_contains($route['handler'], ':') ? explode(':', $route['handler'], 2)[1] : $route['handler'];
            $source = $functions[$handler] ?? null;
            $bus = $source ? $this->reachesBus($handler, $functions) : null;
            $key = $route['method'] . ' ' . $route['route'];

            // Two independent facts, never merged into one score:
            //   dispatch — is declared authority established BEFORE the handler body?
            //   reach    — does the handler body itself reach the capability bus?
            // Reporting only `reach` as "governed" measures the metric: a bus call
            // inside a handler proves nothing about the request's authority.
            if (isset($declarations[$key])) {
                $dispatch = 'enforced';
                $how = 'declared in module.json: ' . $declarations[$key] . ' (checked before the handler body)';
            } elseif (isset($exemptions[$key])) {
                $dispatch = 'exempt';
                $how = 'declared exempt in module.json: ' . $exemptions[$key];
            } else {
                $dispatch = 'undeclared';
                $how = 'no authority declared and no exemption declared';
            }

            if ($bus) {
                $reach = 'bus-reachable';
                $how .= '; executable capability bus call reached via ' . implode(' -> ', $bus);
            } elseif ($source) {
                $reach = 'no-bus-call';
                $how .= '; no executable capability bus call reachable from the handler';
            } else {
                $reach = 'unresolved';
                $how .= '; handler source not resolved';
            }

            $operations[] = array_merge($route, ['dispatch' => $dispatch, 'reach' => $reach, 'source' => $source ? $this->relative($source['file']) . ':' . $source['line'] : 'unresolved', 'how' => $how]);
        }
        $business = array_values(array_filter($operations, fn ($o) => $this->isBusiness($o)));
        $dispatchCounts = array_count_values(array_column($business, 'dispatch'));
        $reachCounts = array_count_values(array_column($business, 'reach'));
        $total = count($business);
        $enforced = $dispatchCounts['enforced'] ?? 0;
        $reachable = $reachCounts['bus-reachable'] ?? 0;

        // Reads are classified exactly like writes and were previously dropped before
        // summarising, so read authority was invisible and a read declaration could not
        // move any published number. They are summarised here under their own denominator
        // and never added to the write total: the scales are not comparable.
        $reads = array_values(array_filter($operations, fn ($o) => $this->isRead($o)));
        $readDispatchCounts = array_count_values(array_column($reads, 'dispatch'));
        $readTotal = count($reads);
        $readEnforced = $readDispatchCounts['enforced'] ?? 0;

        $routeHandlers = array_map(static fn (array $route): string => str_contains($route['handler'], ':') ? explode(':', $route['handler'], 2)[1] : $route['handler'], $routes);
        $nonHttp = $this->scanNonHttpFiles($this->phpFiles($dir), $routeHandlers);
        return ['module' => $id, 'operations' => $operations, 'non_http' => $nonHttp, 'summary' => [
            'module' => $id,
            // Write authority coverage. `dispatch_enforced`/`total`/`ratio` are retained as
            // the write-scoped figures for baseline and consumer compatibility. They are NOT
            // total authority coverage and must never be labelled as such.
            'dispatch_enforced' => $enforced,
            'bus_reachable' => $reachable,
            'exempt' => $dispatchCounts['exempt'] ?? 0,
            'undeclared' => $dispatchCounts['undeclared'] ?? 0,
            'total' => $total,
            'ratio' => $total ? round(100 * $enforced / $total, 1) : 0.0,
            'bus_ratio' => $total ? round(100 * $reachable / $total, 1) : 0.0,
            'write_dispatch_enforced' => $enforced,
            'write_total' => $total,
            'write_ratio' => $total ? round(100 * $enforced / $total, 1) : 0.0,
            'read_dispatch_enforced' => $readEnforced,
            'read_exempt' => $readDispatchCounts['exempt'] ?? 0,
            'read_undeclared' => $readDispatchCounts['undeclared'] ?? 0,
            'read_total' => $readTotal,
            'read_ratio' => $readTotal ? round(100 * $readEnforced / $readTotal, 1) : 0.0,
        ], 'non_http_summary' => $this->nonHttpSummary($id, $nonHttp)];
    }

    /** @return array<string, mixed> */
    private function scanPlatform(): array
    {
        $files = [$this->root . '/ikabud'];
        foreach ([$this->root . '/kernel', $this->root . '/src'] as $dir) {
            $files = array_merge($files, $this->phpFiles($dir));
        }
        $files = array_values(array_filter($files, static function (string $file): bool {
            return !str_contains($file, DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)
                && !str_contains($file, DIRECTORY_SEPARATOR . 'Workbench' . DIRECTORY_SEPARATOR . 'Governance' . DIRECTORY_SEPARATOR)
                && !str_contains($file, DIRECTORY_SEPARATOR . 'src' . DIRECTORY_SEPARATOR . 'http' . DIRECTORY_SEPARATOR)
                && !str_ends_with($file, DIRECTORY_SEPARATOR . 'module-routes.php');
        }));
        $nonHttp = $this->scanNonHttpFiles($files, []);
        return [
            'module' => 'kernel',
            'routed' => false,
            'operations' => [],
            'non_http' => $nonHttp,
            'summary' => [
                'module' => 'kernel', 'dispatch_enforced' => 0, 'bus_reachable' => 0,
                'exempt' => 0, 'undeclared' => 0, 'total' => 0, 'ratio' => 0.0, 'bus_ratio' => 0.0,
                'write_dispatch_enforced' => 0, 'write_total' => 0, 'write_ratio' => 0.0,
                'read_dispatch_enforced' => 0, 'read_exempt' => 0, 'read_undeclared' => 0,
                'read_total' => 0, 'read_ratio' => 0.0,
            ],
            'non_http_summary' => $this->nonHttpSummary('kernel', $nonHttp),
        ];
    }

    /** @return list<string> */
    private function phpFiles(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php'
                && !str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                $files[] = $file->getPathname();
            }
        }
        return $files;
    }

    /**
     * @param list<array<string, mixed>> $entries
     * @return array<string, int|float|string>
     */
    private function nonHttpSummary(string $module, array $entries): array
    {
        $counts = array_count_values(array_column($entries, 'scope'));
        $declared = $counts['declared'] ?? 0;
        $total = count($entries);
        return [
            'module' => $module,
            'declared' => $declared,
            'unscoped' => $counts['unscoped'] ?? 0,
            'unresolved' => $counts['unresolved'] ?? 0,
            'total' => $total,
            'ratio' => $total ? round(100 * $declared / $total, 1) : 0.0,
        ];
    }

    /**
     * Inventory direct capability calls outside statically reachable HTTP route handlers.
     *
     * @param list<string> $files
     * @param list<string> $routeHandlers
     * @return list<array<string, string>>
     */
    private function scanNonHttpFiles(array $files, array $routeHandlers): array
    {
        /** @var array<string, array<string, mixed>> $units */
        $units = [];
        /** @var list<array<string, mixed>> $busCalls */
        $busCalls = [];
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            $tokens = $this->indexedTokens((string) file_get_contents($file));
            $fileUnits = $this->functionUnits($file, $tokens);
            foreach ($fileUnits as $unit) {
                $units[$unit['id']] = $unit;
            }
            foreach ($this->directBusCalls($file, $tokens) as $call) {
                $containing = null;
                $span = PHP_INT_MAX;
                foreach ($fileUnits as $unit) {
                    if ($call['index'] > $unit['open'] && $call['index'] < $unit['close'] && ($unit['close'] - $unit['open']) < $span) {
                        $containing = $unit['id'];
                        $span = $unit['close'] - $unit['open'];
                    }
                }
                $call['unit'] = $containing;
                $busCalls[] = $call;
            }
        }

        /** @var array<string, list<string>> $byName */
        $byName = [];
        foreach ($units as $id => $unit) {
            $byName[$unit['name']][] = $id;
        }
        $edges = [];
        foreach ($units as $id => $unit) {
            $edges[$id] = [];
            foreach ($unit['calls'] as $called) {
                if (count($byName[$called] ?? []) === 1) {
                    $edges[$id][] = $byName[$called][0];
                }
            }
        }

        $routeRoots = [];
        foreach ($routeHandlers as $handler) {
            foreach ($byName[$handler] ?? [] as $id) {
                $routeRoots[] = $id;
            }
        }
        $routeReach = $this->reachableUnits($routeRoots, $edges);

        /** @var array<string, list<string>> $scopeRoots */
        $scopeRoots = [];
        foreach ($units as $id => $unit) {
            foreach ($unit['scope_transports'] as $transport) {
                $scopeRoots[$transport][] = $id;
            }
        }
        $scopeReach = [];
        foreach ($scopeRoots as $transport => $roots) {
            $scopeReach[$transport] = $this->reachableUnits($roots, $edges);
        }

        $entries = [];
        foreach ($busCalls as $call) {
            $unitId = $call['unit'];
            if (is_string($unitId) && isset($routeReach[$unitId])) {
                continue;
            }
            $transports = [];
            if (is_string($unitId)) {
                foreach ($scopeReach as $transport => $reached) {
                    if (isset($reached[$unitId])) {
                        $transports[$this->semanticTransport($transport, $call['file'], $units[$unitId]['name'])] = true;
                    }
                }
                // An inline closure is lexically inside the scope-establishing unit.
                foreach ($units as $ancestor) {
                    if ($ancestor['file'] === $call['file'] && $call['index'] > $ancestor['open'] && $call['index'] < $ancestor['close']) {
                        foreach ($ancestor['scope_transports'] as $transport) {
                            $transports[$this->semanticTransport($transport, $call['file'], $ancestor['name'])] = true;
                        }
                    }
                }
            }

            [$inferredTransport, $transportCertain] = $this->inferTransport($call['file'], is_string($unitId) ? $units[$unitId]['name'] : '');
            $scope = 'unresolved';
            $reason = '';
            if (!$call['static_capability']) {
                $reason = 'capability id is held in a variable or computed expression';
            } elseif ($call['dynamic_provider']) {
                $reason = 'provider routing is selected dynamically';
            } elseif (count($transports) === 1) {
                $scope = 'declared';
                $reason = 'named call path reaches a matching AuthorityScopeResolver::withScope';
            } elseif (count($transports) > 1) {
                $reason = 'call is reachable from more than one authority transport';
            } elseif ($transportCertain) {
                $scope = 'unscoped';
                $reason = 'non-HTTP transport is statically identifiable but no scope establishment reaches the call';
            } else {
                $reason = 'entry transport or callable path cannot be resolved statically';
            }
            $transport = count($transports) === 1 ? (string) array_key_first($transports) : $inferredTransport;
            $entries[] = [
                'transport' => $transport,
                'scope' => $scope,
                'capability' => $call['capability'],
                'source' => $this->relative($call['file']) . ':' . $call['line'],
                'how' => $reason,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['source'], $b['source']));
        return $entries;
    }

    /**
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private function indexedTokens(string $source): array
    {
        $rows = [];
        $line = 1;
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $rows[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
                $line = $token[2] + substr_count($token[1], "\n");
            } else {
                $rows[] = ['id' => null, 'text' => $token, 'line' => $line];
                $line += substr_count($token, "\n");
            }
        }
        return $rows;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     * @return list<array<string, mixed>>
     */
    private function functionUnits(string $file, array $tokens): array
    {
        $units = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION && (!defined('T_FN') || $tokens[$i]['id'] !== T_FN)) {
                continue;
            }
            $isArrow = defined('T_FN') && $tokens[$i]['id'] === T_FN;
            $name = '{closure@' . $tokens[$i]['line'] . '}';
            $open = null;
            $parametersStarted = false;
            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]['text'] === '(') {
                    $parametersStarted = true;
                }
                if ($tokens[$j]['id'] === T_STRING && !$parametersStarted && !$isArrow) {
                    $name = $tokens[$j]['text'];
                }
                if ($tokens[$j]['text'] === '{') {
                    $open = $j;
                    break;
                }
                if ($isArrow && $tokens[$j]['text'] === '=>') {
                    $open = $j;
                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $close = $open;
            if ($isArrow) {
                while ($close + 1 < $count && !in_array($tokens[$close + 1]['text'], [';', ','], true)) {
                    $close++;
                }
            } else {
                $depth = 1;
                for ($close = $open + 1; $close < $count && $depth > 0; $close++) {
                    $depth += $tokens[$close]['text'] === '{' ? 1 : 0;
                    $depth -= $tokens[$close]['text'] === '}' ? 1 : 0;
                }
                $close--;
            }
            $body = array_slice($tokens, $open + 1, max(0, $close - $open - 1));
            $significant = array_values(array_filter($body, static fn (array $token): bool => !in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            $text = implode('', array_column($significant, 'text'));
            $calls = [];
            for ($k = 0, $n = count($significant) - 1; $k < $n; $k++) {
                if ($significant[$k]['id'] === T_STRING && $significant[$k + 1]['text'] === '(') {
                    $calls[] = $significant[$k]['text'];
                }
            }
            $scopeTransports = [];
            if (str_contains($text, 'AuthorityScopeResolver::withScope(')) {
                foreach (['EVENT' => 'event', 'CLI' => 'cli', 'WORKBENCH' => 'workbench', 'QUEUE' => 'worker', 'SERVICE' => 'service'] as $constant => $transport) {
                    if (str_contains($text, 'AuthorityScopeResolver::' . $constant)) {
                        $scopeTransports[] = $transport;
                    }
                }
            }
            $id = $file . ':' . $name . ':' . $tokens[$i]['line'];
            $units[] = ['id' => $id, 'file' => $file, 'name' => $name, 'open' => $open, 'close' => $close, 'calls' => array_values(array_unique($calls)), 'scope_transports' => array_values(array_unique($scopeTransports))];
        }
        return $units;
    }

    /**
     * @param list<array{id: int|null, text: string, line: int}> $tokens
     * @return list<array<string, mixed>>
     */
    private function directBusCalls(string $file, array $tokens): array
    {
        $significant = [];
        foreach ($tokens as $index => $token) {
            if (!in_array($token['id'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $token['index'] = $index;
                $significant[] = $token;
            }
        }
        $calls = [];
        // Accept app()->cap()->call(...) and equivalent App-bearing expressions
        // such as $this->app->cap()->call(...). The receiver is deliberately
        // not interpreted; only the executable cap()->call token chain counts.
        $needle = ['cap', '(', ')', '->', 'call', '('];
        for ($i = 0, $n = count($significant) - count($needle); $i <= $n; $i++) {
            $candidate = array_column(array_slice($significant, $i, count($needle)), 'text');
            if ($candidate !== $needle || $i === 0 || $significant[$i - 1]['text'] !== '->') {
                continue;
            }
            $argument = $significant[$i + count($needle)] ?? null;
            $static = is_array($argument) && $argument['id'] === T_CONSTANT_ENCAPSED_STRING;
            $capability = $static ? stripcslashes(substr($argument['text'], 1, -1)) : 'unresolved';
            $tail = '';
            $depth = 1;
            for ($j = $i + count($needle); $j < count($significant) && $depth > 0; $j++) {
                $tail .= $significant[$j]['text'];
                $depth += $significant[$j]['text'] === '(' ? 1 : 0;
                $depth -= $significant[$j]['text'] === ')' ? 1 : 0;
            }
            $dynamicProvider = preg_match("/(?:'provider'|\"provider\")=>(?:\\$|[^'\"]*\\$)/", $tail) === 1
                || preg_match("/\\[['\"]provider['\"]\\]/", $tail) === 1;
            $calls[] = [
                'file' => $file,
                'line' => $significant[$i]['line'],
                'index' => $significant[$i]['index'],
                'capability' => $capability,
                'static_capability' => $static,
                'dynamic_provider' => $dynamicProvider,
            ];
        }
        return $calls;
    }

    /**
     * @param list<string> $roots
     * @param array<string, list<string>> $edges
     * @return array<string, true>
     */
    private function reachableUnits(array $roots, array $edges): array
    {
        $seen = [];
        $pending = $roots;
        while (($id = array_pop($pending)) !== null) {
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($edges[$id] ?? [] as $next) {
                $pending[] = $next;
            }
        }
        return $seen;
    }

    private function semanticTransport(string $transport, string $file, string $name): string
    {
        $subject = basename($file) . '/' . $name;
        return $transport === 'service' && preg_match('/workflow/i', $subject) === 1 ? 'workflow' : $transport;
    }

    /** @return array{string, bool} */
    private function inferTransport(string $file, string $name): array
    {
        $path = str_replace('\\', '/', $file);
        $subject = basename($file) . '/' . $name;
        if (str_contains($path, '/workflows/') || preg_match('~workflow~i', $subject) === 1) {
            return ['workflow', true];
        }
        if (preg_match('~/Workbench/|workbench~i', $subject) === 1) {
            return ['workbench', true];
        }
        if (preg_match('~worker|queue~i', $subject) === 1) {
            return ['worker', true];
        }
        if (preg_match('~event|trigger~i', $subject) === 1) {
            return ['event', true];
        }
        if (basename($file) === 'ikabud' || preg_match('~command|cli~i', $subject) === 1) {
            return ['cli', true];
        }
        // A generic helper/class is not proof of a service entry point: it may
        // be reached from HTTP or through a dynamic callable. Keep it visible,
        // but unresolved rather than manufacturing non-HTTP debt.
        return ['service', false];
    }

    /** @param array<string, mixed> $o */
    private function isBusiness(array $o): bool
    {
        if (!in_array($o['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }
        // Infrastructure exclusion affects only the ratio, never classification.
        return preg_match('~(?:^|[/_-])(login|logout|refresh|forgot-password|reset-password|session)(?:$|[/_-])~i', $o['route']) !== 1;
    }

    /**
     * A read operation carrying business meaning (GET/HEAD/OPTIONS). Reads are summarised
     * under their own denominator and are never added to the write total. The same
     * infrastructure exclusion applies, so the two routed measures stay comparable in kind.
     *
     * @param array<string, mixed> $o
     */
    private function isRead(array $o): bool
    {
        if (!in_array($o['method'], ['GET', 'HEAD', 'OPTIONS'], true)) {
            return false;
        }
        return preg_match('~(?:^|[/_-])(login|logout|refresh|forgot-password|reset-password|session)(?:$|[/_-])~i', $o['route']) !== 1;
    }

    /** @return list<array{route: string, method: string, handler: string}> */
    private function parseRoutes(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $depth = 0;
        $method = null;
        $pending = null;
        $out = [];
        foreach ($tokens as $token) {
            if ($token === '[') {
                $depth++;
                continue;
            } if ($token === ']') {
                $depth--;
                if ($depth < 2) {
                    $method = null;
                } continue;
            }
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $value = stripcslashes(substr($token[1], 1, -1));
            if ($depth === 1 && in_array(strtoupper($value), ['GET','POST','PUT','PATCH','DELETE','HEAD','OPTIONS'], true)) {
                $method = strtoupper($value);
                continue;
            }
            if ($depth === 2 && $method !== null) {
                if ($pending === null) {
                    $pending = $value;
                } else {
                    $out[] = ['route' => $pending, 'method' => $method, 'handler' => $value];
                    $pending = null;
                }
            }
        }
        return $out;
    }

    /** @return array<string, array{file: string, line: int, tokens: list<mixed>}> */
    private function parseFunctions(string $dir): array
    {
        $map = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php' || str_contains($f->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) {
                continue;
            }
            $t = token_get_all((string) file_get_contents($f->getPathname()));
            for ($i = 0,$n = count($t); $i < $n; $i++) {
                if (is_array($t[$i]) && $t[$i][0] === T_FUNCTION) {
                    for ($j = $i + 1; $j < $n && (!is_array($t[$j]) || $t[$j][0] !== T_STRING); $j++);
                    if ($j >= $n) {
                        continue;
                    }
                    $name = $t[$j][1];
                    for (; $j < $n && $t[$j] !== '{'; $j++);
                    if ($j >= $n) {
                        continue;
                    }
                    $body = [];
                    $d = 1;
                    for ($k = $j + 1; $k < $n && $d; $k++) {
                        if ($t[$k] === '{') {
                            $d++;
                        } elseif ($t[$k] === '}') {
                            $d--;
                        } if ($d) {
                            $body[] = $t[$k];
                        }
                    }
                    $map[$name] = ['file' => $f->getPathname(),'line' => $t[$i][2],'tokens' => $body];
                    $i = $k - 1;
                }
            }
        }
        return $map;
    }

    /**
     * @param array<string, array{file: string, line: int, tokens: list<mixed>}> $functions
     * @param array<string, bool> $seen
     * @return list<string>|null
     */
    private function reachesBus(string $name, array $functions, array $seen = []): ?array
    {
        if (isset($seen[$name]) || !isset($functions[$name])) {
            return null;
        } $seen[$name] = true;
        $tokens = $functions[$name]['tokens'];
        $sig = [];
        foreach ($tokens as $t) {
            if (!is_array($t) || !in_array($t[0], [T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_CONSTANT_ENCAPSED_STRING], true)) {
                $sig[] = is_array($t) ? $t[1] : $t;
            }
        }
        if (str_contains(implode('', $sig), 'app()->cap()->call(')) {
            return [$name];
        }
        for ($i = 0,$n = count($sig);$i < $n - 1;$i++) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $sig[$i]) && $sig[$i + 1] === '(' && isset($functions[$sig[$i]])) {
                $path = $this->reachesBus($sig[$i], $functions, $seen);
                if ($path) {
                    return array_merge([$name], $path);
                }
            }
        }
        return null;
    }

    private function relative(string $p): string
    {
        return str_replace($this->root . '/', '', $p);
    }
}
