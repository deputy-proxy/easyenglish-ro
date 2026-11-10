import { submitBookingAuthForm } from '../booking/booking-auth';
import { normalizePhoneDigits, requiresPhoneReverification } from '../booking/phone-change';

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
        const usedBundleModal = formPanel.querySelector('[data-used-free-bundle-modal]');
        if (usedBundleModal && !usedBundleModal.hidden) {
            requestAnimationFrame(() => usedBundleModal.querySelector('[data-close-used-free-bundle-modal]')?.focus());
        }
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

    formPanel.addEventListener('click', (event) => {
        const openButton = event.target.closest('[data-open-used-free-bundle-modal]');
        const closeButton = event.target.closest('[data-close-used-free-bundle-modal]');
        const modal = formPanel.querySelector('[data-used-free-bundle-modal]');

        if (openButton && modal) {
            modal.hidden = false;
            modal.querySelector('[data-close-used-free-bundle-modal]')?.focus();
        } else if (closeButton && modal) {
            modal.hidden = true;
            formPanel.querySelector('[data-open-used-free-bundle-modal]')?.focus();
        }
    }, options);

    formPanel.addEventListener('keydown', (event) => {
        const modal = formPanel.querySelector('[data-used-free-bundle-modal]');
        if (!modal || modal.hidden) return;

        if (event.key === 'Escape') {
            event.preventDefault();
            modal.hidden = true;
            formPanel.querySelector('[data-open-used-free-bundle-modal]')?.focus();
            return;
        }

        if (event.key !== 'Tab') return;
        const focusable = Array.from(modal.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
        if (focusable.length === 0) return;

        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }, options);

    formPanel.addEventListener('input', (event) => {
        const phoneInput = event.target.closest('input[name="phone"]');
        const form = phoneInput?.closest('form[data-booking-verification-form]');
        if (!form) return;

        const currentDigits = normalizePhoneDigits(phoneInput.value);
        const previousDigits = form.dataset.lastPhoneDigits ?? normalizePhoneDigits(form.dataset.verifiedPhone);
        const contactsVerified = form.dataset.contactsVerified === 'true';
        const emailOnly = form.dataset.emailOnly === 'true';
        const phoneChanged = currentDigits !== normalizePhoneDigits(form.dataset.verifiedPhone);
        const changed = requiresPhoneReverification(phoneInput.value, form.dataset.verifiedPhone, {
            contactsVerified,
            emailOnly,
        });
        const emailOnlyChanged = contactsVerified && emailOnly && phoneChanged;
        const consent = form.querySelector('[data-phone-change-consent]');
        if (currentDigits !== previousDigits && consent) consent.checked = false;
        form.dataset.lastPhoneDigits = currentDigits;
        const controls = form.querySelector('[data-phone-change-controls]');
        const finalSubmit = form.querySelector('[data-booking-final-submit]');
        const eligibility = form.querySelector('[data-free-session-eligibility]');

        if (controls) controls.hidden = !changed;
        if (consent) consent.required = changed;
        if (finalSubmit) {
            finalSubmit.hidden = changed || (! emailOnlyChanged && form.dataset.initialEligible !== 'true');
        }
        if (eligibility) eligibility.hidden = changed || emailOnlyChanged;

        if (feedback && changed) {
            feedback.textContent = formPanel.dataset.bookingPhoneChangedMessage ?? '';
            feedback.hidden = feedback.textContent === '';
        } else if (feedback && emailOnlyChanged) {
            feedback.textContent = formPanel.dataset.bookingPhoneChangedEmailOnlyMessage ?? '';
            feedback.hidden = feedback.textContent === '';
        } else if (feedback) {
            feedback.textContent = '';
            feedback.hidden = true;
        }
    }, options);

    formPanel.addEventListener('submit', async (event) => {
        const form = event.target.closest('form[data-booking-async-form]');
        if (!form || !formPanel.contains(form)) return;
        const submitter = event.submitter;
        const isAuthForm = form.hasAttribute('data-booking-auth-form');
        const hasVerificationAction = submitter?.hasAttribute('formaction') ?? false;
        if (!isAuthForm && !hasVerificationAction) return;

        event.preventDefault();
        const errorContainer = form.querySelector('[data-booking-form-errors]');
        if (errorContainer) {
            errorContainer.textContent = '';
            errorContainer.hidden = true;
        }

        await submitBookingAuthForm(form, {
            csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            submitter,
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

                const dashboardRedirect = stepContent.querySelector('[data-booking-dashboard-redirect]');
                if (dashboardRedirect) {
                    window.setTimeout(() => window.location.assign(dashboardRedirect.dataset.bookingDashboardRedirect), 1800);
                    return;
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
