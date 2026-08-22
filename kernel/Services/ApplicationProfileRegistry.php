<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ApplicationProfileProvider;

/**
 * ApplicationProfileRegistry — discovers and registers application profiles.
 *
 * Scans `storage/application-profiles/` for profile directories containing a
 * `profile.manifest.json` and a provider class implementing
 * ApplicationProfileProvider.
 *
 * This is a kernel-level service. It does not depend on CMS, Theme Studio,
 * or any module.
 *
 * @package Ikabud\Kernel\Services
 */
class ApplicationProfileRegistry
{
    /** @var array<string, ApplicationProfileProvider> */
    private static array $profiles = [];

    /** @var bool */
    private static bool $loaded = false;

    /**
     * Directory where application profiles live.
     */
    private const PROFILES_DIR = 'storage/application-profiles';

    /**
     * Manifest filename to discover.
     */
    private const MANIFEST_FILE = 'profile.manifest.json';

    /**
     * Register a profile provider.
     */
    public static function register(ApplicationProfileProvider $provider): void
    {
        self::$profiles[$provider->id()] = $provider;
    }

    /**
     * Discover and load all profiles from the profiles directory.
     *
     * @param string $basePath Absolute path to the application root
     */
    public static function discover(string $basePath): void
    {
        if (self::$loaded) {
            return;
        }

        $profilesDir = $basePath . '/' . self::PROFILES_DIR;

        if (!is_dir($profilesDir)) {
            self::$loaded = true;
            return;
        }

        foreach (scandir($profilesDir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $profilePath = $profilesDir . '/' . $entry;
            $manifestPath = $profilePath . '/' . self::MANIFEST_FILE;

            if (!is_dir($profilePath) || !is_file($manifestPath)) {
                continue;
            }

            self::loadProfile($profilePath, $manifestPath);
        }

        self::$loaded = true;
    }

    /**
     * Load a single profile from its directory.
     */
    private static function loadProfile(string $profilePath, string $manifestPath): void
    {
        $manifest = json_decode(file_get_contents($manifestPath), true);

        if (!is_array($manifest) || empty($manifest['name'])) {
            return;
        }

        // Look for provider declaration
        $provider = $manifest['provider'] ?? null;
        if ($provider === null) {
            return;
        }

        // Support both string (class name only) and object {class, file} formats
        $providerClass = is_string($provider) ? $provider : ($provider['class'] ?? null);
        $providerFile = is_array($provider) ? ($provider['file'] ?? null) : null;

        if ($providerClass === null) {
            return;
        }

        // If a provider file is specified, require it explicitly
        if ($providerFile !== null) {
            $fullPath = $profilePath . '/' . $providerFile;
            $realPath = realpath($fullPath);

            // Security: ensure the file is within the profile directory
            $profileRoot = rtrim((string)realpath($profilePath), DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR;

            if ($realPath === false || !str_starts_with($realPath, $profileRoot)) {
                write_log("ApplicationProfileRegistry: provider file '{$providerFile}' for profile '{$manifest['name']}' is outside the profile directory — skipping");
                return;
            }

            if (!is_file($realPath)) {
                write_log("ApplicationProfileRegistry: provider file '{$providerFile}' not found for profile '{$manifest['name']}' — skipping");
                return;
            }

            require_once $realPath;
        }

        if (!class_exists($providerClass)) {
            write_log("ApplicationProfileRegistry: provider class '{$providerClass}' not found for profile '{$manifest['name']}' after loading '{$providerFile}'");
            return;
        }

        $instance = new $providerClass();

        if (!($instance instanceof \Ikabud\Kernel\Contracts\ApplicationProfileProvider)) {
            write_log("ApplicationProfileRegistry: provider class '{$providerClass}' does not implement ApplicationProfileProvider");
            return;
        }

        self::register($instance);
        self::$manifestPaths[$instance->id()] = $manifestPath;
    }

    /**
     * Get a registered profile by ID.
     */
    public static function get(string $id): ?ApplicationProfileProvider
    {
        return self::$profiles[$id] ?? null;
    }

    /**
     * Get all registered profiles.
     *
     * @return array<string, ApplicationProfileProvider>
     */
    public static function all(): array
    {
        return self::$profiles;
    }

    /**
     * Check if a profile is registered.
     */
    public static function has(string $id): bool
    {
        return isset(self::$profiles[$id]);
    }

    /**
     * Sync every discovered profile into the global artifact registry
     * (kernel_application_profile_registry, base/control DB).
     *
     * Uses the digest-conflict contract from ArtifactRegistry: identical
     * identity + digest is a no-op; a changed digest yields an explicit
     * CONFLICT (never a silent overwrite). This is a separate, explicit
     * operation from in-memory discovery — it is NOT called from the hot
     * discover() path.
     *
     * @return array{registered:int,idempotent:int,conflicts:array<int,string>}
     */
    public static function syncGlobalRegistry(?\PDO $db = null): array
    {
        $outcome = ['registered' => 0, 'idempotent' => 0, 'conflicts' => []];

        if (self::$profiles === []) {
            return $outcome;
        }

        if ($db === null) {
            if (!function_exists('app')) {
                return $outcome;
            }
            try {
                $db = \app()->controlDb();
            } catch (\Throwable $e) {
                return $outcome;
            }
        }

        foreach (self::$profiles as $provider) {
            $name = $provider->id();
            $version = $provider->version();
            $manifestPath = self::$manifestPaths[$name] ?? '';

            $manifest = [];
            if ($manifestPath !== '' && is_file($manifestPath)) {
                $decoded = json_decode((string)file_get_contents($manifestPath), true);
                if (is_array($decoded)) {
                    $manifest = $decoded;
                }
            }
            $digest = ArtifactRegistry::canonicalDigest($manifest);

            $result = ArtifactRegistry::register(
                $db,
                'kernel_application_profile_registry',
                'profile',
                $name,
                $version,
                $digest,
                $manifestPath
            );

            if (($result['status'] ?? '') === 'registered') {
                $outcome['registered']++;
            } elseif (($result['status'] ?? '') === 'idempotent') {
                $outcome['idempotent']++;
            } elseif (($result['status'] ?? '') === 'conflict') {
                $outcome['conflicts'][] = $name . '@' . $version;
            }
        }

        return $outcome;
    }

    /**
     * Clear the registry (primarily for testing).
     */
    public static function reset(): void
    {
        self::$profiles = [];
        self::$manifestPaths = [];
        self::$loaded = false;
    }

    /** @var array<string, string> Profile id => manifest path (for digest sync). */
    private static array $manifestPaths = [];
}