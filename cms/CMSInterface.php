<?php
/**
 * CMS Interface - Contract for all CMS adapters
 * 
 * All CMS (WordPress, Joomla, Drupal, Native) must implement this interface
 * to be supervised by the Ikabud Kernel as userland processes.
 * 
 * @version 1.0.0
 */

namespace IkabudKernel\CMS;

interface CMSInterface
{
    /**
     * Initialize the CMS environment
     * 
     * Sets up constants, paths, and basic configuration
     * Does NOT boot the CMS yet
     * 
     * @param array $config Instance configuration
     * @return void
     * @throws \Exception if initialization fails
     */
    public function initialize(array $config): void;
    
    /**
     * Boot the CMS
     * 
     * Loads the CMS core, plugins, themes, etc.
     * This is where the CMS actually starts running
     * 
     * @return void
     * @throws \Exception if boot fails
     */
    public function boot(): void;
    
    /**
     * Shutdown the CMS cleanly
     * 
     * Cleanup, close connections, save state
     * 
     * @return void
     */
    public function shutdown(): void;
    
    /**
     * Execute a query in the CMS context
     * 
     * @param array $query Query parameters (type, limit, filters, etc.)
     * @return array Query results
     */
    public function executeQuery(array $query): array;
    
    /**
     * Get content by ID
     * 
     * @param string $type Content type (post, page, article, etc.)
     * @param int $id Content ID
     * @return array|null Content data or null if not found
     */
    public function getContent(string $type, int $id): ?array;
    
    /**
     * Create content
     * 
     * @param string $type Content type
     * @param array $data Content data
     * @return int Created content ID
     */
    public function createContent(string $type, array $data): int;
    
    /**
     * Update content
     * 
     * @param string $type Content type
     * @param int $id Content ID
     * @param array $data Updated data
     * @return bool Success status
     */
    public function updateContent(string $type, int $id, array $data): bool;
    
    /**
     * Delete content
     * 
     * @param string $type Content type
     * @param int $id Content ID
     * @return bool Success status
     */
    public function deleteContent(string $type, int $id): bool;
    
    /**
     * Get categories/taxonomies
     * 
     * @param string $taxonomy Taxonomy name (category, tag, etc.)
     * @return array List of terms
     */
    public function getCategories(string $taxonomy = 'category'): array;
    
    /**
     * Handle a route request
     * 
     * Process the current HTTP request and return response
     * 
     * @param string $path Request path
     * @param string $method HTTP method
     * @return string Response HTML
     */
    public function handleRoute(string $path, string $method = 'GET'): string;
    
    /**
     * Get database configuration
     * 
     * @return array Database config (host, name, user, etc.)
     */
    public function getDatabaseConfig(): array;
    
    /**
     * Get resource usage statistics
     * 
     * @return array Resource usage (memory, CPU, queries, etc.)
     */
    public function getResourceUsage(): array;
    
    /**
     * Get CMS version
     * 
     * @return string Version number
     */
    public function getVersion(): string;
    
    /**
     * Get CMS type
     * 
     * @return string CMS type (wordpress, joomla, drupal, native)
     */
    public function getType(): string;
    
    /**
     * Check if CMS is initialized
     * 
     * @return bool Initialization status
     */
    public function isInitialized(): bool;
    
    /**
     * Check if CMS is booted
     * 
     * @return bool Boot status
     */
    public function isBooted(): bool;
    
    /**
     * Get instance ID
     * 
     * @return string Instance identifier
     */
    public function getInstanceId(): string;
    
    /**
     * Set instance ID
     * 
     * @param string $instanceId Instance identifier
     * @return void
     */
    public function setInstanceId(string $instanceId): void;
    
    /**
     * Get CMS-specific data
     * 
     * For custom CMS features not covered by standard interface
     * 
     * @param string $key Data key
     * @return mixed Data value
     */
    public function getData(string $key): mixed;
    
    /**
     * Set CMS-specific data
     * 
     * @param string $key Data key
     * @param mixed $value Data value
     * @return void
     */
    public function setData(string $key, mixed $value): void;
    
    /**
     * Render DiSyL AST to HTML
     * 
     * Converts a compiled DiSyL Abstract Syntax Tree into rendered HTML
     * using CMS-specific rendering logic
     * 
     * @param array $ast Compiled DiSyL AST from Compiler
     * @param array $context Rendering context (variables, data, etc.)
     * @return string Rendered HTML
     * @throws \Exception if rendering fails
     */
    public function renderDisyl(array $ast, array $context = []): string;
}
