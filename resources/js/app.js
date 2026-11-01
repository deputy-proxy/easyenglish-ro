document.querySelectorAll('[data-testimonials-carousel]').forEach((carousel) => {
    const panels = Array.from(carousel.querySelectorAll('[data-testimonial-panel]'));
    const selectors = Array.from(carousel.querySelectorAll('[data-testimonial-selector]'));
    const previous = carousel.parentElement.querySelector('[data-testimonial-previous]');
    const next = carousel.parentElement.querySelector('[data-testimonial-next]');

    if (panels.length < 2 || panels.length !== selectors.length) {
        return;
    }

    let activeIndex = 0;

    const showTestimonial = (nextIndex) => {
        activeIndex = (nextIndex + panels.length) % panels.length;

        panels.forEach((panel, index) => {
            panel.hidden = index !== activeIndex;
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
