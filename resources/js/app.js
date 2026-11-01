document.querySelectorAll('[data-testimonials-carousel]').forEach((carousel) => {
    const panels = Array.from(carousel.querySelectorAll('[data-testimonial-panel]'));
    const selectors = Array.from(carousel.querySelectorAll('[data-testimonial-selector]'));
    const section = carousel.closest('#testimonials');
    const previous = section?.querySelector('[data-testimonial-previous]');
    const next = section?.querySelector('[data-testimonial-next]');

    if (panels.length < 2 || panels.length !== selectors.length) {
        return;
    }

    let activeIndex = 0;

    const showTestimonial = (nextIndex) => {
        activeIndex = (nextIndex + panels.length) % panels.length;

        panels.forEach((panel, index) => {
            const isActive = index === activeIndex;

            panel.hidden = !isActive;

            if (isActive) {
                panel.classList.remove('is-entering');
                // Restart the entrance animation when a previously hidden panel becomes visible.
                void panel.offsetWidth;
                panel.classList.add('is-entering');
            }
        });

        selectors.forEach((selector, index) => {
            const isActive = index === activeIndex;

            selector.dataset.active = String(isActive);
            selector.setAttribute('aria-pressed', String(isActive));
        });
    };

    previous?.addEventListener('click', () => showTestimonial(activeIndex - 1));
    next?.addEventListener('click', () => showTestimonial(activeIndex + 1));

    selectors.forEach((selector, index) => {
        selector.addEventListener('click', () => showTestimonial(index));
    });
});
