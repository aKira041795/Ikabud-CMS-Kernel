<?php
/**
 * DiSyL v11.0 HTMX Headers
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * HTMX response headers
 */
class HTMXHeaders
{
    public const HX_TRIGGER = 'HX-Trigger';
    public const HX_TRIGGER_AFTER_SETTLE = 'HX-Trigger-After-Settle';
    public const HX_TRIGGER_AFTER_SWAP = 'HX-Trigger-After-Swap';
    public const HX_PUSH_URL = 'HX-Push-Url';
    public const HX_REDIRECT = 'HX-Redirect';
    public const HX_REFRESH = 'HX-Refresh';
    public const HX_REPLACE_URL = 'HX-Replace-Url';
    public const HX_RESWAP = 'HX-Reswap';
    public const HX_RETARGET = 'HX-Retarget';
    public const HX_RESELECT = 'HX-Reselect';
}
