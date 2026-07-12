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
     *
     * Supported constraints:
     *   ^0.1  → >=0.1.0 and <0.2.0  (zero-major: minor must match)
     *   ^0.2  → >=0.2.0 and <0.3.0
     *   ^1.0  → >=1.0.0 and <2.0.0
     *   ^1.2  → >=1.2.0 and <2.0.0
     *   >=1.0  → >=1.0.0
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
        $actualParts = explode('.', $actual);
        $actualMajor = (int)($actualParts[0] ?? 0);
        $actualMinor = (int)($actualParts[1] ?? 0);

        // Caret constraint: ^X.Y
        if (str_starts_with($requiredVersion, '^')) {
            $min = substr($requiredVersion, 1);
            $parts = explode('.', $min);
            $reqMajor = (int)($parts[0] ?? 0);
            $reqMinor = (int)($parts[1] ?? 0);

            // Zero-major: minor must match exactly. ^0.1 accepts 0.1.x only.
            if ($reqMajor === 0) {
                if ($actualMajor !== 0 || $actualMinor !== $reqMinor) {
                    return false;
                }
                return version_compare($actual, $min, '>=');
            }

            // Stable major: >= req and < (reqMajor+1).0.0
            if ($actualMajor !== $reqMajor) {
                return false;
            }

            return version_compare($actual, $min, '>=');
        }

        // Plain minimum: >=X.Y.Z
        if (str_starts_with($requiredVersion, '>=')) {
            $minimum = trim(substr($requiredVersion, 2));
            return version_compare($actual, $minimum, '>=');
        }

        // Exact version
        if (preg_match('/^\d+\.\d+\.\d+$/', $requiredVersion)) {
            return version_compare($actual, $requiredVersion, '==');
        }

        // Unrecognized constraint — fail safe
        return false;
    }
}