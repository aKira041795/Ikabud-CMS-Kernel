<?php
/**
 * Header Template (Fallback)
 * 
 * This file exists only to satisfy WordPress's requirement for header.php.
 * Actual header rendering is done via DiSyL templates (disyl/components/header.disyl).
 */
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
