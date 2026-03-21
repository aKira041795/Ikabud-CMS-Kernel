<?php
/**
 * CMS Builder Animation & Effects Definitions
 * 
 * Centralized definitions for entrance animations, hover animations, and timing.
 * Used by PHP renderers, frontend scripts, and builder UI.
 */

/**
 * Get all available entrance animations
 * @return array{name: string, label: string, icon?: string}[]
 */
function cmsBuilderEntranceAnimations(): array
{
    return [
        // Fade
        'fadeIn' => [
            'label' => 'Fade In',
            'category' => 'fade',
            'css' => '[data-animate="fadeIn"]{ opacity: 0; } [data-animate="fadeIn"].cms-animated{ opacity: 1; }',
        ],
        'fadeInUp' => [
            'label' => 'Fade In Up',
            'category' => 'fade',
            'css' => '[data-animate="fadeInUp"]{ opacity: 0; transform: translateY(30px); } [data-animate="fadeInUp"].cms-animated{ opacity: 1; transform: none; }',
        ],
        'fadeInDown' => [
            'label' => 'Fade In Down',
            'category' => 'fade',
            'css' => '[data-animate="fadeInDown"]{ opacity: 0; transform: translateY(-30px); } [data-animate="fadeInDown"].cms-animated{ opacity: 1; transform: none; }',
        ],
        'fadeInLeft' => [
            'label' => 'Fade In Left',
            'category' => 'fade',
            'css' => '[data-animate="fadeInLeft"]{ opacity: 0; transform: translateX(-30px); } [data-animate="fadeInLeft"].cms-animated{ opacity: 1; transform: none; }',
        ],
        'fadeInRight' => [
            'label' => 'Fade In Right',
            'category' => 'fade',
            'css' => '[data-animate="fadeInRight"]{ opacity: 0; transform: translateX(30px); } [data-animate="fadeInRight"].cms-animated{ opacity: 1; transform: none; }',
        ],
        // Zoom
        'zoomIn' => [
            'label' => 'Zoom In',
            'category' => 'zoom',
            'css' => '[data-animate="zoomIn"]{ opacity: 0; transform: scale(0.85); } [data-animate="zoomIn"].cms-animated{ opacity: 1; transform: scale(1); }',
        ],
        // Slide
        'slideInUp' => [
            'label' => 'Slide In Up',
            'category' => 'slide',
            'css' => '[data-animate="slideInUp"]{ opacity: 0; transform: translateY(60px); } [data-animate="slideInUp"].cms-animated{ opacity: 1; transform: translateY(0); }',
        ],
        'slideInDown' => [
            'label' => 'Slide In Down',
            'category' => 'slide',
            'css' => '[data-animate="slideInDown"]{ opacity: 0; transform: translateY(-60px); } [data-animate="slideInDown"].cms-animated{ opacity: 1; transform: translateY(0); }',
        ],
        'slideInLeft' => [
            'label' => 'Slide In Left',
            'category' => 'slide',
            'css' => '[data-animate="slideInLeft"]{ opacity: 0; transform: translateX(-60px); } [data-animate="slideInLeft"].cms-animated{ opacity: 1; transform: translateX(0); }',
        ],
        'slideInRight' => [
            'label' => 'Slide In Right',
            'category' => 'slide',
            'css' => '[data-animate="slideInRight"]{ opacity: 0; transform: translateX(60px); } [data-animate="slideInRight"].cms-animated{ opacity: 1; transform: translateX(0); }',
        ],
        // Bounce
        'bounceIn' => [
            'label' => 'Bounce In',
            'category' => 'bounce',
            'css' => '[data-animate="bounceIn"]{ opacity: 0; transform: scale(0.7); animation: cms-bounce-in 0.6s cubic-bezier(0.68, -0.55, 0.265, 1.55); } [data-animate="bounceIn"].cms-animated{ opacity: 1; } @keyframes cms-bounce-in { 0%{ transform: scale(0.7); } 50%{ transform: scale(1.05); } 100%{ transform: scale(1); } }',
        ],
        // Flip
        'flipInX' => [
            'label' => 'Flip In X',
            'category' => 'flip',
            'css' => '[data-animate="flipInX"]{ opacity: 0; transform: perspective(400px) rotateX(90deg); } [data-animate="flipInX"].cms-animated{ opacity: 1; transform: perspective(400px) rotateX(0); }',
        ],
        'flipInY' => [
            'label' => 'Flip In Y',
            'category' => 'flip',
            'css' => '[data-animate="flipInY"]{ opacity: 0; transform: perspective(400px) rotateY(90deg); } [data-animate="flipInY"].cms-animated{ opacity: 1; transform: perspective(400px) rotateY(0); }',
        ],
        // Rotate
        'rotateIn' => [
            'label' => 'Rotate In',
            'category' => 'rotate',
            'css' => '[data-animate="rotateIn"]{ opacity: 0; transform: rotate(-120deg); } [data-animate="rotateIn"].cms-animated{ opacity: 1; transform: rotate(0); }',
        ],
    ];
}

/**
 * Get all available hover animations
 * @return array{label: string, icon?: string}[]
 */
function cmsBuilderHoverAnimations(): array
{
    return [
        'grow' => [
            'label' => 'Grow',
            'category' => 'scale',
            'css' => '[data-hover-animate="grow"]{ transition: transform 0.3s ease; } [data-hover-animate="grow"]:hover{ transform: scale(1.05); }',
        ],
        'shrink' => [
            'label' => 'Shrink',
            'category' => 'scale',
            'css' => '[data-hover-animate="shrink"]{ transition: transform 0.3s ease; } [data-hover-animate="shrink"]:hover{ transform: scale(0.95); }',
        ],
        'lift' => [
            'label' => 'Lift',
            'category' => 'motion',
            'css' => '[data-hover-animate="lift"]{ transition: transform 0.3s ease, box-shadow 0.3s ease; } [data-hover-animate="lift"]:hover{ transform: translateY(-8px); box-shadow: 0 15px 35px rgba(0,0,0,0.2); }',
        ],
        'float' => [
            'label' => 'Float',
            'category' => 'motion',
            'css' => '[data-hover-animate="float"]{ transition: transform 0.3s ease; } [data-hover-animate="float"]:hover{ transform: translateY(-5px); }',
        ],
        'pulse' => [
            'label' => 'Pulse',
            'category' => 'scale',
            'css' => '[data-hover-animate="pulse"]{ transition: transform 0.15s ease; } [data-hover-animate="pulse"]:hover{ animation: cms-pulse-animation 0.4s ease; } @keyframes cms-pulse-animation { 0%{ transform: scale(1); } 25%{ transform: scale(1.08); } 50%{ transform: scale(0.95); } 75%{ transform: scale(1.02); } 100%{ transform: scale(1); } }',
        ],
        'bob' => [
            'label' => 'Bob',
            'category' => 'motion',
            'css' => '[data-hover-animate="bob"]{ transition: transform 0.15s ease; } [data-hover-animate="bob"]:hover{ animation: cms-bob-animation 0.5s ease-in-out infinite; } @keyframes cms-bob-animation { 0%, 100%{ transform: translateY(0); } 50%{ transform: translateY(-6px); } }',
        ],
        'shake' => [
            'label' => 'Shake',
            'category' => 'motion',
            'css' => '[data-hover-animate="shake"]{ transition: transform 0.15s ease; } [data-hover-animate="shake"]:hover{ animation: cms-shake-animation 0.5s ease-in-out; } @keyframes cms-shake-animation { 0%, 100%{ transform: translateX(0); } 10%, 30%, 50%, 70%, 90%{ transform: translateX(-4px); } 20%, 40%, 60%, 80%{ transform: translateX(4px); } }',
        ],
        'glow' => [
            'label' => 'Glow',
            'category' => 'shadow',
            'css' => '[data-hover-animate="glow"]{ transition: box-shadow 0.3s ease, filter 0.3s ease; } [data-hover-animate="glow"]:hover{ box-shadow: 0 0 20px rgba(255,255,255,0.5), 0 0 40px rgba(100,100,255,0.3); filter: brightness(1.1); }',
        ],
        'shadow' => [
            'label' => 'Shadow',
            'category' => 'shadow',
            'css' => '[data-hover-animate="shadow"]{ transition: box-shadow 0.3s ease; } [data-hover-animate="shadow"]:hover{ box-shadow: 0 10px 30px rgba(0,0,0,0.15); }',
        ],
        'shadowGrow' => [
            'label' => 'Shadow Grow',
            'category' => 'shadow',
            'css' => '[data-hover-animate="shadowGrow"]{ transition: transform 0.3s ease, box-shadow 0.3s ease; } [data-hover-animate="shadowGrow"]:hover{ transform: scale(1.05); box-shadow: 0 15px 40px rgba(0,0,0,0.25); }',
        ],
    ];
}

/**
 * Get entrance animation label
 * @param string $key Animation key
 * @return string Animation label for UI
 */
function cmsBuilderGetEntranceAnimationLabel(string $key): string
{
    $animations = cmsBuilderEntranceAnimations();
    return $animations[$key]['label'] ?? ucfirst(str_replace('In', ' In ', $key));
}

/**
 * Get hover animation label
 * @param string $key Animation key
 * @return string Animation label for UI
 */
function cmsBuilderGetHoverAnimationLabel(string $key): string
{
    $animations = cmsBuilderHoverAnimations();
    return $animations[$key]['label'] ?? ucfirst($key);
}

/**
 * Generate CSS for all entrance animations
 * @return string CSS rules
 */
function cmsBuilderGetEntranceAnimationsCss(): string
{
    $css = '/* Entrance Animations */';
    $css .= "\n" . '[data-animate]{' . "\n";
    $css .= '  opacity: 0;' . "\n";
    $css .= '  transition: opacity var(--cms-animation-duration, 0.6s) ease, transform var(--cms-animation-duration, 0.6s) ease;' . "\n";
    $css .= '  transition-delay: var(--cms-animation-delay, 0s);' . "\n";
    $css .= '}' . "\n";
    $css .= '[data-animate].cms-animated{' . "\n";
    $css .= '  opacity: 1;' . "\n";
    $css .= '  transform: none;' . "\n";
    $css .= '}' . "\n\n";

    foreach (cmsBuilderEntranceAnimations() as $key => $def) {
        $css .= $def['css'] . "\n";
    }

    return $css;
}

/**
 * Generate CSS for all hover animations
 * @return string CSS rules
 */
function cmsBuilderGetHoverAnimationsCss(): string
{
    $css = '/* Hover Animations */' . "\n";
    foreach (cmsBuilderHoverAnimations() as $key => $def) {
        $css .= $def['css'] . "\n";
    }
    return $css;
}

/**
 * Render animation data attributes for a node
 * @param string $entranceAnimation Entrance animation key
 * @param string $duration Animation duration (e.g., '0.6s')
 * @param string $delay Animation delay (e.g., '0s')
 * @param string $hoverAnimation Hover animation key
 * @return array<string, string> Attributes to apply to node wrapper
 */
function cmsBuilderGetAnimationAttrs(
    string $entranceAnimation = '',
    string $duration = '',
    string $delay = '',
    string $hoverAnimation = ''
): array {
    $attrs = [];

    if ($entranceAnimation !== '' && array_key_exists($entranceAnimation, cmsBuilderEntranceAnimations())) {
        $attrs['data-animate'] = $entranceAnimation;
        
        if ($duration !== '') {
            $attrs['style'] = ($attrs['style'] ?? '') . "--cms-animation-duration:{$duration};";
        }
        if ($delay !== '') {
            $attrs['style'] = ($attrs['style'] ?? '') . "--cms-animation-delay:{$delay};";
        }
    }

    if ($hoverAnimation !== '' && array_key_exists($hoverAnimation, cmsBuilderHoverAnimations())) {
        $attrs['data-hover-animate'] = $hoverAnimation;
    }

    return $attrs;
}
