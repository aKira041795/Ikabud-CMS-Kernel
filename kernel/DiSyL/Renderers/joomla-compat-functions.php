<?php
/**
 * WordPress-compatible filter functions for Joomla
 * 
 * These functions provide WordPress filter compatibility in Joomla
 * They are defined in the global namespace so they can be used in eval()
 * 
 * @package     IkabudKernel
 * @subpackage  DiSyL
 * @version     0.5.0
 */

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url) {
        return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_trim_words')) {
    function wp_trim_words($text, $num_words = 55, $more = null) {
        if (null === $more) {
            $more = '&hellip;';
        }
        $text = strip_tags($text);
        $words = preg_split("/[\n\r\t ]+/", $text, $num_words + 1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > $num_words) {
            array_pop($words);
            $text = implode(' ', $words);
            $text = $text . $more;
        } else {
            $text = implode(' ', $words);
        }
        return $text;
    }
}
