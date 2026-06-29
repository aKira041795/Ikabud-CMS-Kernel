<?php

declare(strict_types=1);

namespace Ikabud\Kernel\Contracts;

/**
 * @deprecated Use ThemeCustomizerProvider instead.
 * This stub extends ThemeCustomizerProvider for backward compatibility
 * during the migration period.
 *
 * New themes MUST implement ThemeCustomizerProvider.
 * The methods slug(), definition(), validate(), transformContext(),
 * and templateForRegion() replace the old renderHeader/Footer/Sidebar
 * and supportedSections/sectionDefaults/validateSettings pattern.
 *
 * @see ThemeCustomizerProvider
 * @package Ikabud\Kernel\Contracts
 */
interface ThemeCustomizer extends ThemeCustomizerProvider
{
}
