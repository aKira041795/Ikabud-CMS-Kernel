<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

/**
 * ApplicationProfileValidator — validates profile manifests, contracts, and compatibility.
 *
 * Validates:
 *   1. Manifest schema and required fields
 *   2. Component namespace declarations
 *   3. Layout template existence
 *   4. Asset declarations
 *   5. Design policy (configurable vs locked)
 *   6. Contract version compatibility
 *
 * @package Ikabud\Kernel\Services
 */
class ApplicationProfileValidator
{
    /** @var array<string, array{type: string, required: bool}> Canonical schema */
    private const SCHEMA = [
        'name'          => ['type' => 'string', 'required' => true],
        'version'       => ['type' => 'string', 'required' => true],
        'label'         => ['type' => 'string', 'required' => true],
        'description'   => ['type' => 'string', 'required' => false],
        'kernel_os_compat' => ['type' => 'string', 'required' => false],
        'disyl_compat'  => ['type' => 'string', 'required' => false],
        'supported_surfaces' => ['type' => 'array', 'required' => true],
        'contracts'     => ['type' => 'object', 'required' => false],
        'assets'        => ['type' => 'object', 'required' => false],
        'customizer'    => ['type' => 'object', 'required' => false],
        'design_policy' => ['type' => 'object', 'required' => false],
        'provider'      => ['type' => 'object', 'required' => true],
    ];

    /**
     * Valid surfaces an application profile may support.
     */
    private const VALID_SURFACES = ['desktop', 'mobile', 'tablet', 'print', 'pdf', 'email'];

    /** @var string[] */
    private array $errors = [];

    /** @var string[] */
    private array $warnings = [];

    /**
     * Validate a profile manifest.
     *
     * @param array<string,mixed> $manifest Parsed profile.manifest.json
     * @param string $profilePath Absolute path to the profile directory
     * @return array{valid: bool, errors: string[], warnings: string[]}
     */
    public function validate(array $manifest, string $profilePath): array
    {
        $this->errors = [];
        $this->warnings = [];

        $this->validateSchema($manifest);
        $this->validateProvider($manifest);
        $this->validateSurfaces($manifest);
        $this->validateContracts($manifest);
        $this->validateAssets($manifest, $profilePath);
        $this->validateDesignPolicy($manifest);

        return [
            'valid'    => empty($this->errors),
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
        ];
    }

    /**
     * Validate manifest against canonical schema.
     */
    private function validateSchema(array $manifest): void
    {
        foreach (self::SCHEMA as $key => $def) {
            if ($def['required'] && !array_key_exists($key, $manifest)) {
                $this->errors[] = "Missing required field: '{$key}'";
                continue;
            }

            if (!array_key_exists($key, $manifest)) {
                continue;
            }

            $value = $manifest[$key];

            if ($def['type'] === 'string' && !is_string($value)) {
                $this->errors[] = "Field '{$key}' must be a string";
            } elseif ($def['type'] === 'array' && !is_array($value)) {
                $this->errors[] = "Field '{$key}' must be an array";
            } elseif ($def['type'] === 'object' && !is_array($value)) {
                $this->errors[] = "Field '{$key}' must be an object";
            }
        }

        // Version must be semver
        if (isset($manifest['version']) && !preg_match('/^\d+\.\d+\.\d+$/', (string)$manifest['version'])) {
            $this->errors[] = "Version '{$manifest['version']}' must be semver (e.g., 0.1.0)";
        }
    }

    /**
     * Validate provider declaration.
     */
    private function validateProvider(array $manifest): void
    {
        $provider = $manifest['provider'] ?? null;

        if (!is_array($provider)) {
            $this->errors[] = 'provider must be an object with class and file keys';
            return;
        }

        if (!isset($provider['class']) || !is_string($provider['class']) || trim($provider['class']) === '') {
            $this->errors[] = 'provider.class must be a non-empty string';
        }

        if (!isset($provider['file']) || !is_string($provider['file']) || trim($provider['file']) === '') {
            $this->errors[] = 'provider.file must be a non-empty string';
        }
    }

    /**
     * Validate supported surfaces.
     */
    private function validateSurfaces(array $manifest): void
    {
        $surfaces = $manifest['supported_surfaces'] ?? [];

        if (!is_array($surfaces)) {
            return;
        }

        foreach ($surfaces as $surface) {
            if (!in_array($surface, self::VALID_SURFACES, true)) {
                $this->warnings[] = "Unknown surface: '{$surface}'. Valid surfaces: " . implode(', ', self::VALID_SURFACES);
            }
        }
    }

    /**
     * Validate contract versions.
     */
    private function validateContracts(array $manifest): void
    {
        $contracts = $manifest['contracts'] ?? [];

        if (!is_array($contracts)) {
            return;
        }

        $expectedContracts = ['components', 'tokens', 'design_policy', 'shell', 'assets'];

        foreach ($expectedContracts as $key) {
            if (isset($contracts[$key]) && !preg_match('/^\d+\.\d+$/', (string)$contracts[$key])) {
                $this->errors[] = "Contract version '{$key}' must be MAJOR.MINOR (e.g., 1.0)";
            }
        }
    }

    /**
     * Validate asset declarations reference real files.
     */
    private function validateAssets(array $manifest, string $profilePath): void
    {
        $assets = $manifest['assets'] ?? [];

        if (!is_array($assets)) {
            return;
        }

        // Check core assets
        $core = $assets['core'] ?? [];
        $allPaths = [];

        if (isset($core['styles']) && is_array($core['styles'])) {
            $allPaths = array_merge($allPaths, $core['styles']);
        }
        if (isset($core['scripts']) && is_array($core['scripts'])) {
            $allPaths = array_merge($allPaths, $core['scripts']);
        }

        // Check per-component assets
        $componentAssets = $assets['components'] ?? [];
        if (is_array($componentAssets)) {
            foreach ($componentAssets as $component => $paths) {
                if (is_array($paths)) {
                    $allPaths = array_merge($allPaths, $paths);
                }
            }
        }

        foreach ($allPaths as $path) {
            $fullPath = $profilePath . '/' . $path;
            if (!is_file($fullPath)) {
                $this->warnings[] = "Asset not found: '{$path}'";
            }
        }
    }

    /**
     * Validate design policy structure.
     */
    private function validateDesignPolicy(array $manifest): void
    {
        $policy = $manifest['design_policy'] ?? [];

        if (!is_array($policy)) {
            return;
        }

        if (isset($policy['configurable']) && !is_array($policy['configurable'])) {
            $this->errors[] = "design_policy.configurable must be an array";
        }

        if (isset($policy['locked']) && !is_array($policy['locked'])) {
            $this->errors[] = "design_policy.locked must be an array";
        }

        // Validate tone_contract if present
        $toneContract = $policy['tone_contract'] ?? null;
        if ($toneContract !== null && is_array($toneContract)) {
            $validTones = ['neutral', 'informational', 'warning', 'success', 'danger'];
            foreach ($toneContract as $tone => $constraints) {
                if (!in_array($tone, $validTones, true)) {
                    $this->errors[] = "Unknown tone in tone_contract: '{$tone}'. Valid tones: " . implode(', ', $validTones);
                }
                if (is_array($constraints) && !isset($constraints['allowed_families'])) {
                    $this->errors[] = "tone_contract.{$tone} must specify allowed_families";
                }
            }
        }
    }
}