<?php

declare(strict_types=1);

use Ikabud\Modules\ProjectAuditLedger\Presentation\PalMoneyPresenter;
use Ikabud\Modules\ProjectAuditLedger\Presentation\MoneyValue;

/**
 * PalMoneyPresenterTest — verifies safe DECIMAL string → integer minor units conversion.
 */
class PalMoneyPresenterTest extends \PHPUnit\Framework\TestCase
{
    private PalMoneyPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new PalMoneyPresenter('₱', 2);
    }

    public function testConvertsDecimalStringToMinorUnits(): void
    {
        $money = $this->presenter->fromDecimalString('1234.56', 'PHP');

        $this->assertSame(123456, $money->minorUnits);
        $this->assertSame('PHP', $money->currency);
        $this->assertFalse($money->isNegative);
    }

    public function testConvertsWholeNumber(): void
    {
        $money = $this->presenter->fromDecimalString('100', 'PHP');

        $this->assertSame(10000, $money->minorUnits);
    }

    public function testConvertsNegativeAmount(): void
    {
        $money = $this->presenter->fromDecimalString('-50.25', 'PHP');

        $this->assertSame(-5025, $money->minorUnits);
        $this->assertTrue($money->isNegative);
    }

    public function testConvertsZero(): void
    {
        $money = $this->presenter->fromDecimalString('0.00', 'PHP');

        $this->assertSame(0, $money->minorUnits);
        $this->assertFalse($money->isNegative);
    }

    public function testFormatsPhilippinePeso(): void
    {
        $money = $this->presenter->fromMinorUnits(123456, 'PHP');

        $this->assertStringContainsString('₱', $money->formatted);
        $this->assertStringContainsString('1,234', $money->formatted);
    }

    public function testFormatsNegativePhilippinePeso(): void
    {
        $money = $this->presenter->fromMinorUnits(-5025, 'PHP');

        $this->assertStringStartsWith('-', $money->formatted);
        $this->assertStringContainsString('₱', $money->formatted);
    }

    public function testMoneyValueImplementsTemplateContextValue(): void
    {
        $money = $this->presenter->fromMinorUnits(123456, 'PHP');
        $ctx = $money->toTemplateValue();

        $this->assertIsArray($ctx);
        $this->assertArrayHasKey('minor_units', $ctx);
        $this->assertArrayHasKey('currency', $ctx);
        $this->assertArrayHasKey('formatted', $ctx);
        $this->assertArrayHasKey('is_negative', $ctx);
    }

    public function testRoundingWithExtraDecimals(): void
    {
        // Database might return '99.999' — should truncate to 2 decimal places
        $money = $this->presenter->fromDecimalString('99.999', 'PHP');

        $this->assertSame(9999, $money->minorUnits);
    }
}
