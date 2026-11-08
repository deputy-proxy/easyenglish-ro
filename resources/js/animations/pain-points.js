export function initializePainPointStack(gsap, ScrollTrigger, root = document) {
    const section = root.querySelector('[data-pain-point-stack]');

    if (!section) {
        return () => {};
    }

    const cards = Array.from(section.querySelectorAll('[data-pain-point-card]'));
    const contents = cards.map((card) => card.querySelector('[data-pain-point-content]'));

    if (cards.length < 2 || contents.some((content) => !content)) {
        return () => {};
    }

    const view = section.ownerDocument.defaultView;
    let context;
    let disposed = false;

    const updateStackLayout = () => {
        let stackOffset = 0;
        const minimumStep = view.matchMedia('(max-width: 767px)').matches ? 112 : 88;

        cards.forEach((card, index) => {
            card.style.setProperty('--stack-offset', `${stackOffset}px`);
            card.style.setProperty('--stack-order', String(index + 1));

            if (index < contents.length - 1) {
                const compactContentHeight = contents[index].offsetHeight * 0.76;
                const visibleContentHeight = 24 + compactContentHeight + 16;

                stackOffset += Math.max(minimumStep, Math.ceil(visibleContentHeight));
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

    const setShadowCard = (activeIndex) => {
        cards.forEach((card, index) => {
            card.classList.toggle('has-stack-shadow', index === activeIndex);
        });
    };

    updateStackLayout();
    setShadowCard(0);

    try {
        context = gsap.context(() => {
            cards.slice(0, -1).forEach((_, index) => {
                const nextCard = cards[index + 1];
                const content = contents[index];
                const animation = gsap.fromTo(content, {
                    scale: 1,
                    y: 0,
                    transformOrigin: 'top left',
                }, {
                    scale: 0.76,
                    y: () => 24 - content.offsetTop,
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
                    onEnter: () => setShadowCard(index + 1),
                    onEnterBack: () => setShadowCard(index + 1),
                    onLeaveBack: () => setShadowCard(index),
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
            card.classList.remove('has-stack-shadow');
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
            card.classList.remove('has-stack-shadow');
            card.style.removeProperty('--stack-offset');
            card.style.removeProperty('--stack-order');
        });
    };
}
