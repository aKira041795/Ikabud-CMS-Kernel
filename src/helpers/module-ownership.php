<?php
/**
 * Resolve lifecycle ownership for the installed fleet. It refuses to infer
 * authority from suites or capabilities because neither relationship grants
 * lifecycle control.
 *
 * @param array<string,array<string,mixed>>|null $fleet
 * @param array<string,array<string,mixed>>|null $provenance
 * @return array{roots:array<string,array<string,mixed>>,unresolved:list<array<string,string>>,facts:array<string,array<string,mixed>>}
 */
function moduleOwnershipResolveAll(?array $fleet = null, ?array $provenance = null): array
{
    $fleet = $fleet ?? discoverModules();
    $provenance = $provenance ?? moduleOwnershipProvenance();

    $modules = [];
    foreach ($fleet as $key => $manifest) {
        if (!is_array($manifest)) {
            continue;
        }
        $id = trim((string)($manifest['id'] ?? (is_string($key) ? $key : '')));
        if ($id !== '') {
            $manifest['id'] = $id;
            $modules[$id] = $manifest;
        }
    }

    $parents = [];
    $sources = [];
    $invalid = [];
    foreach ($modules as $id => $manifest) {
        $declared = trim((string)($manifest['extends'] ?? ''));
        if ($declared === '') {
            $declared = trim((string)($manifest['parent'] ?? ''));
        }
        $recorded = trim((string)($provenance[$id]['installed_by'] ?? ''));

        if ($declared !== '' && $recorded !== '' && $declared !== $recorded) {
            $parents[$id] = $declared;
            $sources[$id] = 'extends';
            $invalid[$id] = ['reason_code' => 'ownership_conflict', 'detail' => "Manifest parent '{$declared}' conflicts with install provenance '{$recorded}'."];
            continue;
        }
        if ($declared !== '') {
            $parents[$id] = $declared;
            $sources[$id] = 'extends';
        } elseif ($recorded !== '' && $recorded !== $id && isset($modules[$recorded])) {
            $parents[$id] = $recorded;
            $sources[$id] = 'provenance';
        } else {
            $parents[$id] = null;
            $sources[$id] = 'root';
        }
        if ($parents[$id] !== null && !isset($modules[$parents[$id]])) {
            $invalid[$id] = ['reason_code' => 'parent_missing', 'detail' => "Lifecycle parent '{$parents[$id]}' is not installed."];
        }
    }

    $facts = [];
    $unresolvedById = [];
    foreach ($modules as $id => $manifest) {
        $current = $id;
        $seen = [];
        $depth = 0;
        $root = null;
        $failure = null;
        while ($depth <= 32) {
            if (isset($seen[$current])) {
                $failure = ['reason_code' => 'ownership_cycle', 'detail' => "Ownership chain for '{$id}' contains a cycle."];
                break;
            }
            $seen[$current] = true;
            if (isset($invalid[$current])) {
                $failure = $invalid[$current];
                break;
            }
            $parent = $parents[$current] ?? null;
            if ($parent === null) {
                $root = $current;
                break;
            }
            $current = $parent;
            $depth++;
        }
        if ($root === null && $failure === null) {
            $failure = ['reason_code' => 'ownership_cycle', 'detail' => "Ownership chain for '{$id}' exceeds the safe depth limit."];
        }
        $facts[$id] = [
            'direct_parent' => $parents[$id] ?? null,
            'root' => $root,
            'depth' => $depth,
            'source' => $sources[$id] ?? 'root',
        ];
        if ($failure !== null) {
            $unresolvedById[$id] = ['id' => $id] + $failure;
        }
    }

    $roots = [];
    foreach ($facts as $id => $fact) {
        if ($fact['root'] !== $id || $fact['direct_parent'] !== null) {
            continue;
        }
        $manifest = $modules[$id];
        $roots[$id] = [
            'id' => $id,
            'name' => (string)($manifest['name'] ?? $id),
            'version' => (string)($manifest['version'] ?? '0.0.0'),
            'kind' => (string)($manifest['kind'] ?? ''),
            'child_count' => 0,
            'children' => [],
        ];
    }
    foreach ($facts as $id => $fact) {
        $root = $fact['root'];
        if (!is_string($root) || $root === $id || !isset($roots[$root])) {
            continue;
        }
        $manifest = $modules[$id];
        $roots[$root]['children'][] = [
            'id' => $id,
            'name' => (string)($manifest['name'] ?? $id),
            'version' => (string)($manifest['version'] ?? '0.0.0'),
            'kind' => (string)($manifest['kind'] ?? ''),
            'direct_parent' => $fact['direct_parent'],
            'root' => $root,
            'depth' => $fact['depth'],
        ];
    }
    ksort($roots);
    foreach ($roots as &$entry) {
        usort($entry['children'], static fn(array $a, array $b): int => [$a['depth'], $a['id']] <=> [$b['depth'], $b['id']]);
        $entry['child_count'] = count($entry['children']);
    }
    unset($entry);
    ksort($unresolvedById);

    return ['roots' => $roots, 'unresolved' => array_values($unresolvedById), 'facts' => $facts];
}

/**
 * Return one module's ultimate lifecycle root. It refuses unknown and
 * unresolved modules so callers cannot accidentally treat ambiguity as authority.
 */
function moduleOwnershipRoot(string $moduleId, ?array $fleet = null, ?array $provenance = null): ?string
{
    $resolved = moduleOwnershipResolveAll($fleet, $provenance);
    $root = $resolved['facts'][$moduleId]['root'] ?? null;
    return is_string($root) && $root !== '' ? $root : null;
}

/**
 * Return every resolved descendant of a root. It refuses to derive children
 * from suites or capabilities because those are not lifecycle relationships.
 *
 * @return list<array<string,mixed>>
 */
function moduleOwnershipChildren(string $rootId, ?array $fleet = null, ?array $provenance = null): array
{
    $resolved = moduleOwnershipResolveAll($fleet, $provenance);
    return isset($resolved['roots'][$rootId]) ? $resolved['roots'][$rootId]['children'] : [];
}

/**
 * Read kernel-written install facts. It refuses malformed files and entries
 * rather than allowing creator-authored or corrupt data to become authority.
 *
 * @return array<string,array<string,mixed>>
 */
function moduleOwnershipProvenance(?string $moduleId = null): array
{
    $file = BASE_PATH . '/storage/state/module-provenance.json';
    $raw = @file_get_contents($file);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    $modules = is_array($decoded) && is_array($decoded['modules'] ?? null) ? $decoded['modules'] : [];
    $clean = [];
    foreach ($modules as $id => $entry) {
        if (is_string($id) && $id !== '' && is_array($entry) && trim((string)($entry['installed_by'] ?? '')) !== '') {
            $clean[$id] = $entry;
        }
    }
    if ($moduleId === null) {
        return $clean;
    }
    return isset($clean[$moduleId]) ? [$moduleId => $clean[$moduleId]] : [];
}

/**
 * Atomically record a server-known installer. It refuses empty/self ownership
 * and reserved fields in extras because requests and manifests must not forge
 * kernel install facts.
 */
function moduleOwnershipRecordInstall(string $moduleId, string $installedBy, array $extra = []): bool
{
    $moduleId = trim($moduleId);
    $installedBy = trim($installedBy);
    if ($moduleId === '' || $installedBy === '' || $moduleId === $installedBy) {
        error_log('Module ownership: refused invalid install provenance');
        return false;
    }
    unset($extra['installed_by'], $extra['installed_at']);
    $modules = moduleOwnershipProvenance();
    $modules[$moduleId] = ['installed_by' => $installedBy, 'installed_at' => gmdate('c')] + $extra;
    $file = BASE_PATH . '/storage/state/module-provenance.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(['modules' => $modules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_dir($dir) || !is_string($json)) {
        error_log("Module ownership: unable to prepare provenance for {$moduleId}");
        return false;
    }
    try {
        $suffix = bin2hex(random_bytes(4));
    } catch (\Throwable $e) {
        $suffix = (string)mt_rand(100000, 999999);
    }
    $temp = $file . '.' . getmypid() . '.' . $suffix . '.tmp';
    $written = @file_put_contents($temp, $json . "\n", LOCK_EX);
    if ($written === false || !@rename($temp, $file)) {
        @unlink($temp);
        error_log("Module ownership: unable to write provenance for {$moduleId}");
        return false;
    }
    return true;
}

/**
 * Atomically forget one uninstall fact. It refuses to alter any other entry so
 * uninstalling one module cannot orphan unrelated provenance.
 */
function moduleOwnershipForget(string $moduleId): bool
{
    $moduleId = trim($moduleId);
    if ($moduleId === '') {
        return false;
    }
    $modules = moduleOwnershipProvenance();
    if (!array_key_exists($moduleId, $modules)) {
        return true;
    }
    unset($modules[$moduleId]);
    $file = BASE_PATH . '/storage/state/module-provenance.json';
    $dir = dirname($file);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $json = json_encode(['modules' => $modules], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        error_log("Module ownership: unable to encode provenance removal for {$moduleId}");
        return false;
    }
    $temp = $file . '.' . getmypid() . '.' . mt_rand(100000, 999999) . '.tmp';
    $written = @file_put_contents($temp, $json . "\n", LOCK_EX);
    if ($written === false || !@rename($temp, $file)) {
        @unlink($temp);
        error_log("Module ownership: unable to forget provenance for {$moduleId}");
        return false;
    }
    return true;
}
