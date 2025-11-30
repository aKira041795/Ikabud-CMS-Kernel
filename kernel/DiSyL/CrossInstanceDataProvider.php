<?php
/**
 * DiSyL Cross-Instance Data Provider
 * 
 * Enables fetching data from any registered CMS instance,
 * allowing content federation across WordPress, Joomla, Drupal, etc.
 * 
 * Security Features (v0.6.0):
 * - Instance authorization (per-instance permissions)
 * - Rate limiting (queries per minute)
 * - Result limit enforcement
 * 
 * Usage in templates:
 *   {ikb_query cms="joomla" instance="joomla-content" type="article" limit="5"}
 *     {article.title}
 *   {/ikb_query}
 * 
 * @package IkabudKernel\Core\DiSyL
 * @version 0.6.0
 */

namespace IkabudKernel\Core\DiSyL;

use PDO;
use PDOException;
use IkabudKernel\Core\DiSyL\Security\InstanceAuthorization;
use IkabudKernel\Core\DiSyL\Security\RateLimiter;

class CrossInstanceDataProvider
{
    /** @var array Cached database connections */
    private static array $connections = [];
    
    /** @var array Cached instance configs */
    private static array $instanceConfigs = [];
    
    /** @var string Base path for instances */
    private static string $instancesPath;
    
    /** @var string|null Current source instance (for authorization) */
    private static ?string $currentSourceInstance = null;
    
    /** @var bool Whether security features are initialized */
    private static bool $securityInitialized = false;
    
    /**
     * Initialize the provider
     * 
     * @param string|null $instancesPath Path to instances directory
     * @param string|null $sourceInstance Current instance making queries
     */
    public static function init(?string $instancesPath = null, ?string $sourceInstance = null): void
    {
        self::$instancesPath = $instancesPath ?? dirname(__DIR__, 2) . '/instances';
        self::$currentSourceInstance = $sourceInstance;
        
        // Initialize security features
        self::initSecurity();
    }
    
    /**
     * Initialize security features
     */
    private static function initSecurity(): void
    {
        if (self::$securityInitialized) {
            return;
        }
        
        // Initialize authorization if class exists
        if (class_exists(InstanceAuthorization::class)) {
            InstanceAuthorization::init();
        }
        
        // Initialize rate limiter if class exists
        if (class_exists(RateLimiter::class)) {
            RateLimiter::init();
        }
        
        self::$securityInitialized = true;
    }
    
    /**
     * Set the current source instance
     * 
     * @param string $instanceId
     */
    public static function setSourceInstance(string $instanceId): void
    {
        self::$currentSourceInstance = $instanceId;
    }
    
    /**
     * Check if query should use cross-instance data
     * 
     * @param array $attrs Query attributes
     * @return bool
     */
    public static function isCrossInstanceQuery(array $attrs): bool
    {
        return !empty($attrs['instance']) || !empty($attrs['cms']);
    }
    
    /**
     * Execute a cross-instance query
     * 
     * @param array $attrs Query attributes (instance, cms, type, limit, etc.)
     * @param string|null $sourceInstance Override source instance for authorization
     * @return array Array of items with normalized field names
     */
    public static function query(array $attrs, ?string $sourceInstance = null): array
    {
        // Ensure security is initialized
        self::initSecurity();
        
        $targetInstanceId = $attrs['instance'] ?? null;
        $cmsType = $attrs['cms'] ?? null;
        $contentType = $attrs['type'] ?? 'post';
        $limit = (int)($attrs['limit'] ?? 10);
        
        // If only cms specified, find first matching instance
        if (!$targetInstanceId && $cmsType) {
            $targetInstanceId = self::findInstanceByCms($cmsType);
        }
        
        if (!$targetInstanceId) {
            error_log("[DiSyL CrossInstance] No instance found for query: " . json_encode($attrs));
            return [];
        }
        
        // Determine source instance
        $source = $sourceInstance ?? self::$currentSourceInstance ?? 'unknown';
        
        // === SECURITY CHECK: Authorization ===
        if (class_exists(InstanceAuthorization::class)) {
            $authResult = InstanceAuthorization::authorize($source, $targetInstanceId, $contentType, $limit);
            
            if (!$authResult->isAuthorized()) {
                error_log("[DiSyL CrossInstance] Authorization denied: " . $authResult->getReason());
                return [];
            }
            
            // Adjust limit if needed
            if (isset($authResult->getMetadata()['adjusted_limit'])) {
                $limit = $authResult->getMetadata()['adjusted_limit'];
                $attrs['limit'] = $limit;
            }
        }
        
        // === SECURITY CHECK: Rate Limiting ===
        if (class_exists(RateLimiter::class)) {
            $rateResult = RateLimiter::check($source, $targetInstanceId);
            
            if (!$rateResult->isAllowed()) {
                error_log("[DiSyL CrossInstance] Rate limit exceeded: " . $rateResult->getMessage());
                return [];
            }
            
            // Enforce result limit
            $limit = RateLimiter::enforceResultLimit($limit);
            $attrs['limit'] = $limit;
        }
        
        // Get instance config
        $config = self::getInstanceConfig($targetInstanceId);
        if (!$config) {
            error_log("[DiSyL CrossInstance] Could not load config for instance: {$targetInstanceId}");
            return [];
        }
        
        // Get database connection
        $pdo = self::getConnection($targetInstanceId, $config);
        if (!$pdo) {
            error_log("[DiSyL CrossInstance] Could not connect to database for instance: {$targetInstanceId}");
            return [];
        }
        
        // Execute CMS-specific query
        $cmsType = $config['cms_type'];
        
        switch ($cmsType) {
            case 'wordpress':
                return self::queryWordPress($pdo, $config, $attrs);
            case 'joomla':
                return self::queryJoomla($pdo, $config, $attrs);
            case 'drupal':
                return self::queryDrupal($pdo, $config, $attrs);
            default:
                error_log("[DiSyL CrossInstance] Unsupported CMS type: {$cmsType}");
                return [];
        }
    }
    
    /**
     * Find first instance matching CMS type
     */
    private static function findInstanceByCms(string $cmsType): ?string
    {
        if (!is_dir(self::$instancesPath)) {
            return null;
        }
        
        $instances = scandir(self::$instancesPath);
        foreach ($instances as $instance) {
            if ($instance === '.' || $instance === '..') continue;
            
            $instancePath = self::$instancesPath . '/' . $instance;
            if (!is_dir($instancePath)) continue;
            
            $detectedCms = self::detectCmsType($instancePath);
            if ($detectedCms === $cmsType) {
                return $instance;
            }
        }
        
        return null;
    }
    
    /**
     * Detect CMS type from instance path
     */
    private static function detectCmsType(string $path): string
    {
        if (file_exists($path . '/wp-config.php')) return 'wordpress';
        if (file_exists($path . '/configuration.php')) return 'joomla';
        if (file_exists($path . '/sites/default/settings.php')) return 'drupal';
        return 'native';
    }
    
    /**
     * Get instance configuration (database credentials, etc.)
     */
    private static function getInstanceConfig(string $instanceId): ?array
    {
        if (isset(self::$instanceConfigs[$instanceId])) {
            return self::$instanceConfigs[$instanceId];
        }
        
        $instancePath = self::$instancesPath . '/' . $instanceId;
        if (!is_dir($instancePath)) {
            return null;
        }
        
        $cmsType = self::detectCmsType($instancePath);
        $dbConfig = self::parseDbConfig($instancePath, $cmsType);
        
        if (!$dbConfig) {
            return null;
        }
        
        $config = [
            'id' => $instanceId,
            'path' => $instancePath,
            'cms_type' => $cmsType,
            'db' => $dbConfig
        ];
        
        self::$instanceConfigs[$instanceId] = $config;
        return $config;
    }
    
    /**
     * Parse database configuration from CMS config files
     */
    private static function parseDbConfig(string $instancePath, string $cmsType): ?array
    {
        switch ($cmsType) {
            case 'wordpress':
                return self::parseWordPressConfig($instancePath);
            case 'joomla':
                return self::parseJoomlaConfig($instancePath);
            case 'drupal':
                return self::parseDrupalConfig($instancePath);
            default:
                return null;
        }
    }
    
    /**
     * Parse WordPress wp-config.php
     */
    private static function parseWordPressConfig(string $path): ?array
    {
        $configFile = $path . '/wp-config.php';
        if (!file_exists($configFile)) return null;
        
        $content = file_get_contents($configFile);
        $config = [];
        
        if (preg_match("/define\s*\(\s*['\"]DB_NAME['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['name'] = $m[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_USER['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['user'] = $m[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_PASSWORD['\"]\s*,\s*['\"]([^'\"]*?)['\"]\s*\)/", $content, $m)) {
            $config['pass'] = $m[1];
        }
        if (preg_match("/define\s*\(\s*['\"]DB_HOST['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)/", $content, $m)) {
            $config['host'] = $m[1];
        }
        if (preg_match("/\\\$table_prefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['prefix'] = $m[1];
        }
        
        if (empty($config['name']) || empty($config['user'])) {
            return null;
        }
        
        $config['host'] = $config['host'] ?? 'localhost';
        $config['prefix'] = $config['prefix'] ?? 'wp_';
        
        return $config;
    }
    
    /**
     * Parse Joomla configuration.php
     */
    private static function parseJoomlaConfig(string $path): ?array
    {
        $configFile = $path . '/configuration.php';
        if (!file_exists($configFile)) return null;
        
        $content = file_get_contents($configFile);
        $config = [];
        
        if (preg_match("/public\s+\\\$db\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['name'] = $m[1];
        }
        if (preg_match("/public\s+\\\$user\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['user'] = $m[1];
        }
        if (preg_match("/public\s+\\\$password\s*=\s*['\"]([^'\"]*?)['\"]/", $content, $m)) {
            $config['pass'] = $m[1];
        }
        if (preg_match("/public\s+\\\$host\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['host'] = $m[1];
        }
        if (preg_match("/public\s+\\\$dbprefix\s*=\s*['\"]([^'\"]+)['\"]/", $content, $m)) {
            $config['prefix'] = $m[1];
        }
        
        if (empty($config['name']) || empty($config['user'])) {
            return null;
        }
        
        $config['host'] = $config['host'] ?? 'localhost';
        $config['prefix'] = $config['prefix'] ?? 'jos_';
        
        return $config;
    }
    
    /**
     * Parse Drupal settings.php
     */
    private static function parseDrupalConfig(string $path): ?array
    {
        $settingsFile = $path . '/sites/default/settings.php';
        if (!file_exists($settingsFile)) return null;
        
        $content = file_get_contents($settingsFile);
        
        if (preg_match("/\\\$databases\s*\[\s*['\"]default['\"]\s*\]\s*\[\s*['\"]default['\"]\s*\]\s*=\s*\[([^\]]+)\]/s", $content, $m)) {
            $dbArray = $m[1];
            $config = [];
            
            if (preg_match("/['\"]database['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $dbArray, $dm)) {
                $config['name'] = $dm[1];
            }
            if (preg_match("/['\"]username['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $dbArray, $dm)) {
                $config['user'] = $dm[1];
            }
            if (preg_match("/['\"]password['\"]\s*=>\s*['\"]([^'\"]*?)['\"]/", $dbArray, $dm)) {
                $config['pass'] = $dm[1];
            }
            if (preg_match("/['\"]host['\"]\s*=>\s*['\"]([^'\"]+)['\"]/", $dbArray, $dm)) {
                $config['host'] = $dm[1];
            }
            if (preg_match("/['\"]prefix['\"]\s*=>\s*['\"]([^'\"]*?)['\"]/", $dbArray, $dm)) {
                $config['prefix'] = $dm[1];
            }
            
            if (!empty($config['name']) && !empty($config['user'])) {
                $config['host'] = $config['host'] ?? 'localhost';
                $config['prefix'] = $config['prefix'] ?? '';
                return $config;
            }
        }
        
        return null;
    }
    
    /**
     * Get or create database connection
     */
    private static function getConnection(string $instanceId, array $config): ?PDO
    {
        if (isset(self::$connections[$instanceId])) {
            return self::$connections[$instanceId];
        }
        
        $db = $config['db'];
        
        try {
            $pdo = new PDO(
                "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4",
                $db['user'],
                $db['pass'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
                ]
            );
            
            self::$connections[$instanceId] = $pdo;
            return $pdo;
            
        } catch (PDOException $e) {
            error_log("[DiSyL CrossInstance] Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Query WordPress database
     */
    private static function queryWordPress(PDO $pdo, array $config, array $attrs): array
    {
        $prefix = $config['db']['prefix'];
        $type = $attrs['type'] ?? 'post';
        $limit = (int)($attrs['limit'] ?? 10);
        $orderby = $attrs['orderby'] ?? 'date';
        $order = strtoupper($attrs['order'] ?? 'DESC');
        $category = $attrs['category'] ?? null;
        
        // Map orderby to column
        $orderColumn = match($orderby) {
            'title' => 'post_title',
            'date' => 'post_date',
            'modified' => 'post_modified',
            'id' => 'ID',
            default => 'post_date'
        };
        
        $sql = "SELECT p.*, 
                       u.display_name as author_name,
                       u.user_email as author_email
                FROM {$prefix}posts p
                LEFT JOIN {$prefix}users u ON p.post_author = u.ID
                WHERE p.post_type = :type 
                AND p.post_status = 'publish'";
        
        $params = ['type' => $type];
        
        // Add category filter if specified
        if ($category) {
            $sql .= " AND p.ID IN (
                SELECT tr.object_id FROM {$prefix}term_relationships tr
                INNER JOIN {$prefix}term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
                INNER JOIN {$prefix}terms t ON tt.term_id = t.term_id
                WHERE tt.taxonomy = 'category' AND t.slug = :category
            )";
            $params['category'] = $category;
        }
        
        $sql .= " ORDER BY p.{$orderColumn} {$order} LIMIT {$limit}";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $posts = $stmt->fetchAll();
            
            // Normalize to common field names
            return array_map(function($post) use ($prefix, $pdo, $config) {
                return [
                    // Common fields (work across CMS)
                    'id' => $post['ID'],
                    'title' => $post['post_title'],
                    'content' => $post['post_content'],
                    'excerpt' => $post['post_excerpt'] ?: wp_trim_words($post['post_content'], 55),
                    'date' => $post['post_date'],
                    'modified' => $post['post_modified'],
                    'author' => $post['author_name'],
                    'slug' => $post['post_name'],
                    'status' => $post['post_status'],
                    'type' => $post['post_type'],
                    
                    // WordPress-specific (aliased for compatibility)
                    'post' => [
                        'ID' => $post['ID'],
                        'title' => $post['post_title'],
                        'content' => $post['post_content'],
                        'excerpt' => $post['post_excerpt'],
                        'date' => $post['post_date'],
                        'modified' => $post['post_modified'],
                        'author' => $post['author_name'],
                        'permalink' => self::buildWordPressPermalink($post, $config),
                        'thumbnail' => self::getWordPressThumbnail($pdo, $prefix, $post['ID']),
                    ],
                    
                    // Source info
                    '_source' => [
                        'instance' => $config['id'],
                        'cms' => 'wordpress'
                    ]
                ];
            }, $posts);
            
        } catch (PDOException $e) {
            error_log("[DiSyL CrossInstance] WordPress query failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query Joomla database
     */
    private static function queryJoomla(PDO $pdo, array $config, array $attrs): array
    {
        $prefix = $config['db']['prefix'];
        $type = $attrs['type'] ?? 'article';
        $limit = (int)($attrs['limit'] ?? 10);
        $orderby = $attrs['orderby'] ?? 'created';
        $order = strtoupper($attrs['order'] ?? 'DESC');
        $category = $attrs['category'] ?? null;
        
        // Map orderby to column
        $orderColumn = match($orderby) {
            'title' => 'a.title',
            'date', 'created' => 'a.created',
            'modified' => 'a.modified',
            'hits' => 'a.hits',
            'ordering' => 'a.ordering',
            default => 'a.created'
        };
        
        $sql = "SELECT a.*, 
                       c.title as category_title,
                       c.alias as category_alias,
                       u.name as author_name
                FROM {$prefix}content a
                LEFT JOIN {$prefix}categories c ON a.catid = c.id
                LEFT JOIN {$prefix}users u ON a.created_by = u.id
                WHERE a.state = 1";
        
        $params = [];
        
        // Add category filter
        if ($category) {
            $sql .= " AND (c.alias = :category OR c.title = :category_title)";
            $params['category'] = $category;
            $params['category_title'] = $category;
        }
        
        $sql .= " ORDER BY {$orderColumn} {$order} LIMIT {$limit}";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $articles = $stmt->fetchAll();
            
            // Normalize to common field names
            return array_map(function($article) use ($config) {
                return [
                    // Common fields
                    'id' => $article['id'],
                    'title' => $article['title'],
                    'content' => $article['introtext'] . $article['fulltext'],
                    'excerpt' => $article['introtext'],
                    'date' => $article['created'],
                    'modified' => $article['modified'],
                    'author' => $article['author_name'],
                    'slug' => $article['alias'],
                    'status' => $article['state'] == 1 ? 'publish' : 'draft',
                    'type' => 'article',
                    'category' => $article['category_title'],
                    'hits' => $article['hits'],
                    
                    // Joomla-specific (aliased for compatibility)
                    'article' => [
                        'id' => $article['id'],
                        'title' => $article['title'],
                        'introtext' => $article['introtext'],
                        'fulltext' => $article['fulltext'],
                        'alias' => $article['alias'],
                        'created' => $article['created'],
                        'modified' => $article['modified'],
                        'publish_up' => $article['publish_up'],
                        'author' => $article['author_name'],
                        'category' => $article['category_title'],
                        'hits' => $article['hits'],
                        'images' => json_decode($article['images'] ?? '{}', true),
                        'urls' => json_decode($article['urls'] ?? '{}', true),
                    ],
                    
                    // Source info
                    '_source' => [
                        'instance' => $config['id'],
                        'cms' => 'joomla'
                    ]
                ];
            }, $articles);
            
        } catch (PDOException $e) {
            error_log("[DiSyL CrossInstance] Joomla query failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Query Drupal database
     */
    private static function queryDrupal(PDO $pdo, array $config, array $attrs): array
    {
        $prefix = $config['db']['prefix'];
        $type = $attrs['type'] ?? 'article';
        $limit = (int)($attrs['limit'] ?? 10);
        $orderby = $attrs['orderby'] ?? 'created';
        $order = strtoupper($attrs['order'] ?? 'DESC');
        
        // Map orderby to column
        $orderColumn = match($orderby) {
            'title' => 'nfd.title',
            'date', 'created' => 'n.created',
            'changed', 'modified' => 'n.changed',
            default => 'n.created'
        };
        
        // Drupal 8+ uses different table structure
        $sql = "SELECT n.nid, n.type, n.created, n.changed, n.status,
                       nfd.title, nfd.uid,
                       ufd.name as author_name
                FROM {$prefix}node n
                INNER JOIN {$prefix}node_field_data nfd ON n.nid = nfd.nid
                LEFT JOIN {$prefix}users_field_data ufd ON nfd.uid = ufd.uid
                WHERE n.type = :type AND nfd.status = 1
                ORDER BY {$orderColumn} {$order}
                LIMIT {$limit}";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['type' => $type]);
            $nodes = $stmt->fetchAll();
            
            // Normalize to common field names
            return array_map(function($node) use ($pdo, $prefix, $config) {
                // Get body field
                $body = self::getDrupalFieldValue($pdo, $prefix, $node['nid'], 'body');
                
                return [
                    // Common fields
                    'id' => $node['nid'],
                    'title' => $node['title'],
                    'content' => $body['value'] ?? '',
                    'excerpt' => $body['summary'] ?? substr($body['value'] ?? '', 0, 300),
                    'date' => date('Y-m-d H:i:s', $node['created']),
                    'modified' => date('Y-m-d H:i:s', $node['changed']),
                    'author' => $node['author_name'],
                    'status' => $node['status'] == 1 ? 'publish' : 'draft',
                    'type' => $node['type'],
                    
                    // Drupal-specific
                    'node' => [
                        'nid' => $node['nid'],
                        'title' => $node['title'],
                        'body' => $body['value'] ?? '',
                        'type' => $node['type'],
                        'created' => $node['created'],
                        'changed' => $node['changed'],
                        'author' => $node['author_name'],
                        'status' => $node['status'],
                    ],
                    
                    // Source info
                    '_source' => [
                        'instance' => $config['id'],
                        'cms' => 'drupal'
                    ]
                ];
            }, $nodes);
            
        } catch (PDOException $e) {
            error_log("[DiSyL CrossInstance] Drupal query failed: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Get Drupal field value
     */
    private static function getDrupalFieldValue(PDO $pdo, string $prefix, int $nid, string $field): array
    {
        try {
            $table = "{$prefix}node__{$field}";
            $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE entity_id = :nid LIMIT 1");
            $stmt->execute(['nid' => $nid]);
            $row = $stmt->fetch();
            
            if ($row) {
                return [
                    'value' => $row["{$field}_value"] ?? '',
                    'summary' => $row["{$field}_summary"] ?? '',
                    'format' => $row["{$field}_format"] ?? 'basic_html'
                ];
            }
        } catch (PDOException $e) {
            // Field table might not exist
        }
        
        return [];
    }
    
    /**
     * Build WordPress permalink (simplified)
     */
    private static function buildWordPressPermalink(array $post, array $config): string
    {
        // This is a simplified version - real implementation would need site URL
        return "/{$post['post_name']}/";
    }
    
    /**
     * Get WordPress thumbnail URL
     */
    private static function getWordPressThumbnail(PDO $pdo, string $prefix, int $postId): ?string
    {
        try {
            // Get thumbnail ID from post meta
            $stmt = $pdo->prepare("SELECT meta_value FROM {$prefix}postmeta WHERE post_id = :id AND meta_key = '_thumbnail_id'");
            $stmt->execute(['id' => $postId]);
            $thumbnailId = $stmt->fetchColumn();
            
            if ($thumbnailId) {
                // Get attachment URL
                $stmt = $pdo->prepare("SELECT guid FROM {$prefix}posts WHERE ID = :id");
                $stmt->execute(['id' => $thumbnailId]);
                return $stmt->fetchColumn() ?: null;
            }
        } catch (PDOException $e) {
            // Ignore errors
        }
        
        return null;
    }
    
    /**
     * Clear all cached connections and configs
     */
    public static function clearCache(): void
    {
        self::$connections = [];
        self::$instanceConfigs = [];
    }
}
