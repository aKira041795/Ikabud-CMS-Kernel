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
            'timeline' => $this->renderTimeline($data),
            'carousel' => $this->renderCarousel($data),
            'table' => $this->renderTable($data),
            'accordion' => $this->renderAccordion($data),
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
    
    /**
     * Render as timeline
     */
    private function renderTimeline(array $data): string
    {
        $html = '<div class="ikb-timeline">';
        
        foreach ($data as $item) {
            $html .= '<div class="ikb-timeline-item">';
            $html .= '<div class="ikb-timeline-marker"></div>';
            $html .= '<div class="ikb-timeline-content">';
            
            if (!empty($item['date'])) {
                $html .= sprintf(
                    '<time class="ikb-timeline-date">%s</time>',
                    htmlspecialchars($item['date'])
                );
            }
            
            if (!empty($item['title'])) {
                $html .= sprintf(
                    '<h3 class="ikb-timeline-title"><a href="%s">%s</a></h3>',
                    htmlspecialchars($item['permalink'] ?? '#'),
                    htmlspecialchars($item['title'])
                );
            }
            
            if (!empty($item['excerpt'])) {
                $html .= sprintf(
                    '<p class="ikb-timeline-excerpt">%s</p>',
                    htmlspecialchars($item['excerpt'])
                );
            }
            
            $html .= '</div></div>';
        }
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render as carousel
     */
    private function renderCarousel(array $data): string
    {
        $html = '<div class="ikb-carousel">';
        $html .= '<div class="ikb-carousel-track">';
        
        foreach ($data as $index => $item) {
            $html .= sprintf('<div class="ikb-carousel-slide" data-index="%d">', $index);
            
            if (!empty($item['thumbnail'])) {
                $html .= sprintf(
                    '<img src="%s" alt="%s" class="ikb-carousel-image">',
                    htmlspecialchars($item['thumbnail']),
                    htmlspecialchars($item['title'] ?? '')
                );
            }
            
            $html .= '<div class="ikb-carousel-caption">';
            
            if (!empty($item['title'])) {
                $html .= sprintf(
                    '<h3 class="ikb-carousel-title"><a href="%s">%s</a></h3>',
                    htmlspecialchars($item['permalink'] ?? '#'),
                    htmlspecialchars($item['title'])
                );
            }
            
            if (!empty($item['excerpt'])) {
                $html .= sprintf(
                    '<p class="ikb-carousel-excerpt">%s</p>',
                    htmlspecialchars($item['excerpt'])
                );
            }
            
            $html .= '</div></div>';
        }
        
        $html .= '</div>';
        
        // Add navigation
        $html .= '<button class="ikb-carousel-prev" aria-label="Previous">&lsaquo;</button>';
        $html .= '<button class="ikb-carousel-next" aria-label="Next">&rsaquo;</button>';
        
        // Add indicators
        $html .= '<div class="ikb-carousel-indicators">';
        foreach ($data as $index => $item) {
            $active = $index === 0 ? ' active' : '';
            $html .= sprintf(
                '<button class="ikb-carousel-indicator%s" data-index="%d" aria-label="Slide %d"></button>',
                $active,
                $index,
                $index + 1
            );
        }
        $html .= '</div>';
        
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render as table
     */
    private function renderTable(array $data): string
    {
        $html = '<div class="ikb-table-wrapper">';
        $html .= '<table class="ikb-table">';
        $html .= '<thead><tr>';
        $html .= '<th>Title</th>';
        $html .= '<th>Date</th>';
        $html .= '<th>Author</th>';
        $html .= '<th>Categories</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';
        
        foreach ($data as $item) {
            $html .= '<tr>';
            
            // Title
            $html .= '<td class="ikb-table-title">';
            if (!empty($item['title'])) {
                $html .= sprintf(
                    '<a href="%s">%s</a>',
                    htmlspecialchars($item['permalink'] ?? '#'),
                    htmlspecialchars($item['title'])
                );
            }
            $html .= '</td>';
            
            // Date
            $html .= '<td class="ikb-table-date">';
            if (!empty($item['date'])) {
                $html .= htmlspecialchars($item['date']);
            }
            $html .= '</td>';
            
            // Author
            $html .= '<td class="ikb-table-author">';
            if (!empty($item['author'])) {
                $html .= htmlspecialchars($item['author']);
            }
            $html .= '</td>';
            
            // Categories
            $html .= '<td class="ikb-table-categories">';
            if (!empty($item['categories']) && is_array($item['categories'])) {
                $html .= htmlspecialchars(implode(', ', $item['categories']));
            }
            $html .= '</td>';
            
            $html .= '</tr>';
        }
        
        $html .= '</tbody></table>';
        $html .= '</div>';
        return $html;
    }
    
    /**
     * Render as accordion
     */
    private function renderAccordion(array $data): string
    {
        $html = '<div class="ikb-accordion">';
        
        foreach ($data as $index => $item) {
            $html .= sprintf('<div class="ikb-accordion-item" data-index="%d">', $index);
            
            // Header
            $html .= sprintf(
                '<button class="ikb-accordion-header" aria-expanded="false" aria-controls="accordion-content-%d">',
                $index
            );
            
            if (!empty($item['title'])) {
                $html .= htmlspecialchars($item['title']);
            }
            
            $html .= '<span class="ikb-accordion-icon">+</span>';
            $html .= '</button>';
            
            // Content
            $html .= sprintf(
                '<div class="ikb-accordion-content" id="accordion-content-%d" hidden>',
                $index
            );
            
            if (!empty($item['thumbnail'])) {
                $html .= sprintf(
                    '<img src="%s" alt="%s" class="ikb-accordion-image">',
                    htmlspecialchars($item['thumbnail']),
                    htmlspecialchars($item['title'] ?? '')
                );
            }
            
            if (!empty($item['excerpt'])) {
                $html .= sprintf('<p>%s</p>', htmlspecialchars($item['excerpt']));
            }
            
            if (!empty($item['permalink'])) {
                $html .= sprintf(
                    '<a href="%s" class="ikb-accordion-link">Read more &rarr;</a>',
                    htmlspecialchars($item['permalink'])
                );
            }
            
            $html .= '</div></div>';
        }
        
        $html .= '</div>';
        return $html;
    }
}
