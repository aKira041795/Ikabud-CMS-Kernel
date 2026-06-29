<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Theme Customizer Provider — Kernel OS governed theme customization contract.
 *
 * Every theme that declares "customizer.owns: true" in its manifest MAY
 * provide a custom provider class. If none is provided, the
 * DeclarativeThemeCustomizerProvider loads settings from the theme's
 * declarative schema files (customizer.schema.json, tokens.json, slots.json).
 *
 * Kernel OS + DiSyL architecture rules:
 *   1. Provider MUST NOT query the database, check auth, or resolve tenants
 *   2. Provider MUST NOT generate HTML directly — return template paths instead
 *   3. Provider MUST return template paths relative to the theme directory
 *   4. Provider MUST be stateless (no constructor dependencies on DB/services)
 *   5. Provider SHOULD be purely declarative when possible
 *
 * @package Ikabud\Kernel\Contracts
 */
interface ThemeCustomizerProvider
{
    /**
     * The theme machine name (e.g., "ark").
     * Must match the theme directory slug.
     */
    public function slug(): string;

    /**
     * Return the theme's customizer definition.
     * This includes all sections, regions, tokens, and slots.
     * Most implementations delegate to ThemeDefinitionLoader.
     */
    public function definition(): ThemeCustomizerDefinition;

    /**
     * Validate a customizer submission before saving.
     * Returns a result with errors/warnings and optionally corrected values.
     *
     * @param ThemeCustomizationSubmission $submission The submitted settings
     * @return ThemeValidationResult Validation result with corrected values
     */
    public function validate(ThemeCustomizationSubmission $submission): ThemeValidationResult;

    /**
     * Transform the render context before passing to the template.
     * This is the ONLY place a provider may modify the context.
     * Default implementation is a no-op returning the context unchanged.
     *
     * @param ThemeRenderContext $context The pre-built immutable context
     * @return ThemeRenderContext A new context with any transformations applied
     */
    public function transformContext(ThemeRenderContext $context): ThemeRenderContext;

    /**
     * Return the DiSyL template path for a render region, relative to theme root.
     * Return null if the theme does not render this region (CMS will fall back).
     *
     * Example: 'templates/regions/header.disyl'
     *
     * @param string $region Region identifier (header, footer, sidebar, etc.)
     * @return string|null Template path or null
     */
    public function templateForRegion(string $region): ?string;
}
