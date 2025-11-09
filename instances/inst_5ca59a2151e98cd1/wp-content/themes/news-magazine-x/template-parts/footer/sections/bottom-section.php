<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
if (defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code()) {
    $enable = newsx_get_option('section_ft_bottom_enable');
    $elements = newsx_get_option('section_ft_bottom_elements');
} else {
    $enable = true;
    $elements = [
        [
            'ft_bottom_select_element' => 'copyright',
            'ft_bottom_element_position' => '1',
        ],
    ];
}

if ( !$enable || !is_array($elements) || empty($elements)) {
	return;
}

// Render Builder Grid Row
newsx_hf_builder_grid_row_markup('footer', 'bottom', $elements);
