export function initializeFaqAnimations(gsap) {
    const section = document.querySelector('[data-faq-section]');

    if (!section) {
        return () => {};
    }

    const controller = new AbortController();
    const animationContext = gsap.context(() => {}, section);
    const options = { signal: controller.signal };

    section.querySelectorAll('details').forEach((details) => {
        const summary = details.querySelector('summary');
        const answer = details.querySelector('[data-faq-answer]');
        const chevron = summary?.querySelector('[data-faq-chevron]');

        if (!summary || !answer) {
            return;
        }

        summary.addEventListener('click', (event) => {
            event.preventDefault();
            gsap.killTweensOf(answer);

            if (details.open) {
                animationContext.add(() => {
                    gsap.set(answer, { overflow: 'hidden' });

                    if (chevron) {
                        gsap.to(chevron, { rotation: 0, duration: 0.2, ease: 'power1.inOut' });
                    }

                    gsap.to(answer, {
                        height: 0,
                        autoAlpha: 0,
                        duration: 0.22,
                        ease: 'power1.in',
                        onComplete: () => {
                            details.open = false;
                            gsap.set(answer, { clearProps: 'height,opacity,visibility,overflow' });
                        },
                    });
                });

                return;
            }

            details.open = true;
            animationContext.add(() => {
                if (chevron) {
                    gsap.to(chevron, { rotation: 180, duration: 0.24, ease: 'power2.out' });
                }

                gsap.fromTo(answer, {
                    height: 0,
                    autoAlpha: 0,
                    overflow: 'hidden',
                }, {
                    height: 'auto',
                    autoAlpha: 1,
                    duration: 0.3,
                    ease: 'power2.out',
                    onComplete: () => gsap.set(answer, { clearProps: 'height,opacity,visibility,overflow' }),
                });
            });
        }, options);
    });

    return () => {
        controller.abort();
        animationContext.revert();
    };
}
