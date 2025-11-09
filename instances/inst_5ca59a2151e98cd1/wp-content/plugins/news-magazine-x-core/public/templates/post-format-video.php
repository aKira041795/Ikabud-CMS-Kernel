<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

echo wp_kses_post( get_field('newsx_post_video_url'));
