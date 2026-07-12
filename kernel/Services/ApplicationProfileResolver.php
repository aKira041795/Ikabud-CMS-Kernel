<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ApplicationProfileProvider;

/**
 * ApplicationProfileResolver — resolves the active application profile for a module.
 *
 * Resolution precedence:
 *   1. Module-required profile (declared in module.json `application_profile.id`)
 *   2. Tenant-selected compatible profile
 *   3. Module default profile
 *   4. Kernel fallback profile
 *
 * If a module declares a required profile and no compatible profile is available,
 * resolution fails with a diagnostic. Silent fallback is not permitted for
 * operational modules.
 *
 * @package Ikabud\Kernel\Services
 */
class ApplicationProfileResolver
{
    /**
     * Resolve the active profile for a module.
     *
     * @param array{application_profile?: array{id: string, version: string, required_components?: array<string,string>}} $moduleManifest
     * @param string|null $tenantProfileId Tenant-selected profile ID (optional)
     * @return array{profile: ApplicationProfileProvider|null, error: string|null}
     */
    public static function resolve(array $moduleManifest, ?string $tenantProfileId = null): array
    {
        $declared = $moduleManifest['application_profile'] ?? null;

        // Module declares no profile — no resolution needed
        if ($declared === null) {
            return ['profile' => null, 'error' => null];
        }

        $requiredId = $declared['id'] ?? null;
        $requiredVersion = $declared['version'] ?? null;

        if ($requiredId === null) {
            return ['profile' => null, 'error' => 'Module declares application_profile without id'];
        }

        // 1. Tenant-selected profile (must be compatible)
        if ($tenantProfileId !== null) {
            $tenantProfile = ApplicationProfileRegistry::get($tenantProfileId);
            if ($tenantProfile !== null) {
                if (self::isCompatible($tenantProfile, $requiredId, $requiredVersion)) {
                    return ['profile' => $tenantProfile, 'error' => null];
                }
                return [
                    'profile' => null,
                    'error' => "Tenant-selected profile '{$tenantProfileId}' is not compatible with required '{$requiredId}@{$requiredVersion}'",
                ];
            }
        }

        // 2. Module-required profile
        $profile = ApplicationProfileRegistry::get($requiredId);
        if ($profile !== null) {
            if (self::isCompatible($profile, $requiredId, $requiredVersion)) {
                return ['profile' => $profile, 'error' => null];
            }
            return [
                'profile' => null,
                'error' => "Profile '{$requiredId}' version {$profile->version()} does not satisfy requirement {$requiredVersion}",
            ];
        }

        // 3. Profile not found
        return [
            'profile' => null,
            'error' => "Required application profile '{$requiredId}' is not registered. Module activation cannot proceed.",
        ];
    }

    /**
     * Check if a profile satisfies a version constraint.
     * Simple semver comparison: "^0.1", "^1.0", ">=1.0"
     */
    private static function isCompatible(ApplicationProfileProvider $profile, string $requiredId, ?string $requiredVersion): bool
    {
        if ($profile->id() !== $requiredId) {
            return false;
        }

        if ($requiredVersion === null) {
            return true;
        }

        $actual = $profile->version();

        // Caret constraint: ^X.Y means >=X.Y.0 and <(X+1).0.0
        if (str_starts_with($requiredVersion, '^')) {
            $min = substr($requiredVersion, 1);
            $parts = explode('.', $min);
            $major = (int)($parts[0] ?? 0);

            // Simple: actual major must match, actual >= required
            $actualParts = explode('.', $actual);
            $actualMajor = (int)($actualParts[0] ?? 0);

            if ($actualMajor !== $major) {
                return false;
            }

            return version_compare($actual, $min, '>=');
        }

        return version_compare($actual, $requiredVersion, '>=');
    }
}