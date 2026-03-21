<?php
/**
 * DiSyL v11.0 HTMX Swap Strategy
 * 
 * @package Ikabud\Kernel\DiSyL\Reactive
 * @version 11.0.0
 */

namespace Ikabud\Kernel\DiSyL\Reactive;

/**
 * HTMX swap strategies
 */
enum SwapStrategy: string
{
    case INNER_HTML = 'innerHTML';
    case OUTER_HTML = 'outerHTML';
    case BEFORE_BEGIN = 'beforebegin';
    case AFTER_BEGIN = 'afterbegin';
    case BEFORE_END = 'beforeend';
    case AFTER_END = 'afterend';
    case DELETE = 'delete';
    case NONE = 'none';
}
