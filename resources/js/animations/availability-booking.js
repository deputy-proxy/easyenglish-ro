import { submitBookingAuthForm } from '../booking/booking-auth';

export function initializeAvailabilityBookingTransition(gsap) {
    const root = document.querySelector('[data-availability]');
    const content = root?.querySelector('[data-availability-content]');
    const formPanel = root?.querySelector('[data-booking-form-panel]');
    const closeButton = formPanel?.querySelector('[data-booking-form-close]');
    const getSelectedSlots = () => formPanel?.querySelectorAll('[data-booking-starts-at]') ?? [];
    const updateUrlSlot = (startsAt) => {
        const url = new URL(window.location.href);
        if (startsAt) {
            url.searchParams.set('slot', startsAt);
        } else {
            url.searchParams.delete('slot');
        }
        window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
    };
    const slotLabel = formPanel?.querySelector('[data-booking-slot-label]');
    const feedback = formPanel?.querySelector('[data-booking-feedback]');
    const stepContent = formPanel?.querySelector('[data-booking-step-content]');
    if (!formPanel) {
        return () => {};
    }

    const controller = new AbortController();
    const options = { signal: controller.signal };

    // Account switching must work independently of calendar animation setup.
    formPanel.addEventListener('click', (event) => {
        const toggle = event.target.closest('[data-booking-branch-toggle]');

        if (!toggle || !formPanel.contains(toggle)) return;

        const showLogin = toggle.dataset.bookingBranchToggle === 'login';
        const registerBranch = formPanel.querySelector('[data-booking-register-branch]');
        const loginBranch = formPanel.querySelector('[data-booking-login-branch]');

        registerBranch?.classList.toggle('hidden', showLogin);
        loginBranch?.classList.toggle('hidden', !showLogin);
        formPanel.querySelectorAll('[data-booking-branch-toggle]').forEach((branchToggle) => {
            branchToggle.classList.toggle('hidden', branchToggle.dataset.bookingBranchToggle === (showLogin ? 'login' : 'register'));
        });

        (showLogin ? loginBranch : registerBranch)?.querySelector('input:not([type=\"hidden\"])')?.focus();
    }, options);

    if (!root || !content || !closeButton || getSelectedSlots().length === 0 || !slotLabel) {
        return () => controller.abort();
    }
    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let isOpen = false;
    let lastTrigger = null;
    let activeTimeline = null;

    const setVisible = (visible) => {
        formPanel.classList.toggle('invisible', !visible);
        formPanel.classList.toggle('pointer-events-none', !visible);
        formPanel.setAttribute('aria-hidden', String(!visible));
        content.setAttribute('aria-hidden', String(visible));
        content.inert = visible;
    };

    const open = ({ startsAt, label, trigger = null }) => {
        if (!startsAt) {
            return;
        }

        getSelectedSlots().forEach((input) => { input.value = startsAt; });
        updateUrlSlot(startsAt);
        slotLabel.textContent = label || startsAt;
        lastTrigger = trigger;

        if (isOpen) {
            return;
        }

        isOpen = true;
        const contentHeight = content.getBoundingClientRect().height;
        const formHeight = formPanel.scrollHeight;
        const intervalCards = [
            ...root.querySelectorAll('[data-weekgrid] > *, [data-weekgrid-mobile] > *'),
        ];
        root.style.height = `${contentHeight}px`;
        setVisible(true);

        if (reducedMotion.matches) {
            content.style.visibility = 'hidden';
            content.style.opacity = '0';
            formPanel.style.opacity = '1';
            formPanel.style.visibility = 'visible';
            root.style.height = `${formHeight}px`;
        } else {
            activeTimeline?.kill();
            activeTimeline = gsap.timeline({ defaults: { ease: 'power3.out' } });
            activeTimeline
                .to(intervalCards, { autoAlpha: 0, y: 22, duration: 0.24, stagger: 0.012, ease: 'power2.in' }, 0)
                .to(content, { autoAlpha: 0, y: 26, duration: 0.32, ease: 'power2.in' }, 0.04)
                .fromTo(formPanel, { autoAlpha: 0, y: -28 }, { autoAlpha: 1, y: 0, duration: 0.42 }, 0.08)
                .to(root, { height: formHeight, duration: 0.42, ease: 'power2.inOut' }, 0);
        }

        root.scrollIntoView({ behavior: reducedMotion.matches ? 'auto' : 'smooth', block: 'nearest' });
    };

    const close = () => {
        if (!isOpen) {
            return;
        }

        isOpen = false;
        getSelectedSlots().forEach((input) => { input.value = ''; });
        updateUrlSlot('');
        root.dataset.initialSlot = '';
        const contentHeight = content.getBoundingClientRect().height;
        const intervalCards = [
            ...root.querySelectorAll('[data-weekgrid] > *, [data-weekgrid-mobile] > *'),
        ];

        if (reducedMotion.matches) {
            content.style.visibility = '';
            content.style.opacity = '';
            content.style.transform = '';
            formPanel.style.opacity = '';
            formPanel.style.visibility = '';
            root.style.height = '';
            setVisible(false);
        } else {
            activeTimeline?.kill();
            activeTimeline = gsap.timeline({
                defaults: { ease: 'power3.out' },
                onComplete: () => {
                    setVisible(false);
                    gsap.set([content, formPanel, ...intervalCards], { clearProps: 'all' });
                    root.style.height = '';
                },
            });
            activeTimeline
                .to(formPanel, { autoAlpha: 0, y: -24, duration: 0.24, ease: 'power2.in' }, 0)
                .fromTo(content, { autoAlpha: 0, y: 24 }, { autoAlpha: 1, y: 0, duration: 0.34 }, 0.06)
                .fromTo(intervalCards, { autoAlpha: 0, y: 22 }, { autoAlpha: 1, y: 0, duration: 0.3, stagger: 0.012 }, 0.1)
                .to(root, { height: contentHeight, duration: 0.36, ease: 'power2.inOut' }, 0);
        }

        if (lastTrigger?.isConnected) {
            lastTrigger.focus({ preventScroll: true });
        }
        lastTrigger = null;
    };

    formPanel.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-booking-async-form]');
        if (!form || !formPanel.contains(form)) return;

        event.preventDefault();
        const errorContainer = form.querySelector('[data-booking-form-errors]');
        if (errorContainer) {
            errorContainer.textContent = '';
            errorContainer.hidden = true;
        }

        await submitBookingAuthForm(form, {
            csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            onState: (state) => {
                const messages = {
                    submitting: formPanel.dataset.bookingSubmittingMessage,
                    network_error: formPanel.dataset.bookingNetworkError,
                    server_error: formPanel.dataset.bookingServerError,
                    session_expired: formPanel.dataset.bookingSessionExpired,
                    rate_limited: formPanel.dataset.bookingRateLimited,
                    two_factor_required: formPanel.dataset.bookingTwoFactorMessage,
                };
                if (feedback) {
                    const message = messages[state] ?? '';
                    feedback.textContent = message;
                    feedback.hidden = message === '';
                }
            },
            onError: (result) => {
                const validationMessages = Object.values(result.errors ?? {}).flat().filter((message) => typeof message === 'string');
                const message = validationMessages.join(' ') || result.message || ({
                    network_error: formPanel.dataset.bookingNetworkError,
                    server_error: formPanel.dataset.bookingServerError,
                    session_expired: formPanel.dataset.bookingSessionExpired,
                    rate_limited: formPanel.dataset.bookingRateLimited,
                }[result.status] ?? formPanel.dataset.bookingServerError);

                if (errorContainer) {
                    errorContainer.textContent = message;
                    errorContainer.hidden = false;
                }
                if (feedback) {
                    feedback.textContent = '';
                    feedback.hidden = true;
                }
            },
            onSuccess: (payload) => {
                if (!stepContent) return;

                stepContent.innerHTML = payload.html;
                const freshCsrfToken = stepContent.querySelector('input[name="_token"]')?.value;
                const csrfMeta = document.querySelector('meta[name="csrf-token"]');
                if (freshCsrfToken && csrfMeta) csrfMeta.content = freshCsrfToken;
                getSelectedSlots().forEach((input) => { input.value = payload.slot ?? ''; });

                if (feedback) {
                    feedback.textContent = payload.message ?? '';
                    feedback.hidden = feedback.textContent === '';
                }
                if (isOpen) {
                    root.style.height = `${formPanel.scrollHeight}px`;
                }

                const nextControl = stepContent.querySelector('input:not([type="hidden"]), select, textarea, button');
                nextControl?.focus({ preventScroll: true });
            },
            onRedirect: (url) => window.location.assign(url),
        });
    }, options);

    document.addEventListener('easyenglish:availability-slot-selected', (event) => open(event.detail || {}), options);
    closeButton.addEventListener('click', close, options);



    if (root.dataset.initialSlot) {
        const initialSlot = root.dataset.initialSlot;
        open({ startsAt: initialSlot, label: initialSlot });
    }

    return () => {
        controller.abort();
        activeTimeline?.kill();
        gsap.set([content, formPanel], { clearProps: 'all' });
    };
}
