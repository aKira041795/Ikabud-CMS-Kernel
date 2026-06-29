<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Services;

use Ikabud\Kernel\Contracts\ThemeCustomizerProvider;
use Ikabud\Kernel\Contracts\ThemeCustomizerDefinition;
use Ikabud\Kernel\Contracts\ThemeCustomizationScope;
use Ikabud\Kernel\Contracts\ThemeRenderContext;
use Ikabud\Kernel\Contracts\ThemeCustomizationSubmission;
use Ikabud\Kernel\Contracts\ThemeValidationResult;

/**
 * Generic declarative theme customizer provider.
 *
 * Used when a theme declares customizer.owns: true but does not provide
 * a custom provider class. Reads all definitions from the theme's
 * declarative schema files.
 *
 * This is the DEFAULT provider for all theme-owned customizers.
 * It is safe for third-party and marketplace themes since it has
 * no PHP execution surface — all behavior is schema-driven.
 *
 * @package Ikabud\Kernel\Services
 */
class DeclarativeThemeCustomizerProvider implements ThemeCustomizerProvider
{
    private string $slug;
    private string $themePath;
    private ?ThemeCustomizerDefinition $cachedDefinition = null;

    public function __construct(string $slug, string $themePath)
    {
        $this->slug = $slug;
        $this->themePath = $themePath;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function definition(): ThemeCustomizerDefinition
    {
        if ($this->cachedDefinition === null) {
            $this->cachedDefinition = ThemeDefinitionLoader::load($this->slug, $this->themePath)
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

            $correctedValue = self::validateControl($value, $control, $ctrlId, $messages);
            $corrected[$ctrlId] = $correctedValue;
        }

        return new ThemeValidationResult(
            valid: empty(array_filter($messages, fn($m) => $m['type'] === 'error')),
            correctedValues: $corrected,
            messages: $messages,
        );
    }

    public function transformContext(ThemeRenderContext $context): ThemeRenderContext
    {
        // Default: no transformation needed for declarative themes
        return $context;
    }

    public function templateForRegion(string $region): ?string
    {
        return $this->definition()->templateForRegion($region);
    }

    /**
     * Validate a single control value based on its definition.
     */
    private static function validateControl(
        mixed $value,
        \Ikabud\Kernel\Contracts\ControlDefinition $control,
        string $ctrlId,
        array &$messages,
    ): mixed {
        return match ($control->type) {
            'boolean' => (int)(bool)$value,
            'number', 'integer' => self::validateNumber($value, $control, $ctrlId, $messages),
            'color' => self::validateColor($value, $control, $ctrlId, $messages),
            'select' => self::validateSelect($value, $control, $ctrlId, $messages),
            'textarea' => (string)$value,
            default => (string)$value,
        };
    }

    private static function validateNumber(mixed $value, $control, string $ctrlId, array &$messages): int|float
    {
        $num = (float)$value;
        $min = $control->constraints['min'] ?? null;
        $max = $control->constraints['max'] ?? null;

        if ($min !== null && $num < $min) {
            $messages[] = ['field' => $ctrlId, 'message' => "Minimum value is {$min}", 'type' => 'warning'];
            $num = (float)$min;
        }
        if ($max !== null && $num > $max) {
            $messages[] = ['field' => $ctrlId, 'message' => "Maximum value is {$max}", 'type' => 'warning'];
            $num = (float)$max;
        }

        return $control->type === 'integer' ? (int)$num : $num;
    }

    private static function validateColor(mixed $value, $control, string $ctrlId, array &$messages): string
    {
        $str = (string)$value;
        if ($str === '' || preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/', $str)) {
            return $str;
        }
        // Named CSS colors and rgb/rgba/hsl
        if (preg_match('/^(rgb|rgba|hsl|hsla)\(/', $str)) {
            return $str;
        }
        $messages[] = ['field' => $ctrlId, 'message' => "Invalid color value", 'type' => 'warning'];
        return (string)($control->default ?? '#000000');
    }

    private static function validateSelect(mixed $value, $control, string $ctrlId, array &$messages): string
    {
        $str = (string)$value;
        $valid = $control->options;
        if (in_array($str, $valid, true)) {
            return $str;
        }
        $messages[] = ['field' => $ctrlId, 'message' => "Invalid option, using default", 'type' => 'warning'];
        return (string)($control->default ?? ($valid[0] ?? ''));
    }
}
