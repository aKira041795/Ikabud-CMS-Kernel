<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;
use Ikabud\Kernel\Contracts\ThemeCustomizerDefinition;
use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\Contracts\ThemeCustomizationSubmission;
use Ikabud\Kernel\Contracts\ThemeValidationResult;
use Ikabud\Kernel\Contracts\SectionDefinition;
use Ikabud\Kernel\Contracts\ControlDefinition;

/**
 * Adapter that wraps the legacy CMS generic customizer into the
 * ThemeCustomizerProvider contract.
 *
 * This ensures one unified orchestration pipeline for ALL themes:
 * - Theme-owned customizer → declarative or custom provider
 * - Legacy theme → LegacyCmsCustomizerAdapter
 *
 * The adapter reads the old CMS customizer functions and exposes
 * them through the new interface. This is a compatibility layer —
 * eventually legacy themes should migrate to the new system.
 *
 * @package Ikabud\Kernel\Services
 */
class LegacyCmsCustomizerAdapter implements ThemeCustomizerProvider
{
    private string $slug;

    public function __construct(string $slug)
    {
        $this->slug = $slug;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function definition(): ThemeCustomizerDefinition
    {
        // Build a definition from the legacy CMS customizer functions.
        // The legacy system doesn't have a schema, so we define standard
        // sections with minimal metadata.

        $sections = [];

        // Standard sections always available in the CMS customizer
        $standardSections = [
            'header' => 'Header Settings',
            'footer' => 'Footer Settings',
            'sidebar' => 'Sidebar Settings',
            'colors' => 'Colors',
            'theme' => 'Theme Layout',
        ];

        foreach ($standardSections as $id => $label) {
            $defaults = $this->legacyDefaults($id);
            $controls = [];
            foreach ($defaults as $ctrlId => $defaultValue) {
                $type = match (true) {
                    is_bool($defaultValue) || in_array($ctrlId, ['enabled', 'sticky', 'show_*'], true) => 'boolean',
                    is_int($defaultValue) => 'number',
                    str_starts_with((string)$defaultValue, '#') => 'color',
                    default => 'text',
                };
                $controls[$ctrlId] = new ControlDefinition(
                    id: $ctrlId,
                    label: ucfirst(str_replace('_', ' ', $ctrlId)),
                    type: $type,
                    default: $defaultValue,
                );
            }

            $sections[$id] = new SectionDefinition(
                id: $id,
                label: $label,
                controls: $controls,
                isRegion: in_array($id, ['header', 'footer', 'sidebar'], true),
                defaults: $defaults,
            );
        }

        $regions = [
            'header' => 'regions/header.disyl',
            'footer' => 'regions/footer.disyl',
            'sidebar' => 'regions/sidebar.disyl',
        ];

        return new ThemeCustomizerDefinition(
            sections: $sections,
            regions: $regions,
            tokens: [],
            slots: [],
        );
    }

    public function validate(ThemeCustomizationSubmission $submission): ThemeValidationResult
    {
        // Delegate to legacy CMS validation if available
        if (function_exists('cmsValidateCustomizerSectionSettings')) {
            $validated = cmsValidateCustomizerSectionSettings(
                $submission->section,
                $submission->values,
                $submission->scope->toLegacyString(),
            );
            return new ThemeValidationResult(
                valid: true,
                correctedValues: $validated,
            );
        }

        // Fallback: use DeclarativeThemeCustomizerProvider validation
        $declarative = new DeclarativeThemeCustomizerProvider($this->slug, '');
        return $declarative->validate($submission);
    }

    public function transformContext(ThemeRenderContext $context): ThemeRenderContext
    {
        return $context;
    }

    public function templateForRegion(string $region): ?string
    {
        // Legacy themes render regions via CMS functions, not templates.
        // Return null to signal the orchestrator to use the legacy render path.
        return null;
    }

    /**
     * Get legacy defaults for a section.
     */
    private function legacyDefaults(string $section): array
    {
        if (function_exists('cmsCustomizerSectionDefaults')) {
            $defaults = cmsCustomizerSectionDefaults($section, '');
            if (is_array($defaults)) {
                return $defaults;
            }
        }

        return match ($section) {
            'header' => [
                'layout' => 'default',
                'sticky' => 1,
                'bg_color' => '#ffffff',
                'text_color' => '#1f2937',
                'link_color' => '#1f2937',
            ],
            'footer' => [
                'columns' => 3,
                'bg_color' => '#1e293b',
                'text_color' => '#cbd5e1',
            ],
            'sidebar' => [
                'enabled' => 0,
                'placement' => 'right',
                'width' => '300',
            ],
            'colors' => [
                'color_primary' => '#3b82f6',
                'body_bg_color' => '#ffffff',
                'body_text_color' => '#1e293b',
            ],
            'theme' => [
                'container_width' => '1200',
                'font_body' => 'Inter',
            ],
            default => [],
        };
    }
}
