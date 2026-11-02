export function initializeScrollReveals(gsap) {
    const mobile = window.matchMedia('(max-width: 767px)').matches;
    const distance = mobile ? 14 : 22;
    const duration = mobile ? 0.5 : 0.64;

    gsap.utils.toArray('[data-reveal-stagger]').forEach((group) => {
        const items = gsap.utils.toArray(group.querySelectorAll(':scope > [data-reveal-item]'));

        if (!items.length) {
            return;
        }

        gsap.fromTo(items, {
            autoAlpha: 0,
            y: distance,
        }, {
            autoAlpha: 1,
            y: 0,
            duration,
            stagger: mobile ? 0.07 : 0.12,
            ease: 'power3.out',
            clearProps: 'transform,opacity,visibility',
            scrollTrigger: {
                trigger: group,
                start: 'top 86%',
                once: true,
            },
        });
    });

    gsap.utils.toArray('[data-reveal]').forEach((element) => {
        if (element.closest('[data-reveal-stagger]')) {
            return;
        }

        gsap.fromTo(element, {
            autoAlpha: 0,
            y: distance,
        }, {
            autoAlpha: 1,
            y: 0,
            duration,
            ease: 'power3.out',
            clearProps: 'transform,opacity,visibility',
            scrollTrigger: {
                trigger: element,
                start: 'top 88%',
                once: true,
            },
        });
    });

    gsap.utils.toArray('[data-image-reveal]').forEach((image) => {
        if (mobile) {
            gsap.fromTo(image, {
                autoAlpha: 0,
                y: 12,
            }, {
                autoAlpha: 1,
                y: 0,
                duration: 0.55,
                ease: 'power3.out',
                clearProps: 'transform,opacity,visibility',
                scrollTrigger: {
                    trigger: image,
                    start: 'top 88%',
                    once: true,
                },
            });

            return;
        }

        gsap.fromTo(image, {
            clipPath: 'inset(8% 0 8% 0)',
            scale: 1.035,
        }, {
            clipPath: 'inset(0% 0 0% 0)',
            scale: 1,
            duration: 0.9,
            ease: 'power3.out',
            clearProps: 'clipPath,transform',
            scrollTrigger: {
                trigger: image,
                start: 'top 88%',
                once: true,
            },
        });
    });

    gsap.utils.toArray('[data-reveal-background]').forEach((background) => {
        gsap.fromTo(background, { autoAlpha: 0 }, {
            autoAlpha: 1,
            duration: mobile ? 0.4 : 0.55,
            ease: 'power2.out',
            clearProps: 'opacity,visibility',
            scrollTrigger: {
                trigger: background,
                start: 'top 86%',
                once: true,
            },
        });
    });
}
