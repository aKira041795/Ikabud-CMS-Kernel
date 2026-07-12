<?php

declare(strict_types=1);

use Ikabud\Kernel\Services\ApplicationProfileResolver;

/**
 * ApplicationProfileResolverTest — verifies semver constraint handling.
 */
class ApplicationProfileResolverTest extends \PHPUnit\Framework\TestCase
{
    public function testCaretZeroOneAcceptsExactMinor(): void
    {
        $result = $this->resolveWithVersion('0.1.9', '^0.1');
        $this->assertNull($result['error'], "Expected ^0.1 to accept 0.1.9, got: {$result['error']}");
    }

    public function testCaretZeroOneRejectsDifferentMinor(): void
    {
        $result = $this->resolveWithVersion('0.2.0', '^0.1');
        $this->assertNotNull($result['error'], 'Expected ^0.1 to reject 0.2.0');
    }

    public function testCaretOneAcceptsMinorBump(): void
    {
        $result = $this->resolveWithVersion('1.9.0', '^1.2');
        $this->assertNull($result['error'], "Expected ^1.2 to accept 1.9.0, got: {$result['error']}");
    }

    public function testCaretOneRejectsMajorBump(): void
    {
        $result = $this->resolveWithVersion('2.0.0', '^1.2');
        $this->assertNotNull($result['error'], 'Expected ^1.2 to reject 2.0.0');
    }

    public function testGteRejectsLowerVersion(): void
    {
        $result = $this->resolveWithVersion('0.9.0', '>=1.0');
        $this->assertNotNull($result['error'], 'Expected >=1.0 to reject 0.9.0');
    }

    public function testGteAcceptsHigherVersion(): void
    {
        $result = $this->resolveWithVersion('1.5.0', '>=1.0');
        $this->assertNull($result['error'], "Expected >=1.0 to accept 1.5.0, got: {$result['error']}");
    }

    public function testExactVersionMatches(): void
    {
        $result = $this->resolveWithVersion('1.0.0', '1.0.0');
        $this->assertNull($result['error']);
    }

    public function testExactVersionRejectsMismatch(): void
    {
        $result = $this->resolveWithVersion('1.0.1', '1.0.0');
        $this->assertNotNull($result['error']);
    }

    /** @return array{profile: null, error: string|null} */
    private function resolveWithVersion(string $providerVersion, string $requiredVersion): array
    {
        $provider = new class($providerVersion) implements \Ikabud\Kernel\Contracts\ApplicationProfileProvider {
            public function __construct(private string $version) {}
            public function id(): string { return 'ark.workbench'; }
            public function version(): string { return $this->version; }
            public function componentNamespaces(): array { return []; }
            public function layouts(): array { return []; }
            public function assets(): array { return []; }
            public function designPolicy(): array { return []; }
        };

        \Ikabud\Kernel\Services\ApplicationProfileRegistry::reset();
        \Ikabud\Kernel\Services\ApplicationProfileRegistry::register($provider);

        return ApplicationProfileResolver::resolve([
            'application_profile' => [
                'id' => 'ark.workbench',
                'version' => $requiredVersion,
            ],
        ]);
    }
}
