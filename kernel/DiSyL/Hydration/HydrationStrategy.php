<?php
/**
 * DiSyL v11.0 Hydration Strategy
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Hydration strategies for islands.
 *
 * LOAD and IMMEDIATE both mean "hydrate on page load" — LOAD is the v11
 * canonical name, IMMEDIATE is the v1 alias kept for backward compatibility.
 */
enum HydrationStrategy: string
{
    case LOAD = 'load';
    case IMMEDIATE = 'immediate';
    case IDLE = 'idle';
    case VISIBLE = 'visible';
    case MEDIA = 'media';
    case INTERACTION = 'interaction';
    case NEVER = 'never';
}
