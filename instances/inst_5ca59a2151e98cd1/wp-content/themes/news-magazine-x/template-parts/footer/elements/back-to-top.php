<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

$ft_backtop_enable = newsx_get_option( 'ft_backtop_enable' );
$ft_backtop_icon = newsx_get_option( 'ft_backtop_icon' );
$ft_backtop_transparent = defined('NEWSX_CORE_PRO_VERSION') && newsx_core_pro_fs()->can_use_premium_code() ? newsx_get_option( 'ft_backtop_transparent' ) : false;

if ( ! $ft_backtop_enable ) {
    return;
}

?>

<div tabindex="0" id="newsx-back-to-top" <?php echo 'class="'. esc_attr( $ft_backtop_transparent ? 'newsx-trans-bg' : '' ) .'"'; ?>>
    <?php

	echo newsx_customizer_edit_button_markup('ft_backtop');
    echo newsx_get_svg_icon( $ft_backtop_icon );
    
    ?>
</div>