<?php

declare(strict_types=1);

/**
 * ApplicationProfileRegistryTest — verifies profile discovery and registration.
 */
class ApplicationProfileRegistryTest extends \PHPUnit\Framework\TestCase
{
    protected function setUp(): void
    {
        \Ikabud\Kernel\Services\ApplicationProfileRegistry::reset();
    }

    public function testRegistryIsEmptyByDefault(): void
    {
        $this->assertEmpty(\Ikabud\Kernel\Services\ApplicationProfileRegistry::all());
        $this->assertFalse(\Ikabud\Kernel\Services\ApplicationProfileRegistry::has('ark.workbench'));
    }

    public function testRegisterAndRetrieveProfile(): void
    {
        $provider = new \Ikabud\Themes\ArkWorkbench\ArkWorkbenchProvider();

        \Ikabud\Kernel\Services\ApplicationProfileRegistry::register($provider);

        $this->assertTrue(\Ikabud\Kernel\Services\ApplicationProfileRegistry::has('ark.workbench'));
        $this->assertSame($provider, \Ikabud\Kernel\Services\ApplicationProfileRegistry::get('ark.workbench'));
    }

    public function testGetNonExistentProfileReturnsNull(): void
    {
        $this->assertNull(\Ikabud\Kernel\Services\ApplicationProfileRegistry::get('nonexistent'));
    }
}
