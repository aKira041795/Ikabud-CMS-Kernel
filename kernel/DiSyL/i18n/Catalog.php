<?php

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL\i18n;

/**
 * DiSyL 4.1 — i18n catalog loader and message resolver.
 *
 * Lookup priority (highest first):
 *   1. Per-tenant catalog: storage/i18n/{tenant_id}/{locale}.json
 *   2. Global catalog:     storage/i18n/{locale}.json
 *   3. Inline body fallback (the source-language string between
 *      {trans} and {/trans})
 *
 * Catalog file format (JSON):
 *   {
 *     "cart.empty":            { "value": "Tu carrito está vacío." },
 *     "cart.items": {
 *       "plural": {
 *         "one":   "1 artículo",
 *         "other": "%(count)s artículos"
 *       }
 *     },
 *     "product.title:shop_grid": { "value": "%(name)s" }
 *   }
 *
 * The per-process cache is keyed by (storage_root, tenant_id, locale).
 */
final class Catalog
{
    /** @var array<string, array<string, mixed>> */
    private static array $cache = [];

    private function __construct() {}

    /**
     * Resolve a translation. Returns null when the key is unknown so the
     * caller can fall back to the inline body text.
     *
     * @param array<string,string> $vars Placeholder values keyed by name.
     */
    public static function translate(
        string $storageRoot,
        string $tenantId,
        string $locale,
        string $key,
        ?string $context = null,
        array $vars = [],
        ?string $pluralArm = null
    ): ?string {
        $catalogKey = $context !== null ? $key . ':' . $context : $key;
        $entry = self::lookup($storageRoot, $tenantId, $locale, $catalogKey);
        if ($entry === null) {
            return null;
        }

        if ($pluralArm !== null) {
            $forms = $entry['plural'] ?? null;
            if (!is_array($forms)) {
                return null;
            }
            $template = $forms[$pluralArm] ?? $forms['other'] ?? null;
            if (!is_string($template)) {
                return null;
            }
            return self::interpolate($template, $vars);
        }

        $value = $entry['value'] ?? null;
        if (!is_string($value)) {
            return null;
        }
        return self::interpolate($value, $vars);
    }

    /**
     * Decide which plural arm name to use (CLDR subset).
     *
     * 4.1 implements a deliberate subset: `one` for n == 1, `other` for
     * everything else. Locales that need zero/two/few/many can extend this
     * by overriding via {@see registerPluralRule()}.
     */
    public static function pluralCategory(string $locale, int|float $n): string
    {
        $rule = self::$pluralRules[strtolower($locale)] ?? null;
        if ($rule !== null) {
            return ($rule)($n);
        }
        return ((int) $n === 1 && (float) $n === 1.0) ? 'one' : 'other';
    }

    /** @var array<string, callable(int|float):string> */
    private static array $pluralRules = [];

    /**
     * Register a custom plural-rule resolver for a locale. Used by tests and
     * by extension modules that ship locale packs.
     *
     * @param callable(int|float):string $resolver
     */
    public static function registerPluralRule(string $locale, callable $resolver): void
    {
        self::$pluralRules[strtolower($locale)] = $resolver;
    }

    /**
     * Reset cached catalogs and registered plural rules. Test-only helper.
     */
    public static function resetForTests(): void
    {
        self::$cache = [];
        self::$pluralRules = [];
    }

    /**
     * @return array{value?:string, plural?:array<string,string>}|null
     */
    private static function lookup(
        string $storageRoot,
        string $tenantId,
        string $locale,
        string $catalogKey
    ): ?array {
        $merged = self::loadMerged($storageRoot, $tenantId, $locale);
        $entry = $merged[$catalogKey] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        return $entry;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadMerged(string $storageRoot, string $tenantId, string $locale): array
    {
        $cacheKey = $storageRoot . '|' . $tenantId . '|' . strtolower($locale);
        if (array_key_exists($cacheKey, self::$cache)) {
            return self::$cache[$cacheKey];
        }
        $global = self::loadFile($storageRoot . '/' . $locale . '.json');
        $tenant = $tenantId !== ''
            ? self::loadFile($storageRoot . '/' . $tenantId . '/' . $locale . '.json')
            : [];
        // Tenant overrides global on key collision.
        $merged = array_replace($global, $tenant);
        self::$cache[$cacheKey] = $merged;
        return $merged;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private static function loadFile(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return $decoded;
    }

    /**
     * Replace `%(name)s` placeholders with values from the vars map.
     *
     * @param array<string,string|int|float|bool|null> $vars
     */
    private static function interpolate(string $template, array $vars): string
    {
        if ($vars === [] || !str_contains($template, '%(')) {
            return $template;
        }
        return preg_replace_callback(
            '/%\(([A-Za-z_][A-Za-z0-9_.]*)\)s/',
            static function (array $m) use ($vars): string {
                $name = $m[1];
                if (!array_key_exists($name, $vars)) {
                    return $m[0];
                }
                $val = $vars[$name];
                if ($val === null) {
                    return '';
                }
                if (is_bool($val)) {
                    return $val ? 'true' : 'false';
                }
                return (string) $val;
            },
            $template
        );
    }
}
