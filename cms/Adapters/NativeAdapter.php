<?php
/**
 * Native Adapter - Pure Ikabud CMS
 * 
 * Lightweight CMS without external dependencies
 * Directly uses kernel syscalls for all operations
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS\Adapters;

use IkabudKernel\CMS\CMSInterface;
use IkabudKernel\Core\Kernel;
use PDO;
use Exception;

class NativeAdapter implements CMSInterface
{
    private string $instanceId;
    private array $config = [];
    private bool $initialized = false;
    private bool $booted = false;
    private array $data = [];
    private PDO $db;
    private float $bootStartTime;
    
    /**
     * Initialize Native CMS
     */
    public function initialize(array $config): void
    {
        if ($this->initialized) {
            return;
        }
        
        $this->config = $config;
        
        // Get database connection from kernel
        $kernel = Kernel::getInstance();
        $this->db = $kernel->getDatabase();
        
        $this->initialized = true;
    }
    
    /**
     * Boot Native CMS
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        
        if (!$this->initialized) {
            throw new Exception('Native CMS must be initialized before booting');
        }
        
        $this->bootStartTime = microtime(true);
        
        // Native CMS is always ready - no external dependencies
        $this->booted = true;
    }
    
    /**
     * Shutdown
     */
    public function shutdown(): void
    {
        $this->booted = false;
    }
    
    /**
     * Execute query using kernel syscalls
     */
    public function executeQuery(array $query): array
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        // Use kernel syscall
        return Kernel::syscall('content.fetch', $query);
    }
    
    /**
     * Get content by ID
     */
    public function getContent(string $type, int $id): ?array
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        // Query from native tables (to be implemented)
        $stmt = $this->db->prepare("
            SELECT * FROM native_content
            WHERE id = ? AND type = ?
        ");
        $stmt->execute([$id, $type]);
        
        return $stmt->fetch() ?: null;
    }
    
    /**
     * Create content
     */
    public function createContent(string $type, array $data): int
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO native_content (type, title, content, status, created_at)
            VALUES (?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $type,
            $data['title'] ?? '',
            $data['content'] ?? '',
            $data['status'] ?? 'published'
        ]);
        
        return (int) $this->db->lastInsertId();
    }
    
    /**
     * Update content
     */
    public function updateContent(string $type, int $id, array $data): bool
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        $updates = [];
        $params = [];
        
        if (isset($data['title'])) {
            $updates[] = 'title = ?';
            $params[] = $data['title'];
        }
        if (isset($data['content'])) {
            $updates[] = 'content = ?';
            $params[] = $data['content'];
        }
        if (isset($data['status'])) {
            $updates[] = 'status = ?';
            $params[] = $data['status'];
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $id;
        $params[] = $type;
        
        $stmt = $this->db->prepare("
            UPDATE native_content 
            SET " . implode(', ', $updates) . ", updated_at = NOW()
            WHERE id = ? AND type = ?
        ");
        
        $stmt->execute($params);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Delete content
     */
    public function deleteContent(string $type, int $id): bool
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        $stmt = $this->db->prepare("
            DELETE FROM native_content
            WHERE id = ? AND type = ?
        ");
        
        $stmt->execute([$id, $type]);
        
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Get categories
     */
    public function getCategories(string $taxonomy = 'category'): array
    {
        if (!$this->booted) {
            throw new Exception('Native CMS must be booted');
        }
        
        $stmt = $this->db->prepare("
            SELECT * FROM native_categories
            WHERE taxonomy = ?
            ORDER BY name
        ");
        $stmt->execute([$taxonomy]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Handle route
     */
    public function handleRoute(string $path, string $method = 'GET'): string
    {
        if (!$this->booted) {
            $this->boot();
        }
        
        // Simple routing for native CMS
        if ($path === '/' || $path === '') {
            return $this->renderHomepage();
        }
        
        // Check if it's a post/page
        $slug = trim($path, '/');
        $content = $this->getContentBySlug($slug);
        
        if ($content) {
            return $this->renderContent($content);
        }
        
        return $this->render404();
    }
    
    /**
     * Get database configuration
     */
    public function getDatabaseConfig(): array
    {
        return [
            'host' => $_ENV['DB_HOST'],
            'name' => $_ENV['DB_DATABASE'],
            'user' => $_ENV['DB_USERNAME'],
            'prefix' => 'native_'
        ];
    }
    
    /**
     * Get resource usage
     */
    public function getResourceUsage(): array
    {
        return [
            'memory' => memory_get_usage(),
            'memory_peak' => memory_get_peak_usage(),
            'boot_time' => $this->bootStartTime ? (microtime(true) - $this->bootStartTime) * 1000 : 0
        ];
    }
    
    /**
     * Get version
     */
    public function getVersion(): string
    {
        return '1.0.0';
    }
    
    /**
     * Get type
     */
    public function getType(): string
    {
        return 'native';
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
    
    private function getContentBySlug(string $slug): ?array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM native_content
            WHERE slug = ? AND status = 'published'
        ");
        $stmt->execute([$slug]);
        
        return $stmt->fetch() ?: null;
    }
    
    private function renderHomepage(): string
    {
        return '<html><body><h1>Native Ikabud CMS</h1><p>Homepage</p></body></html>';
    }
    
    private function renderContent(array $content): string
    {
        return sprintf(
            '<html><body><h1>%s</h1><div>%s</div></body></html>',
            htmlspecialchars($content['title']),
            $content['content']
        );
    }
    
    private function render404(): string
    {
        return '<html><body><h1>404 Not Found</h1></body></html>';
    }
    
    /**
     * Render DiSyL AST to HTML
     */
    public function renderDisyl(array $ast, array $context = []): string
    {
        require_once __DIR__ . '/../../kernel/DiSyL/Renderers/BaseRenderer.php';
        require_once __DIR__ . '/../../kernel/DiSyL/Renderers/NativeRenderer.php';
        
        $renderer = new \IkabudKernel\Core\DiSyL\Renderers\NativeRenderer($this);
        return $renderer->render($ast, $context);
    }
}
