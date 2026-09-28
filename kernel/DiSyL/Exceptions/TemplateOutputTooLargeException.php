<?php

/**
 * Port provenance: copied from /var/www/html/ikabudsix.
 * The ikabudsix and applicationostest lineages have no merge base; do not merge histories.
 */

/**
 * DiSyL Template Output Size Exception
 *
 * Thrown when a rendered template exceeds the configured output byte budget.
 *
 * This is deliberately a distinct type rather than a bare RuntimeException: the
 * compiled renderer must abort on it (it is a hard safety limit), while every
 * other render failure — an unwritable compiled cache, a read-only release
 * directory, a full disk — must fall back to the interpreted pipeline so a
 * caching problem can never break page rendering (including error pages).
 *
 * Extends RuntimeException so existing callers that catch RuntimeException keep
 * working unchanged.
 *
 * @version 0.1.0
 */

namespace Ikabud\Kernel\DiSyL\Exceptions;

class TemplateOutputTooLargeException extends \RuntimeException
{
}
