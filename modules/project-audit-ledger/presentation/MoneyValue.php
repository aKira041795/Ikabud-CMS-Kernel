<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * MoneyValue — immutable money representation in integer minor units.
 *
 * Never uses float for authoritative money values.
 * Formatted string is produced by the presenter, not by this value object.
 */
final readonly class MoneyValue implements TemplateContextValue
{
    public function __construct(
        public int $minorUnits,      // 123456 (= ₱1,234.56)
        public string $currency,     // "PHP"
        public string $formatted,    // "₱1,234.56"
        public bool $isNegative,
    ) {}

    /** @return array<string,mixed> */
    public function toTemplateValue(): array
    {
        return [
            'minor_units' => $this->minorUnits,
            'currency'    => $this->currency,
            'formatted'   => $this->formatted,
            'is_negative' => $this->isNegative,
        ];
    }
}
