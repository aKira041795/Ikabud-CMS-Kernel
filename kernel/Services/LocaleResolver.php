<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * 4.2: i18n Locale Resolver & Translation Helper
 *
 * Provides locale detection (Accept-Language, cookie, query param, tenant default)
 * and a simple translation lookup against kernel_translations.
 *
 * Designed to be lightweight enough for early adoption; modules can extend
 * with content-level translations independently.
 */
class LocaleResolver
{
    private string $defaultLocale = 'en';
    private ?string $resolvedLocale = null;
    private array $cache = [];

    /**
     * Resolve the current request locale.
     * Priority: query ?lang= > cookie > Accept-Language header > tenant default > 'en'.
     */
    public function resolve(): string
    {
        if ($this->resolvedLocale !== null) {
            return $this->resolvedLocale;
        }

        // 1. Explicit query parameter
        $lang = trim((string)($_GET['lang'] ?? ''));
        if ($lang !== '' && $this->isValid($lang)) {
            $this->resolvedLocale = $lang;
            return $lang;
        }

        // 2. Cookie
        $cookie = trim((string)($_COOKIE['locale'] ?? ''));
        if ($cookie !== '' && $this->isValid($cookie)) {
            $this->resolvedLocale = $cookie;
            return $cookie;
        }

        // 3. Accept-Language header
        $header = trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
        if ($header !== '') {
            $parsed = $this->parseAcceptLanguage($header);
            foreach ($parsed as $candidate) {
                if ($this->isActive($candidate)) {
                    $this->resolvedLocale = $candidate;
                    return $candidate;
                }
            }
        }

        // 4. Tenant default
        $this->resolvedLocale = $this->defaultLocale;
        return $this->resolvedLocale;
    }

    /**
     * Override the resolved locale (e.g., from admin UI toggle).
     */
    public function setLocale(string $locale): void
    {
        $this->resolvedLocale = $locale;
    }

    /**
     * Set the default locale (typically loaded from DB).
     */
    public function setDefault(string $locale): void
    {
        $this->defaultLocale = $locale;
    }

    /**
     * Translate a key.
     *
     * @param string $key        Dot-notation key, e.g. 'messages.welcome'
     * @param array  $replace    Placeholder replacements: ['name' => 'John']
     * @param string $namespace  Module ID or 'kernel'
     * @return string Translated string, or the key itself if not found.
     */
    public function trans(string $key, array $replace = [], string $namespace = 'kernel'): string
    {
        $locale = $this->resolve();
        $cacheKey = "$locale:$namespace:$key";

        if (isset($this->cache[$cacheKey])) {
            $value = $this->cache[$cacheKey];
        } else {
            $value = $this->lookup($locale, $namespace, $key);
            $this->cache[$cacheKey] = $value;
        }

        if ($value === null) {
            return $key; // fallback to key
        }

        // Apply replacements: :name => value
        foreach ($replace as $placeholder => $val) {
            $value = str_replace(':' . $placeholder, (string)$val, $value);
        }

        return $value;
    }

    /**
     * Get all active locales.
     *
     * @return array<array{code: string, name: string, native_name: ?string, direction: string}>
     */
    public function activeLocales(): array
    {
        try {
            $db = app()->db();
            $stmt = $db->query("SELECT code, name, native_name, direction FROM kernel_locales WHERE is_active = 1 ORDER BY is_default DESC, name ASC");
            return $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        } catch (\Throwable $e) {
            return [['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'direction' => 'ltr']];
        }
    }

    // ── Internals ────────────────────────────────────────────────

    private function lookup(string $locale, string $namespace, string $key): ?string
    {
        // Parse key into group + item: "messages.welcome" => group=messages, item=welcome
        $dot = strpos($key, '.');
        if ($dot !== false) {
            $group = substr($key, 0, $dot);
            $item = substr($key, $dot + 1);
        } else {
            $group = 'messages';
            $item = $key;
        }

        try {
            $db = app()->db();
            $stmt = $db->prepare(
                "SELECT value FROM kernel_translations WHERE locale = :loc AND namespace = :ns AND group_key = :grp AND item_key = :item LIMIT 1"
            );
            $stmt->execute([':loc' => $locale, ':ns' => $namespace, ':grp' => $group, ':item' => $item]);
            $val = $stmt->fetchColumn();
            return $val !== false ? (string)$val : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function isValid(string $locale): bool
    {
        return (bool)preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale);
    }

    private function isActive(string $locale): bool
    {
        if (!$this->isValid($locale)) return false;
        try {
            $db = app()->db();
            $stmt = $db->prepare("SELECT 1 FROM kernel_locales WHERE code = :c AND is_active = 1 LIMIT 1");
            $stmt->execute([':c' => $locale]);
            return $stmt->fetchColumn() !== false;
        } catch (\Throwable $e) {
            return $locale === 'en';
        }
    }

    private function parseAcceptLanguage(string $header): array
    {
        $locales = [];
        $parts = explode(',', $header);
        foreach ($parts as $part) {
            $segments = explode(';', trim($part));
            $code = trim($segments[0]);
            $q = 1.0;
            if (isset($segments[1])) {
                $qMatch = [];
                if (preg_match('/q\s*=\s*([0-9.]+)/', $segments[1], $qMatch)) {
                    $q = (float)$qMatch[1];
                }
            }
            // Normalize: en-US => en-US, en => en
            $code = str_replace('_', '-', $code);
            $locales[$code] = $q;
        }
        arsort($locales);
        return array_keys($locales);
    }
}
