<?php
/**
 * Drupal Adapter - Drupal CMS Integration
 * 
 * Boots Drupal from shared-cores as a supervised userland process
 * Provides isolation and implements CMSInterface
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS\Adapters;

use IkabudKernel\CMS\CMSInterface;
use Exception;

class DrupalAdapter implements CMSInterface
{
    private string $instanceId;
    private array $config = [];
    private bool $initialized = false;
    private bool $booted = false;
    private array $data = [];
    private string $corePath;
    private float $bootStartTime;
    private $kernel;
    
    /**
     * Constructor
     * 
     * @param string $corePath Path to Drupal core
     */
    public function __construct(string $corePath = null)
    {
        $this->corePath = $corePath ?? __DIR__ . '/../../shared-cores/drupal';
    }
    
    /**
     * Initialize Drupal environment
     */
    public function initialize(array $config): void
    {
        if ($this->initialized) {
            return;
        }
        
        $this->config = $config;
        $this->instanceId = $config['instance_id'] ?? 'default';
        
        // Set Drupal root
        if (!defined('DRUPAL_ROOT')) {
            define('DRUPAL_ROOT', $this->corePath);
        }
        
        // Set instance-specific sites directory
        $instancePath = __DIR__ . '/../../instances/' . $this->instanceId;
        
        // Create instance directories if needed
        $this->createInstanceDirectories($instancePath);
        
        $this->initialized = true;
    }
    
    /**
     * Boot Drupal
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        
        if (!$this->initialized) {
            throw new Exception('Drupal must be initialized before booting');
        }
        
        $this->bootStartTime = microtime(true);
        
        try {
            // Load Drupal autoloader
            $autoloader = require_once DRUPAL_ROOT . '/autoload.php';
            
            // Create Drupal kernel
            $request = \Symfony\Component\HttpFoundation\Request::createFromGlobals();
            
            // Use DrupalKernel
            $this->kernel = \Drupal\Core\DrupalKernel::createFromRequest(
                $request,
                $autoloader,
                'prod'
            );
            
            // Boot the kernel
            $this->kernel->boot();
            
            $this->booted = true;
            
        } catch (Exception $e) {
            throw new Exception('Failed to boot Drupal: ' . $e->getMessage());
        }
    }
    
    /**
     * Shutdown Drupal
     */
    public function shutdown(): void
    {
        if (!$this->booted) {
            return;
        }
        
        if ($this->kernel) {
            $this->kernel->shutdown();
        }
        
        $this->booted = false;
    }
    
    /**
     * Execute a query
     */
    public function executeQuery(array $query): array
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted before executing queries');
        }
        
        $type = $query['type'] ?? 'node';
        $limit = $query['limit'] ?? 10;
        
        // Get entity type manager
        $entityTypeManager = \Drupal::entityTypeManager();
        $storage = $entityTypeManager->getStorage($type);
        
        // Build query
        $entityQuery = $storage->getQuery()
            ->accessCheck(true)
            ->range(0, $limit);
        
        // Add filters
        if (isset($query['bundle'])) {
            $entityQuery->condition('type', $query['bundle']);
        }
        
        if (isset($query['status'])) {
            $entityQuery->condition('status', $query['status']);
        } else {
            $entityQuery->condition('status', 1); // Published by default
        }
        
        if (isset($query['orderby'])) {
            $order = $query['order'] ?? 'DESC';
            $entityQuery->sort($query['orderby'], $order);
        }
        
        // Execute query
        $ids = $entityQuery->execute();
        
        if (empty($ids)) {
            return [];
        }
        
        // Load entities
        $entities = $storage->loadMultiple($ids);
        
        $results = [];
        foreach ($entities as $entity) {
            $results[] = $this->entityToArray($entity);
        }
        
        return $results;
    }
    
    /**
     * Get content by ID
     */
    public function getContent(string $type, int $id): ?array
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted');
        }
        
        try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage($type);
            $entity = $storage->load($id);
            
            if (!$entity) {
                return null;
            }
            
            return $this->entityToArray($entity);
            
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Create content
     */
    public function createContent(string $type, array $data): int
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted');
        }
        
        try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage($type);
            
            $entity = $storage->create($data);
            $entity->save();
            
            return (int) $entity->id();
            
        } catch (Exception $e) {
            throw new Exception('Failed to create content: ' . $e->getMessage());
        }
    }
    
    /**
     * Update content
     */
    public function updateContent(string $type, int $id, array $data): bool
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted');
        }
        
        try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage($type);
            $entity = $storage->load($id);
            
            if (!$entity) {
                return false;
            }
            
            foreach ($data as $field => $value) {
                if ($entity->hasField($field)) {
                    $entity->set($field, $value);
                }
            }
            
            $entity->save();
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Delete content
     */
    public function deleteContent(string $type, int $id): bool
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted');
        }
        
        try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage($type);
            $entity = $storage->load($id);
            
            if (!$entity) {
                return false;
            }
            
            $entity->delete();
            return true;
            
        } catch (Exception $e) {
            return false;
        }
    }
    
    /**
     * Get categories (taxonomy terms)
     */
    public function getCategories(string $taxonomy = 'tags'): array
    {
        if (!$this->booted) {
            throw new Exception('Drupal must be booted');
        }
        
        try {
            $entityTypeManager = \Drupal::entityTypeManager();
            $storage = $entityTypeManager->getStorage('taxonomy_term');
            
            $query = $storage->getQuery()
                ->accessCheck(true)
                ->condition('vid', $taxonomy);
            
            $ids = $query->execute();
            
            if (empty($ids)) {
                return [];
            }
            
            $terms = $storage->loadMultiple($ids);
            
            $result = [];
            foreach ($terms as $term) {
                $result[] = [
                    'id' => $term->id(),
                    'name' => $term->getName(),
                    'description' => $term->getDescription(),
                    'weight' => $term->getWeight(),
                ];
            }
            
            return $result;
            
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Handle route
     */
    public function handleRoute(string $path, string $method = 'GET'): string
    {
        if (!$this->booted) {
            $this->boot();
        }
        
        try {
            // Create request
            $request = \Symfony\Component\HttpFoundation\Request::create($path, $method);
            
            // Handle request
            $response = $this->kernel->handle($request);
            
            // Get content
            $content = $response->getContent();
            
            // Terminate kernel
            $this->kernel->terminate($request, $response);
            
            return $content;
            
        } catch (Exception $e) {
            return 'Error: ' . $e->getMessage();
        }
    }
    
    /**
     * Get database configuration
     */
    public function getDatabaseConfig(): array
    {
        if (!$this->booted) {
            return [];
        }
        
        $database = \Drupal\Core\Database\Database::getConnectionInfo();
        
        if (isset($database['default'])) {
            return [
                'host' => $database['default']['host'] ?? 'localhost',
                'name' => $database['default']['database'] ?? '',
                'user' => $database['default']['username'] ?? '',
                'driver' => $database['default']['driver'] ?? 'mysql',
                'prefix' => $database['default']['prefix'] ?? '',
            ];
        }
        
        return [];
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
     * Get Drupal version
     */
    public function getVersion(): string
    {
        if ($this->booted) {
            return \Drupal::VERSION;
        }
        
        return 'unknown';
    }
    
    /**
     * Get CMS type
     */
    public function getType(): string
    {
        return 'drupal';
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
     * Convert Drupal entity to array
     */
    private function entityToArray($entity): array
    {
        $data = [
            'id' => $entity->id(),
            'type' => $entity->getEntityTypeId(),
        ];
        
        // Add common fields if they exist
        if ($entity->hasField('title')) {
            $data['title'] = $entity->get('title')->value;
        }
        
        if ($entity->hasField('body')) {
            $bodyField = $entity->get('body');
            if (!$bodyField->isEmpty()) {
                $data['content'] = $bodyField->value;
                $data['summary'] = $bodyField->summary ?? '';
            }
        }
        
        if ($entity->hasField('created')) {
            $data['created'] = date('Y-m-d H:i:s', $entity->get('created')->value);
        }
        
        if ($entity->hasField('changed')) {
            $data['modified'] = date('Y-m-d H:i:s', $entity->get('changed')->value);
        }
        
        if ($entity->hasField('status')) {
            $data['status'] = $entity->get('status')->value;
        }
        
        if ($entity->hasField('uid')) {
            $data['author'] = $entity->get('uid')->target_id;
        }
        
        if (method_exists($entity, 'toUrl')) {
            try {
                $data['url'] = $entity->toUrl()->toString();
            } catch (Exception $e) {
                $data['url'] = '';
            }
        }
        
        return $data;
    }
    
    /**
     * Create instance directories
     */
    private function createInstanceDirectories(string $path): void
    {
        $dirs = [
            $path,
            $path . '/sites',
            $path . '/sites/default',
            $path . '/sites/default/files',
            $path . '/sites/default/modules',
            $path . '/sites/default/themes',
        ];
        
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }
}
