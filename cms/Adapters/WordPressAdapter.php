<?php
/**
 * WordPress Adapter - WordPress CMS Integration
 * 
 * Boots WordPress from shared-cores as a supervised userland process
 * Provides isolation and implements CMSInterface
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS\Adapters;

use IkabudKernel\CMS\CMSInterface;
use Exception;

class WordPressAdapter implements CMSInterface
{
    private string $instanceId;
    private array $config = [];
    private bool $initialized = false;
    private bool $booted = false;
    private array $data = [];
    private string $corePath;
    private float $bootStartTime;
    
    /**
     * Constructor
     * 
     * @param string $corePath Path to WordPress core
     */
    public function __construct(string $corePath = null)
    {
        $this->corePath = $corePath ?? __DIR__ . '/../../shared-cores/wordpress';
    }
    
    /**
     * Initialize WordPress environment
     */
    public function initialize(array $config): void
    {
        if ($this->initialized) {
            return;
        }
        
        $this->config = $config;
        $this->instanceId = $config['instance_id'] ?? 'default';
        
        // Set WordPress constants
        if (!defined('ABSPATH')) {
            define('ABSPATH', $this->corePath . '/');
        }
        
        // Set database configuration
        if (!defined('DB_NAME')) {
            define('DB_NAME', $config['database_name'] ?? 'wordpress');
        }
        if (!defined('DB_USER')) {
            define('DB_USER', $_ENV['DB_USERNAME'] ?? 'root');
        }
        if (!defined('DB_PASSWORD')) {
            define('DB_PASSWORD', $_ENV['DB_PASSWORD'] ?? '');
        }
        if (!defined('DB_HOST')) {
            define('DB_HOST', $_ENV['DB_HOST'] ?? 'localhost');
        }
        if (!defined('DB_CHARSET')) {
            define('DB_CHARSET', 'utf8mb4');
        }
        if (!defined('DB_COLLATE')) {
            define('DB_COLLATE', '');
        }
        
        // Set table prefix
        $GLOBALS['table_prefix'] = $config['database_prefix'] ?? 'wp_';
        
        // Set WordPress keys and salts (use from config or generate)
        $this->defineSecurityKeys();
        
        // Set WordPress debug mode
        if (!defined('WP_DEBUG')) {
            define('WP_DEBUG', $_ENV['APP_DEBUG'] === 'true');
        }
        if (!defined('WP_DEBUG_LOG')) {
            define('WP_DEBUG_LOG', false);
        }
        if (!defined('WP_DEBUG_DISPLAY')) {
            define('WP_DEBUG_DISPLAY', false);
        }
        
        // Set content directory (instance-specific)
        $instancePath = __DIR__ . '/../../instances/' . $this->instanceId;
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', $instancePath . '/wp-content');
        }
        if (!defined('WP_CONTENT_URL')) {
            define('WP_CONTENT_URL', '/wp-content');
        }
        
        // Create instance directories if needed
        $this->createInstanceDirectories($instancePath);
        
        $this->initialized = true;
    }
    
    /**
     * Boot WordPress
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        
        if (!$this->initialized) {
            throw new Exception('WordPress must be initialized before booting');
        }
        
        $this->bootStartTime = microtime(true);
        
        // Start output buffering to capture WordPress output
        ob_start();
        
        try {
            // Load WordPress
            require_once ABSPATH . 'wp-load.php';
            
            // WordPress is now loaded
            $this->booted = true;
            
            // Clean output buffer
            ob_end_clean();
            
        } catch (Exception $e) {
            ob_end_clean();
            throw new Exception('Failed to boot WordPress: ' . $e->getMessage());
        }
    }
    
    /**
     * Shutdown WordPress
     */
    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }
        
        // WordPress doesn't have a formal shutdown, but we can cleanup
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
        }
        
        $this->booted = false;
    }
    
    /**
     * Execute a query
     */
    public function executeQuery(array $query): array
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted before executing queries');
        }
        
        $type = $query['type'] ?? 'post';
        $limit = $query['limit'] ?? 10;
        
        // Build WP_Query args
        $args = [
            'post_type' => $type,
            'posts_per_page' => $limit,
            'post_status' => 'publish'
        ];
        
        // Add filters
        if (isset($query['category'])) {
            $args['category_name'] = $query['category'];
        }
        if (isset($query['tag'])) {
            $args['tag'] = $query['tag'];
        }
        if (isset($query['author'])) {
            $args['author'] = $query['author'];
        }
        if (isset($query['orderby'])) {
            $args['orderby'] = $query['orderby'];
        }
        if (isset($query['order'])) {
            $args['order'] = $query['order'];
        }
        
        // Execute query
        $wp_query = new \WP_Query($args);
        
        $results = [];
        if ($wp_query->have_posts()) {
            while ($wp_query->have_posts()) {
                $wp_query->the_post();
                
                $results[] = [
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'content' => get_the_content(),
                    'excerpt' => get_the_excerpt(),
                    'date' => get_the_date('Y-m-d H:i:s'),
                    'author' => get_the_author(),
                    'permalink' => get_permalink(),
                    'thumbnail' => get_the_post_thumbnail_url(null, 'large')
                ];
            }
            wp_reset_postdata();
        }
        
        return $results;
    }
    
    /**
     * Get content by ID
     */
    public function getContent(string $type, int $id): ?array
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted');
        }
        
        $post = get_post($id);
        
        if (!$post || $post->post_type !== $type) {
            return null;
        }
        
        return [
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'status' => $post->post_status,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => $post->post_author,
            'type' => $post->post_type
        ];
    }
    
    /**
     * Create content
     */
    public function createContent(string $type, array $data): int
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted');
        }
        
        $postData = [
            'post_type' => $type,
            'post_title' => $data['title'] ?? '',
            'post_content' => $data['content'] ?? '',
            'post_excerpt' => $data['excerpt'] ?? '',
            'post_status' => $data['status'] ?? 'publish',
            'post_author' => $data['author'] ?? 1
        ];
        
        $postId = wp_insert_post($postData);
        
        if (is_wp_error($postId)) {
            throw new Exception('Failed to create content: ' . $postId->get_error_message());
        }
        
        return $postId;
    }
    
    /**
     * Update content
     */
    public function updateContent(string $type, int $id, array $data): bool
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted');
        }
        
        $postData = ['ID' => $id];
        
        if (isset($data['title'])) $postData['post_title'] = $data['title'];
        if (isset($data['content'])) $postData['post_content'] = $data['content'];
        if (isset($data['excerpt'])) $postData['post_excerpt'] = $data['excerpt'];
        if (isset($data['status'])) $postData['post_status'] = $data['status'];
        
        $result = wp_update_post($postData);
        
        return !is_wp_error($result) && $result > 0;
    }
    
    /**
     * Delete content
     */
    public function deleteContent(string $type, int $id): bool
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted');
        }
        
        $result = wp_delete_post($id, true);
        
        return $result !== false && $result !== null;
    }
    
    /**
     * Get categories
     */
    public function getCategories(string $taxonomy = 'category'): array
    {
        if (!$this->booted) {
            throw new Exception('WordPress must be booted');
        }
        
        $terms = get_terms([
            'taxonomy' => $taxonomy,
            'hide_empty' => false
        ]);
        
        if (is_wp_error($terms)) {
            return [];
        }
        
        $result = [];
        foreach ($terms as $term) {
            $result[] = [
                'id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'description' => $term->description,
                'count' => $term->count
            ];
        }
        
        return $result;
    }
    
    /**
     * Handle route
     */
    public function handleRoute(string $path, string $method = 'GET'): string
    {
        if (!$this->booted) {
            $this->boot();
        }
        
        // Set up WordPress query vars
        global $wp, $wp_query, $wp_the_query;
        
        // Parse request
        $wp->parse_request();
        
        // Query posts
        $wp_query->query($wp->query_vars);
        
        // Start output buffering
        ob_start();
        
        // Load template
        if (function_exists('template_redirect')) {
            template_redirect();
        }
        
        // Get output
        $output = ob_get_clean();
        
        return $output;
    }
    
    /**
     * Get database configuration
     */
    public function getDatabaseConfig(): array
    {
        return [
            'host' => DB_HOST,
            'name' => DB_NAME,
            'user' => DB_USER,
            'charset' => DB_CHARSET,
            'prefix' => $GLOBALS['table_prefix']
        ];
    }
    
    /**
     * Get resource usage
     */
    public function getResourceUsage(): array
    {
        global $wpdb;
        
        return [
            'memory' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
            'queries' => $wpdb ? $wpdb->num_queries : 0,
            'boot_time' => $this->bootStartTime ? (microtime(true) - $this->bootStartTime) * 1000 : 0
        ];
    }
    
    /**
     * Get WordPress version
     */
    public function getVersion(): string
    {
        if ($this->booted && function_exists('get_bloginfo')) {
            return get_bloginfo('version');
        }
        
        return 'unknown';
    }
    
    /**
     * Get CMS type
     */
    public function getType(): string
    {
        return 'wordpress';
    }
    
    /**
     * Check if initialized
     */
    public function isInitialized(): bool
    {
        return $this->initialized;
    }
    
    /**
     * Check if booted
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }
    
    /**
     * Get instance ID
     */
    public function getInstanceId(): string
    {
        return $this->instanceId;
    }
    
    /**
     * Set instance ID
     */
    public function setInstanceId(string $instanceId): void
    {
        $this->instanceId = $instanceId;
    }
    
    /**
     * Get data
     */
    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }
    
    /**
     * Set data
     */
    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
    
    // ========================================================================
    // PRIVATE HELPER METHODS
    // ========================================================================
    
    /**
     * Define WordPress security keys
     */
    private function defineSecurityKeys(): void
    {
        $keys = [
            'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
            'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT'
        ];
        
        foreach ($keys as $key) {
            if (!defined($key)) {
                define($key, bin2hex(random_bytes(32)));
            }
        }
    }
    
    /**
     * Create instance directories
     */
    private function createInstanceDirectories(string $path): void
    {
        $dirs = [
            $path,
            $path . '/wp-content',
            $path . '/wp-content/themes',
            $path . '/wp-content/plugins',
            $path . '/wp-content/uploads',
            $path . '/wp-content/cache'
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}
