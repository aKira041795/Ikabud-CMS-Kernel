<?php
/**
 * DiSyL Client-Side Hydration Runtime v1.0.0
 * 
 * Provides server-side rendering (SSR) with client-side hydration support.
 * 
 * Features:
 * - Serialize component state for client hydration
 * - Generate hydration markers in HTML
 * - Client-side JavaScript runtime generation
 * - Progressive hydration support
 * - Selective hydration (islands architecture)
 * 
 * @version 1.0.0
 */

namespace Ikabud\Kernel\DiSyL\Hydration;

/**
 * Hydration marker types
 */
enum HydrationMarker: string
{
    case COMPONENT_START = 'disyl-component';
    case COMPONENT_END = 'disyl-component-end';
    case STATE = 'disyl-state';
    case PROPS = 'disyl-props';
    case SLOT = 'disyl-slot';
}

// HydrationStrategy enum is defined in HydrationStrategy.php (canonical).
// Both HydrationStrategy::LOAD and HydrationStrategy::IMMEDIATE are available.
require_once __DIR__ . '/HydrationStrategy.php';

/**
 * Component hydration data
 */
class HydrationData
{
    public function __construct(
        public readonly string $componentId,
        public readonly string $componentName,
        public readonly array $props,
        public readonly array $state,
        public readonly HydrationStrategy $strategy,
        public readonly array $options = []
    ) {}
    
    /**
     * Serialize to JSON for embedding in HTML
     */
    public function toJSON(): string
    {
        return json_encode([
            'id' => $this->componentId,
            'name' => $this->componentName,
            'props' => $this->props,
            'state' => $this->state,
            'strategy' => $this->strategy->value,
            'options' => $this->options,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    }
    
    /**
     * Generate script tag for hydration data
     */
    public function toScriptTag(): string
    {
        return sprintf(
            '<script type="application/json" data-disyl-hydration="%s">%s</script>',
            htmlspecialchars($this->componentId),
            $this->toJSON()
        );
    }
}

/**
 * Hydration context for tracking components during SSR
 */
class HydrationContext
{
    /** @var array<string, HydrationData> Registered components */
    private array $components = [];
    
    /** @var int Component ID counter */
    private int $idCounter = 0;
    
    /** @var bool Whether hydration is enabled */
    private bool $enabled = true;
    
    /** @var HydrationStrategy Default strategy */
    private HydrationStrategy $defaultStrategy = HydrationStrategy::IMMEDIATE;
    
    /**
     * Generate unique component ID
     */
    public function generateId(string $componentName): string
    {
        return sprintf('disyl-%s-%d', $this->slugify($componentName), ++$this->idCounter);
    }
    
    /**
     * Register a component for hydration
     */
    public function registerComponent(
        string $componentId,
        string $componentName,
        array $props,
        array $state,
        ?HydrationStrategy $strategy = null,
        array $options = []
    ): HydrationData {
        $data = new HydrationData(
            $componentId,
            $componentName,
            $props,
            $state,
            $strategy ?? $this->defaultStrategy,
            $options
        );
        
        $this->components[$componentId] = $data;
        
        return $data;
    }
    
    /**
     * Get all registered components
     */
    public function getComponents(): array
    {
        return $this->components;
    }
    
    /**
     * Get component by ID
     */
    public function getComponent(string $id): ?HydrationData
    {
        return $this->components[$id] ?? null;
    }
    
    /**
     * Set default hydration strategy
     */
    public function setDefaultStrategy(HydrationStrategy $strategy): void
    {
        $this->defaultStrategy = $strategy;
    }
    
    /**
     * Enable/disable hydration
     */
    public function setEnabled(bool $enabled): void
    {
        $this->enabled = $enabled;
    }
    
    /**
     * Check if hydration is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
    
    /**
     * Reset context
     */
    public function reset(): void
    {
        $this->components = [];
        $this->idCounter = 0;
    }
    
    /**
     * Generate all hydration scripts
     */
    public function generateHydrationScripts(): string
    {
        if (!$this->enabled || empty($this->components)) {
            return '';
        }
        
        $scripts = [];
        
        // Add hydration data for each component
        foreach ($this->components as $data) {
            $scripts[] = $data->toScriptTag();
        }
        
        // Add hydration runtime
        $scripts[] = $this->generateRuntimeScript();
        
        return implode("\n", $scripts);
    }
    
    /**
     * Generate the client-side hydration runtime
     */
    private function generateRuntimeScript(): string
    {
        return <<<'JS'
<script>
(function() {
  'use strict';
  
  const DiSyLHydration = {
    components: new Map(),
    observers: new Map(),
    
    init() {
      // Collect all hydration data
      document.querySelectorAll('script[data-disyl-hydration]').forEach(script => {
        try {
          const data = JSON.parse(script.textContent);
          this.components.set(data.id, data);
          this.scheduleHydration(data);
        } catch (e) {
          console.error('DiSyL: Failed to parse hydration data', e);
        }
      });
    },
    
    scheduleHydration(data) {
      const element = document.querySelector(`[data-disyl-id="${data.id}"]`);
      if (!element) return;
      
      switch (data.strategy) {
        case 'immediate':
          this.hydrate(data, element);
          break;
          
        case 'idle':
          if ('requestIdleCallback' in window) {
            requestIdleCallback(() => this.hydrate(data, element));
          } else {
            setTimeout(() => this.hydrate(data, element), 1);
          }
          break;
          
        case 'visible':
          this.observeVisibility(data, element);
          break;
          
        case 'interaction':
          this.observeInteraction(data, element);
          break;
          
        case 'media':
          this.observeMedia(data, element);
          break;
          
        case 'never':
          // Static component, no hydration
          break;
      }
    },
    
    observeVisibility(data, element) {
      if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              observer.disconnect();
              this.hydrate(data, element);
            }
          });
        }, { rootMargin: '50px' });
        
        observer.observe(element);
        this.observers.set(data.id, observer);
      } else {
        // Fallback to immediate
        this.hydrate(data, element);
      }
    },
    
    observeInteraction(data, element) {
      const events = ['click', 'focus', 'mouseover', 'touchstart'];
      const handler = () => {
        events.forEach(e => element.removeEventListener(e, handler));
        this.hydrate(data, element);
      };
      events.forEach(e => element.addEventListener(e, handler, { once: true, passive: true }));
    },
    
    observeMedia(data, element) {
      const query = data.options.mediaQuery || '(min-width: 768px)';
      const mql = window.matchMedia(query);
      
      const handler = (e) => {
        if (e.matches) {
          mql.removeEventListener('change', handler);
          this.hydrate(data, element);
        }
      };
      
      if (mql.matches) {
        this.hydrate(data, element);
      } else {
        mql.addEventListener('change', handler);
      }
    },
    
    async hydrate(data, element) {
      if (element.dataset.disylHydrated) return;
      element.dataset.disylHydrated = 'true';
      
      try {
        // Load component module
        const module = await this.loadComponent(data.name);
        if (!module) {
          console.warn(`DiSyL: Component "${data.name}" not found`);
          return;
        }
        
        // Create component instance
        const Component = module.default || module[data.name];
        if (typeof Component !== 'function') {
          console.warn(`DiSyL: Invalid component "${data.name}"`);
          return;
        }
        
        // Hydrate with React/Preact/Vue depending on framework
        if (window.React && window.ReactDOM) {
          this.hydrateReact(Component, data, element);
        } else if (window.preact) {
          this.hydratePreact(Component, data, element);
        } else if (window.Vue) {
          this.hydrateVue(Component, data, element);
        } else {
          // Vanilla JS hydration
          this.hydrateVanilla(Component, data, element);
        }
        
        element.dispatchEvent(new CustomEvent('disyl:hydrated', { detail: data }));
      } catch (e) {
        console.error(`DiSyL: Hydration failed for "${data.name}"`, e);
      }
    },
    
    async loadComponent(name) {
      // Check global registry
      if (window.DiSyLComponents && window.DiSyLComponents[name]) {
        return window.DiSyLComponents[name];
      }
      
      // Validate component name before dynamic import to prevent path traversal
      if (!/^[a-zA-Z0-9_-]+$/.test(name)) {
        console.error(`DiSyL: Invalid component name "${name}" — only alphanumeric, hyphen, and underscore are allowed`);
        return null;
      }
      
      // Try dynamic import
      try {
        return await import(`/components/${name}.js`);
      } catch (e) {
        return null;
      }
    },
    
    hydrateReact(Component, data, element) {
      const props = { ...data.props, initialState: data.state };
      
      if (ReactDOM.hydrateRoot) {
        // React 18+
        ReactDOM.hydrateRoot(element, React.createElement(Component, props));
      } else if (ReactDOM.hydrate) {
        // React 16-17
        ReactDOM.hydrate(React.createElement(Component, props), element);
      }
    },
    
    hydratePreact(Component, data, element) {
      const props = { ...data.props, initialState: data.state };
      preact.hydrate(preact.h(Component, props), element);
    },
    
    hydrateVue(Component, data, element) {
      const app = Vue.createApp(Component, { ...data.props, initialState: data.state });
      app.mount(element);
    },
    
    hydrateVanilla(Component, data, element) {
      new Component(element, { ...data.props, initialState: data.state });
    },
    
    // Manual hydration API
    hydrateById(id) {
      const data = this.components.get(id);
      if (!data) return false;
      
      const element = document.querySelector(`[data-disyl-id="${id}"]`);
      if (!element) return false;
      
      this.hydrate(data, element);
      return true;
    },
    
    hydrateAll() {
      this.components.forEach((data, id) => {
        const element = document.querySelector(`[data-disyl-id="${id}"]`);
        if (element && !element.dataset.disylHydrated) {
          this.hydrate(data, element);
        }
      });
    }
  };
  
  // Initialize on DOM ready
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => DiSyLHydration.init());
  } else {
    DiSyLHydration.init();
  }
  
  // Expose API
  window.DiSyLHydration = DiSyLHydration;
})();
</script>
JS;
    }
    
    /**
     * Slugify component name for ID
     */
    private function slugify(string $name): string
    {
        return strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
    }
}

/**
 * Hydration renderer mixin
 */
trait HydrationRendererTrait
{
    /** @var HydrationContext|null */
    protected ?HydrationContext $hydrationContext = null;
    
    /**
     * Set hydration context
     */
    public function setHydrationContext(HydrationContext $context): void
    {
        $this->hydrationContext = $context;
    }
    
    /**
     * Get hydration context
     */
    public function getHydrationContext(): ?HydrationContext
    {
        return $this->hydrationContext;
    }
    
    /**
     * Wrap component output with hydration markers
     */
    protected function wrapWithHydration(
        string $html,
        string $componentName,
        array $props,
        array $state,
        ?HydrationStrategy $strategy = null,
        array $options = []
    ): string {
        if ($this->hydrationContext === null || !$this->hydrationContext->isEnabled()) {
            return $html;
        }
        
        $componentId = $this->hydrationContext->generateId($componentName);
        
        $this->hydrationContext->registerComponent(
            $componentId,
            $componentName,
            $props,
            $state,
            $strategy,
            $options
        );
        
        // Wrap HTML with hydration marker
        return sprintf(
            '<div data-disyl-id="%s" data-disyl-component="%s">%s</div>',
            htmlspecialchars($componentId),
            htmlspecialchars($componentName),
            $html
        );
    }
    
    /**
     * Generate hydration scripts for page footer
     */
    protected function generateHydrationFooter(): string
    {
        if ($this->hydrationContext === null) {
            return '';
        }
        
        return $this->hydrationContext->generateHydrationScripts();
    }
}

// Canonical Island class lives in Island.php — do not duplicate.
require_once __DIR__ . '/Island.php';

/**
 * Progressive hydration helper
 */
class ProgressiveHydration
{
    /** @var array<Island> Islands to hydrate */
    private array $islands = [];
    
    /** @var HydrationContext */
    private HydrationContext $context;
    
    public function __construct(?HydrationContext $context = null)
    {
        $this->context = $context ?? new HydrationContext();
    }
    
    /**
     * Add an island component
     */
    public function addIsland(Island $island): self
    {
        $this->islands[] = $island;
        return $this;
    }
    
    /**
     * Create island with immediate hydration
     */
    public function immediate(string $component, array $props = []): Island
    {
        $island = new Island(uniqid('island_', true), $component, $props, HydrationStrategy::IMMEDIATE);
        $this->islands[] = $island;
        return $island;
    }
    
    /**
     * Create island with idle hydration
     */
    public function idle(string $component, array $props = []): Island
    {
        $island = new Island(uniqid('island_', true), $component, $props, HydrationStrategy::IDLE);
        $this->islands[] = $island;
        return $island;
    }
    
    /**
     * Create island with visible hydration
     */
    public function visible(string $component, array $props = []): Island
    {
        $island = new Island(uniqid('island_', true), $component, $props, HydrationStrategy::VISIBLE);
        $this->islands[] = $island;
        return $island;
    }
    
    /**
     * Create island with interaction hydration
     */
    public function interaction(string $component, array $props = []): Island
    {
        $island = new Island(uniqid('island_', true), $component, $props, HydrationStrategy::INTERACTION);
        $this->islands[] = $island;
        return $island;
    }
    
    /**
     * Create static island (no hydration)
     */
    public function static(string $component, array $props = []): Island
    {
        $island = new Island(uniqid('island_', true), $component, $props, HydrationStrategy::NEVER);
        $this->islands[] = $island;
        return $island;
    }
    
    /**
     * Get hydration context
     */
    public function getContext(): HydrationContext
    {
        return $this->context;
    }
    
    /**
     * Get all islands
     */
    public function getIslands(): array
    {
        return $this->islands;
    }
}
