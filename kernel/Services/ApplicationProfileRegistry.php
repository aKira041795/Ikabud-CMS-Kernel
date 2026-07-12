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

        // Look for a provider class
        $providerClass = $manifest['provider'] ?? null;
        if ($providerClass && class_exists($providerClass)) {
            /** @var ApplicationProfileProvider $provider */
            $provider = new $providerClass();
            if ($provider instanceof ApplicationProfileProvider) {
                self::register($provider);
            }
        }
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
     * Clear the registry (primarily for testing).
     */
    public static function reset(): void
    {
        self::$profiles = [];
        self::$loaded = false;
    }
}