import gsap from 'gsap';
import { initializeAnimations } from './animations';

initializeAnimations();

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
    const controller = new AbortController();
    const options = { signal: controller.signal };
    const animationContext = gsap.context(() => {}, carousel);
    const initialPanel = panels[activeIndex];

    initialPanel.classList.remove('is-entering');

    if (!reducedMotion.matches) {
        animationContext.add(() => {
            gsap.fromTo(initialPanel, {
                autoAlpha: 0,
                y: 8,
            }, {
                autoAlpha: 1,
                y: 0,
                duration: 0.36,
                ease: 'power3.out',
                clearProps: 'opacity,visibility,transform',
            });
        });
    }

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
            outgoing.classList.remove('is-entering');
            gsap.killTweensOf(outgoing);

            if (reducedMotion.matches) {
                outgoing.hidden = true;
                gsap.set(outgoing, { clearProps: 'opacity,visibility,transform' });
            } else {
                animationContext.add(() => {
                    gsap.to(outgoing, {
                        autoAlpha: 0,
                        y: -5,
                        duration: 0.2,
                        ease: 'power1.in',
                        onComplete: () => {
                            outgoing.hidden = true;
                            gsap.set(outgoing, { clearProps: 'opacity,visibility,transform' });
                        },
                    });
                });
            }
        }

        gsap.killTweensOf(incoming);
        incoming.hidden = false;
        incoming.classList.remove('is-leaving', 'is-entering');

        if (reducedMotion.matches) {
            gsap.set(incoming, { clearProps: 'opacity,visibility,transform' });
        } else {
            animationContext.add(() => {
                gsap.fromTo(incoming, {
                    autoAlpha: 0,
                    y: 8,
                }, {
                    autoAlpha: 1,
                    y: 0,
                    duration: 0.36,
                    ease: 'power3.out',
                    clearProps: 'opacity,visibility,transform',
                });
            });
        }

        restartProgress();
        scheduleNext();
    };

    previous?.addEventListener('click', () => showTestimonial(activeIndex - 1), options);
    next?.addEventListener('click', () => showTestimonial(activeIndex + 1), options);

    carousel.addEventListener('pointerdown', (event) => {
        if (event.pointerType === 'mouse') return;
        touchStartX = event.clientX;
        touchStartY = event.clientY;
        carousel.setPointerCapture(event.pointerId);
    }, { passive: true, signal: controller.signal });

    carousel.addEventListener('pointerup', (event) => {
        if (touchStartX === null || touchStartY === null) return;
        const deltaX = event.clientX - touchStartX;
        const deltaY = event.clientY - touchStartY;
        touchStartX = null;
        touchStartY = null;

        if (Math.abs(deltaX) < 45 || Math.abs(deltaX) < Math.abs(deltaY) * 1.2) return;
        showTestimonial(activeIndex + (deltaX < 0 ? 1 : -1));
    }, { passive: true, signal: controller.signal });

    carousel.addEventListener('pointercancel', () => {
        touchStartX = null;
        touchStartY = null;
    }, { passive: true, signal: controller.signal });

    carousel.addEventListener('mouseenter', () => {
        isPaused = true;
        window.clearTimeout(timer);
    }, options);
    carousel.addEventListener('mouseleave', () => {
        isPaused = false;
        scheduleNext();
    }, options);
    carousel.addEventListener('focusin', () => {
        isPaused = true;
        window.clearTimeout(timer);
    }, options);
    carousel.addEventListener('focusout', (event) => {
        if (!carousel.contains(event.relatedTarget)) {
            isPaused = false;
            scheduleNext();
        }
    }, options);
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) window.clearTimeout(timer);
        else scheduleNext();
    }, options);
    reducedMotion.addEventListener('change', () => {
        if (reducedMotion.matches) {
            window.clearTimeout(timer);
            gsap.killTweensOf(panels);
            panels.forEach((panel, index) => {
                panel.hidden = index !== activeIndex;
                gsap.set(panel, { clearProps: 'opacity,visibility,transform' });
            });
        }

        restartProgress();
        scheduleNext();
    }, options);

    restartProgress();
    scheduleNext();

    window.addEventListener('pagehide', (event) => {
        if (event.persisted) {
            return;
        }

        controller.abort();
        window.clearTimeout(timer);
        animationContext.revert();
    });
});
