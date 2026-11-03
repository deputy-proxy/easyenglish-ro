import gsap from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { initializeAvailabilityBookingTransition } from './availability-booking';
import { initializeBookingCta } from './booking-cta';
import { initializeCalendarAnimations } from './calendar';
import { initializeFaqAnimations } from './faq';
import { initializeHeroAnimations } from './hero';
import { initializeHeroShellNavigation } from './hero-shell-navigation';
import { initializeProgressAnimation } from './progress';
import { initializeScrollReveals } from './reveals';

gsap.registerPlugin(ScrollTrigger);

export function initializeAnimations() {
    const motionPreference = window.matchMedia('(prefers-reduced-motion: reduce)');

    initializeBookingCta(gsap, ScrollTrigger);
    initializeHeroShellNavigation();
    const bookingTransitionCleanup = initializeAvailabilityBookingTransition(gsap);

    if (motionPreference.matches) {
        return;
    }

    const cleanup = [bookingTransitionCleanup];
    let context;
    let disposed = false;

    const handlePageHide = (event) => {
        if (!event.persisted) {
            dispose();
        }
    };
    const dispose = () => {
        if (disposed) {
            return;
        }

        disposed = true;
        motionPreference.removeEventListener('change', handleMotionPreferenceChange);
        window.removeEventListener('pagehide', handlePageHide);
        cleanup.forEach((disposeAnimation) => disposeAnimation());
        context?.revert();
        document.documentElement.classList.remove('gsap-ready');
    };
    const handleMotionPreferenceChange = (event) => {
        if (event.matches) {
            dispose();
        }
    };

    try {
        context = gsap.context(() => {
            initializeHeroAnimations(gsap);
            initializeScrollReveals(gsap);
            cleanup.push(initializeProgressAnimation(gsap, ScrollTrigger));
            cleanup.push(initializeCalendarAnimations(gsap, ScrollTrigger));
            cleanup.push(initializeFaqAnimations(gsap));
        });

        document.documentElement.classList.add('gsap-ready');
        motionPreference.addEventListener('change', handleMotionPreferenceChange);
        window.addEventListener('pagehide', handlePageHide);
    } catch (error) {
        dispose();
        gsap.set('[data-reveal], [data-reveal-item], [data-reveal-background], [data-image-reveal], [data-hero-image], [data-hero-mobile-image], [data-hero-title], [data-hero-title-line], [data-hero-description], [data-hero-cta], [data-hero-trust], [data-progress-chart] [data-chart-grid], [data-progress-chart] [data-chart-labels], [data-chart-legend]', {
            clearProps: 'all',
        });
        gsap.set('.hero-conversation-mobile__viewport', { height: 'auto', overflow: 'visible' });
        gsap.set('.hero-conversation-mobile__track', { clearProps: 'transform' });
        gsap.set('.hero-conversation-mobile__message', { autoAlpha: 1, clearProps: 'transform' });
        console.error('EasyEnglish animations could not be initialized.', error);
    }
}
