<?php

declare(strict_types=1);

/**
 * CMS Builder Document Audit Tool
 *
 * Purpose:
 * - Sweep builder documents for known preview/frontend inconsistency patterns.
 * - Optionally auto-fix safe issues.
 *
 * Usage:
 *   php modules/cms/builder-document-audit.php
 *   php modules/cms/builder-document-audit.php --content-id=139
 *   php modules/cms/builder-document-audit.php --fix
 *   php modules/cms/builder-document-audit.php --fix --content-id=139 --json
 */

$root = dirname(__DIR__, 2);
$envPath = $root . '/.env';
$configPath = $root . '/config/database.php';

if (!is_file($configPath)) {
    fwrite(STDERR, "Missing config/database.php\n");
    exit(1);
}

loadEnv($envPath);
$dbConfig = require $configPath;
$pdo = createPdo($dbConfig);

$options = parseOptions($argv);
$fix = !empty($options['fix']);
$jsonOut = !empty($options['json']);
$contentId = isset($options['content-id']) ? (int)$options['content-id'] : null;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : null;

$docs = fetchDocuments($pdo, $contentId, $limit);

$summary = [
    'scanned_docs' => 0,
    'changed_docs' => 0,
    'issues' => 0,
    'fixes' => 0,
    'documents' => [],
];

foreach ($docs as $doc) {
    $summary['scanned_docs']++;

    $docId = (int)$doc['id'];
    $content = (int)$doc['content_id'];
    $status = (string)$doc['status'];

    $parsed = json_decode((string)$doc['document_json'], true);
    if (!is_array($parsed)) {
        $summary['issues']++;
        $summary['documents'][] = [
            'id' => $docId,
            'content_id' => $content,
            'status' => $status,
            'changed' => false,
            'issues' => [[
                'severity' => 'error',
                'path' => '$',
                'rule' => 'invalid-json',
                'message' => 'document_json is not valid JSON object',
            ]],
            'fixes' => [],
        ];
        continue;
    }

    $docRoot = &extractRoot($parsed);
    if (!is_array($docRoot)) {
        $summary['issues']++;
        $summary['documents'][] = [
            'id' => $docId,
            'content_id' => $content,
            'status' => $status,
            'changed' => false,
            'issues' => [[
                'severity' => 'error',
                'path' => '$.document',
                'rule' => 'missing-root-node',
                'message' => 'Unable to resolve builder root node',
            ]],
            'fixes' => [],
        ];
        continue;
    }

    $issues = [];
    $fixes = [];
    $changed = false;

    auditNode($docRoot, '$.document', $issues, $fixes, $changed, $fix);

    if ($changed && $fix) {
        $newJson = json_encode($parsed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($newJson === false) {
            $issues[] = [
                'severity' => 'error',
                'path' => '$',
                'rule' => 'encode-failed',
                'message' => 'Failed to encode updated JSON',
            ];
            $changed = false;
        } else {
            $stmt = $pdo->prepare('UPDATE cms_builder_documents SET document_json = :json, updated_at = NOW() WHERE id = :id');
            $stmt->execute([
                ':json' => $newJson,
                ':id' => $docId,
            ]);
        }
    }

    $summary['issues'] += count($issues);
    $summary['fixes'] += count($fixes);
    if ($changed) {
        $summary['changed_docs']++;
    }

    $summary['documents'][] = [
        'id' => $docId,
        'content_id' => $content,
        'status' => $status,
        'changed' => $changed,
        'issues' => $issues,
        'fixes' => $fixes,
    ];
}

if ($jsonOut) {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

$mode = $fix ? 'APPLY' : 'DRY-RUN';
echo "Builder audit ({$mode}) complete: scanned={$summary['scanned_docs']}, changed={$summary['changed_docs']}, issues={$summary['issues']}, fixes={$summary['fixes']}\n";

foreach ($summary['documents'] as $docReport) {
    $prefix = "- doc#{$docReport['id']} content#{$docReport['content_id']} [{$docReport['status']}]";
    echo $prefix . ($docReport['changed'] ? ' CHANGED' : '') . "\n";
    foreach ($docReport['issues'] as $issue) {
        echo "  ! {$issue['severity']} {$issue['rule']} @ {$issue['path']}: {$issue['message']}\n";
    }
    foreach ($docReport['fixes'] as $fixLine) {
        echo "  + {$fixLine}\n";
    }
}

exit(0);

function loadEnv(string $envPath): void
{
    if (!is_file($envPath)) {
        return;
    }
    $lines = @file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '' && getenv($k) === false) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
        }
    }
}

function createPdo(array $dbConfig): PDO
{
    $host = (string)($dbConfig['host'] ?? getenv('DB_HOST') ?: '127.0.0.1');
    $port = (int)($dbConfig['port'] ?? getenv('DB_PORT') ?: 3306);
    $name = (string)($dbConfig['database'] ?? getenv('DB_DATABASE') ?: '');
    $user = (string)($dbConfig['username'] ?? getenv('DB_USERNAME') ?: '');
    $pass = (string)($dbConfig['password'] ?? getenv('DB_PASSWORD') ?: '');
    $charset = (string)($dbConfig['charset'] ?? 'utf8mb4');

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
}

function parseOptions(array $argv): array
{
    $opts = [];
    foreach ($argv as $arg) {
        if (!str_starts_with((string)$arg, '--')) {
            continue;
        }
        $arg = substr((string)$arg, 2);
        if (str_contains($arg, '=')) {
            [$k, $v] = explode('=', $arg, 2);
            $opts[$k] = $v;
        } else {
            $opts[$arg] = true;
        }
    }
    return $opts;
}

function fetchDocuments(PDO $pdo, ?int $contentId, ?int $limit): array
{
    $sql = 'SELECT id, content_id, status, document_json FROM cms_builder_documents';
    $where = [];
    $bind = [];

    if ($contentId !== null && $contentId > 0) {
        $where[] = 'content_id = :content_id';
        $bind[':content_id'] = $contentId;
    }

    if (!empty($where)) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }

    $sql .= ' ORDER BY content_id ASC, id ASC';
    if ($limit !== null) {
        $sql .= ' LIMIT ' . (int)$limit;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($bind);
    return $stmt->fetchAll();
}

function &extractRoot(array &$parsed): mixed
{
    if (isset($parsed['document']) && is_array($parsed['document'])) {
        return $parsed['document'];
    }
    return $parsed;
}

function auditNode(array &$node, string $path, array &$issues, array &$fixes, bool &$changed, bool $applyFix): void
{
    // Rule 1: remove null/empty style values (top-level and responsive buckets).
    if (isset($node['style']) && is_array($node['style'])) {
        normalizeStyle($node['style'], $path . '.style', $issues, $fixes, $changed, $applyFix);
    }

    $type = (string)($node['type'] ?? '');
    $props = isset($node['props']) && is_array($node['props']) ? $node['props'] : [];
    $hover = trim((string)($props['hoverAnimation'] ?? ''));
    $entrance = trim((string)($props['entranceAnimation'] ?? ''));

    // Rule 2: parent layout animation duplication with child button animation.
    $layoutTypes = ['section', 'container', 'row', 'column'];
    if (in_array($type, $layoutTypes, true) && ($hover !== '' || $entrance !== '')) {
        $buttons = [];
        collectDescendantButtons($node, $path, $buttons, '');

        if ($hover !== '') {
            resolveAnimationDuplication($node, $path, 'hoverAnimation', $hover, $buttons, $issues, $fixes, $changed, $applyFix);
        }
        if ($entrance !== '') {
            resolveAnimationDuplication($node, $path, 'entranceAnimation', $entrance, $buttons, $issues, $fixes, $changed, $applyFix);
        }
    }

    // Rule 3: combined transform-risk warning (entrance + hover + explicit transform style).
    $transform = '';
    if (isset($node['style']) && is_array($node['style']) && isset($node['style']['transform']) && !is_array($node['style']['transform'])) {
        $transform = trim((string)$node['style']['transform']);
    }
    if ($hover !== '' && $entrance !== '' && $transform !== '') {
        $issues[] = [
            'severity' => 'warn',
            'path' => $path,
            'rule' => 'transform-conflict-risk',
            'message' => 'Node has entrance + hover animation with explicit transform style; verify transform stacking in preview/frontend.',
        ];
    }

    if (!isset($node['children']) || !is_array($node['children'])) {
        return;
    }

    foreach ($node['children'] as $idx => &$child) {
        if (!is_array($child)) {
            continue;
        }
        auditNode($child, $path . '.children[' . $idx . ']', $issues, $fixes, $changed, $applyFix);
    }
}

function normalizeStyle(array &$style, string $path, array &$issues, array &$fixes, bool &$changed, bool $applyFix): void
{
    foreach ($style as $k => $v) {
        if (is_array($v) && ($k === 'tablet' || $k === 'mobile')) {
            normalizeStyle($style[$k], $path . '.' . $k, $issues, $fixes, $changed, $applyFix);
            if ($applyFix && isset($style[$k]) && is_array($style[$k]) && empty($style[$k])) {
                unset($style[$k]);
                $fixes[] = "remove empty responsive style bucket {$path}.{$k}";
                $changed = true;
            }
            continue;
        }

        if ($v === null || $v === '') {
            $issues[] = [
                'severity' => 'warn',
                'path' => $path . '.' . $k,
                'rule' => 'empty-style-value',
                'message' => 'Style value is null/empty and can override defaults inconsistently.',
            ];
            if ($applyFix) {
                unset($style[$k]);
                $fixes[] = "remove empty style {$path}.{$k}";
                $changed = true;
            }
        }
    }
}

function collectDescendantButtons(array $node, string $path, array &$buttons, string $relativePath): void
{
    if (($node['type'] ?? '') === 'button') {
        $buttons[] = [
            'path' => $path,
            'relative_path' => $relativePath,
            'props' => isset($node['props']) && is_array($node['props']) ? $node['props'] : [],
        ];
    }

    $children = isset($node['children']) && is_array($node['children']) ? $node['children'] : [];
    foreach ($children as $idx => $child) {
        if (!is_array($child)) {
            continue;
        }
        collectDescendantButtons(
            $child,
            $path . '.children[' . $idx . ']',
            $buttons,
            $relativePath . '.children[' . $idx . ']'
        );
    }
}

function resolveAnimationDuplication(
    array &$layoutNode,
    string $layoutPath,
    string $prop,
    string $value,
    array $buttons,
    array &$issues,
    array &$fixes,
    bool &$changed,
    bool $applyFix
): void {
    if (empty($buttons)) {
        return;
    }

    $hasSame = false;
    $emptyTargets = 0;
    foreach ($buttons as $btn) {
        $p = isset($btn['props'][$prop]) ? trim((string)$btn['props'][$prop]) : '';
        if ($p === $value) {
            $hasSame = true;
        }
        if ($p === '') {
            $emptyTargets++;
        }
    }

    if ($hasSame) {
        $issues[] = [
            'severity' => 'warn',
            'path' => $layoutPath . '.props.' . $prop,
            'rule' => 'duplicate-layout-animation',
            'message' => "Layout {$prop} duplicates descendant button animation ({$value}).",
        ];

        if ($applyFix && isset($layoutNode['props'][$prop])) {
            unset($layoutNode['props'][$prop]);
            $fixes[] = "remove duplicated {$prop}={$value} from {$layoutPath}";
            $changed = true;
        }
        return;
    }

    if ($emptyTargets === 1) {
        $issues[] = [
            'severity' => 'warn',
            'path' => $layoutPath . '.props.' . $prop,
            'rule' => 'likely-wrong-animation-target',
            'message' => "Layout {$prop}={$value} likely belongs to a child button.",
        ];

        if ($applyFix && isset($layoutNode['props'][$prop])) {
            $targetRelativePath = null;
            foreach ($buttons as $btn) {
                $p = isset($btn['props'][$prop]) ? trim((string)$btn['props'][$prop]) : '';
                if ($p === '') {
                    $targetRelativePath = (string)($btn['relative_path'] ?? '');
                    break;
                }
            }

            if ($targetRelativePath !== null && setPropByRelativePath($layoutNode, $targetRelativePath, $prop, $value)) {
                unset($layoutNode['props'][$prop]);
                $fixes[] = "move {$prop}={$value} from {$layoutPath} to {$layoutPath}{$targetRelativePath}";
                $changed = true;
            }
        }
    }
}

function setPropByRelativePath(array &$root, string $relativePath, string $prop, string $value): bool
{
    $node = &$root;

    if ($relativePath !== '') {
        if (!preg_match_all('/\\.children\\[(\\d+)\\]/', $relativePath, $matches)) {
            return false;
        }

        foreach ($matches[1] as $idxRaw) {
            $idx = (int)$idxRaw;
            if (!isset($node['children']) || !is_array($node['children']) || !isset($node['children'][$idx]) || !is_array($node['children'][$idx])) {
                return false;
            }
            $node = &$node['children'][$idx];
        }
    }

    if (!isset($node['props']) || !is_array($node['props'])) {
        $node['props'] = [];
    }

    $node['props'][$prop] = $value;
    return true;
}
