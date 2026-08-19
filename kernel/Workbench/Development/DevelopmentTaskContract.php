<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Workbench\Development;

/**
 * Architecture task contract: strict .ai/current-task.md import adapter and
 * normalized scope/contract building for the Development Control Plane.
 *
 * The canonical persisted representation is the versioned normalized record.
 * Missing required sections are rejected rather than guessed. Scope matching
 * operates on normalized repository-relative paths; ambiguous patterns fail closed.
 */
final class DevelopmentTaskContract
{
    public const REQUIRED_HEADINGS = [
        'Objective',
        'Architectural constraints',
        'Files likely affected',
        'Acceptance criteria',
        'Required tests',
        'Risks',
        'Forbidden changes',
    ];

    /** Headings whose content is captured verbatim as normative contract text. */
    public const TEXT_HEADINGS = [
        'Objective' => 'objective',
        'Architectural constraints' => 'constraints',
        'Acceptance criteria' => 'acceptance',
        'Required tests' => 'required_tests',
        'Risks' => 'risks',
        'Forbidden changes' => 'forbidden_rules',
    ];

    /** @var list<string> */
    private const SECRET_TOKEN_PATTERNS = [
        // The leading negative lookbehind stops "sk-" from matching inside a
        // larger word/path (e.g. the "sk" in "task-2026..."), which would mangle
        // structural paths during envelope redaction.
        '/(?<![A-Za-z0-9_-])(sk|pk|ghp|gho|ghu|github_pat)[-_][A-Za-z0-9_\-]{12,}/',
        '/(api[_-]?key|secret|token|password|passwd|authorization|cookie|session|csrf|credential)\s*[:=]\s*["\']?[A-Za-z0-9_\-\.\/\+]{8,}/i',
    ];

    /**
     * Parse .ai/current-task.md fixed headings into a normalized parsed contract.
     *
     * @throws \InvalidArgumentException when a required heading is missing.
     * @return array<string,mixed>
     */
    public static function parseCurrentTaskMarkdown(string $markdown): array
    {
        $markdown = trim($markdown);
        if ($markdown === '') {
            throw new \InvalidArgumentException('Architecture source is empty');
        }

        $sections = self::extractSections($markdown);
        $missing = [];
        foreach (self::REQUIRED_HEADINGS as $heading) {
            if (!isset($sections[$heading]) || trim($sections[$heading]) === '') {
                $missing[] = $heading;
            }
        }
        if ($missing !== []) {
            throw new \InvalidArgumentException(
                'Architecture import rejected: missing required section(s): ' . implode(', ', $missing)
            );
        }

        $allowed = self::parseScopeBullets($sections['Files likely affected'], 'allowed');
        $forbidden = self::parseScopeBullets($sections['Forbidden changes'], 'forbidden');

        // Optional "Baseline" heading: explicitly declared pre-existing working-tree
        // changes that must remain as-is and are NOT task scope (e.g. dirty .github
        // files that predate the task). When absent, the ingestor captures the
        // baseline from Git at import time.
        $baseline = [];
        if (isset($sections['Baseline']) && trim($sections['Baseline']) !== '') {
            $baseline = self::parseScopeBullets($sections['Baseline'], 'baseline');
        }

        $parsed = [
            'objective' => trim($sections['Objective']),
            'constraints' => self::bulletLines($sections['Architectural constraints']),
            'acceptance' => self::bulletLines($sections['Acceptance criteria']),
            'required_tests' => self::bulletLines($sections['Required tests']),
            'risks' => self::bulletLines($sections['Risks']),
            'forbidden_rules' => self::bulletLines($sections['Forbidden changes']),
            'allowed_scope' => $allowed,
            'forbidden_scope' => $forbidden,
            'baseline_scope' => $baseline,
            'source_hash' => hash('sha256', $markdown),
            'files_affected' => self::bulletLines($sections['Files likely affected']),
        ];

        return self::normalizeParsed($parsed);
    }

    /**
     * Normalize and validate a parsed contract. Fail closed on ambiguous patterns.
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public static function normalizeParsed(array $parsed): array
    {
        $parsed['objective'] = trim((string) ($parsed['objective'] ?? ''));
        if ($parsed['objective'] === '') {
            throw new \InvalidArgumentException('Architecture import rejected: objective is empty');
        }

        $parsed['allowed_scope'] = self::validateScopeEntries(
            (array) ($parsed['allowed_scope'] ?? []),
            'allowed'
        );
        $parsed['forbidden_scope'] = self::validateScopeEntries(
            (array) ($parsed['forbidden_scope'] ?? []),
            'forbidden'
        );
        $parsed['baseline_scope'] = self::validateScopeEntries(
            (array) ($parsed['baseline_scope'] ?? []),
            'baseline'
        );

        $parsed['source_hash'] = (string) ($parsed['source_hash'] ?? hash('sha256', json_encode($parsed)));

        return $parsed;
    }

    /** Immutable revision id derived from the canonical normalized content. */
    public static function revisionId(array $normalized): string
    {
        $canonical = [
            'objective' => $normalized['objective'] ?? '',
            'constraints' => $normalized['constraints'] ?? [],
            'acceptance' => $normalized['acceptance'] ?? [],
            'required_tests' => $normalized['required_tests'] ?? [],
            'risks' => $normalized['risks'] ?? [],
            'forbidden_rules' => $normalized['forbidden_rules'] ?? [],
            'allowed_scope' => $normalized['allowed_scope'] ?? [],
            'forbidden_scope' => $normalized['forbidden_scope'] ?? [],
            'baseline_scope' => $normalized['baseline_scope'] ?? [],
        ];

        return substr(hash('sha256', json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * Normalize a repository-relative path. Rejects traversal, absolute paths and
     * ambiguous glob patterns (glob only on the basename collapses to a directory
     * prefix; anything else fails closed).
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path, " \t\n\r\0\x0B`\"'");
        $path = preg_replace('#^\./+#', '', $path) ?? $path;
        $path = rtrim($path, '/');

        if ($path === '' || $path === '.') {
            throw new \InvalidArgumentException('Invalid scope path: empty');
        }
        if (str_starts_with($path, '/') || preg_match('#^[A-Za-z]:[\\\\/]#', $path) === 1) {
            throw new \InvalidArgumentException("Invalid absolute scope path: {$path}");
        }
        if (in_array($path, ['.', '..'], true) || str_contains($path, '../') || str_contains($path, '/../')
            || preg_match('#(^|/)\.\.(/|$)#', $path) === 1) {
            throw new \InvalidArgumentException("Invalid traversal scope path: {$path}");
        }

        return $path;
    }

    /** @return array{ok:bool,reason:string,path:string,kind:string} */
    public static function parseScopeEntry(string $raw, string $scopeKind): array
    {
        // Extract the first backtick-quoted token, else the first whitespace token.
        $token = '';
        if (preg_match('/`([^`]+)`/', $raw, $m) === 1) {
            $token = $m[1];
        } else {
            $token = preg_split('/\s+/', trim($raw))[0] ?? '';
        }
        $token = trim($token, " \t\n\r\0\x0B,;:-");

        if ($token === '') {
            return ['ok' => false, 'reason' => 'no path token', 'path' => '', 'kind' => 'file'];
        }

        $wasDirectory = str_ends_with($token, '/');
        $isGlob = preg_match('/[*?\[\]{}]/', $token) === 1;
        if ($isGlob) {
            // Collapse a basename-only glob (e.g. dir/*.md) to its directory prefix.
            if (preg_match('#^(.+)/[^/]*[*?\[\]{}][^/]*$#', $token, $g) === 1) {
                $token = $g[1];
                $wasDirectory = true;
            } else {
                return ['ok' => false, 'reason' => "ambiguous glob pattern: {$token}", 'path' => $token, 'kind' => 'file'];
            }
        }

        // Prose is not a path. Forbidden prose is retained as a rule; allowed scope
        // entries must be path-like or the import fails closed.
        if (!preg_match('#^[A-Za-z0-9_./\-]+$#', $token)) {
            if ($scopeKind === 'forbidden') {
                return ['ok' => false, 'reason' => 'non-path forbidden statement', 'path' => $token, 'kind' => 'rule'];
            }
            return ['ok' => false, 'reason' => 'allowed scope entry is not a path', 'path' => $token, 'kind' => 'file'];
        }

        try {
            $token = self::normalizePath($token);
        } catch (\InvalidArgumentException $e) {
            return ['ok' => false, 'reason' => $e->getMessage(), 'path' => $token, 'kind' => 'file'];
        }

        return ['ok' => true, 'reason' => '', 'path' => $token, 'kind' => $wasDirectory ? 'directory' : 'file'];
    }

    /**
     * Build normalized scope entries from a section's bullet list.
     *
     * @param list<string> $lines
     * @return list<array{path:string,kind:string}>
     */
    private static function parseScopeBullets(string $section, string $scopeKind): array
    {
        $entries = [];
        foreach (self::bulletLines($section) as $line) {
            $parsed = self::parseScopeEntry($line, $scopeKind);
            if (!$parsed['ok']) {
                // Prose forbidden rules are not path scope; non-path allowed lines fail closed.
                if ($scopeKind === 'forbidden' && $parsed['kind'] === 'rule') {
                    continue;
                }
                throw new \InvalidArgumentException(
                    "Architecture import rejected: invalid {$scopeKind} scope entry '{$line}': {$parsed['reason']}"
                );
            }
            $entries[] = ['path' => $parsed['path'], 'kind' => $parsed['kind']];
        }

        return $entries;
    }

    /**
     * Validate pre-built scope entries (used after parsing and before revision hash).
     *
     * @param list<array{path:string,kind:string}> $entries
     * @return list<array{path:string,kind:string}>
     */
    private static function validateScopeEntries(array $entries, string $scopeKind): array
    {
        $out = [];
        foreach ($entries as $entry) {
            $path = (string) ($entry['path'] ?? '');
            $kind = (string) ($entry['kind'] ?? 'file');
            if (!in_array($kind, ['file', 'directory', 'rule'], true)) {
                throw new \InvalidArgumentException("Invalid {$scopeKind} scope kind: {$kind}");
            }
            $out[] = ['path' => $path, 'kind' => $kind];
        }

        return $out;
    }

    /** @return list<string> */
    private static function bulletLines(string $section): array
    {
        $lines = [];
        foreach (preg_split('/\r?\n/', $section) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '```')) {
                continue;
            }
            $lines[] = preg_replace('/^[-*]\s+/', '', $line) ?? $line;
        }

        return $lines;
    }

    /** @return array<string,string> */
    private static function extractSections(string $markdown): array
    {
        $sections = [];
        $lines = preg_split('/\r?\n/', $markdown) ?: [];
        $current = null;
        $buffer = [];

        foreach ($lines as $line) {
            if (preg_match('/^#{1,3}\s+(.+?)\s*#*\s*$/', $line, $m) === 1) {
                if ($current !== null) {
                    $sections[$current] = trim(implode("\n", $buffer));
                }
                $current = trim($m[1]);
                $buffer = [];
                continue;
            }
            if ($current !== null) {
                $buffer[] = $line;
            }
        }
        if ($current !== null) {
            $sections[$current] = trim(implode("\n", $buffer));
        }

        return $sections;
    }

    /** Best-effort removal of secrets from arbitrary scalar text (mirrors redaction). */
    public static function redactScalar(string $value): string
    {
        foreach (self::SECRET_TOKEN_PATTERNS as $pattern) {
            $value = (string) preg_replace($pattern, '[REDACTED]', $value);
        }

        return $value;
    }
}
