<?php
/**
 * DiSyL Kernel Integration
 * 
 * Integrates DiSyL template rendering with Ikabud Kernel's routing system
 */

namespace IkabudKernel\Core\DiSyL;

class KernelIntegration
{
    /**
     * Initialize DiSyL integration with WordPress
     */
    public static function initWordPress(): void
    {
        // Hook into WordPress template loading
        add_filter('template_include', [self::class, 'interceptTemplate'], 999);
        
        // Add DiSyL detection header
        add_action('send_headers', function() {
            header('X-DiSyL-Enabled: true');
        });
    }
    
    /**
     * Intercept WordPress template loading
     * 
     * @param string $template The path of the template to include
     * @return string Modified template path or DiSyL output
     */
    public static function interceptTemplate(string $template): string
    {
        error_log('[DiSyL] interceptTemplate called with: ' . $template);
        
        // Check if current theme supports DiSyL
        if (!self::themeSupportsDisyl()) {
            error_log('[DiSyL] Theme does not support DiSyL');
            return $template;
        }
        
        error_log('[DiSyL] Theme supports DiSyL!');
        
        // Get theme directory
        $theme_dir = get_stylesheet_directory();
        $disyl_dir = $theme_dir . '/disyl';
        
        // Check if DiSyL templates directory exists
        if (!is_dir($disyl_dir)) {
            return $template;
        }
        
        // Determine which DiSyL template to use
        $template_name = self::getDisylTemplateName();
        $disyl_template = $disyl_dir . '/' . $template_name . '.disyl';
        
        error_log('[DiSyL] Looking for template: ' . $disyl_template);
        
        // If DiSyL template doesn't exist, fall back to PHP template
        if (!file_exists($disyl_template)) {
            error_log('[DiSyL] Template not found, falling back to PHP');
            return $template;
        }
        
        error_log('[DiSyL] Template found! Rendering...');
        
        // Render DiSyL template
        try {
            $output = self::renderDisylTemplate($template_name);
            
            error_log('[DiSyL] Rendering successful! Output length: ' . strlen($output));
            
            // Wrap with HTML structure and WordPress hooks
            $html = '<!DOCTYPE html>' . "\n";
            $html .= '<html ' . get_language_attributes() . '>' . "\n";
            $html .= '<head>' . "\n";
            $html .= '<meta charset="' . get_bloginfo('charset') . '">' . "\n";
            $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\n";
            
            // Let WordPress output its head content
            ob_start();
            wp_head();
            $html .= ob_get_clean();
            
            $html .= '</head>' . "\n";
            $html .= '<body class="' . implode(' ', get_body_class()) . '">' . "\n";
            
            // DiSyL content
            $html .= $output;
            
            // Let WordPress output its footer content
            ob_start();
            wp_footer();
            $html .= ob_get_clean();
            
            $html .= '</body>' . "\n";
            $html .= '</html>';
            
            // Output and exit
            echo $html;
            exit;
            
        } catch (\Exception $e) {
            // Log error and fall back to PHP template
            error_log('[DiSyL] Rendering error: ' . $e->getMessage());
            error_log('[DiSyL] Stack trace: ' . $e->getTraceAsString());
            return $template;
        }
    }
    
    /**
     * Check if current theme supports DiSyL
     */
    private static function themeSupportsDisyl(): bool
    {
        // Check if theme has DiSyL function
        if (function_exists('disyl_render_template')) {
            return true;
        }
        
        // Check if theme has disyl directory
        $theme_dir = get_stylesheet_directory();
        return is_dir($theme_dir . '/disyl');
    }
    
    /**
     * Get DiSyL template name based on WordPress context
     * 
     * Delegates to WordPressAdapter for CMS-specific routing logic.
     */
    private static function getDisylTemplateName(): string
    {
        $adapter = new \IkabudKernel\CMS\Adapters\WordPressAdapter(ABSPATH);
        return $adapter->getTemplateName();
    }
    
    /**
     * Render DiSyL template
     */
    private static function renderDisylTemplate(string $template_name): string
    {
        // Use theme's disyl_render_template function if available
        if (function_exists('disyl_render_template')) {
            return disyl_render_template($template_name);
        }
        
        // Otherwise, render directly using Engine
        $theme_dir = get_stylesheet_directory();
        $template_path = $theme_dir . '/disyl/' . $template_name . '.disyl';
        
        // Load required classes
        require_once __DIR__ . '/Engine.php';
        require_once __DIR__ . '/Lexer.php';
        require_once __DIR__ . '/Parser.php';
        require_once __DIR__ . '/Compiler.php';
        require_once __DIR__ . '/../cms/CMSInterface.php';
        require_once __DIR__ . '/../cms/Adapters/WordPressAdapter.php';
        
        // Use Engine to orchestrate compilation
        $engine = new Engine();
        $compiled = $engine->compileFile($template_path);
        
        // Render through adapter (adapter builds context automatically)
        $adapter = new \IkabudKernel\CMS\Adapters\WordPressAdapter(ABSPATH);
        return $adapter->renderDisyl($compiled);
    }
}
