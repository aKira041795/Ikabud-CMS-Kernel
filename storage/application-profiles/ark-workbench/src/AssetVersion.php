<?php

declare(strict_types=1);

namespace Ikabud\ApplicationProfiles\ArkWorkbench;

/**
 * Asset version helper — resolves asset URLs with cache-busting version strings.
 *
 * Uses file mtime as the version to ensure browsers always load the latest
 * version after a deployment.
 *
 * @package Ikabud\ApplicationProfiles\ArkWorkbench
 */
final class AssetVersion
{
    /** @var array<string, string> Cache of resolved paths to version strings */
    private static array $versionCache = [];

    /**
     * Resolve an asset path to a versioned URL.
     *
     * @param string $publicPath The public URL path (e.g., "/assets/workbench/workbench.css")
     * @param string|null $filePath Optional absolute filesystem path; if null, derived from publicPath
     * @return string Versioned URL (e.g., "/assets/workbench/workbench.css?v=1712345678")
     */
    public static function versioned(string $publicPath, ?string $filePath = null): string
    {
        if ($filePath === null) {
            // Derive filesystem path from public path
            $filePath = dirname(__DIR__, 3) . '/public' . $publicPath;
        }

        if (!isset(self::$versionCache[$publicPath])) {
            $version = '1';
            if (is_file($filePath)) {
                $mtime = filemtime($filePath);
                if ($mtime !== false) {
                    $version = (string)$mtime;
                }
            }
            self::$versionCache[$publicPath] = $version;
        }

        return $publicPath . '?v=' . self::$versionCache[$publicPath];
    }

    /**
     * Clear the internal version cache.
     */
    public static function reset(): void
    {
        self::$versionCache = [];
    }
}
