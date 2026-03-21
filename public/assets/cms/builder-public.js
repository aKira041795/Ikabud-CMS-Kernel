/**
 * CMS Builder — Public Frontend Scripts
 * Handles interactive widgets: slideshows, tabs, counters, flip boxes.
 */
document.addEventListener('DOMContentLoaded', function() {
  'use strict';

  // ─── Slideshow ─────────────────────────────────────────────────────────
  document.querySelectorAll('.cms-builder-node--slideshow').forEach(function(s) {
    var slides = [].slice.call(s.querySelectorAll('.cms-builder-slide'));
    if (slides.length < 2 || s.dataset.initialized) return;
    s.dataset.initialized = '1';
    var current = 0;
    var interval = parseInt(s.dataset.interval, 10) || 5000;
    var autoplay = s.dataset.autoplay !== 'false';
    var animation = s.dataset.animation || 'slide';
    var dots = [].slice.call(s.querySelectorAll('.cms-builder-slide-dot'));
    var track = s.querySelector('.cms-builder-slide-track');

    // Ken Burns transforms cycle
    var kbTransforms = [
      'scale(1.15) translate(-2%, -1%)',
      'scale(1.20) translate(2%, 1%)',
      'scale(1.15) translate(-1%, 2%)',
      'scale(1.10) translate(1%, -2%)'
    ];
    var kbIdx = 0;

    function showSlide(idx) {
      if (animation === 'slide' || animation === 'carousel' || animation === 'coverflow') {
        // CSS transform sliding via flex track
        if (track) track.style.transform = 'translateX(-' + (idx * 100) + '%)';
      } else {
        // Stacked opacity transitions (fade, zoom, kenburns, flip)
        slides.forEach(function(slide, i) {
          var isActive = i === idx;
          slide.style.opacity = isActive ? '1' : '0';
          slide.style.zIndex = isActive ? '1' : '0';
          if (!isActive && slide.style.position !== 'absolute' && i > 0) {
            slide.style.position = 'absolute';
          }

          if (animation === 'kenburns') {
            var img = slide.querySelector('.cms-kb-img') || slide.querySelector('img');
            if (img) {
              if (isActive) {
                img.style.transition = 'transform 8s ease-in-out';
                img.style.transform = kbTransforms[kbIdx % kbTransforms.length];
              } else {
                img.style.transition = 'none';
                img.style.transform = 'scale(1)';
              }
            }
          } else if (animation === 'zoom') {
            var zImg = slide.querySelector('img');
            if (zImg) {
              zImg.style.transition = 'transform 0.6s ease-in-out';
              zImg.style.transform = isActive ? 'scale(1)' : 'scale(1.1)';
            }
          }
        });
        if (animation === 'kenburns') kbIdx++;
      }
      // Sync dot active state
      dots.forEach(function(dot, i) {
        dot.style.opacity = i === idx ? '1' : '0.5';
      });
    }

    // Initial state
    showSlide(0);

    // Autoplay timer
    var autoTimer = null;
    function startAutoTimer() {
      if (!autoplay) return;
      autoTimer = setInterval(function() {
        current = (current + 1) % slides.length;
        showSlide(current);
      }, interval);
    }
    function resetAutoTimer() {
      if (autoTimer) clearInterval(autoTimer);
      startAutoTimer();
    }
    startAutoTimer();

    // Arrow navigation
    var prevBtn = s.querySelector('.cms-builder-slide-prev');
    var nextBtn = s.querySelector('.cms-builder-slide-next');
    if (prevBtn) {
      prevBtn.addEventListener('click', function(e) {
        e.preventDefault();
        current = (current - 1 + slides.length) % slides.length;
        showSlide(current);
        resetAutoTimer();
      });
    }
    if (nextBtn) {
      nextBtn.addEventListener('click', function(e) {
        e.preventDefault();
        current = (current + 1) % slides.length;
        showSlide(current);
        resetAutoTimer();
      });
    }

    // Dot navigation
    dots.forEach(function(dot, i) {
      dot.addEventListener('click', function(e) {
        e.preventDefault();
        current = i;
        showSlide(current);
        resetAutoTimer();
      });
    });
  });

  // ─── Tab Panel Switching ──────────────────────────────────────────────
  document.querySelectorAll('.cms-builder-node--tabs').forEach(function(tabsWidget) {
    var buttons = [].slice.call(tabsWidget.querySelectorAll('.cms-builder-tab-btn'));
    var panels = [].slice.call(tabsWidget.querySelectorAll('.cms-builder-tab-panel'));
    if (buttons.length === 0 || panels.length === 0) return;

    // Activate first tab by default
    if (!buttons.some(function(b) { return b.classList.contains('active'); })) {
      buttons[0].classList.add('active');
      if (panels[0]) panels[0].classList.add('active');
    }

    buttons.forEach(function(btn, idx) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        // Deactivate all
        buttons.forEach(function(b) { b.classList.remove('active'); });
        panels.forEach(function(p) { p.classList.remove('active'); });
        // Activate clicked
        btn.classList.add('active');
        var targetId = btn.getAttribute('data-tab');
        var targetPanel = targetId
          ? tabsWidget.querySelector('.cms-builder-tab-panel[data-tab="' + targetId + '"]')
          : panels[idx];
        if (targetPanel) targetPanel.classList.add('active');
      });
    });
  });

  // ─── Counter Animation (count up on scroll into view) ─────────────────
  if ('IntersectionObserver' in window) {
    var counters = document.querySelectorAll('.cms-builder-node--counter');
    if (counters.length > 0) {
      var counterObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (!entry.isIntersecting || entry.target.dataset.counted) return;
          entry.target.dataset.counted = '1';
          var el = entry.target.querySelector('.cms-builder-counter-value');
          if (!el) return;
          var target = parseInt(el.getAttribute('data-target') || el.textContent, 10) || 0;
          var duration = parseInt(el.getAttribute('data-duration'), 10) || 2000;
          var prefix = el.getAttribute('data-prefix') || '';
          var suffix = el.getAttribute('data-suffix') || '';
          var start = 0;
          var startTime = null;

          function animateCounter(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            // ease-out quad
            var eased = 1 - (1 - progress) * (1 - progress);
            var currentVal = Math.round(eased * target);
            el.textContent = prefix + currentVal.toLocaleString() + suffix;
            if (progress < 1) {
              requestAnimationFrame(animateCounter);
            }
          }

          requestAnimationFrame(animateCounter);
        });
      }, { threshold: 0.3 });

      counters.forEach(function(c) { counterObserver.observe(c); });
    }
  }

  // ─── Flip Box Interaction ─────────────────────────────────────────────
  // Flip on click for touch devices, hover is handled via CSS
  document.querySelectorAll('.cms-builder-node--flip_box').forEach(function(flipBox) {
    flipBox.addEventListener('click', function() {
      flipBox.classList.toggle('flipped');
    });
  });

  // ─── Accordion (fallback for non-details) ────────────────────────────
  document.querySelectorAll('.cms-builder-accordion-header').forEach(function(header) {
    var parent = header.parentElement;
    if (parent && parent.tagName !== 'DETAILS') {
      header.addEventListener('click', function() {
        var body = parent.querySelector('.cms-builder-accordion-body');
        if (body) {
          var isOpen = body.style.display === 'block';
          body.style.display = isOpen ? 'none' : 'block';
          parent.classList.toggle('open', !isOpen);
        }
      });
    }
  });

  // ─── Entrance Animations (trigger on scroll into view) ────────────────
  if ('IntersectionObserver' in window) {
    var animatedElements = document.querySelectorAll('[data-animate]');
    if (animatedElements.length > 0) {
      var animObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          var duration = el.getAttribute('data-animate-duration');
          var delay = el.getAttribute('data-animate-delay');
          if (duration) el.style.transitionDuration = duration;
          if (delay) el.style.transitionDelay = delay;
          // Trigger the animation by adding the class
          el.classList.add('cms-animated');
          animObserver.unobserve(el);
        });
      }, { threshold: 0.15 });

      animatedElements.forEach(function(el) { animObserver.observe(el); });
    }
  } else {
    // Fallback: show everything immediately
    document.querySelectorAll('[data-animate]').forEach(function(el) {
      el.classList.add('cms-animated');
    });
  }

  // ─── Countdown Timer ──────────────────────────────────────────────────
  document.querySelectorAll('.cms-builder-node--countdown').forEach(function(cdWidget) {
    var targetAttr = cdWidget.getAttribute('data-target-date');
    if (!targetAttr) return;
    var targetTime = new Date(targetAttr).getTime();
    if (isNaN(targetTime)) return;
    var expiredMsg = cdWidget.getAttribute('data-expired-message') || 'Event has ended!';

    function updateCountdown() {
      var now = Date.now();
      var remaining = Math.max(0, Math.floor((targetTime - now) / 1000));
      if (remaining <= 0) {
        cdWidget.innerHTML = '<div style="text-align:center;padding:24px;font-size:18px;color:#6b7280">' + expiredMsg + '</div>';
        return;
      }
      var d = Math.floor(remaining / 86400);
      var h = Math.floor((remaining % 86400) / 3600);
      var m = Math.floor((remaining % 3600) / 60);
      var s = remaining % 60;
      var spans = cdWidget.querySelectorAll('.cms-countdown-value');
      if (spans.length >= 4) {
        spans[0].textContent = d;
        spans[1].textContent = h;
        spans[2].textContent = m;
        spans[3].textContent = s;
      }
      setTimeout(updateCountdown, 1000);
    }
    updateCountdown();
  });

  // ─── Alert Dismiss ────────────────────────────────────────────────────
  document.querySelectorAll('.cms-builder-alert-dismiss').forEach(function(btn) {
    btn.addEventListener('click', function() {
      var alert = btn.closest('.cms-builder-node--alert');
      if (alert) {
        alert.style.transition = 'opacity 0.3s';
        alert.style.opacity = '0';
        setTimeout(function() { alert.style.display = 'none'; }, 300);
      }
    });
  });
});
