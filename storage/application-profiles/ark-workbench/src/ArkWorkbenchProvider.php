<?php

declare(strict_types=1);

namespace Ikabud\Themes\ArkWorkbench;

use Ikabud\Kernel\Contracts\ApplicationProfileProvider;

/**
 * ARK Workbench Provider — the reference application profile for operational modules.
 *
 * Implements ApplicationProfileProvider with declarative configuration loaded
 * from profile.manifest.json and companion files.
 *
 * @package Ikabud\Themes\ArkWorkbench
 */
final class ArkWorkbenchProvider implements ApplicationProfileProvider
{
    private string $profilePath;

    /** @var array<string,mixed>|null */
    private ?array $manifest = null;

    public function __construct()
    {
        $this->profilePath = dirname(__DIR__);
    }

    public function id(): string
    {
        return 'ark.workbench';
    }

    public function version(): string
    {
        return $this->getManifest()['version'] ?? '0.1.0';
    }

    public function componentNamespaces(): array
    {
        return [
            'workbench' => 'components/',
        ];
    }

    public function layouts(): array
    {
        return [
            'app-shell'        => 'layouts/app-shell.disyl',
            'app-shell-mobile' => 'layouts/app-shell-mobile.disyl',
        ];
    }

    public function assets(): array
    {
        return [
            'core' => [
                'styles'  => ['assets/workbench.css'],
                'scripts' => ['assets/workbench-core.js'],
            ],
        ];
    }

    public function designPolicy(): array
    {
        $policyPath = $this->profilePath . '/design-policy.json';

        if (is_file($policyPath)) {
            $policy = json_decode(file_get_contents($policyPath), true);
            if (is_array($policy)) {
                return $policy;
            }
        }

        return [
            'configurable' => [],
            'locked'       => [],
        ];
    }

    /**
     * Get the profile path (for template resolution).
     */
    public function profilePath(): string
    {
        return $this->profilePath;
    }

    /**
     * Load the manifest with caching.
     *
     * @return array<string,mixed>
     */
    private function getManifest(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $manifestPath = $this->profilePath . '/profile.manifest.json';

        if (is_file($manifestPath)) {
            $decoded = json_decode(file_get_contents($manifestPath), true);
            $this->manifest = is_array($decoded) ? $decoded : [];
        } else {
            $this->manifest = [];
        }

        return $this->manifest;
    }
}
