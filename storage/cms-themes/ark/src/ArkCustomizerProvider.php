<?php

declare(strict_types=1);

namespace Ikabud\Themes\Ark;

use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;
use Ikabud\Kernel\Contracts\ThemeCustomizerDefinition;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\Contracts\ThemeCustomizationSubmission;
use Ikabud\Kernel\Contracts\ThemeValidationResult;
use Ikabud\Kernel\Services\ThemeDefinitionLoader;

/**
 * ARK Theme Customizer Provider — optional PHP provider for ARK.
 *
 * ARK is mostly declarative (customizer.schema.json covers the majority).
 * This provider exists for genuinely custom validation logic that cannot
 * be expressed in the schema.
 *
 * Architecture boundary:
 *   - NO database access
 *   - NO HTML generation (return template paths instead)
 *   - NO business logic
 *   - Pure validation and context transformation only
 *
 * @package Ikabud\Themes\Ark
 */
class ArkCustomizerProvider implements ThemeCustomizerProvider
{
    private string $slug = 'ark';
    private ?ThemeCustomizerDefinition $cachedDefinition = null;

    public function slug(): string
    {
        return $this->slug;
    }

    public function definition(): ThemeCustomizerDefinition
    {
        if ($this->cachedDefinition === null) {
            $themePath = defined('CMS_THEMES_PATH')
                ? rtrim(CMS_THEMES_PATH, '/') . '/ark'
                : (__DIR__ . '/../../storage/cms-themes/ark');

            $this->cachedDefinition = ThemeDefinitionLoader::load($this->slug, $themePath)
                ?? new ThemeCustomizerDefinition([], [], [], []);
        }
        return $this->cachedDefinition;
    }

    public function validate(ThemeCustomizationSubmission $submission): ThemeValidationResult
    {
        $definition = $this->definition();
        $section = $definition->section($submission->section);

        if ($section === null) {
            return new ThemeValidationResult(
                valid: false,
                correctedValues: $submission->values,
                messages: [['field' => '_section', 'message' => "Unknown section: {$submission->section}", 'type' => 'error']],
            );
        }

        $corrected = [];
        $messages = [];

        foreach ($section->controls as $ctrlId => $control) {
            $value = $submission->values[$ctrlId] ?? $control->default;
            $correctedValue = match ($control->type) {
                'boolean' => (int)(bool)$value,
                'number' => self::clamp((float)$value, $control->constraints['min'] ?? null, $control->constraints['max'] ?? null),
                'color' => self::validateColor($value, (string)$control->default),
                'select' => in_array((string)$value, $control->options, true) ? (string)$value : (string)$control->default,
                default => (string)$value,
            };
            $corrected[$ctrlId] = $correctedValue;
        }

        return new ThemeValidationResult(
            valid: true,
            correctedValues: $corrected,
            messages: $messages,
        );
    }

    public function transformContext(ThemeRenderContext $context): ThemeRenderContext
    {
        // ARK applies color token transformations from settings
        $tokens = $context->tokens;
        $colors = $context->settingsFor('colors');

        foreach ($tokens as $key => $tokenDef) {
            $colorKey = str_replace(['--color-', '-'], ['', '_'], $key);
            if (isset($colors[$colorKey])) {
                $tokens[$key]['default'] = $colors[$colorKey];
            }
        }

        return new ThemeRenderContext(
            theme: $context->theme,
            scope: $context->scope,
            settings: $context->settings,
            tokens: $tokens,
            site: $context->site,
            navigation: $context->navigation,
            entityContext: $context->entityContext,
            slotContributions: $context->slotContributions,
        );
    }

    public function templateForRegion(string $region): ?string
    {
        return match ($region) {
            'header' => 'templates/regions/header.disyl',
            'footer' => 'templates/regions/footer.disyl',
            'sidebar' => 'templates/regions/sidebar.disyl',
            default => null,
        };
    }

    private static function clamp(float $value, ?float $min, ?float $max): float|int
    {
        if ($min !== null && $value < $min) $value = $min;
        if ($max !== null && $value > $max) $value = $max;
        return $value;
    }

    private static function validateColor(mixed $value, string $default): string
    {
        $str = (string)$value;
        if ($str === '' || preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $str)) {
            return $str;
        }
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(/', $str)) {
            return $str;
        }
        return $default;
    }
}
