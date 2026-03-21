type CleanupFn = () => void;

function toArray<T extends Element>(nodes: NodeListOf<T>): T[] {
  return Array.prototype.slice.call(nodes) as T[];
}

interface AnimationQueue {
  element: HTMLElement;
  animation: string;
  duration: string;
  delay: string;
  startTime: number;
  frameId: number | null;
}

export function initBuilderPreviewRuntime(root: HTMLElement): CleanupFn {
  const cleanups: CleanupFn[] = [];
  const animationQueue: Map<HTMLElement, AnimationQueue> = new Map();
  const intersectionObservers: IntersectionObserver[] = [];

  const safeAddListener = <T extends EventTarget>(
    target: T,
    eventName: string,
    handler: EventListenerOrEventListenerObject,
    options?: AddEventListenerOptions
  ) => {
    target.addEventListener(eventName, handler, options);
    cleanups.push(() => target.removeEventListener(eventName, handler, options));
  };

  const safeCreateObserver = (observer: IntersectionObserver) => {
    intersectionObservers.push(observer);
    cleanups.push(() => observer.disconnect());
  };

  // Slideshow
  toArray(root.querySelectorAll<HTMLElement>('.cms-builder-node--slideshow')).forEach((slideshow) => {
    const slides = toArray(slideshow.querySelectorAll<HTMLElement>('.cms-builder-slide'));
    if (slides.length < 2) return;

    let current = 0;
    const intervalMs = Number.parseInt(slideshow.dataset.interval || '5000', 10) || 5000;

    const showSlide = (idx: number) => {
      slides.forEach((slide, i) => {
        const isActive = i === idx;
        slide.style.display = isActive ? 'block' : 'none';
        slide.classList.toggle('active', isActive);
      });
    };

    showSlide(0);

    const timer = window.setInterval(() => {
      current = (current + 1) % slides.length;
      showSlide(current);
    }, intervalMs);
    cleanups.push(() => window.clearInterval(timer));

    const prevBtn = slideshow.querySelector<HTMLElement>('.cms-builder-slide-prev');
    const nextBtn = slideshow.querySelector<HTMLElement>('.cms-builder-slide-next');

    if (prevBtn) {
      safeAddListener(prevBtn, 'click', (event) => {
        event.preventDefault();
        current = (current - 1 + slides.length) % slides.length;
        showSlide(current);
      });
    }

    if (nextBtn) {
      safeAddListener(nextBtn, 'click', (event) => {
        event.preventDefault();
        current = (current + 1) % slides.length;
        showSlide(current);
      });
    }

    toArray(slideshow.querySelectorAll<HTMLElement>('.cms-builder-slide-dot')).forEach((dot, index) => {
      safeAddListener(dot, 'click', (event) => {
        event.preventDefault();
        current = index;
        showSlide(current);
      });
    });
  });

  // Tabs
  toArray(root.querySelectorAll<HTMLElement>('.cms-builder-node--tabs')).forEach((tabsWidget) => {
    const buttons = toArray(tabsWidget.querySelectorAll<HTMLElement>('.cms-builder-tab-btn'));
    const panels = toArray(tabsWidget.querySelectorAll<HTMLElement>('.cms-builder-tab-panel'));
    if (buttons.length === 0 || panels.length === 0) return;

    if (!buttons.some((button) => button.classList.contains('active'))) {
      buttons[0].classList.add('active');
      if (panels[0]) panels[0].classList.add('active');
    }

    buttons.forEach((button, index) => {
      safeAddListener(button, 'click', (event) => {
        event.preventDefault();
        buttons.forEach((btn) => btn.classList.remove('active'));
        panels.forEach((panel) => panel.classList.remove('active'));
        button.classList.add('active');

        const targetId = button.getAttribute('data-tab');
        const targetPanel = targetId
          ? tabsWidget.querySelector<HTMLElement>(`.cms-builder-tab-panel[data-tab="${targetId}"]`)
          : panels[index];
        if (targetPanel) targetPanel.classList.add('active');
      });
    });
  });

  // Counter
  const counters = toArray(root.querySelectorAll<HTMLElement>('.cms-builder-node--counter'));
  if ('IntersectionObserver' in window && counters.length > 0) {
    const observer = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const counter = entry.target as HTMLElement;
        if (counter.dataset.counted === '1') return;
        counter.dataset.counted = '1';

        const value = counter.querySelector<HTMLElement>('.cms-builder-counter-value');
        if (!value) return;

        const target = Number.parseInt(value.getAttribute('data-target') || value.textContent || '0', 10) || 0;
        const duration = Number.parseInt(value.getAttribute('data-duration') || '2000', 10) || 2000;
        const prefix = value.getAttribute('data-prefix') || '';
        const suffix = value.getAttribute('data-suffix') || '';

        let startTime = 0;
        const animateCounter = (timestamp: number) => {
          if (!startTime) startTime = timestamp;
          const progress = Math.min((timestamp - startTime) / duration, 1);
          const eased = 1 - (1 - progress) * (1 - progress);
          value.textContent = `${prefix}${Math.round(eased * target).toLocaleString()}${suffix}`;
          if (progress < 1) requestAnimationFrame(animateCounter);
        };

        requestAnimationFrame(animateCounter);
        observer.unobserve(counter);
      });
    }, { threshold: 0.2 });

    counters.forEach((counter) => observer.observe(counter));
    safeCreateObserver(observer);
  } else {
    counters.forEach((counter) => {
      const value = counter.querySelector<HTMLElement>('.cms-builder-counter-value');
      if (!value) return;
      const target = Number.parseInt(value.getAttribute('data-target') || value.textContent || '0', 10) || 0;
      const prefix = value.getAttribute('data-prefix') || '';
      const suffix = value.getAttribute('data-suffix') || '';
      value.textContent = `${prefix}${target.toLocaleString()}${suffix}`;
    });
  }

  // Flip box click interaction
  toArray(root.querySelectorAll<HTMLElement>('.cms-builder-node--flip_box')).forEach((flipBox) => {
    safeAddListener(flipBox, 'click', () => {
      flipBox.classList.toggle('flipped');
    });
  });

  // Accordion fallback
  toArray(root.querySelectorAll<HTMLElement>('.cms-builder-accordion-header')).forEach((header) => {
    const parent = header.parentElement;
    if (!parent || parent.tagName === 'DETAILS') return;

    safeAddListener(header, 'click', () => {
      const body = parent.querySelector<HTMLElement>('.cms-builder-accordion-body');
      if (!body) return;
      const isOpen = body.style.display === 'block';
      body.style.display = isOpen ? 'none' : 'block';
      parent.classList.toggle('open', !isOpen);
    });
  });

  // Entrance animations (trigger on scroll into view with proper lifecycle)
  const animatedElements = toArray(root.querySelectorAll<HTMLElement>('[data-animate]'));
  if ('IntersectionObserver' in window && animatedElements.length > 0) {
    const animObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) return;
        const el = entry.target as HTMLElement;
        
        // Prevent double-triggering
        if (el.classList.contains('cms-animated')) {
          animObserver.unobserve(el);
          return;
        }

        const duration = el.getAttribute('data-animate-duration') || '0.6s';
        const delay = el.getAttribute('data-animate-delay') || '0s';
        
        if (duration) el.style.transitionDuration = duration;
        if (delay) el.style.transitionDelay = delay;

        // Use requestAnimationFrame to ensure paint happens before animation class
        const frameId = requestAnimationFrame(() => {
          el.classList.add('cms-animated');
        });

        // Track animation in queue
        const queueEntry: AnimationQueue = {
          element: el,
          animation: el.getAttribute('data-animate') || '',
          duration,
          delay,
          startTime: Date.now(),
          frameId,
        };
        animationQueue.set(el, queueEntry);

        // Cleanup when animation completes
        const durationMs = Number.parseFloat(duration) * 1000 || 600;
        const timeoutId = window.setTimeout(() => {
          animationQueue.delete(el);
          animObserver.unobserve(el);
        }, durationMs + 100);

        cleanups.push(() => window.clearTimeout(timeoutId));
        animObserver.unobserve(el);
      });
    }, { threshold: 0.15 });

    animatedElements.forEach((el) => animObserver.observe(el));
    safeCreateObserver(animObserver);
  } else {
    // Fallback: instant animation for unsupported browsers
    animatedElements.forEach((el) => {
      el.classList.add('cms-animated');
    });
  }

  // Cleanup animation queue on exit
  cleanups.push(() => {
    animationQueue.forEach(({ frameId }) => {
      if (frameId !== null) cancelAnimationFrame(frameId);
    });
    animationQueue.clear();
  });

  return () => {
    cleanups.forEach((cleanup) => cleanup());
  };
}