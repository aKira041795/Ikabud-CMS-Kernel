/**
 * Minimalist Theme - JavaScript
 */

document.addEventListener('DOMContentLoaded', function() {
    // Initialize theme functionality

    // Contact form handler (if form exists on page)
    const contactForm = document.getElementById('contact-form');
    if (contactForm) {
        contactForm.addEventListener('submit', handleContactSubmit);
    }

    // Add smooth scroll to anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function(e) {
            const href = this.getAttribute('href');
            if (href !== '#' && document.querySelector(href)) {
                e.preventDefault();
                document.querySelector(href).scrollIntoView({
                    behavior: 'smooth'
                });
            }
        });
    });
});

/**
 * Handle contact form submission
 */
function handleContactSubmit(e) {
    e.preventDefault();

    const form = e.target;
    const name = form.querySelector('[name="name"]').value;
    const email = form.querySelector('[name="email"]').value;
    const message = form.querySelector('[name="message"]').value;

    // Validate form
    if (!name || !email || !message) {
        alert('Please fill in all fields.');
        return;
    }

    if (message.length < 10) {
        alert('Message must be at least 10 characters long.');
        return;
    }

    // Submit form
    fetch('/api/v1/contact-form/submit', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            name: name,
            email: email,
            message: message
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.ok) {
            alert(data.message);
            form.reset();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while submitting the form.');
    });
}

/**
 * Mobile menu toggle (if needed)
 */
function toggleMobileMenu() {
    const nav = document.querySelector('.site-nav');
    if (nav) {
        nav.classList.toggle('active');
    }
}

// Utility: Add active class to current page navigation link
function highlightCurrentNavLink() {
    const currentPath = window.location.pathname;
    document.querySelectorAll('.site-nav a').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
}

// Call utility function on page load
document.addEventListener('DOMContentLoaded', highlightCurrentNavLink);
