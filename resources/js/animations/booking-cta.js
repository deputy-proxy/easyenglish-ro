export function initializeBookingCta(gsap, ScrollTrigger) {
    const cta = document.querySelector('[data-mobile-booking-cta]');
    const heroCta = document.querySelector('[data-hero-cta]');
    const blockers = Array.from(document.querySelectorAll('[data-mobile-booking-cta-blocker]'));

    if (!cta || !heroCta || blockers.length === 0) {
        return () => {};
    }

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    const triggers = [];
    const blockerStates = new Map();
    const heroRect = heroCta.getBoundingClientRect();
    let heroCtaVisible = heroRect.top < window.innerHeight && heroRect.bottom > 0;
    let hasHeroCtaEntered = heroRect.bottom <= 0 || heroCtaVisible;
    let isVisible = false;
    let disposed = false;

    const syncVisibility = () => {
        const blocked = Array.from(blockerStates.values()).some(Boolean);
        const shouldBeVisible = hasHeroCtaEntered && !heroCtaVisible && !blocked;

        if (shouldBeVisible === isVisible) {
            return;
        }

        isVisible = shouldBeVisible;
        gsap.killTweensOf(cta);

        if (isVisible) {
            cta.hidden = false;
            cta.inert = false;
            cta.setAttribute('aria-hidden', 'false');

            if (reducedMotion.matches) {
                gsap.set(cta, { clearProps: 'opacity,visibility,transform' });
                return;
            }

            gsap.fromTo(cta, {
                autoAlpha: 0,
                y: 8,
            }, {
                autoAlpha: 1,
                y: 0,
                duration: 0.24,
                ease: 'power2.out',
                clearProps: 'opacity,visibility,transform',
            });

            return;
        }

        cta.inert = true;
        cta.setAttribute('aria-hidden', 'true');

        const hide = () => {
            if (isVisible) {
                return;
            }

            cta.hidden = true;
            cta.inert = true;
            cta.setAttribute('aria-hidden', 'true');
            gsap.set(cta, { clearProps: 'opacity,visibility,transform' });
        };

        if (reducedMotion.matches) {
            hide();
            return;
        }

        gsap.to(cta, {
            autoAlpha: 0,
            y: 8,
            duration: 0.16,
            ease: 'power1.in',
            onComplete: hide,
        });
    };

    const heroTrigger = ScrollTrigger.create({
        trigger: heroCta,
        start: 'top bottom',
        end: 'bottom top',
        onToggle: (self) => {
            heroCtaVisible = self.isActive;

            if (self.isActive) {
                hasHeroCtaEntered = true;
            }

            syncVisibility();
        },
    });

    triggers.push(heroTrigger);

    blockers.forEach((element, index) => {
        const trigger = ScrollTrigger.create({
            trigger: element,
            start: 'top 88%',
            end: 'bottom 12%',
            onToggle: (self) => {
                blockerStates.set(index, self.isActive);
                syncVisibility();
            },
        });

        triggers.push(trigger);
        blockerStates.set(index, trigger.isActive);
    });

    ScrollTrigger.refresh();
    heroCtaVisible = heroTrigger.isActive;

    if (heroCtaVisible) {
        hasHeroCtaEntered = true;
    }

    triggers.slice(1).forEach((trigger, index) => {
        blockerStates.set(index, trigger.isActive);
    });

    syncVisibility();

    const handleMotionPreferenceChange = (event) => {
        if (!event.matches) {
            return;
        }

        gsap.killTweensOf(cta);

        if (isVisible) {
            gsap.set(cta, { clearProps: 'opacity,visibility,transform' });
        } else {
            cta.hidden = true;
            cta.inert = true;
            cta.setAttribute('aria-hidden', 'true');
            gsap.set(cta, { clearProps: 'opacity,visibility,transform' });
        }
    };

    const cleanup = () => {
        if (disposed) {
            return;
        }

        disposed = true;
        triggers.forEach((trigger) => trigger.kill());
        gsap.killTweensOf(cta);
        reducedMotion.removeEventListener('change', handleMotionPreferenceChange);
        window.removeEventListener('pagehide', handlePageHide);
    };

    const handlePageHide = (event) => {
        if (!event.persisted) {
            cleanup();
        }
    };

    reducedMotion.addEventListener('change', handleMotionPreferenceChange);
    window.addEventListener('pagehide', handlePageHide);

    return cleanup;
}
