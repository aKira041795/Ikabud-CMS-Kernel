<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * Customization scope — identifies which context a set of settings applies to.
 *
 * @package Ikabud\Kernel\Contracts
 */
final class ThemeCustomizationScope
{
    public function __construct(
        public readonly string $themeSlug,
        public readonly ?string $tenantId = null,
        public readonly ?string $siteId = null,
        public readonly string $scopeType = 'theme', // 'theme', 'tenant', 'page'
        public readonly ?string $scopeId = null,
    ) {}

    /**
     * Create a scope from a compound string like "native_ark".
     */
    public static function fromString(string $scope, ?string $tenantId = null): self
    {
        // Parse "native_ark" → themeSlug="ark"
        $parts = explode('_', $scope, 2);
        $themeSlug = end($parts) ?: $scope;

        return new self(
            themeSlug: $themeSlug,
            tenantId: $tenantId,
            scopeType: 'theme',
        );
    }

    /**
     * Get the compound scope string used in the legacy CMS table.
     */
    public function toLegacyString(): string
    {
        return 'native_' . $this->themeSlug;
    }
}
