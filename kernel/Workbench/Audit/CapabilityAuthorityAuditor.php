<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Audit;

use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Deterministic static audit of manifest-declared capability authority.
 *
 * Installed manifests are treated as enabled unless a fixture explicitly sets
 * `enabled` or `_enabled` to false. Source beneath those disabled module
 * directories is also excluded from consumer scanning.
 *
 * Kernel authority is closed-world: KERNEL_CAPABILITIES lists every static
 * CapabilityRegistry registration in kernel/App.php. Every non-kernel literal in
 * src/kernel must be present in OPTIONAL_KERNEL_CAPABILITIES; this is the
 * exhaustive classification inventory, and resolved providers are still checked
 * with caller `kernel`. Real-repository baseline is zero findings: any
 * `kernel.*` literal absent from KERNEL_CAPABILITIES is critical (fail-on-new).
 */
final class CapabilityAuthorityAuditor
{
    private string $modulesRoot;
    private string $projectRoot;

    /** @var array<string, string> capability => registration source */
    private const KERNEL_CAPABILITIES = [
        'kernel.auth.user@1' => 'kernel/App.php:161',
        'kernel.auth.require@1' => 'kernel/App.php:165',
        'kernel.http.request_context@1' => 'kernel/App.php:174',
        'kernel.idempotency.claim@1' => 'kernel/App.php:208',
        'kernel.idempotency.commit@1' => 'kernel/App.php:245',
        'kernel.idempotency.release@1' => 'kernel/App.php:269',
        'kernel.idempotency.hash@1' => 'kernel/App.php:290',
        'kernel.audit.record@1' => 'kernel/App.php:306',
        'kernel.audit.list@1' => 'kernel/App.php:417',
        'kernel.auth.delegate@1' => 'kernel/App.php:516',
        'kernel.auth.validate_delegate@1' => 'kernel/App.php:604',
        'kernel.render.context@1' => 'kernel/App.php:684',
        'kernel.auth.authenticate@1' => 'kernel/App.php:696',
        'workflow.state.get@1' => 'kernel/App.php:748',
        'workflow.transition@1' => 'kernel/App.php:752',
    ];

    /**
     * Exhaustive inventory of non-kernel literals intentionally called by
     * src/kernel consumers. Absence is runtime-handled, but when a provider is
     * installed its allow_callers policy is evaluated for caller `kernel`.
     *
     * @var array<string, string>
     */
    private const OPTIONAL_KERNEL_CAPABILITIES = [
        'ai.capability.suggest@1' => 'src/http/admin-handlers.php:2138; optional AI suggestion provider',
        'ai.text.generate@1' => 'kernel/Workbench/AI/WorkbenchAiAnalyzer.php:89; optional Workbench AI provider',
        'antispam.check@1' => 'src/helpers/module-manager.php:2819; optional activation-guarded anti-spam provider',
    ];

    public function __construct(string $modulesRoot)
    {
        $this->modulesRoot = rtrim($this->normalizePath($modulesRoot), '/');
        $this->projectRoot = dirname($this->modulesRoot);
    }

    /** @return list<array{code: string, severity: string, file: string, line: int, message: string}> */
    public function audit(): array
    {
        $findings = [];
        $modules = $this->discoverModules($findings);
        $providers = $this->providerInventory($modules);

        foreach ($modules as $module) {
            $this->auditExposes($module, $findings);
        }

        foreach ($this->scanFiles() as $file) {
            $consumer = $this->consumerForFile($file, $modules);
            foreach ($this->literalCalls($file) as $call) {
                $this->auditCall($call, $consumer, $modules, $providers, $findings);
            }
        }

        $kernelProviders = $this->kernelProviderInventory();
        foreach ($modules as $module) {
            foreach ($module['depends'] as $dependency) {
                $requested = (string)$dependency['id'];
                $inventory = str_starts_with($requested, 'kernel.') ? $kernelProviders : $providers;
                if ($this->resolve($requested, $inventory) === null) {
                    $authority = str_starts_with($requested, 'kernel.')
                        ? 'the closed-world kernel inventory'
                        : 'an enabled module';
                    $findings[] = $this->finding(
                        'CAPABILITY_DEPENDENCY_UNEXPOSED',
                        'critical',
                        (string)$module['manifest_file'],
                        (int)$dependency['line'],
                        "Module '{$module['id']}' depends on '{$requested}', but {$authority} exposes no compatible capability."
                    );
                }
            }
        }

        usort($findings, static fn (array $left, array $right): int => [
            $left['file'], $left['line'], $left['code'], $left['message'],
        ] <=> [
            $right['file'], $right['line'], $right['code'], $right['message'],
        ]);

        return $findings;
    }

    /**
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings
     * @return array<string, array<string, mixed>>
     */
    private function discoverModules(array &$findings): array
    {
        $modules = [];
        if (!is_dir($this->modulesRoot)) {
            $findings[] = $this->finding(
                'CAPABILITY_MODULES_ROOT_UNREADABLE',
                'critical',
                $this->relative($this->modulesRoot),
                1,
                'Modules root could not be read.'
            );
            return [];
        }

        $paths = [];
        foreach ($this->phpAndManifestFiles($this->modulesRoot) as $file) {
            if (basename($file) === 'module.json') {
                $paths[] = $file;
            }
        }
        sort($paths);

        foreach ($paths as $path) {
            $source = @file_get_contents($path);
            $manifest = is_string($source) ? json_decode($source, true) : null;
            if (!is_array($manifest)) {
                $findings[] = $this->finding(
                    'CAPABILITY_MANIFEST_INVALID',
                    'critical',
                    $this->relative($path),
                    1,
                    'Module manifest is unreadable or invalid JSON.'
                );
                continue;
            }
            if ($this->isDisabledManifest($manifest)) {
                continue;
            }

            $id = trim((string)($manifest['id'] ?? ''));
            if ($id === '') {
                $findings[] = $this->finding(
                    'CAPABILITY_MANIFEST_INVALID',
                    'critical',
                    $this->relative($path),
                    1,
                    'Module manifest has no non-empty id.'
                );
                continue;
            }

            $caps = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
            $exposes = $this->manifestIds((array)($caps['exposes'] ?? []), $source);
            $depends = $this->manifestIds((array)($caps['depends'] ?? []), $source);
            $service = is_array($manifest['service'] ?? null) ? $manifest['service'] : [];
            $endpoint = $service['endpoint'] ?? null;
            $protocol = $service['protocol'] ?? null;
            $modules[$id] = [
                'id' => $id,
                'type' => trim((string)($manifest['type'] ?? 'module')),
                'path' => dirname($path),
                'manifest_file' => $this->relative($path),
                'exposes' => $exposes,
                'depends' => $depends,
                'policy' => is_array($caps['policy'] ?? null) ? $caps['policy'] : [],
                'service_binding_valid' => is_string($endpoint) && trim($endpoint) !== ''
                    && is_string($protocol) && trim($protocol) !== '',
            ];
        }
        ksort($modules);
        return $modules;
    }

    /**
     * @param list<mixed> $entries
     * @return list<array{id: string, line: int}>
     */
    private function manifestIds(array $entries, string $source): array
    {
        $result = [];
        $searchOffset = 0;
        foreach ($entries as $entry) {
            $id = trim(is_string($entry) ? $entry : (is_array($entry) ? (string)($entry['id'] ?? '') : ''));
            if ($id === '') {
                continue;
            }
            $offset = strpos($source, '"' . $id . '"', $searchOffset);
            if ($offset === false) {
                $offset = strpos($source, $id, $searchOffset);
            }
            $offset = $offset === false ? 0 : $offset;
            $searchOffset = $offset + strlen($id);
            $result[] = ['id' => $id, 'line' => $this->lineAt($source, $offset)];
        }
        return $result;
    }

    /**
     * @param array<string, array<string, mixed>> $modules
     * @return array<string, list<array{module: string, policy: array<string, mixed>}>>
     */
    private function providerInventory(array $modules): array
    {
        $providers = [];
        foreach ($modules as $module) {
            foreach ($module['exposes'] as $expose) {
                $id = (string)$expose['id'];
                $providers[$id][] = [
                    'module' => (string)$module['id'],
                    'policy' => $this->policyFor((array)$module['policy'], $id),
                ];
            }
        }
        foreach (self::KERNEL_CAPABILITIES as $id => $source) {
            if (!str_starts_with($id, 'kernel.')) {
                $providers[$id][] = ['module' => 'kernel', 'policy' => [], 'source' => $source];
            }
        }
        ksort($providers);
        foreach ($providers as &$entries) {
            usort($entries, static fn (array $a, array $b): int => strcmp($a['module'], $b['module']));
        }
        unset($entries);
        return $providers;
    }

    /** @return array<string, list<array{module: string, policy: array<string, mixed>}>> */
    private function kernelProviderInventory(): array
    {
        $providers = [];
        foreach (self::KERNEL_CAPABILITIES as $id => $source) {
            if (str_starts_with($id, 'kernel.')) {
                $providers[$id][] = ['module' => 'kernel', 'policy' => [], 'source' => $source];
            }
        }
        ksort($providers);
        return $providers;
    }

    /**
     * @param array<string, mixed> $module
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings
     */
    private function auditExposes(array $module, array &$findings): void
    {
        // Polyglot service-modules implement exposes out-of-process. Their
        // implementation proof is the complete ServiceProxy binding, not a PHP
        // symbol. An incomplete binding remains a finding for every expose.
        if (($module['type'] ?? 'module') === 'service-module') {
            if (($module['service_binding_valid'] ?? false) === true) {
                return;
            }
            foreach ($module['exposes'] as $expose) {
                $id = (string)$expose['id'];
                $findings[] = $this->finding(
                    'CAPABILITY_EXPOSE_UNIMPLEMENTED',
                    'critical',
                    (string)$module['manifest_file'],
                    (int)$expose['line'],
                    "Module '{$module['id']}' exposes '{$id}', but a service-module must declare non-empty service.endpoint + service.protocol."
                );
            }
            return;
        }

        $functions = [];
        $classes = [];
        $handlerEntries = [];
        $prefix = preg_replace('/[^a-z0-9]+/i', '_', (string)$module['id']) ?? '';
        $mapFunction = $prefix . '_capability_handlers';

        foreach ($this->phpAndManifestFiles((string)$module['path']) as $file) {
            if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'php') {
                continue;
            }
            $source = @file_get_contents($file);
            if (!is_string($source)) {
                continue;
            }
            $symbols = $this->callableSymbols($source);
            $functions += $symbols['functions'];
            foreach ($symbols['classes'] as $class => $methods) {
                $classes[$class] = ($classes[$class] ?? []) + $methods;
            }
            foreach ($this->handlerMap($source, $mapFunction) as $id => $handler) {
                $handlerEntries[$id] = $handler;
            }
        }

        foreach ($module['exposes'] as $expose) {
            $id = (string)$expose['id'];
            $handler = $handlerEntries[$id] ?? null;
            $callable = is_array($handler) && (
                ($handler['type'] === 'function' && isset($functions[strtolower($handler['function'])]))
                || ($handler['type'] === 'method'
                    && isset($classes[strtolower($handler['class'])][strtolower($handler['method'])]))
                // A closure needs no symbol lookup: the literal is inherently callable.
                || $handler['type'] === 'closure'
            );
            // Mirror module-manager.php C3b: a declared capability is implemented
            // when either the exported handler map resolves OR the convention
            // function {prefix}_cap_{sanitised-capability} exists.
            $sanitized = preg_replace('/[^a-z0-9]+/i', '_', $id) ?? $id;
            $conventionFunction = $prefix . '_cap_' . strtolower(trim((string)$sanitized, '_'));
            $conventionCallable = isset($functions[strtolower($conventionFunction)]);
            if (!$callable && !$conventionCallable) {
                $findings[] = $this->finding(
                    'CAPABILITY_EXPOSE_UNIMPLEMENTED',
                    'critical',
                    (string)$module['manifest_file'],
                    (int)$expose['line'],
                    "Module '{$module['id']}' exposes '{$id}', but {$mapFunction}() has no statically callable handler entry."
                );
            }
        }
    }

    /**
     * @return array{functions: array<string, true>, classes: array<string, array<string, true>>}
     */
    private function callableSymbols(string $source): array
    {
        $tokens = token_get_all($source);
        $functions = [];
        $classes = [];
        $depth = 0;
        $pendingClass = null;
        $classDepths = [];
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];
            if (is_array($token) && $token[0] === T_CLASS) {
                $name = $this->nextTokenText($tokens, $index + 1, [T_STRING]);
                $pendingClass = $name !== null ? strtolower($name) : null;
                continue;
            }
            if ($token === '{') {
                $depth++;
                if ($pendingClass !== null) {
                    $classDepths[$depth] = $pendingClass;
                    $pendingClass = null;
                }
                continue;
            }
            if ($token === '}') {
                unset($classDepths[$depth]);
                $depth--;
                continue;
            }
            if (!is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }
            $name = $this->nextTokenText($tokens, $index + 1, [T_STRING]);
            if ($name === null) {
                continue;
            }
            $class = $classDepths === [] ? null : end($classDepths);
            if (is_string($class)) {
                $classes[$class][strtolower($name)] = true;
            } else {
                $functions[strtolower($name)] = true;
            }
        }
        return ['functions' => $functions, 'classes' => $classes];
    }

    /**
     * Parse only the array expression returned by the named map function.
     *
     * @return array<string, array{type: string, function: string, class: string, method: string}>
     */
    private function handlerMap(string $source, string $function): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($index = 0; $index < $count; $index++) {
            if (!is_array($tokens[$index]) || $tokens[$index][0] !== T_FUNCTION) {
                continue;
            }
            $nameIndex = $this->nextSignificantIndex($tokens, $index + 1);
            if ($nameIndex === null || !is_array($tokens[$nameIndex])
                || strtolower($tokens[$nameIndex][1]) !== strtolower($function)) {
                continue;
            }
            $open = $this->nextTokenIndex($tokens, $nameIndex + 1, '{');
            if ($open === null) {
                return [];
            }
            $depth = 0;
            for ($cursor = $open + 1; $cursor < $count; $cursor++) {
                $token = $tokens[$cursor];
                if ($token === '{') {
                    $depth++;
                } elseif ($token === '}') {
                    if ($depth === 0) {
                        return [];
                    }
                    $depth--;
                } elseif ($depth === 0 && is_array($token) && $token[0] === T_RETURN) {
                    $arrayStart = $this->nextSignificantIndex($tokens, $cursor + 1);
                    if ($arrayStart === null || $tokens[$arrayStart] !== '[') {
                        return [];
                    }
                    return $this->parseReturnedHandlerArray($tokens, $arrayStart);
                }
            }
        }
        return [];
    }

    /**
     * @param list<mixed> $tokens
     * @return array<string, array{type: string, function: string, class: string, method: string}>
     */
    private function parseReturnedHandlerArray(array $tokens, int $start): array
    {
        $entries = [];
        $depth = 1;
        $count = count($tokens);
        for ($index = $start + 1; $index < $count && $depth > 0; $index++) {
            $token = $tokens[$index];
            if ($token === '[') {
                $depth++;
                continue;
            }
            if ($token === ']') {
                $depth--;
                continue;
            }
            if ($depth !== 1 || !is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $arrow = $this->nextSignificantIndex($tokens, $index + 1);
            if ($arrow === null || !is_array($tokens[$arrow]) || $tokens[$arrow][0] !== T_DOUBLE_ARROW) {
                continue;
            }
            $value = $this->nextSignificantIndex($tokens, $arrow + 1);
            if ($value === null) {
                continue;
            }
            $id = $this->decodeLiteral($token[1]);
            if (is_array($tokens[$value]) && $tokens[$value][0] === T_CONSTANT_ENCAPSED_STRING) {
                $entries[$id] = [
                    'type' => 'function',
                    'function' => $this->decodeLiteral($tokens[$value][1]),
                    'class' => '',
                    'method' => '',
                ];
                continue;
            }
            if ($tokens[$value] === '[') {
                $callable = $this->parseMethodCallable($tokens, $value);
                if ($callable !== null) {
                    $entries[$id] = $callable;
                }
                continue;
            }
            // A closure is a legitimate handler. module-manager.php C3b resolves the
            // exported map at runtime and accepts any is_callable() value, so an
            // inlined closure passes there. Dropping it here produced a
            // false-critical CAPABILITY_EXPOSE_UNIMPLEMENTED for modules that inline
            // their handlers (e.g. modules/security/helpers.php:111 'security.audit@1').
            if (is_array($tokens[$value]) && $tokens[$value][0] === T_FUNCTION) {
                $entries[$id] = [
                    'type' => 'closure',
                    'function' => '',
                    'class' => '',
                    'method' => '',
                ];
            }
        }
        return $entries;
    }

    /**
     * @param list<mixed> $tokens
     * @return array{type: string, function: string, class: string, method: string}|null
     */
    private function parseMethodCallable(array $tokens, int $start): ?array
    {
        $classIndex = $this->nextSignificantIndex($tokens, $start + 1);
        if ($classIndex === null || !is_array($tokens[$classIndex])) {
            return null;
        }
        if ($tokens[$classIndex][0] === T_CONSTANT_ENCAPSED_STRING) {
            $class = $this->decodeLiteral($tokens[$classIndex][1]);
            $comma = $this->nextSignificantIndex($tokens, $classIndex + 1);
        } else {
            $class = $tokens[$classIndex][1];
            $doubleColon = $this->nextSignificantIndex($tokens, $classIndex + 1);
            $classKeyword = $doubleColon === null ? null : $this->nextSignificantIndex($tokens, $doubleColon + 1);
            if ($doubleColon === null || !is_array($tokens[$doubleColon]) || $tokens[$doubleColon][0] !== T_DOUBLE_COLON
                || $classKeyword === null || !is_array($tokens[$classKeyword])
                || strtolower($tokens[$classKeyword][1]) !== 'class') {
                return null;
            }
            $comma = $this->nextSignificantIndex($tokens, $classKeyword + 1);
        }
        if ($comma === null || $tokens[$comma] !== ',') {
            return null;
        }
        $methodIndex = $this->nextSignificantIndex($tokens, $comma + 1);
        if ($methodIndex === null || !is_array($tokens[$methodIndex])
            || $tokens[$methodIndex][0] !== T_CONSTANT_ENCAPSED_STRING) {
            return null;
        }
        return [
            'type' => 'method',
            'function' => '',
            'class' => basename(str_replace('\\', '/', ltrim($class, '\\'))),
            'method' => $this->decodeLiteral($tokens[$methodIndex][1]),
        ];
    }

    /**
     * @param list<mixed> $tokens
     * @param list<int> $types
     */
    private function nextTokenText(array $tokens, int $start, array $types): ?string
    {
        $index = $this->nextSignificantIndex($tokens, $start);
        $token = $index === null ? null : $tokens[$index];
        return is_array($token) && in_array($token[0], $types, true) ? $token[1] : null;
    }

    /** @param list<mixed> $tokens */
    private function nextSignificantIndex(array $tokens, int $start): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            $token = $tokens[$index];
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $index;
            }
        }
        return null;
    }

    /** @param list<mixed> $tokens */
    private function nextTokenIndex(array $tokens, int $start, string $wanted): ?int
    {
        for ($index = $start, $count = count($tokens); $index < $count; $index++) {
            if ($tokens[$index] === $wanted) {
                return $index;
            }
        }
        return null;
    }

    /**
     * @param array{file: string, line: int, id: string} $call
     * @param string $consumer
     * @param array<string, array<string, mixed>> $modules
     * @param array<string, list<array{module: string, policy: array<string, mixed>}>> $providers
     * @param list<array{code: string, severity: string, file: string, line: int, message: string}> $findings
     */
    private function auditCall(array $call, string $consumer, array $modules, array $providers, array &$findings): void
    {
        $requested = $call['id'];
        if (str_starts_with($requested, 'kernel.')) {
            if ($this->resolve($requested, $this->kernelProviderInventory()) !== null) {
                return;
            }
            $findings[] = $this->finding(
                'CAPABILITY_CALL_UNEXPOSED',
                'critical',
                $call['file'],
                $call['line'],
                "Kernel capability '{$requested}' is neither statically registered nor explicitly baselined."
            );
            return;
        }

        $resolved = $this->resolve($requested, $providers);
        if ($resolved === null) {
            if ($consumer === 'kernel' && isset(self::OPTIONAL_KERNEL_CAPABILITIES[$requested])) {
                return;
            }
            $baseExists = preg_match('/@\d+$/', $requested) === 1 && $this->baseExists($requested, $providers);
            $findings[] = $this->finding(
                $baseExists ? 'CAPABILITY_VERSION_UNEXPOSED' : 'CAPABILITY_CALL_UNEXPOSED',
                'critical',
                $call['file'],
                $call['line'],
                $baseExists
                    ? "Capability call '{$requested}' requests a major version no enabled module exposes."
                    : "Capability call '{$requested}' has no enabled module provider."
            );
            return;
        }

        $authorizedRelationship = false;
        if ($consumer === 'kernel') {
            $authorizedRelationship = isset(self::OPTIONAL_KERNEL_CAPABILITIES[$requested]);
        } else {
            $module = $modules[$consumer] ?? null;
            if (!is_array($module)) {
                return;
            }
            $declared = array_merge((array)$module['depends'], (array)$module['exposes']);
            foreach ($declared as $entry) {
                if ($this->declarationMatches((string)$entry['id'], $requested, $resolved)) {
                    $authorizedRelationship = true;
                    break;
                }
            }
        }
        if (!$authorizedRelationship) {
            $subject = $consumer === 'kernel' ? 'Kernel consumer' : "Module '{$consumer}'";
            $findings[] = $this->finding(
                'CAPABILITY_DEPENDENCY_UNDECLARED',
                'critical',
                $call['file'],
                $call['line'],
                "{$subject} calls '{$requested}' without an explicit compatible classification, dependency, or self-expose."
            );
        }

        foreach ($providers[$resolved] ?? [] as $provider) {
            $allow = $provider['policy']['allow_callers'] ?? [];
            $deny = $provider['policy']['deny_callers'] ?? [];
            $denied = is_array($deny) && in_array($consumer, $deny, true);
            $excluded = is_array($allow) && $allow !== [] && !in_array($consumer, $allow, true);
            if ($denied || $excluded) {
                $findings[] = $this->finding(
                    'CAPABILITY_CALLER_DENIED',
                    'critical',
                    $call['file'],
                    $call['line'],
                    "Caller '{$consumer}' calls '{$resolved}', but provider '{$provider['module']}' caller policy denies it."
                );
            }
        }
    }

    /**
     * @param array<string, list<array{module: string, policy: array<string, mixed>}>> $providers
     */
    private function resolve(string $requested, array $providers): ?string
    {
        if (isset($providers[$requested])) {
            return $requested;
        }
        if (preg_match('/@\d+$/', $requested) === 1) {
            return null;
        }
        $best = null;
        $major = -1;
        foreach (array_keys($providers) as $id) {
            if (preg_match('/^' . preg_quote($requested, '/') . '@(\d+)$/', $id, $match) === 1
                && (int)$match[1] > $major) {
                $best = $id;
                $major = (int)$match[1];
            }
        }
        return $best;
    }

    /** @param array<string, list<array{module: string, policy: array<string, mixed>}>> $providers */
    private function baseExists(string $requested, array $providers): bool
    {
        $base = preg_replace('/@\d+$/', '', $requested) ?? $requested;
        foreach (array_keys($providers) as $id) {
            if (preg_replace('/@\d+$/', '', $id) === $base) {
                return true;
            }
        }
        return false;
    }

    private function declarationMatches(string $declared, string $requested, string $resolved): bool
    {
        if ($declared === $requested || $declared === $resolved) {
            return true;
        }
        return preg_match('/@\d+$/', $declared) !== 1
            && $declared === (preg_replace('/@\d+$/', '', $resolved) ?? $resolved);
    }

    /**
     * @param array<string, mixed> $policy
     * @return array<string, mixed>
     */
    private function policyFor(array $policy, string $id): array
    {
        $default = is_array($policy['default'] ?? null) ? $policy['default'] : [];
        $caps = is_array($policy['capabilities'] ?? null) ? $policy['capabilities'] : [];
        $specific = is_array($caps[$id] ?? null) ? $caps[$id] : [];
        $combined = array_merge($default, $specific);
        foreach (['allow_callers', 'deny_callers'] as $key) {
            $values = [];
            foreach ([$default[$key] ?? null, $specific[$key] ?? null] as $list) {
                if (is_array($list)) {
                    $values = array_merge($values, $list);
                }
            }
            $combined[$key] = array_values(array_filter(
                $values,
                static fn (mixed $value): bool => is_string($value) && $value !== ''
            ));
        }
        return $combined;
    }

    /** @param array<string, array<string, mixed>> $modules */
    private function consumerForFile(string $file, array $modules): string
    {
        $file = $this->normalizePath($file);
        $selected = 'kernel';
        $length = -1;
        foreach ($modules as $module) {
            $path = rtrim((string)$module['path'], '/') . '/';
            if (str_starts_with($file, $path) && strlen($path) > $length) {
                $selected = (string)$module['id'];
                $length = strlen($path);
            }
        }
        return $selected;
    }

    /** @return list<array{file: string, line: int, id: string}> */
    private function literalCalls(string $file): array
    {
        $source = @file_get_contents($file);
        if (!is_string($source)) {
            return [];
        }
        $tokens = token_get_all($source);
        $significant = [];
        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $significant[] = $token;
        }
        $calls = [];
        $count = count($significant);
        for ($index = 0; $index + 7 < $count; $index++) {
            $token = $significant[$index];
            if (!is_array($token) || $token[0] !== T_STRING
                || !in_array(strtolower($token[1]), ['cap', 'capabilities'], true)) {
                continue;
            }
            if (!$this->isObjectOperator($significant[$index - 1] ?? null)) {
                continue;
            }
            if (($significant[$index + 1] ?? null) !== '(' || ($significant[$index + 2] ?? null) !== ')'
                || !$this->isObjectOperator($significant[$index + 3] ?? null)
                || !$this->isNamedToken($significant[$index + 4] ?? null, 'call')
                || ($significant[$index + 5] ?? null) !== '(') {
                continue;
            }
            $literal = $significant[$index + 6] ?? null;
            if (!is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            $calls[] = [
                'file' => $this->relative($file),
                'line' => (int)$literal[2],
                'id' => $this->decodeLiteral($literal[1]),
            ];
        }
        return $calls;
    }

    private function isObjectOperator(mixed $token): bool
    {
        return $token === '->' || (is_array($token) && $token[0] === T_OBJECT_OPERATOR);
    }

    private function isNamedToken(mixed $token, string $name): bool
    {
        return is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === $name;
    }

    private function decodeLiteral(string $literal): string
    {
        $quote = $literal[0] ?? '';
        $value = substr($literal, 1, -1);
        return $quote === "'" ? str_replace(["\\\\", "\\'"], ["\\", "'"], $value) : stripcslashes($value);
    }

    /** @return list<string> */
    private function scanFiles(): array
    {
        $files = [];
        $disabledModuleDirectories = $this->disabledModuleDirectories();
        foreach ([$this->modulesRoot, $this->projectRoot . '/src', $this->projectRoot . '/kernel'] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $excludedDirectories = $root === $this->modulesRoot ? $disabledModuleDirectories : [];
            foreach ($this->phpAndManifestFiles($root, $excludedDirectories) as $file) {
                if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
                    $files[$this->normalizePath($file)] = true;
                }
            }
        }
        $result = array_keys($files);
        sort($result);
        return $result;
    }

    /** @return array<string, true> */
    private function disabledModuleDirectories(): array
    {
        $directories = [];
        foreach ($this->phpAndManifestFiles($this->modulesRoot) as $file) {
            if (basename($file) !== 'module.json') {
                continue;
            }
            $source = @file_get_contents($file);
            $manifest = is_string($source) ? json_decode($source, true) : null;
            if (is_array($manifest) && $this->isDisabledManifest($manifest)) {
                $directories[$this->normalizePath(dirname($file))] = true;
            }
        }
        return $directories;
    }

    /** @param array<string, mixed> $manifest */
    private function isDisabledManifest(array $manifest): bool
    {
        return (array_key_exists('enabled', $manifest) && $manifest['enabled'] === false)
            || (array_key_exists('_enabled', $manifest) && $manifest['_enabled'] === false);
    }

    /**
     * @param array<string, true> $excludedDirectories
     * @return list<string>
     */
    private function phpAndManifestFiles(string $root, array $excludedDirectories = []): array
    {
        if (!is_dir($root)) {
            return [];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
            static function (SplFileInfo $entry) use ($excludedDirectories): bool {
                if (!$entry->isDir()) {
                    return true;
                }
                $path = str_replace('\\', '/', $entry->getPathname());
                return !isset($excludedDirectories[$path])
                    && !in_array($entry->getFilename(), ['vendor', 'node_modules', '.git'], true);
            }
        ));
        $files = [];
        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()
                && (strtolower($entry->getExtension()) === 'php' || $entry->getFilename() === 'module.json')) {
                $files[] = $this->normalizePath($entry->getPathname());
            }
        }
        sort($files);
        return $files;
    }

    /** @return array{code: string, severity: string, file: string, line: int, message: string} */
    private function finding(string $code, string $severity, string $file, int $line, string $message): array
    {
        return ['code' => $code, 'severity' => $severity, 'file' => $file, 'line' => $line, 'message' => $message];
    }

    private function lineAt(string $source, int $offset): int
    {
        return 1 + substr_count(substr($source, 0, $offset), "\n");
    }

    private function relative(string $path): string
    {
        $path = $this->normalizePath($path);
        $prefix = rtrim($this->projectRoot, '/') . '/';
        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
