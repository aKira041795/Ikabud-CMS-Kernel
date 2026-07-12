<?php

declare(strict_types=1);

use Ikabud\Kernel\Services\ApplicationProfileValidator;

/**
 * ApplicationProfileValidatorTest — verifies manifest validation rules.
 */
class ApplicationProfileValidatorTest extends \PHPUnit\Framework\TestCase
{
    private ApplicationProfileValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ApplicationProfileValidator();
    }

    public function testValidManifestPasses(): void
    {
        $manifest = [
            'name'               => 'test-profile',
            'version'            => '0.1.0',
            'label'              => 'Test Profile',
            'supported_surfaces' => ['desktop', 'mobile'],
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function testMissingRequiredFieldsFail(): void
    {
        $manifest = [
            'name' => 'test-profile',
            // missing version, label, supported_surfaces
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertFalse($result['valid']);
        $this->assertNotEmpty($result['errors']);
    }

    public function testInvalidVersionFails(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => 'not-semver',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop'],
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertFalse($result['valid']);
    }

    public function testToneContractRejectsDomainStatuses(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => '0.1.0',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop'],
            'design_policy'      => [
                'tone_contract' => [
                    'approved' => ['allowed_families' => ['green']],  // domain status, not tone
                ],
            ],
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        // 'approved' is not a valid tone — should error
        $this->assertFalse($result['valid']);
    }

    public function testToneContractAcceptsValidTones(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => '0.1.0',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop'],
            'design_policy'      => [
                'tone_contract' => [
                    'success' => ['allowed_families' => ['green', 'teal']],
                    'danger'  => ['allowed_families' => ['red']],
                ],
            ],
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertTrue($result['valid']);
    }

    public function testWarnsOnUnknownSurface(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => '0.1.0',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop', 'unknown-surface'],
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertNotEmpty($result['warnings']);
    }

    public function testArkWorkbenchManifestPasses(): void
    {
        $root = dirname(__DIR__, 3)
            . '/storage/application-profiles/ark-workbench';

        $manifest = json_decode(
            file_get_contents($root . '/profile.manifest.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        $result = $this->validator->validate($manifest, $root);

        $this->assertTrue(
            $result['valid'],
            "ARK Workbench manifest failed validation:\n" . implode("\n", $result['errors'])
        );
    }

    public function testProviderMustBeObject(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => '0.1.0',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop'],
            'provider'           => 'SomeClass',  // string, not {class, file}
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertFalse($result['valid']);
    }

    public function testProviderMustHaveClassAndFile(): void
    {
        $manifest = [
            'name'               => 'test',
            'version'            => '0.1.0',
            'label'              => 'Test',
            'supported_surfaces' => ['desktop'],
            'provider'           => ['class' => 'Foo'],  // missing file
        ];

        $result = $this->validator->validate($manifest, '/tmp');

        $this->assertFalse($result['valid']);
    }
}
