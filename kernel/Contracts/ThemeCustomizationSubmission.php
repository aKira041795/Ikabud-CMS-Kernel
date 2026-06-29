<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * A settings submission from the admin customizer form.
 *
 * Represents user-submitted values before validation.
 * The provider validates these and returns a ThemeValidationResult
 * with corrected values and any errors/warnings.
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ThemeCustomizationSubmission
{
    /**
     * @param string $section Section identifier
     * @param array<string, mixed> $values Raw submitted key-value pairs
     * @param ThemeCustomizationScope $scope Customization scope
     * @param string|null $submittedBy User identifier who submitted
     */
    public function __construct(
        public readonly string $section,
        public readonly array $values,
        public readonly ThemeCustomizationScope $scope,
        public readonly ?string $submittedBy = null,
    ) {}
}
