<?php

declare(strict_types=1);

use Ikabud\Modules\ProjectAuditLedger\Presentation\PalStatusPresenter;
use Ikabud\Modules\ProjectAuditLedger\Presentation\StatusValue;

/**
 * PalStatusPresenterTest — verifies domain status → semantic tone mapping.
 */
class PalStatusPresenterTest extends \PHPUnit\Framework\TestCase
{
    private PalStatusPresenter $presenter;

    protected function setUp(): void
    {
        $this->presenter = new PalStatusPresenter();
    }

    public function testApprovedStatusMapsToSuccess(): void
    {
        $status = $this->presenter->resolve('approved');
        $this->assertSame('success', $status->tone);
        $this->assertSame('Approved', $status->label);
    }

    public function testPendingStatusMapsToWarning(): void
    {
        $status = $this->presenter->resolve('pending');
        $this->assertSame('warning', $status->tone);
    }

    public function testRejectedStatusMapsToDanger(): void
    {
        $status = $this->presenter->resolve('rejected');
        $this->assertSame('danger', $status->tone);
    }

    public function testDraftStatusMapsToNeutral(): void
    {
        $status = $this->presenter->resolve('draft');
        $this->assertSame('neutral', $status->tone);
    }

    public function testUnknownStatusFallsBackToNeutral(): void
    {
        $status = $this->presenter->resolve('nonexistent_status');
        $this->assertSame('neutral', $status->tone);
        $this->assertSame('nonexistent_status', $status->key);
    }

    public function testAllKnownStatusesReturnValidTones(): void
    {
        $validTones = ['neutral', 'informational', 'warning', 'success', 'danger'];
        $all = $this->presenter->all();

        $this->assertNotEmpty($all);
        foreach ($all as $status) {
            $this->assertContains($status->tone, $validTones);
            $this->assertNotEmpty($status->label);
            $this->assertNotEmpty($status->key);
        }
    }

    public function testStatusValueImplementsTemplateContextValue(): void
    {
        $status = $this->presenter->resolve('paid');
        $ctx = $status->toTemplateValue();

        $this->assertIsArray($ctx);
        $this->assertArrayHasKey('key', $ctx);
        $this->assertArrayHasKey('label', $ctx);
        $this->assertArrayHasKey('tone', $ctx);
        $this->assertArrayHasKey('description', $ctx);
    }

    public function testDoesNotExposeIsTerminal(): void
    {
        $status = $this->presenter->resolve('approved');
        $ctx = $status->toTemplateValue();

        // isTerminal belongs to workflow service, not visual presenter
        $this->assertArrayNotHasKey('is_terminal', $ctx);
        $this->assertArrayNotHasKey('isTerminal', $ctx);
    }
}
