export function initializePainPointStack(gsap, ScrollTrigger, root = document) {
    const section = root.querySelector('[data-pain-point-stack]');

    if (!section) {
        return () => {};
    }

    const cards = Array.from(section.querySelectorAll('[data-pain-point-card]'));
    if (cards.length < 2) {
        return () => {};
    }

    const view = section.ownerDocument.defaultView;
    let context;
    let disposed = false;

    const updateStackLayout = () => {
        cards.forEach((card, index) => {
            card.style.setProperty('--stack-offset', '0px');
            card.style.setProperty('--stack-order', String(index + 1));
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
            card.classList.remove('is-compact');
        });
    };

    updateStackLayout();
    setShadowCard(0);

    try {
        context = gsap.context(() => {
            cards.slice(0, -1).forEach((_, index) => {
                const nextCard = cards[index + 1];
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
            card.classList.remove('has-stack-shadow', 'is-compact');
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
            card.classList.remove('has-stack-shadow', 'is-compact');
            card.style.removeProperty('--stack-offset');
            card.style.removeProperty('--stack-order');
        });
    };
}
