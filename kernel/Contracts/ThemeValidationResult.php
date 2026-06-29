<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Result of validating a customizer submission.
 *
 * Contains corrected values and any validation messages.
 * The CMS uses this to determine whether to save and what to show the user.
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ThemeValidationResult
{
    /**
     * @param bool $valid Whether the submission is valid
     * @param array<string, mixed> $correctedValues Sanitized/corrected values
     * @param array<array{field: string, message: string, type: 'error'|'warning'}> $messages Validation messages
     */
    public function __construct(
        public readonly bool $valid,
        public readonly array $correctedValues,
        public readonly array $messages = [],
    ) {}
}
