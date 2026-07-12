<?php

declare(strict_types=1);

namespace Ikabud\Modules\ProjectAuditLedger\Presentation;

/**
 * PalMoneyPresenter — safe money formatting from DECIMAL database columns.
 *
 * Converts database DECIMAL strings to integer minor units without floating-point.
 * Produces formatted display strings with correct currency symbol.
 */
final readonly class PalMoneyPresenter
{
    private string $currencySymbol;
    private int $decimalPlaces;

    public function __construct(
        string $currencySymbol = '₱',
        int $decimalPlaces = 2,
    ) {
        $this->currencySymbol = $currencySymbol;
        $this->decimalPlaces = $decimalPlaces;
    }

    /**
     * Create a MoneyValue from a database DECIMAL string.
     *
     * Example: fromDecimalString('1234.56', 'PHP') → MoneyValue(minorUnits: 123456, ...)
     */
    public function fromDecimalString(string $amount, string $currency): MoneyValue
    {
        $isNegative = str_starts_with($amount, '-');
        $clean = ltrim($amount, '-');
        $parts = explode('.', $clean);
        $whole = (int)$parts[0];
        $fraction = isset($parts[1])
            ? (int)str_pad(substr($parts[1], 0, $this->decimalPlaces), $this->decimalPlaces, '0')
            : 0;
        $minorUnits = ($whole * (10 ** $this->decimalPlaces)) + $fraction;

        if ($isNegative) {
            $minorUnits = -$minorUnits;
        }

        return new MoneyValue(
            minorUnits: $minorUnits,
            currency: $currency,
            formatted: $this->format($minorUnits),
            isNegative: $isNegative,
        );
    }

    /**
     * Create a MoneyValue from integer minor units directly.
     */
    public function fromMinorUnits(int $minorUnits, string $currency): MoneyValue
    {
        return new MoneyValue(
            minorUnits: $minorUnits,
            currency: $currency,
            formatted: $this->format($minorUnits),
            isNegative: $minorUnits < 0,
        );
    }

    /**
     * Format minor units to display string.
     */
    public function format(int $minorUnits): string
    {
        $isNegative = $minorUnits < 0;
        $abs = abs($minorUnits);
        $divisor = 10 ** $this->decimalPlaces;
        $whole = intdiv($abs, $divisor);
        $fraction = $abs % $divisor;

        $formatted = number_format(
            (float) ("{$whole}." . str_pad((string)$fraction, $this->decimalPlaces, '0', STR_PAD_LEFT)),
            $this->decimalPlaces,
            '.',
            ','
        );

        return ($isNegative ? '-' : '') . $this->currencySymbol . $formatted;
    }
}
