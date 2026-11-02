document.querySelectorAll('[data-testimonials-carousel]').forEach((carousel) => {
    const panels = Array.from(carousel.querySelectorAll('[data-testimonial-panel]'));
    const progress = Array.from(carousel.querySelectorAll('[data-testimonial-progress]'));
    const section = carousel.closest('#testimonials');
    const previous = section?.querySelector('[data-testimonial-previous]');
    const next = section?.querySelector('[data-testimonial-next]');
    const duration = Number(carousel.dataset.autoplayDuration) || 7000;
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    if (panels.length < 2 || panels.length !== progress.length) {
        return;
    }

    let activeIndex = 0;
    let timer = null;
    let isPaused = false;
    let touchStartX = null;
    let touchStartY = null;
    const hideTimers = new WeakMap();

    const restartProgress = () => {
        progress.forEach((bar, index) => {
            bar.style.animation = 'none';
            bar.style.transform = 'scaleX(0)';
            if (index === activeIndex && !reducedMotion.matches) {
                void bar.offsetWidth;
                bar.style.animation = `testimonial-progress ${duration}ms linear forwards`;
            }
        });
    };

    const scheduleNext = () => {
        window.clearTimeout(timer);
        if (!reducedMotion.matches && !document.hidden && !isPaused) {
            timer = window.setTimeout(() => showTestimonial(activeIndex + 1), duration);
        }
    };

    const showTestimonial = (nextIndex) => {
        const outgoing = panels[activeIndex];
        activeIndex = (nextIndex + panels.length) % panels.length;
        const incoming = panels[activeIndex];

        if (outgoing !== incoming) {
            window.clearTimeout(hideTimers.get(outgoing));
            outgoing.classList.remove('is-entering');
            outgoing.classList.add('is-leaving');
            hideTimers.set(outgoing, window.setTimeout(() => {
                outgoing.hidden = true;
                outgoing.classList.remove('is-leaving');
            }, reducedMotion.matches ? 0 : 240));
        }

        window.clearTimeout(hideTimers.get(incoming));
        incoming.hidden = false;
        incoming.classList.remove('is-leaving', 'is-entering');
        void incoming.offsetWidth;
        incoming.classList.add('is-entering');

        restartProgress();
        scheduleNext();
    };

    previous?.addEventListener('click', () => showTestimonial(activeIndex - 1));
    next?.addEventListener('click', () => showTestimonial(activeIndex + 1));

    carousel.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse') return;
        touchStartX = event.clientX;
        touchStartY = event.clientY;
        carousel.setPointerCapture(event.pointerId);
    }, { passive: true });

    carousel.addEventListener('pointerup', (event) => {
        if (touchStartX === null || touchStartY === null) return;
        const deltaX = event.clientX - touchStartX;
        const deltaY = event.clientY - touchStartY;
        touchStartX = null;
        touchStartY = null;
        if (Math.abs(deltaX) < 45 || Math.abs(deltaX) < Math.abs(deltaY) * 1.2) return;
        showTestimonial(activeIndex + (deltaX < 0 ? 1 : -1));
    }, { passive: true });

    carousel.addEventListener('pointercancel', () => {
        touchStartX = null;
        touchStartY = null;
    }, { passive: true });

    carousel.addEventListener('mouseenter', () => {
        isPaused = true;
        window.clearTimeout(timer);
    });
    carousel.addEventListener('mouseleave', () => {
        isPaused = false;
        scheduleNext();
    });
    carousel.addEventListener('focusin', () => {
        isPaused = true;
        window.clearTimeout(timer);
    });
    carousel.addEventListener('focusout', (event) => {
        if (!carousel.contains(event.relatedTarget)) {
            isPaused = false;
            scheduleNext();
        }
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) window.clearTimeout(timer);
        else scheduleNext();
    });
    reducedMotion.addEventListener('change', () => {
        restartProgress();
        scheduleNext();
    });

    restartProgress();
    scheduleNext();
});
