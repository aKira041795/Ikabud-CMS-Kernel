<?php
/**
 * Template Name: DiSyL v0.2 Test
 * Description: Test template for DiSyL Manifest v0.2 features
 */

// This template will be intercepted by DiSyL and render test-v0.2.disyl
// The DiSyL integration in functions.php handles the routing

get_header();
?>

<div class="disyl-test-fallback">
    <h1>DiSyL v0.2 Test Template</h1>
    <p>If you see this, DiSyL rendering is not working. Check:</p>
    <ul>
        <li>DiSyL integration is enabled in functions.php</li>
        <li>Template file exists: disyl/test-v0.2.disyl</li>
        <li>Kernel is properly initialized</li>
    </ul>
</div>

<?php
get_footer();
