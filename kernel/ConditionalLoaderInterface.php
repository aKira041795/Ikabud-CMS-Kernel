<?php
namespace IkabudKernel\Core;

/**
 * Conditional Loader Interface
 * 
 * Contract for CMS-specific conditional loading implementations
 */
interface ConditionalLoaderInterface
{
    /**
     * Determine which extensions (plugins/modules) to load
     * 
     * @param string $requestUri The request URI
     * @param array $context Additional context (post type, meta, etc.)
     * @return array Array of extensions to load
     */
    public function determineExtensions(string $requestUri, array $context = []): array;
    
    /**
     * Load the determined extensions
     * 
     * @param array $extensions Extensions to load
     * @return void
     */
    public function loadExtensions(array $extensions): void;
    
    /**
     * Get list of loaded extensions
     * 
     * @return array
     */
    public function getLoadedExtensions(): array;
    
    /**
     * Check if conditional loading is enabled
     * 
     * @return bool
     */
    public function isEnabled(): bool;
    
    /**
     * Get loading statistics
     * 
     * @return array
     */
    public function getStats(): array;
    
    /**
     * Get the CMS type this loader handles
     * 
     * @return string (wordpress, joomla, drupal, etc.)
     */
    public function getCMSType(): string;
}
