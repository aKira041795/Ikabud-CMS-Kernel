/**
 * Featured Slider Functionality
 */
(function() {
    'use strict';
    
    // Wait for DOM to be ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSlider);
    } else {
        initSlider();
    }
    
    function initSlider() {
        const slider = document.getElementById('heroSlider');
        if (!slider) return;
        
        const slides = slider.querySelectorAll('.featured-slide');
        const dotsContainer = slider.parentElement.querySelector('.slider-dots');
        const prevBtn = slider.parentElement.querySelector('.slider-prev');
        const nextBtn = slider.parentElement.querySelector('.slider-next');
        
        if (slides.length === 0) return;
        
        let currentSlide = 0;
        const totalSlides = slides.length;
        let autoplayInterval;
        
        // Create dots
        slides.forEach((_, index) => {
            const dot = document.createElement('span');
            dot.classList.add('slider-dot');
            if (index === 0) dot.classList.add('active');
            dot.addEventListener('click', () => goToSlide(index));
            dotsContainer.appendChild(dot);
        });
        
        const dots = dotsContainer.querySelectorAll('.slider-dot');
        
        // Go to specific slide
        function goToSlide(index) {
            currentSlide = index;
            const offset = -100 * currentSlide;
            slider.style.transform = `translateX(${offset}%)`;
            
            // Update dots
            dots.forEach((dot, i) => {
                dot.classList.toggle('active', i === currentSlide);
            });
            
            // Reset autoplay
            resetAutoplay();
        }
        
        // Next slide
        function nextSlide() {
            currentSlide = (currentSlide + 1) % totalSlides;
            goToSlide(currentSlide);
        }
        
        // Previous slide
        function prevSlide() {
            currentSlide = (currentSlide - 1 + totalSlides) % totalSlides;
            goToSlide(currentSlide);
        }
        
        // Autoplay
        function startAutoplay() {
            autoplayInterval = setInterval(nextSlide, 5000); // Change slide every 5 seconds
        }
        
        function stopAutoplay() {
            if (autoplayInterval) {
                clearInterval(autoplayInterval);
            }
        }
        
        function resetAutoplay() {
            stopAutoplay();
            startAutoplay();
        }
        
        // Event listeners
        if (prevBtn) prevBtn.addEventListener('click', prevSlide);
        if (nextBtn) nextBtn.addEventListener('click', nextSlide);
        
        // Keyboard navigation
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowLeft') prevSlide();
            if (e.key === 'ArrowRight') nextSlide();
        });
        
        // Touch/swipe support
        let touchStartX = 0;
        let touchEndX = 0;
        
        slider.addEventListener('touchstart', (e) => {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });
        
        slider.addEventListener('touchend', (e) => {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });
        
        function handleSwipe() {
            const swipeThreshold = 50;
            const diff = touchStartX - touchEndX;
            
            if (Math.abs(diff) > swipeThreshold) {
                if (diff > 0) {
                    nextSlide(); // Swipe left
                } else {
                    prevSlide(); // Swipe right
                }
            }
        }
        
        // Pause autoplay on hover
        slider.parentElement.addEventListener('mouseenter', stopAutoplay);
        slider.parentElement.addEventListener('mouseleave', startAutoplay);
        
        // Start autoplay
        startAutoplay();
    }
})();
