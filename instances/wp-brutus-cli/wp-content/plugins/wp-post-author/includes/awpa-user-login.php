<?php
if (!function_exists('awpa_render_block_user_login')) {
    function awpa_render_block_user_login($attributes)
    {
        $blockuniqueclass = '';
        $unq_class = mt_rand(100000, 999999);
        if (!empty($attributes['uniqueClass'])) {
            $blockuniqueclass = $attributes['uniqueClass'] . ' ';
        } else {
            $blockuniqueclass = 'awpa-' . $unq_class . ' ';
        }
        ob_start(); ?>
        <div class=<?php echo esc_attr($blockuniqueclass); ?>>

            <div class='awpa-loginfrom-wrap'>
                <?php if (isset($attributes['enableBgImage']) && $attributes['enableBgImage'] && $attributes['imgURL']) { ?>
                    <div class="awpa-block-img" style="background-image: url(<?php echo esc_url($attributes['imgURL']) ?>);">
                    </div>
                <?php } ?>
                <h4><?php echo esc_html($attributes['formTitle']); ?></h4>
                <?php echo awpa_login_form_common($attributes);
                echo awpa_login_form_styles($blockuniqueclass, $attributes);
                ?>
            </div>
        </div>
    <?php

        return   ob_get_clean();
    }
}

if (!function_exists('awpa_register_block_user_login')) {
    function awpa_register_block_user_login()
    {
        if (!function_exists('register_block_type')) {
            return;
        }
        ob_start();
        include AWPA_PLUGIN_DIR . 'assets/user-login-block.json';
        $metadata = json_decode(ob_get_clean(), true);
        /* Block attributes */
        register_block_type(
            'awpa/user-login',
            array(
                'attributes' => $metadata['attributes'],
                'render_callback' => 'awpa_render_block_user_login',
            )
        );
    }
    add_action('init', 'awpa_register_block_user_login');
}
if (!function_exists('awpa_login_form_styles')) {
    function awpa_login_form_styles($blockuniqueclass, $attributes)
    {

        $block_content = '';
        $block_content .= '<style type="text/css">';

        //Title
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap h4{
                color:' . awpa_esc_custom_style($attributes['formTitleColor']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap{
            background-color: transparent;
            text-align:' . awpa_esc_custom_style($attributes['sectionAlignment']) . ';
            border-radius:' . awpa_esc_custom_style($attributes['borderRadius']) . "px" . ';
            padding-top:' . awpa_esc_custom_style($attributes['paddingTop']) . 'px;
            padding-right:' . awpa_esc_custom_style($attributes['paddingRight']) . 'px;
            padding-bottom:' . awpa_esc_custom_style($attributes['paddingBottom']) . 'px;
            padding-left:' . awpa_esc_custom_style($attributes['paddingLeft']) . 'px;
            margin-top:' . awpa_esc_custom_style($attributes['marginTop']) . 'px;
            margin-right:' . awpa_esc_custom_style($attributes['marginRight']) . 'px;
            margin-bottom:' . awpa_esc_custom_style($attributes['marginBottom']) . 'px;
            margin-left:' . awpa_esc_custom_style($attributes['marginLeft']) . 'px;
        }';

        if (isset($attributes['formBgColor'])) {
            $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap{
                background-color:' . awpa_esc_custom_style($attributes['formBgColor']) . ';
            }';
        }

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap label{
            color:' . awpa_esc_custom_style($attributes['labelColor']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap .login-forget-password a{
            color:' . awpa_esc_custom_style($attributes['labelColor']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap .input{
            border-radius:' . awpa_esc_custom_style($attributes['inputBorderRadius']) . "px" . ';
        }';
        
        //Image Background opacity

        if (isset($attributes['enableBgImage'])) {
            $block_content .= ' .' . $blockuniqueclass . '  .awpa-loginfrom-wrap .awpa-block-img{
                opacity: ' . awpa_esc_custom_style($attributes['imageBackgroundOpacity']) . ';
            }';
        }

        //Input Shadow
        if ($attributes['inputBoxShadow']) {
            $block_content .= ' .' . $blockuniqueclass . '  .awpa-loginfrom-wrap .input{
                box-shadow: ' . awpa_esc_custom_style($attributes['inputxOffset']) . 'px ' . awpa_esc_custom_style($attributes['inputyOffset']) . 'px ' . awpa_esc_custom_style($attributes['inputblur']) . 'px ' . awpa_esc_custom_style($attributes['inputspread']) . 'px ' . awpa_esc_custom_style($attributes['inputshadowColor']) . ';   
            }';
        }

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap form input[type="submit"].button{
            background-color:' . awpa_esc_custom_style($attributes['btnBgcolor']) . ';
            border-radius:' . awpa_esc_custom_style($attributes['btnRadius']) . "px" . ';
            color:' . awpa_esc_custom_style($attributes['btnTextColor']) . ';
        }';

        //Fonts
        //Title
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap h4{
            font-size: ' . awpa_esc_custom_style($attributes['titleFontSize']) . awpa_esc_custom_style($attributes['titleFontSizeType']) . ';
            ' . awpacheckFontfamily($attributes['titleFontFamily']) . ';
            font-weight: ' . awpa_esc_custom_style($attributes['titleFontWeight']) . ';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap h4{
            font-size: ' . awpa_esc_custom_style($attributes['titleTabletForntSize']) . awpa_esc_custom_style($attributes['titleFontSizeType']) . ';
        }';

        $block_content .= '}';
        $block_content .= '@media (max-width: 768px) { ';
        $block_content .= ' .' . $blockuniqueclass . '.awpa-loginfrom-wrap h4{
            font-size: ' . awpa_esc_custom_style($attributes['titleMobileForntSize']) . awpa_esc_custom_style($attributes['titleFontSizeType']) . ';
        }';

        $block_content .= '}';
        //End Title

        //lable
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap label{
            font-size: ' . awpa_esc_custom_style($attributes['labelFontSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
            ' . awpacheckFontfamily($attributes['labelFontFamily']) . ';
            font-weight: ' . awpa_esc_custom_style($attributes['labelFontWeight']) . ';
        }';

        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap .login-forget-password a{
            font-size: ' . awpa_esc_custom_style($attributes['labelFontSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
            ' . awpacheckFontfamily($attributes['labelFontFamily']) . ';
            font-weight: ' . awpa_esc_custom_style($attributes['labelFontWeight']) . ';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap label{
            font-size: ' . awpa_esc_custom_style($attributes['labelTabletForntSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap .login-forget-password a{
            font-size: ' . awpa_esc_custom_style($attributes['labelTabletForntSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
        }';

        $block_content .= '}';
        $block_content .= '@media (max-width: 768px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap label{
            font-size: ' . awpa_esc_custom_style($attributes['labelMobileForntSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
        }';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap .login-forget-password a{
            font-size: ' . awpa_esc_custom_style($attributes['labelMobileForntSize']) . awpa_esc_custom_style($attributes['labelFontSizeType']) . ';
        }';

        $block_content .= '}';
        //End label

        //btn
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap form input[type="submit"].button{
            font-size: ' . awpa_esc_custom_style($attributes['btnFontSize']) . awpa_esc_custom_style($attributes['btnFontSizeType']) . ';
            ' . awpacheckFontfamily($attributes['btnFontFamily']) . ';
            font-weight: ' . awpa_esc_custom_style($attributes['btnFontWeight']) . ';
        }';

        $block_content .= '@media (max-width: 1025px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap form input[type="submit"].button{
            font-size: ' . awpa_esc_custom_style($attributes['btnTabletForntSize']) . awpa_esc_custom_style($attributes['btnFontSizeType']) . ';
        }';

        $block_content .= '}';
        $block_content .= '@media (max-width: 768px) { ';
        $block_content .= ' .' . $blockuniqueclass . ' .awpa-loginfrom-wrap form input[type="submit"].button{
            font-size: ' . awpa_esc_custom_style($attributes['btnMobileForntSize']) . awpa_esc_custom_style($attributes['btnFontSizeType']) . ';
        }';

        $block_content .= '}';
        //End btn

        //Box Shadow
        if ($attributes['enableBoxShadow']) {
            $block_content .= ' .' . $blockuniqueclass . '  .awpa-loginfrom-wrap{
                box-shadow: ' . awpa_esc_custom_style($attributes['xOffset']) . 'px ' . awpa_esc_custom_style($attributes['yOffset']) . 'px ' . awpa_esc_custom_style($attributes['blur']) . 'px ' . awpa_esc_custom_style($attributes['spread']) . 'px ' . awpa_esc_custom_style($attributes['shadowColor']) . ';
            }';
        }
        $block_content .= '</style>';
        return $block_content;
    }
}
if (!function_exists('awpacheckFontfamily')) {
    function awpacheckFontfamily($fontFamily)
    {

        $fonts = '';
        if ($fontFamily != 'Default' && $fontFamily != "undefined") {
            $fonts =  'font-family:' . awpa_esc_custom_style($fontFamily);
        }
        return $fonts;
    }
}


if (!function_exists('awpa_login_form_common')) {
    function awpa_login_form_common($attributes)
    {


        $obj_id = get_queried_object_id();
        $current_url = get_permalink($obj_id);

        $form_data = '';

        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_name = $current_user->display_name;
            $form_data .= '<div class="awap-logout">
            <div class="awpa-notice">
                <div class="awpa-notice__logo">
                    <svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 382.31 446.56"><defs><linearGradient id="a" x1="118.66" y1="270.6" x2="393.33" y2="112.03" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#2db8b7"/><stop offset="1" stop-color="#3062af"/></linearGradient></defs><path d="M114.75 425.01a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.46-4.46 8.47 4.46a2.49 2.49 0 0 0 1.16.29 2.56 2.56 0 0 0 1.46-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm47.65 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.25-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.49 2.49 0 0 0 3.61 2.62l8.45-4.46 8.47 4.46a2.49 2.49 0 0 0 2.62-.18 2.49 2.49 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm46.07 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.49 2.49 0 0 0-2 1.69 2.45 2.45 0 0 0 .63 2.54l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.48 2.48 0 0 0 1.15.29 2.57 2.57 0 0 0 1.47-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm49.24 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.59a2.59 2.59 0 0 0-4.45 0l-4.24 8.59-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.85 6.67-1.61 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.47 2.47 0 0 0 1.15.28 2.48 2.48 0 0 0 2.46-2.9l-1.62-9.43 6.86-6.67a2.47 2.47 0 0 0 .63-2.54Zm45.72 0a2.49 2.49 0 0 0-2-1.69l-9.49-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.49 2.49 0 0 0-1.37 4.24l6.86 6.66-1.62 9.44a2.48 2.48 0 0 0 3.61 2.61l8.45-4.45 8.47 4.5a2.49 2.49 0 0 0 2.62-.18 2.48 2.48 0 0 0 1-2.43l-1.62-9.44 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Z" fill="#ffb900"/><path d="m7.15 382.41-7.17-30.06h6.21L10.72 373l5.5-20.65h7.22l5.27 21 4.61-21h6.11l-7.28 30.06h-6.44l-6-22.47-6 22.47Zm35.38 0v-30.06h9.74a35.43 35.43 0 0 1 7.22.45 7.92 7.92 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.3 8.3 0 0 1-2.56 3 8.67 8.67 0 0 1-3.15 1.42 34.32 34.32 0 0 1-6.29.43h-4v11.34Zm6.07-25v8.53h3.32a15.85 15.85 0 0 0 4.8-.47 4 4 0 0 0 2.59-3.82 3.91 3.91 0 0 0-1-2.71 4.19 4.19 0 0 0-2.44-1.33 28.92 28.92 0 0 0-4.37-.2Zm32.85 25v-30.06h9.74a35.36 35.36 0 0 1 7.22.45 7.85 7.85 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.28 8.28 0 0 1-2.55 3 8.82 8.82 0 0 1-3.15 1.42 34.44 34.44 0 0 1-6.3.43h-4v11.34Zm6.07-25v8.53h3.33a15.9 15.9 0 0 0 4.8-.47 4 4 0 0 0 2.58-3.82 3.91 3.91 0 0 0-1-2.71 4.24 4.24 0 0 0-2.45-1.33 28.84 28.84 0 0 0-4.36-.2Zm20.72 10.13a19 19 0 0 1 1.37-7.71 14.1 14.1 0 0 1 2.8-4.13 11.64 11.64 0 0 1 3.89-2.7 16.36 16.36 0 0 1 6.48-1.19q6.65 0 10.63 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.71 0-10.67-4.09t-3.9-11.24Zm6.25-.21q0 5.12 2.36 7.74a8.1 8.1 0 0 0 11.95 0q2.35-2.56 2.35-7.79t-2.26-7.71a8.41 8.41 0 0 0-12.07 0c-1.54 1.71-2.31 4.33-2.31 7.79Zm26.11 5.27 5.9-.57a7.23 7.23 0 0 0 2.17 4.37 6.52 6.52 0 0 0 4.4 1.39 6.75 6.75 0 0 0 4.42-1.24 3.68 3.68 0 0 0 1.48-2.9 2.79 2.79 0 0 0-.62-1.82 5 5 0 0 0-2.19-1.3c-.71-.25-2.33-.68-4.86-1.31q-4.87-1.22-6.85-3a7.83 7.83 0 0 1-2.76-6.05 7.69 7.69 0 0 1 1.3-4.29 8.22 8.22 0 0 1 3.75-3 15.18 15.18 0 0 1 5.92-1c3.77 0 6.61.82 8.52 2.48a8.71 8.71 0 0 1 3 6.62l-6.07.27a5.09 5.09 0 0 0-1.67-3.33 6.09 6.09 0 0 0-3.84-1 6.91 6.91 0 0 0-4.15 1.09 2.19 2.19 0 0 0-1 1.86 2.31 2.31 0 0 0 .9 1.83q1.16 1 5.58 2a29.82 29.82 0 0 1 6.55 2.16 8.47 8.47 0 0 1 3.32 3.06 8.94 8.94 0 0 1 1.2 4.79 8.84 8.84 0 0 1-1.43 4.84 8.62 8.62 0 0 1-4.06 3.35 17 17 0 0 1-6.54 1.1c-3.81 0-6.72-.88-8.76-2.64a11.39 11.39 0 0 1-3.59-7.73Zm36.32 9.78v-25H168v-5.09h23.89v5.09h-8.9v25Zm56.11 0h-6.61l-2.62-6.83h-12l-2.48 6.83h-6.44l11.71-30.06h6.42Zm-11.18-11.89-4.14-11.16-4.06 11.16Zm14.36-18.17h6.07v16.28a34.2 34.2 0 0 0 .22 5 4.84 4.84 0 0 0 1.86 3 6.43 6.43 0 0 0 4 1.12 6.06 6.06 0 0 0 3.89-1.06 4.11 4.11 0 0 0 1.58-2.59 33.49 33.49 0 0 0 .27-5.11v-16.61h6.07v15.79a40.46 40.46 0 0 1-.49 7.65 8.47 8.47 0 0 1-1.82 3.77 9 9 0 0 1-3.53 2.45 15.39 15.39 0 0 1-5.79.92 16.68 16.68 0 0 1-6.53-1 9.32 9.32 0 0 1-3.52-2.58 8.26 8.26 0 0 1-1.7-3.33 36.42 36.42 0 0 1-.59-7.63Zm37.14 30.06v-25h-8.9v-5.09h23.89v5.09h-8.9v25Zm18.9 0v-30.03h6.07v11.83h11.9v-11.83h6.07v30.06h-6.07v-13.13h-11.9v13.14Zm29.08-14.84a19 19 0 0 1 1.38-7.71 13.91 13.91 0 0 1 2.8-4.13 11.7 11.7 0 0 1 3.88-2.7 16.43 16.43 0 0 1 6.48-1.19q6.64 0 10.64 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.7 0-10.67-4.09t-3.91-11.24Zm6.26-.21q0 5.12 2.36 7.74a7.68 7.68 0 0 0 6 2.64 7.58 7.58 0 0 0 5.95-2.62q2.33-2.61 2.33-7.84c0-3.45-.75-6-2.27-7.71a8.39 8.39 0 0 0-12.06 0c-1.52 1.74-2.29 4.36-2.29 7.82Zm27.66 15.05v-30.03h12.8a21.83 21.83 0 0 1 7 .81 6.88 6.88 0 0 1 3.5 2.88 8.62 8.62 0 0 1 1.31 4.74 8 8 0 0 1-2 5.59 9.55 9.55 0 0 1-5.94 2.78 14.06 14.06 0 0 1 3.25 2.52 34.27 34.27 0 0 1 3.45 4.88l3.67 5.86h-7.26l-4.38-6.54a43.26 43.26 0 0 0-3.2-4.42 4.76 4.76 0 0 0-1.83-1.25 10.14 10.14 0 0 0-3.05-.34h-1.24v12.55Zm6.07-17.35h4.5a23.69 23.69 0 0 0 5.45-.36 3.23 3.23 0 0 0 1.7-1.28 3.9 3.9 0 0 0 .62-2.25 3.6 3.6 0 0 0-.81-2.45 3.7 3.7 0 0 0-2.29-1.18c-.49-.07-2-.1-4.43-.1h-4.74Z"/><path d="M414.61 191.34c0-87.46-71.15-158.62-158.61-158.62S97.39 103.88 97.39 191.34a158.2 158.2 0 0 0 51.48 116.84l-.15.13 5.14 4.34c.34.28.7.51 1 .79 2.73 2.27 5.56 4.42 8.45 6.5q1.4 1 2.82 2 4.62 3.18 9.47 6c.7.42 1.41.82 2.12 1.22q5.31 3 10.84 5.66l.82.37a157.61 157.61 0 0 0 38.36 12.14l1.07.19c4.17.72 8.39 1.3 12.67 1.68l1.56.12c4.26.36 8.56.58 12.92.58s8.58-.22 12.82-.57l1.61-.12q6.3-.57 12.56-1.65l1.08-.2a157.39 157.39 0 0 0 37.82-11.85c.43-.2.88-.39 1.32-.6 4.42-2.09 8.76-4.37 13-6.86q4.67-2.73 9.12-5.77c1.07-.72 2.11-1.49 3.17-2.25 2.53-1.82 5-3.7 7.43-5.67.54-.43 1.12-.81 1.64-1.25l5.28-4.41-.16-.13a158.2 158.2 0 0 0 51.96-117.23Zm-305.69 0c0-81.1 66-147.08 147.08-147.08s147.08 66 147.08 147.08a146.72 146.72 0 0 1-49.54 110 43.4 43.4 0 0 0-5.15-3.1l-48.84-24.41a12.8 12.8 0 0 1-7.1-11.5v-17.11c1.13-1.39 2.32-3 3.56-4.71A117.11 117.11 0 0 0 311.09 211a20.93 20.93 0 0 0 12-19v-20.45a21 21 0 0 0-5.09-13.67V131c.3-3 1.36-19.88-10.86-33.82C296.51 85 279.31 78.86 256 78.86S215.49 85 204.86 97.14C192.64 111.07 193.7 128 194 131v26.92a21 21 0 0 0-5.12 13.66V192a21 21 0 0 0 7.73 16.27 108.46 108.46 0 0 0 17.84 36.85v16.68a12.85 12.85 0 0 1-6.7 11.29L162.14 298a41.76 41.76 0 0 0-4.34 2.75 146.76 146.76 0 0 1-48.88-109.41Z" transform="translate(-64.85 -32.72)" fill="url(#a)"/></svg>
                </div>
                <div class="awpa-notice__content">
                    <h2 class="awpa-notice__title">' . __('Hello ', 'wp-post-author') . $user_name . '</h2>
                    <p class="awpa-notice__description"> ' . __('You are already logged in', "wp-post-author") . ' 
                        <a id="wp-submit" class="logout" href="' . wp_logout_url($current_url) . '" title="Logout">' . __('Logout', "wp-post-author") . '</a>
                    </p>
                </div>
            </div>';
        } else {
            $args = array(
                'echo'           => false,
                'redirect'       =>  $current_url,
                'label_log_in'   => $attributes['btnText'],
                'form_id'        => 'awpa-user-login',
                'label_username' => __('Username', 'wp-post-author'),
                'label_password' => __('Password', 'wp-post-author'),
                'label_remember' => __('Remember Me', 'wp-post-author'),
                'id_username'    => 'user_login',
                'id_password'    => 'user_pass',
                'id_submit'      => 'wp-submit',
                'remember'       => true,
                'value_username' => NULL,
                'value_remember' => true
            );

            $form_data .=  wp_login_form($args);
        }
        return $form_data;
    }
}



if (!function_exists('awpa_user_login_shortcodes')) {
    add_shortcode('awpa-user-login', 'awpa_user_login_shortcodes');

    function awpa_user_login_shortcodes($atts)
    {

        $awpa = shortcode_atts(array(
            'title' => __('User Login Form', 'wp-post-author'),
            'button_text' => __('Login', 'wp-post-author')
        ), $atts);
        $btnText = !empty($awpa['button_text']) ? esc_attr($awpa['button_text']) : '';
        $title = isset($awpa['title']) ? esc_attr($awpa['title']) : '';
        $obj_id = get_the_ID();
        $current_url = get_permalink($obj_id);
        $args = array(
            'echo'           => true,
            'remember'       => true,
            'redirect'       => (is_ssl() ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'],
            'form_id'        => 'awpa-user-login',
            'label_log_in' => $btnText,
            'label_username' => __('Username', 'wp-post-author'),
            'label_password' => __('Password', 'wp-post-author'),
            'label_remember' => __('Remember Me', 'wp-post-author'),
            'id_username'    => 'user_login',
            'id_password'    => 'user_pass',
            'id_submit'      => 'wp-submit',            
            'value_username' => NULL,
            'value_remember' => true
        );

        ob_start();
    ?>
        <div class='awpa-loginfrom-wrap'>
            <?php
            if (is_user_logged_in()) {
                $current_user = wp_get_current_user();
                $user_name = $current_user->display_name; ?>
                <div class="awap-logout">
                    <div class="awpa-notice">
                        <div class="awpa-notice__logo">
                            <svg xmlns="http://www.w3.org/2000/svg" data-name="Layer 1" viewBox="0 0 382.31 446.56"><defs><linearGradient id="a" x1="118.66" y1="270.6" x2="393.33" y2="112.03" gradientUnits="userSpaceOnUse"><stop offset="0" stop-color="#2db8b7"/><stop offset="1" stop-color="#3062af"/></linearGradient></defs><path d="M114.75 425.01a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.46-4.46 8.47 4.46a2.49 2.49 0 0 0 1.16.29 2.56 2.56 0 0 0 1.46-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm47.65 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.25-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.48 2.48 0 0 0-1.37 4.23l6.86 6.67-1.62 9.43a2.49 2.49 0 0 0 3.61 2.62l8.45-4.46 8.47 4.46a2.49 2.49 0 0 0 2.62-.18 2.49 2.49 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm46.07 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.47 1.34a2.49 2.49 0 0 0-2 1.69 2.45 2.45 0 0 0 .63 2.54l6.86 6.67-1.62 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.48 2.48 0 0 0 1.15.29 2.57 2.57 0 0 0 1.47-.47 2.51 2.51 0 0 0 1-2.44l-1.62-9.43 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Zm49.24 0a2.47 2.47 0 0 0-2-1.69l-9.48-1.38-4.24-8.59a2.59 2.59 0 0 0-4.45 0l-4.24 8.59-9.47 1.34a2.48 2.48 0 0 0-1.37 4.23l6.85 6.67-1.61 9.43a2.48 2.48 0 0 0 3.6 2.62l8.45-4.46 8.48 4.46a2.47 2.47 0 0 0 1.15.28 2.48 2.48 0 0 0 2.46-2.9l-1.62-9.43 6.86-6.67a2.47 2.47 0 0 0 .63-2.54Zm45.72 0a2.49 2.49 0 0 0-2-1.69l-9.49-1.38-4.24-8.58a2.58 2.58 0 0 0-4.45 0l-4.24 8.58-9.46 1.34a2.49 2.49 0 0 0-1.37 4.24l6.86 6.66-1.62 9.44a2.48 2.48 0 0 0 3.61 2.61l8.45-4.45 8.47 4.5a2.49 2.49 0 0 0 2.62-.18 2.48 2.48 0 0 0 1-2.43l-1.62-9.44 6.86-6.66a2.49 2.49 0 0 0 .63-2.55Z" fill="#ffb900"/><path d="m7.15 382.41-7.17-30.06h6.21L10.72 373l5.5-20.65h7.22l5.27 21 4.61-21h6.11l-7.28 30.06h-6.44l-6-22.47-6 22.47Zm35.38 0v-30.06h9.74a35.43 35.43 0 0 1 7.22.45 7.92 7.92 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.3 8.3 0 0 1-2.56 3 8.67 8.67 0 0 1-3.15 1.42 34.32 34.32 0 0 1-6.29.43h-4v11.34Zm6.07-25v8.53h3.32a15.85 15.85 0 0 0 4.8-.47 4 4 0 0 0 2.59-3.82 3.91 3.91 0 0 0-1-2.71 4.19 4.19 0 0 0-2.44-1.33 28.92 28.92 0 0 0-4.37-.2Zm32.85 25v-30.06h9.74a35.36 35.36 0 0 1 7.22.45 7.85 7.85 0 0 1 4.33 2.94 9.36 9.36 0 0 1 1.74 5.86 9.78 9.78 0 0 1-1 4.65 8.28 8.28 0 0 1-2.55 3 8.82 8.82 0 0 1-3.15 1.42 34.44 34.44 0 0 1-6.3.43h-4v11.34Zm6.07-25v8.53h3.33a15.9 15.9 0 0 0 4.8-.47 4 4 0 0 0 2.58-3.82 3.91 3.91 0 0 0-1-2.71 4.24 4.24 0 0 0-2.45-1.33 28.84 28.84 0 0 0-4.36-.2Zm20.72 10.13a19 19 0 0 1 1.37-7.71 14.1 14.1 0 0 1 2.8-4.13 11.64 11.64 0 0 1 3.89-2.7 16.36 16.36 0 0 1 6.48-1.19q6.65 0 10.63 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.71 0-10.67-4.09t-3.9-11.24Zm6.25-.21q0 5.12 2.36 7.74a8.1 8.1 0 0 0 11.95 0q2.35-2.56 2.35-7.79t-2.26-7.71a8.41 8.41 0 0 0-12.07 0c-1.54 1.71-2.31 4.33-2.31 7.79Zm26.11 5.27 5.9-.57a7.23 7.23 0 0 0 2.17 4.37 6.52 6.52 0 0 0 4.4 1.39 6.75 6.75 0 0 0 4.42-1.24 3.68 3.68 0 0 0 1.48-2.9 2.79 2.79 0 0 0-.62-1.82 5 5 0 0 0-2.19-1.3c-.71-.25-2.33-.68-4.86-1.31q-4.87-1.22-6.85-3a7.83 7.83 0 0 1-2.76-6.05 7.69 7.69 0 0 1 1.3-4.29 8.22 8.22 0 0 1 3.75-3 15.18 15.18 0 0 1 5.92-1c3.77 0 6.61.82 8.52 2.48a8.71 8.71 0 0 1 3 6.62l-6.07.27a5.09 5.09 0 0 0-1.67-3.33 6.09 6.09 0 0 0-3.84-1 6.91 6.91 0 0 0-4.15 1.09 2.19 2.19 0 0 0-1 1.86 2.31 2.31 0 0 0 .9 1.83q1.16 1 5.58 2a29.82 29.82 0 0 1 6.55 2.16 8.47 8.47 0 0 1 3.32 3.06 8.94 8.94 0 0 1 1.2 4.79 8.84 8.84 0 0 1-1.43 4.84 8.62 8.62 0 0 1-4.06 3.35 17 17 0 0 1-6.54 1.1c-3.81 0-6.72-.88-8.76-2.64a11.39 11.39 0 0 1-3.59-7.73Zm36.32 9.78v-25H168v-5.09h23.89v5.09h-8.9v25Zm56.11 0h-6.61l-2.62-6.83h-12l-2.48 6.83h-6.44l11.71-30.06h6.42Zm-11.18-11.89-4.14-11.16-4.06 11.16Zm14.36-18.17h6.07v16.28a34.2 34.2 0 0 0 .22 5 4.84 4.84 0 0 0 1.86 3 6.43 6.43 0 0 0 4 1.12 6.06 6.06 0 0 0 3.89-1.06 4.11 4.11 0 0 0 1.58-2.59 33.49 33.49 0 0 0 .27-5.11v-16.61h6.07v15.79a40.46 40.46 0 0 1-.49 7.65 8.47 8.47 0 0 1-1.82 3.77 9 9 0 0 1-3.53 2.45 15.39 15.39 0 0 1-5.79.92 16.68 16.68 0 0 1-6.53-1 9.32 9.32 0 0 1-3.52-2.58 8.26 8.26 0 0 1-1.7-3.33 36.42 36.42 0 0 1-.59-7.63Zm37.14 30.06v-25h-8.9v-5.09h23.89v5.09h-8.9v25Zm18.9 0v-30.03h6.07v11.83h11.9v-11.83h6.07v30.06h-6.07v-13.13h-11.9v13.14Zm29.08-14.84a19 19 0 0 1 1.38-7.71 13.91 13.91 0 0 1 2.8-4.13 11.7 11.7 0 0 1 3.88-2.7 16.43 16.43 0 0 1 6.48-1.19q6.64 0 10.64 4.12t4 11.46q0 7.29-4 11.39t-10.58 4.12q-6.7 0-10.67-4.09t-3.91-11.24Zm6.26-.21q0 5.12 2.36 7.74a7.68 7.68 0 0 0 6 2.64 7.58 7.58 0 0 0 5.95-2.62q2.33-2.61 2.33-7.84c0-3.45-.75-6-2.27-7.71a8.39 8.39 0 0 0-12.06 0c-1.52 1.74-2.29 4.36-2.29 7.82Zm27.66 15.05v-30.03h12.8a21.83 21.83 0 0 1 7 .81 6.88 6.88 0 0 1 3.5 2.88 8.62 8.62 0 0 1 1.31 4.74 8 8 0 0 1-2 5.59 9.55 9.55 0 0 1-5.94 2.78 14.06 14.06 0 0 1 3.25 2.52 34.27 34.27 0 0 1 3.45 4.88l3.67 5.86h-7.26l-4.38-6.54a43.26 43.26 0 0 0-3.2-4.42 4.76 4.76 0 0 0-1.83-1.25 10.14 10.14 0 0 0-3.05-.34h-1.24v12.55Zm6.07-17.35h4.5a23.69 23.69 0 0 0 5.45-.36 3.23 3.23 0 0 0 1.7-1.28 3.9 3.9 0 0 0 .62-2.25 3.6 3.6 0 0 0-.81-2.45 3.7 3.7 0 0 0-2.29-1.18c-.49-.07-2-.1-4.43-.1h-4.74Z"/><path d="M414.61 191.34c0-87.46-71.15-158.62-158.61-158.62S97.39 103.88 97.39 191.34a158.2 158.2 0 0 0 51.48 116.84l-.15.13 5.14 4.34c.34.28.7.51 1 .79 2.73 2.27 5.56 4.42 8.45 6.5q1.4 1 2.82 2 4.62 3.18 9.47 6c.7.42 1.41.82 2.12 1.22q5.31 3 10.84 5.66l.82.37a157.61 157.61 0 0 0 38.36 12.14l1.07.19c4.17.72 8.39 1.3 12.67 1.68l1.56.12c4.26.36 8.56.58 12.92.58s8.58-.22 12.82-.57l1.61-.12q6.3-.57 12.56-1.65l1.08-.2a157.39 157.39 0 0 0 37.82-11.85c.43-.2.88-.39 1.32-.6 4.42-2.09 8.76-4.37 13-6.86q4.67-2.73 9.12-5.77c1.07-.72 2.11-1.49 3.17-2.25 2.53-1.82 5-3.7 7.43-5.67.54-.43 1.12-.81 1.64-1.25l5.28-4.41-.16-.13a158.2 158.2 0 0 0 51.96-117.23Zm-305.69 0c0-81.1 66-147.08 147.08-147.08s147.08 66 147.08 147.08a146.72 146.72 0 0 1-49.54 110 43.4 43.4 0 0 0-5.15-3.1l-48.84-24.41a12.8 12.8 0 0 1-7.1-11.5v-17.11c1.13-1.39 2.32-3 3.56-4.71A117.11 117.11 0 0 0 311.09 211a20.93 20.93 0 0 0 12-19v-20.45a21 21 0 0 0-5.09-13.67V131c.3-3 1.36-19.88-10.86-33.82C296.51 85 279.31 78.86 256 78.86S215.49 85 204.86 97.14C192.64 111.07 193.7 128 194 131v26.92a21 21 0 0 0-5.12 13.66V192a21 21 0 0 0 7.73 16.27 108.46 108.46 0 0 0 17.84 36.85v16.68a12.85 12.85 0 0 1-6.7 11.29L162.14 298a41.76 41.76 0 0 0-4.34 2.75 146.76 146.76 0 0 1-48.88-109.41Z" transform="translate(-64.85 -32.72)" fill="url(#a)"/></svg>
                        </div>
                        <div class="awpa-notice__content">
                            <h2 class="awpa-notice__title">
                                <?php _e('Hello ', 'wp-post-author'); ?>
                                <?php echo esc_html($user_name); ?>
                            </h2>
                            <p class="awpa-notice__description"><?php _e('You are already logged in', "wp-post-author"); ?> <a id="wp-submit" class="logout" href="<?php echo esc_url(wp_logout_url($current_url)) ?>" title="Logout"><?php _e('Logout', 'wp-post-author'); ?></a></p>
                        </div>
                    </div>
                </div>
            <?php
            } else { ?>
                <h2><?php echo esc_html($title); ?></h2>
            <?php wp_login_form($args);
            } ?>
        </div>
<?php
        return ob_get_clean();
    }
}

add_action('login_form_bottom', 'awpa_add_lost_password_link');
function awpa_add_lost_password_link()
{
    $form_data = '<p class="login-forget-password"><a href="' . esc_url(wp_lostpassword_url(get_home_url())) . '">' . __('Forget Password', 'wp-post-author') . '</a></p>';
    return $form_data;
}

if (!function_exists('awpa_esc_custom_style(')) {

    function awpa_esc_custom_style($props)
    {
        return wp_kses($props, array("\'", '\"'));
    }
}