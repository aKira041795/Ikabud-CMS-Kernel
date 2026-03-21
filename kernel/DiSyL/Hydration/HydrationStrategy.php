<?php
/**
 * DiSyL v11.0 Hydration Strategy
 * 
 * @package Ikabud\Kernel\DiSyL\Hydration
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Hydration strategies for islands
 */
enum HydrationStrategy: string
{
    case LOAD = 'load';
    case IDLE = 'idle';
    case VISIBLE = 'visible';
    case MEDIA = 'media';
    case INTERACTION = 'interaction';
    case NEVER = 'never';
}
