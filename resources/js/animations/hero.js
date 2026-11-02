export function initializeHeroAnimations(gsap) {
    const hero = document.querySelector('[data-hero]');

    if (!hero) {
        return;
    }

    const timeline = gsap.timeline({
        defaults: {
            ease: 'power3.out',
        },
    });

    const title = hero.querySelector('[data-hero-title]');
    const titleLines = gsap.utils.toArray(hero.querySelectorAll('[data-hero-title-line]'));
    const description = hero.querySelector('[data-hero-description]');
    const cta = hero.querySelector('[data-hero-cta]');
    const desktopImages = gsap.utils.toArray(hero.querySelectorAll('[data-hero-image]'));
    const mobileImages = gsap.utils.toArray(hero.querySelectorAll('[data-hero-mobile-image]'));
    const trust = hero.querySelector('[data-hero-trust]');

    if (desktopImages.length) {
        timeline.fromTo(desktopImages, {
            clipPath: 'inset(100% 0 0 0)',
        }, {
            clipPath: 'inset(0% 0 0 0)',
            duration: 1.05,
            stagger: 0.12,
        }, 0);
    }

    if (mobileImages.length) {
        timeline.fromTo(mobileImages, {
            autoAlpha: 0,
            y: 18,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.65,
            stagger: 0.1,
        }, 0.1);
    }

    if (titleLines.length) {
        timeline.fromTo(titleLines, {
            autoAlpha: 0,
            y: 24,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.68,
            stagger: 0.1,
        }, 0.12);
    } else if (title) {
        timeline.fromTo(title, {
            autoAlpha: 0,
            y: 24,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.72,
        }, 0.12);
    }

    if (description) {
        timeline.fromTo(description, {
            autoAlpha: 0,
            y: 18,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.58,
        }, '-=0.35');
    }

    if (cta) {
        timeline.fromTo(cta, {
            autoAlpha: 0,
            y: 14,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.52,
        }, '-=0.28');
    }

    if (trust) {
        timeline.fromTo(trust, {
            autoAlpha: 0,
            y: 10,
        }, {
            autoAlpha: 1,
            y: 0,
            duration: 0.48,
        }, '-=0.18');
    }

    const bubbles = gsap.utils.toArray(hero.querySelectorAll('.hero-conversation__bubble'));

    if (bubbles.length) {
        const conversation = gsap.timeline({
            repeat: -1,
            defaults: {
                ease: 'power2.out',
            },
        });

        bubbles.forEach((bubble) => {
            const delay = Number.parseFloat(bubble.style.getPropertyValue('--conversation-delay')) || 0;

            conversation.fromTo(bubble, {
                autoAlpha: 0,
                y: 9,
                scale: 0.98,
            }, {
                autoAlpha: 1,
                y: 0,
                scale: 1,
                duration: 0.28,
            }, delay);

            conversation.to(bubble, {
                autoAlpha: 0,
                y: -4,
                duration: 0.22,
            }, delay + 1.55);
        });

        conversation.to({}, { duration: 28 }, 0);
    }
}
