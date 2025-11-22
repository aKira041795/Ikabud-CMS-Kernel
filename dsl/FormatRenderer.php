<?php
/**
 * Format Renderer - Renders data in different formats
 * 
 * Supports: card, list, grid, hero, minimal, etc.
 * Generates HTML from query results
 * 
 * @version 1.1.0
 */

namespace IkabudKernel\DSL;

class FormatRenderer
{
    /**
     * Render data in specified format
     */
    public function render(array $data, string $format = 'card'): string
    {
        if (empty($data)) {
            return '<div class="ikb-no-results">No results found.</div>';
        }
        
        return match($format) {
            'card' => $this->renderCard($data),
            'list' => $this->renderList($data),
            'grid' => $this->renderGrid($data),
            'hero' => $this->renderHero($data),
            'minimal' => $this->renderMinimal($data),
            'full' => $this->renderFull($data),
            default => $this->renderCard($data)
        };
    }
    
    /**
     * Render as cards
     */
    private function renderCard(array $data): string
    {
        $html = '';
        
        foreach ($data as $item) {
            $html .= '<article class="ikb-dsl-card">';
            
            if (!empty($item['thumbnail'])) {
                $html .= sprintf(
                    '<img src="%s" alt="%s" class="ikb-dsl-card-image">',
                    htmlspecialchars($item['thumbnail']),
                    htmlspecialchars($item['title'] ?? '')
                );
            }
            
            $html .= '<div class="ikb-dsl-card-content">';
            
            if (!empty($item['title'])) {
                $html .= sprintf(
                    '<h3 class="ikb-dsl-card-title"><a href="%s">%s</a></h3>',
                    htmlspecialchars($item['permalink'] ?? '#'),
                    htmlspecialchars($item['title'])
                );
            }
            
            if (!empty($item['excerpt'])) {
                $html .= sprintf(
                    '<p class="ikb-dsl-card-excerpt">%s</p>',
                    htmlspecialchars($item['excerpt'])
                );
            }
            
            if (!empty($item['date'])) {
                $html .= sprintf(
                    '<time class="ikb-dsl-card-date">%s</time>',
                    htmlspecialchars($item['date'])
                );
            }
            
            $html .= '</div></article>';
        }
        
        return $html;
    }
    
    /**
     * Render as list
     */
    private function renderList(array $data): string
    {
        $html = '<ul class="ikb-list">';
        
        foreach ($data as $item) {
            $html .= '<li class="ikb-list-item">';
            $html .= sprintf(
                '<a href="%s">%s</a>',
                htmlspecialchars($item['permalink'] ?? '#'),
                htmlspecialchars($item['title'] ?? 'Untitled')
            );
            $html .= '</li>';
        }
        
        $html .= '</ul>';
        
        return $html;
    }
    
    /**
     * Render as grid
     */
    private function renderGrid(array $data): string
    {
        return $this->renderCard($data); // Grid is handled by LayoutEngine
    }
    
    /**
     * Render as hero
     */
    private function renderHero(array $data): string
    {
        if (empty($data)) {
            return '';
        }
        
        $item = $data[0]; // Use first item
        
        $html = '<div class="ikb-hero">';
        
        if (!empty($item['thumbnail'])) {
            $html .= sprintf(
                '<div class="ikb-hero-image" style="background-image: url(%s)"></div>',
                htmlspecialchars($item['thumbnail'])
            );
        }
        
        $html .= '<div class="ikb-hero-content">';
        
        if (!empty($item['title'])) {
            $html .= sprintf(
                '<h1 class="ikb-hero-title">%s</h1>',
                htmlspecialchars($item['title'])
            );
        }
        
        if (!empty($item['excerpt'])) {
            $html .= sprintf(
                '<p class="ikb-hero-excerpt">%s</p>',
                htmlspecialchars($item['excerpt'])
            );
        }
        
        if (!empty($item['permalink'])) {
            $html .= sprintf(
                '<a href="%s" class="ikb-hero-button">Read More</a>',
                htmlspecialchars($item['permalink'])
            );
        }
        
        $html .= '</div></div>';
        
        return $html;
    }
    
    /**
     * Render minimal (title only)
     */
    private function renderMinimal(array $data): string
    {
        $html = '<div class="ikb-minimal">';
        
        foreach ($data as $item) {
            $html .= sprintf(
                '<div class="ikb-minimal-item"><a href="%s">%s</a></div>',
                htmlspecialchars($item['permalink'] ?? '#'),
                htmlspecialchars($item['title'] ?? 'Untitled')
            );
        }
        
        $html .= '</div>';
        
        return $html;
    }
    
    /**
     * Render full (all fields)
     */
    private function renderFull(array $data): string
    {
        $html = '';
        
        foreach ($data as $item) {
            $html .= '<article class="ikb-full">';
            
            if (!empty($item['title'])) {
                $html .= sprintf('<h2>%s</h2>', htmlspecialchars($item['title']));
            }
            
            if (!empty($item['content'])) {
                $html .= sprintf('<div class="ikb-content">%s</div>', $item['content']);
            }
            
            if (!empty($item['author'])) {
                $html .= sprintf('<p class="ikb-author">By %s</p>', htmlspecialchars($item['author']));
            }
            
            if (!empty($item['date'])) {
                $html .= sprintf('<time>%s</time>', htmlspecialchars($item['date']));
            }
            
            $html .= '</article>';
        }
        
        return $html;
    }
}
