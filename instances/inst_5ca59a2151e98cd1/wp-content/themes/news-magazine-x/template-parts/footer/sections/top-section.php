<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Get Options
$enable = newsx_get_option('section_ft_top_enable');
$elements = newsx_get_option('section_ft_top_elements');

if ( !$enable || !is_array($elements) || empty($elements) ) {
	return;
}

// Render Builder Grid Row
newsx_hf_builder_grid_row_markup('footer', 'top', $elements);
