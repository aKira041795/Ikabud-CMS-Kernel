<?php
/**
 * Ikabud Cache Invalidation for Drupal
 * 
 * This file should be placed in: sites/default/modules/ikabud_cache/ikabud_cache.module
 * 
 * To install:
 * 1. Create directory: sites/default/modules/ikabud_cache/
 * 2. Copy this file as: ikabud_cache.module
 * 3. Create ikabud_cache.info.yml with module metadata
 * 4. Enable module: drush en ikabud_cache
 */

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;

/**
 * Get Ikabud Cache instance
 */
function ikabud_cache_get_instance() {
    static $cache = null;
    
    if ($cache === null) {
        $instance_id = getenv('IKABUD_INSTANCE_ID') ?: null;
        if (!$instance_id) {
            return null;
        }
        
        $kernel_path = getenv('IKABUD_KERNEL_PATH') ?: dirname(dirname(dirname(dirname(__FILE__)))) . '/../../kernel';
        require_once $kernel_path . '/Cache.php';
        $cache = [
            'instance' => new \IkabudKernel\Core\Cache(),
            'instance_id' => $instance_id
        ];
    }
    
    return $cache;
}

/**
 * Get tags for a node
 */
function ikabud_cache_get_node_tags(NodeInterface $node) {
    $tags = [
        'node-' . $node->id(),
        'node-type-' . $node->bundle(),
        'author-' . $node->getOwnerId(),
    ];
    
    // Add taxonomy term tags
    $fields = $node->getFields();
    foreach ($fields as $field) {
        if ($field->getFieldDefinition()->getType() === 'entity_reference') {
            $target_type = $field->getFieldDefinition()->getSetting('target_type');
            if ($target_type === 'taxonomy_term') {
                foreach ($field->referencedEntities() as $term) {
                    $tags[] = 'term-' . $term->id();
                    $tags[] = 'vocabulary-' . $term->bundle();
                }
            }
        }
    }
    
    // Add date-based tags
    $created = $node->getCreatedTime();
    $tags[] = 'year-' . date('Y', $created);
    $tags[] = 'month-' . date('Y-m', $created);
    
    return $tags;
}

/**
 * Get dependency URLs for a node
 */
function ikabud_cache_get_node_dependencies(NodeInterface $node) {
    $dependencies = [
        '/',  // Homepage
        '/node',  // Node listing
    ];
    
    // Add taxonomy term pages
    $fields = $node->getFields();
    foreach ($fields as $field) {
        if ($field->getFieldDefinition()->getType() === 'entity_reference') {
            $target_type = $field->getFieldDefinition()->getSetting('target_type');
            if ($target_type === 'taxonomy_term') {
                foreach ($field->referencedEntities() as $term) {
                    $dependencies[] = \Drupal::service('path_alias.manager')
                        ->getAliasByPath('/taxonomy/term/' . $term->id());
                }
            }
        }
    }
    
    return $dependencies;
}

/**
 * Implements hook_entity_insert().
 */
function ikabud_cache_entity_insert(EntityInterface $entity) {
    if ($entity instanceof NodeInterface && $entity->isPublished()) {
        $cache_info = ikabud_cache_get_instance();
        if (!$cache_info) return;
        
        $cache = $cache_info['instance'];
        $instance_id = $cache_info['instance_id'];
        
        // Get tags and clear cache
        $tags = ikabud_cache_get_node_tags($entity);
        $cleared = $cache->clearByTags($instance_id, $tags);
        
        // Clear dependencies
        $node_url = $entity->toUrl()->toString();
        $dependencies = ikabud_cache_get_node_dependencies($entity);
        $cleared += $cache->clearWithDependencies($instance_id, $node_url, $dependencies);
        
        \Drupal::logger('ikabud_cache')->notice('Cleared @count cache files for new node @nid', [
            '@count' => $cleared,
            '@nid' => $entity->id()
        ]);
    }
}

/**
 * Implements hook_entity_update().
 */
function ikabud_cache_entity_update(EntityInterface $entity) {
    if ($entity instanceof NodeInterface && $entity->isPublished()) {
        $cache_info = ikabud_cache_get_instance();
        if (!$cache_info) return;
        
        $cache = $cache_info['instance'];
        $instance_id = $cache_info['instance_id'];
        
        // Get tags and clear cache
        $tags = ikabud_cache_get_node_tags($entity);
        $cleared = $cache->clearByTags($instance_id, $tags);
        
        // Clear dependencies
        $node_url = $entity->toUrl()->toString();
        $dependencies = ikabud_cache_get_node_dependencies($entity);
        $cleared += $cache->clearWithDependencies($instance_id, $node_url, $dependencies);
        
        \Drupal::logger('ikabud_cache')->notice('Cleared @count cache files for updated node @nid', [
            '@count' => $cleared,
            '@nid' => $entity->id()
        ]);
    }
}

/**
 * Implements hook_entity_delete().
 */
function ikabud_cache_entity_delete(EntityInterface $entity) {
    if ($entity instanceof NodeInterface) {
        $cache_info = ikabud_cache_get_instance();
        if (!$cache_info) return;
        
        $cache = $cache_info['instance'];
        $instance_id = $cache_info['instance_id'];
        
        $tags = ikabud_cache_get_node_tags($entity);
        $cleared = $cache->clearByTags($instance_id, $tags);
        
        \Drupal::logger('ikabud_cache')->notice('Cleared @count cache files for deleted node @nid', [
            '@count' => $cleared,
            '@nid' => $entity->id()
        ]);
    }
    
    // Clear cache when taxonomy terms are deleted
    if ($entity instanceof TermInterface) {
        $cache_info = ikabud_cache_get_instance();
        if (!$cache_info) return;
        
        $cache = $cache_info['instance'];
        $instance_id = $cache_info['instance_id'];
        
        $tags = ['term-' . $entity->id(), 'vocabulary-' . $entity->bundle()];
        $cleared = $cache->clearByTags($instance_id, $tags);
        
        \Drupal::logger('ikabud_cache')->notice('Cleared @count cache files for term @tid', [
            '@count' => $cleared,
            '@tid' => $entity->id()
        ]);
    }
}

/**
 * Implements hook_comment_insert().
 */
function ikabud_cache_comment_insert($comment) {
    $cache_info = ikabud_cache_get_instance();
    if (!$cache_info) return;
    
    $cache = $cache_info['instance'];
    $instance_id = $cache_info['instance_id'];
    
    // Clear only the node page
    $node = $comment->getCommentedEntity();
    if ($node instanceof NodeInterface) {
        $tags = ['node-' . $node->id()];
        $cleared = $cache->clearByTags($instance_id, $tags);
        
        \Drupal::logger('ikabud_cache')->notice('Cleared @count cache files for comment on node @nid', [
            '@count' => $cleared,
            '@nid' => $node->id()
        ]);
    }
}

/**
 * Implements hook_comment_update().
 */
function ikabud_cache_comment_update($comment) {
    ikabud_cache_comment_insert($comment);
}

/**
 * Implements hook_comment_delete().
 */
function ikabud_cache_comment_delete($comment) {
    ikabud_cache_comment_insert($comment);
}

/**
 * Implements hook_toolbar().
 */
function ikabud_cache_toolbar() {
    $cache_info = ikabud_cache_get_instance();
    if (!$cache_info) return [];
    
    $cache = $cache_info['instance'];
    $instance_id = $cache_info['instance_id'];
    
    $size = $cache->getSize($instance_id);
    
    $items['ikabud_cache'] = [
        '#type' => 'toolbar_item',
        'tab' => [
            '#type' => 'link',
            '#title' => t('⚡ Cache: @files files (@mb MB)', [
                '@files' => $size['files'],
                '@mb' => $size['size_mb']
            ]),
            '#url' => \Drupal\Core\Url::fromRoute('ikabud_cache.clear'),
            '#attributes' => [
                'title' => t('Clear Ikabud Cache'),
                'class' => ['toolbar-icon', 'toolbar-icon-ikabud-cache'],
            ],
        ],
        '#weight' => 999,
    ];
    
    return $items;
}

/**
 * Implements hook_menu().
 */
function ikabud_cache_menu() {
    $items['admin/config/development/ikabud-cache/clear'] = [
        'title' => 'Clear Ikabud Cache',
        'route_name' => 'ikabud_cache.clear',
        'type' => MENU_CALLBACK,
    ];
    
    return $items;
}
