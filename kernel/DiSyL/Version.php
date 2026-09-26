<?php

/**
 * DiSyL engine version — the single authoritative declaration.
 *
 * Why 4.8.0: commit 8e9d5be8 ("DiSyL 4.8 typed {set} assignment + strict-mode
 * type validation") shipped the last v4.8 feature on top of the 4.7 baseline,
 * and docs/kernel/disyl-language-reference.md declares "Version: 4.8.0".
 *
 * This is the engine version and is a different axis from
 * Grammar::SCHEMA_VERSION (the grammar schema, which remains 4.0.0).
 *
 * scripts/generate-release-manifest.php reads the constant below. Do not
 * duplicate the value anywhere else.
 */

declare(strict_types=1);

namespace Ikabud\Kernel\DiSyL;

final class Version
{
    public const DISYL_VERSION = '4.8.0';
}
