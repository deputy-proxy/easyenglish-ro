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
            gsap.killTweensOf([details, answer, chevron].filter(Boolean));

            if (details.open) {
                animationContext.add(() => {
                    gsap.to(details, { flexGrow: 1, duration: 0.24, ease: 'power1.inOut' });
                    gsap.to(answer, {
                        autoAlpha: 0,
                        y: 6,
                        duration: 0.16,
                        ease: 'power1.in',
                        onComplete: () => {
                            details.open = false;
                            gsap.set(answer, { clearProps: 'opacity,visibility,transform' });
                        },
                    });

                    if (chevron) {
                        gsap.to(chevron, { rotation: 0, duration: 0.2, ease: 'power1.inOut' });
                    }
                });

                return;
            }

            details.open = true;
            animationContext.add(() => {
                gsap.to(details, { flexGrow: 4, duration: 0.3, ease: 'power2.out' });
                gsap.fromTo(answer, { autoAlpha: 0, y: 8 }, {
                    autoAlpha: 1,
                    y: 0,
                    duration: 0.28,
                    ease: 'power2.out',
                    onComplete: () => gsap.set(answer, { clearProps: 'opacity,visibility,transform' }),
                });

                if (chevron) {
                    gsap.to(chevron, { rotation: 180, duration: 0.24, ease: 'power2.out' });
                }
            });
        }, options);
    });

    return () => {
        controller.abort();
        animationContext.revert();
    };
}
