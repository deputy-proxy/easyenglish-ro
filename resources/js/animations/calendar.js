export function initializeCalendarAnimations(gsap, ScrollTrigger) {
    const controller = new AbortController();
    const options = { signal: controller.signal };
    let direction = 0;
    const root = document.querySelector('[data-availability]');
    let hasEntered = false;
    const animationContext = gsap.context(() => {}, root || document);

    if (root) {
        ScrollTrigger.create({
            trigger: root,
            start: 'top 86%',
            once: true,
            onEnter: () => {
                hasEntered = true;
                const items = [
                    ...Array.from(root.querySelector('[data-weekgrid]')?.children || []),
                    ...Array.from(root.querySelector('[data-weekgrid-mobile]')?.children || []),
                ];

                animationContext.add(() => {
                    gsap.fromTo(items, { autoAlpha: 0, y: 7 }, {
                        autoAlpha: 1,
                        y: 0,
                        duration: 0.32,
                        stagger: 0.025,
                        ease: 'power3.out',
                        clearProps: 'transform,opacity,visibility',
                    });
                });
            },
        });
    }

    document.addEventListener('easyenglish:availability-loading', (event) => {
        direction = Number(event.detail?.direction) || 0;

        if (direction === 0) {
            return;
        }

        if (!hasEntered || !root) {
            return;
        }

        const grids = root?.querySelectorAll('[data-weekgrid], [data-weekgrid-mobile]');

        grids?.forEach((grid) => {
            animationContext.add(() => {
                gsap.to(grid.children, {
                    autoAlpha: 0,
                    x: direction * -8,
                    duration: 0.12,
                    stagger: 0.008,
                    ease: 'power1.in',
                    overwrite: true,
                });
            });
        });
    }, options);

    document.addEventListener('easyenglish:availability-rendered', (event) => {
        direction = Number(event.detail?.direction) || direction;

        if (!hasEntered) {
            return;
        }

        const currentRoot = event.detail?.root || root;
        const desktopGrid = currentRoot?.querySelector('[data-weekgrid]');
        const mobileGrid = currentRoot?.querySelector('[data-weekgrid-mobile]');
        const items = [
            ...Array.from(desktopGrid?.children || []),
            ...Array.from(mobileGrid?.children || []),
        ];

        if (!items.length) {
            return;
        }

        animationContext.add(() => {
            gsap.fromTo(items, {
                autoAlpha: 0,
                x: direction * 10,
                y: direction === 0 ? 7 : 0,
            }, {
                autoAlpha: 1,
                x: 0,
                y: 0,
                duration: 0.32,
                stagger: 0.025,
                ease: 'power3.out',
                clearProps: 'transform,opacity,visibility',
                overwrite: true,
            });
        });

        direction = 0;
    }, options);

    return () => {
        controller.abort();
        animationContext.revert();
    };
}
