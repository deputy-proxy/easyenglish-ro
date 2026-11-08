export function initializePainPointStack(gsap, ScrollTrigger, root = document) {
    const section = root.querySelector('[data-pain-point-stack]');

    if (!section) {
        return () => {};
    }

    const cards = Array.from(section.querySelectorAll('[data-pain-point-card]'));
    const titles = cards.map((card) => card.querySelector('[data-pain-point-title]'));

    if (cards.length < 2 || titles.some((title) => !title)) {
        return () => {};
    }

    const view = section.ownerDocument.defaultView;
    let context;
    let disposed = false;

    const updateStackLayout = () => {
        let stackOffset = 0;
        const minimumStep = view.matchMedia('(max-width: 767px)').matches ? 96 : 76;

        cards.forEach((card, index) => {
            card.style.setProperty('--stack-offset', `${stackOffset}px`);
            card.style.setProperty('--stack-order', String(index + 1));

            if (index < titles.length - 1) {
                const compactTitleHeight = titles[index].offsetHeight * 0.76;
                const visibleTitleHeight = 24 + compactTitleHeight + 16;

                stackOffset += Math.max(minimumStep, Math.ceil(visibleTitleHeight));
            }
        });
    };

    const refreshStackLayout = () => {
        if (disposed) {
            return;
        }

        updateStackLayout();
        ScrollTrigger.refresh();
    };

    updateStackLayout();

    try {
        context = gsap.context(() => {
            cards.slice(0, -1).forEach((_, index) => {
                const nextCard = cards[index + 1];
                const title = titles[index];
                const animation = gsap.fromTo(title, {
                    scale: 1,
                    y: 0,
                    transformOrigin: 'top left',
                }, {
                    scale: 0.76,
                    y: () => 24 - title.offsetTop,
                    ease: 'none',
                    paused: true,
                });

                ScrollTrigger.create({
                    trigger: nextCard,
                    start: 'top 76%',
                    end: () => {
                        const sectionStyles = view.getComputedStyle(section);
                        const cardStyles = view.getComputedStyle(nextCard);
                        const stackTop = Number.parseFloat(sectionStyles.getPropertyValue('--pain-point-stack-top')) || 24;
                        const stackOffset = Number.parseFloat(cardStyles.getPropertyValue('--stack-offset')) || 0;

                        return `top ${stackTop + stackOffset}px`;
                    },
                    animation,
                    scrub: 0.45,
                    invalidateOnRefresh: true,
                });
            });
        }, section);

        section.classList.add('is-stacking-enabled');
        view.addEventListener('resize', refreshStackLayout, { passive: true });
        section.ownerDocument.fonts?.ready.then(refreshStackLayout);
        ScrollTrigger.refresh();
    } catch (error) {
        disposed = true;
        view.removeEventListener('resize', refreshStackLayout);
        context?.revert();
        section.classList.remove('is-stacking-enabled');
        cards.forEach((card) => {
            card.style.removeProperty('--stack-offset');
            card.style.removeProperty('--stack-order');
        });
        throw error;
    }

    return () => {
        disposed = true;
        view.removeEventListener('resize', refreshStackLayout);
        context.revert();
        section.classList.remove('is-stacking-enabled');
        cards.forEach((card) => {
            card.style.removeProperty('--stack-offset');
            card.style.removeProperty('--stack-order');
        });
    };
}
