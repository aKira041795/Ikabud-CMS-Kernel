<?php
/**
 * Syscall Handlers
 * 
 * Real implementations of kernel syscalls
 */

namespace IkabudKernel\Core;

use PDO;
use Exception;

class SyscallHandlers
{
    private PDO $db;
    private Cache $cache;
    
    private ImageOptimizer $imageOptimizer;
    
    public function __construct(PDO $db, Cache $cache)
    {
        $this->db = $db;
        $this->cache = $cache;
        $this->imageOptimizer = new ImageOptimizer();
    }
    
    /**
     * Content Fetch Syscall
     * Fetches content from cache or database
     */
    public function contentFetch(array $args): array
    {
        $instanceId = $args['instance_id'] ?? null;
        $postId = $args['post_id'] ?? null;
        $postType = $args['post_type'] ?? 'post';
        
        if (!$instanceId) {
            throw new Exception("Missing instance_id");
        }
        
        // Try cache first
        $cacheKey = "content_{$instanceId}_{$postType}_{$postId}";
        if ($cached = $this->cache->get($instanceId, $cacheKey)) {
            return $cached;
        }
        
        // Fetch from database
        if ($postId) {
            $content = $this->fetchSinglePost($instanceId, $postId);
        } else {
            $content = $this->fetchPosts($instanceId, $args);
        }
        
        // Cache result
        if ($content) {
            $this->cache->set($instanceId, $cacheKey, $content, 3600);
        }
        
        return $content;
    }
    
    /**
     * Fetch single post
     */
    private function fetchSinglePost(string $instanceId, int $postId): array
    {
        // Get instance database info
        $instance = $this->getInstanceInfo($instanceId);
        if (!$instance) {
            throw new Exception("Instance not found: {$instanceId}");
        }
        
        $dbName = $instance['database_name'];
        $prefix = $instance['database_prefix'] ?? '';
        
        $stmt = $this->db->prepare("
            SELECT * FROM {$dbName}.{$prefix}posts 
            WHERE ID = ? AND post_status = 'publish'
            LIMIT 1
        ");
        $stmt->execute([$postId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Fetch multiple posts
     */
    private function fetchPosts(string $instanceId, array $args): array
    {
        $instance = $this->getInstanceInfo($instanceId);
        if (!$instance) {
            throw new Exception("Instance not found: {$instanceId}");
        }
        
        $dbName = $instance['database_name'];
        $prefix = $instance['database_prefix'] ?? '';
        
        $limit = $args['limit'] ?? 10;
        $offset = $args['offset'] ?? 0;
        $postType = $args['post_type'] ?? 'post';
        
        $stmt = $this->db->prepare("
            SELECT * FROM {$dbName}.{$prefix}posts 
            WHERE post_type = ? AND post_status = 'publish'
            ORDER BY post_date DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute([$postType, $limit, $offset]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Content Create Syscall
     */
    public function contentCreate(array $args): int
    {
        $instanceId = $args['instance_id'] ?? null;
        $content = $args['content'] ?? [];
        
        if (!$instanceId || !$content) {
            throw new Exception("Missing required arguments");
        }
        
        $instance = $this->getInstanceInfo($instanceId);
        $dbName = $instance['database_name'];
        $prefix = $instance['database_prefix'] ?? '';
        
        $stmt = $this->db->prepare("
            INSERT INTO {$dbName}.{$prefix}posts 
            (post_title, post_content, post_status, post_type, post_author, post_date)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $content['title'] ?? 'Untitled',
            $content['body'] ?? '',
            $content['status'] ?? 'draft',
            $content['type'] ?? 'post',
            $content['author_id'] ?? 1
        ]);
        
        $postId = (int)$this->db->lastInsertId();
        
        // Clear cache
        $this->cache->clearByTag($instanceId, 'content');
        
        return $postId;
    }
    
    /**
     * Content Update Syscall
     */
    public function contentUpdate(array $args): bool
    {
        $instanceId = $args['instance_id'] ?? null;
        $postId = $args['post_id'] ?? null;
        $content = $args['content'] ?? [];
        
        if (!$instanceId || !$postId) {
            throw new Exception("Missing required arguments");
        }
        
        $instance = $this->getInstanceInfo($instanceId);
        $dbName = $instance['database_name'];
        $prefix = $instance['database_prefix'] ?? '';
        
        $updates = [];
        $params = [];
        
        if (isset($content['title'])) {
            $updates[] = "post_title = ?";
            $params[] = $content['title'];
        }
        
        if (isset($content['body'])) {
            $updates[] = "post_content = ?";
            $params[] = $content['body'];
        }
        
        if (isset($content['status'])) {
            $updates[] = "post_status = ?";
            $params[] = $content['status'];
        }
        
        if (empty($updates)) {
            return true;
        }
        
        $updates[] = "post_modified = NOW()";
        $params[] = $postId;
        
        $sql = "UPDATE {$dbName}.{$prefix}posts SET " . implode(', ', $updates) . " WHERE ID = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        // Clear cache
        $this->cache->clear($instanceId, "content_{$instanceId}_post_{$postId}");
        $this->cache->clearByTag($instanceId, 'content');
        
        return true;
    }
    
    /**
     * Content Delete Syscall
     */
    public function contentDelete(array $args): bool
    {
        $instanceId = $args['instance_id'] ?? null;
        $postId = $args['post_id'] ?? null;
        
        if (!$instanceId || !$postId) {
            throw new Exception("Missing required arguments");
        }
        
        $instance = $this->getInstanceInfo($instanceId);
        $dbName = $instance['database_name'];
        $prefix = $instance['database_prefix'] ?? '';
        
        $stmt = $this->db->prepare("
            DELETE FROM {$dbName}.{$prefix}posts WHERE ID = ?
        ");
        $stmt->execute([$postId]);
        
        // Clear cache
        $this->cache->clear($instanceId, "content_{$instanceId}_post_{$postId}");
        $this->cache->clearByTag($instanceId, 'content');
        
        return true;
    }
    
    /**
     * Database Query Syscall
     */
    public function dbQuery(array $args): array
    {
        $query = $args['query'] ?? null;
        $params = $args['params'] ?? [];
        
        if (!$query) {
            throw new Exception("Missing query");
        }
        
        $stmt = $this->db->prepare($query);
        $stmt->execute($params);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Database Insert Syscall
     */
    public function dbInsert(array $args): int
    {
        $table = $args['table'] ?? null;
        $data = $args['data'] ?? [];
        
        if (!$table || empty($data)) {
            throw new Exception("Missing table or data");
        }
        
        $columns = array_keys($data);
        $placeholders = array_fill(0, count($columns), '?');
        
        $sql = "INSERT INTO {$table} (" . implode(', ', $columns) . ") VALUES (" . implode(', ', $placeholders) . ")";
        $stmt = $this->db->prepare($sql);
        $stmt->execute(array_values($data));
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * HTTP GET Syscall
     */
    public function httpGet(array $args): string
    {
        $url = $args['url'] ?? null;
        $headers = $args['headers'] ?? [];
        $timeout = $args['timeout'] ?? 30;
        
        if (!$url) {
            throw new Exception("Missing URL");
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("HTTP request failed: {$error}");
        }
        
        return $response;
    }
    
    /**
     * HTTP POST Syscall
     */
    public function httpPost(array $args): string
    {
        $url = $args['url'] ?? null;
        $data = $args['data'] ?? [];
        $headers = $args['headers'] ?? [];
        $timeout = $args['timeout'] ?? 30;
        
        if (!$url) {
            throw new Exception("Missing URL");
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        
        $defaultHeaders = ['Content-Type: application/json'];
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge($defaultHeaders, $headers));
        
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("HTTP request failed: {$error}");
        }
        
        return $response;
    }
    
    /**
     * Asset Enqueue Syscall
     */
    public function assetEnqueue(array $args): void
    {
        $handle = $args['handle'] ?? null;
        $src = $args['src'] ?? null;
        $type = $args['type'] ?? 'script';
        
        if (!$handle || !$src) {
            throw new Exception("Missing handle or src");
        }
        
        // Store in global assets queue
        global $ikabud_assets_queue;
        if (!isset($ikabud_assets_queue)) {
            $ikabud_assets_queue = [];
        }
        
        $ikabud_assets_queue[] = [
            'handle' => $handle,
            'src' => $src,
            'type' => $type,
            'deps' => $args['deps'] ?? [],
            'version' => $args['version'] ?? '1.0'
        ];
    }
    
    /**
     * Theme Render Syscall
     */
    public function themeRender(array $args): string
    {
        $template = $args['template'] ?? null;
        $data = $args['data'] ?? [];
        
        if (!$template) {
            throw new Exception("Missing template");
        }
        
        // Extract data for template
        extract($data);
        
        // Start output buffering
        ob_start();
        
        // Include template file
        $templatePath = dirname(__DIR__) . "/templates/{$template}.php";
        if (file_exists($templatePath)) {
            include $templatePath;
        } else {
            throw new Exception("Template not found: {$template}");
        }
        
        return ob_get_clean();
    }
    
    /**
     * Image Optimize Syscall
     */
    public function imageOptimize(array $args): array
    {
        $imagePath = $args['path'] ?? null;
        $options = $args['options'] ?? [];
        
        if (!$imagePath) {
            throw new Exception("Missing image path");
        }
        
        return $this->imageOptimizer->optimize($imagePath, $options);
    }
    
    /**
     * Image Generate Responsive Syscall
     */
    public function imageResponsive(array $args): array
    {
        $imagePath = $args['path'] ?? null;
        
        if (!$imagePath) {
            throw new Exception("Missing image path");
        }
        
        return $this->imageOptimizer->generateResponsive($imagePath);
    }
    
    /**
     * Image Generate Picture Tag Syscall
     */
    public function imagePictureTag(array $args): string
    {
        $imagePath = $args['path'] ?? null;
        $alt = $args['alt'] ?? '';
        $attributes = $args['attributes'] ?? [];
        
        if (!$imagePath) {
            throw new Exception("Missing image path");
        }
        
        return $this->imageOptimizer->generatePictureTag($imagePath, $alt, $attributes);
    }
    
    /**
     * Get instance info from database
     */
    private function getInstanceInfo(string $instanceId): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM instances WHERE instance_id = ? LIMIT 1
        ");
        $stmt->execute([$instanceId]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
