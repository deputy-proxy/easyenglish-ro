export const CONSENT_STORAGE_KEY = 'easyenglish.consent.v1';
export const CAMPAIGN_STORAGE_KEY = 'easyenglish.campaign.v1';
export const CONSENT_MAX_AGE_MS = 180 * 24 * 60 * 60 * 1000;
export const CAMPAIGN_MAX_AGE_MS = 30 * 24 * 60 * 60 * 1000;

export function normalizeConsent(value, now = Date.now()) {
    if (!value || typeof value !== 'object' || value.version !== 1) {
        return null;
    }

    if (!Number.isFinite(value.updatedAt) || now - value.updatedAt > CONSENT_MAX_AGE_MS || value.updatedAt > now) {
        return null;
    }

    return {
        version: 1,
        analytics: value.analytics === true,
        advertising: value.advertising === true,
        updatedAt: value.updatedAt,
    };
}

export function googleConsentState(consent) {
    return {
        analytics_storage: consent?.analytics ? 'granted' : 'denied',
        ad_storage: consent?.advertising ? 'granted' : 'denied',
        ad_user_data: consent?.advertising ? 'granted' : 'denied',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
    };
}

export function createAnalyticsEvent(event, parameters = {}) {
    return {
        ...parameters,
        event,
    };
}

export function setCookieManagePosition(container, hasConsent) {
    if (!container) return;

    ['fixed', 'inset-x-0', 'bottom-0', 'z-[110]'].forEach((className) => {
        container.classList.toggle(className, !hasConsent);
    });
}

export function isValidGtmContainerId(containerId) {
    return typeof containerId === 'string' && /^GTM-[A-Z0-9]+$/.test(containerId);
}

export function canLoadGoogleTagManager(consent, containerId) {
    return isValidGtmContainerId(containerId) && (consent?.analytics === true || consent?.advertising === true);
}

export function canTrackAnalytics(consent, containerId) {
    return isValidGtmContainerId(containerId) && consent?.analytics === true;
}

export function canTrackAdvertising(consent, containerId) {
    return isValidGtmContainerId(containerId) && consent?.advertising === true;
}

export function normalizeCampaignParameters(value, now = Date.now()) {
    if (!value || typeof value !== 'object' || !Number.isFinite(value.updatedAt)) {
        return {};
    }

    if (now - value.updatedAt > CAMPAIGN_MAX_AGE_MS || value.updatedAt > now) {
        return {};
    }

    const campaign = {};
    ['utm_source', 'utm_medium', 'utm_campaign'].forEach((key) => {
        const candidate = value[key];
        if (typeof candidate === 'string' && /^[a-zA-Z0-9._-]{1,100}$/.test(candidate)) {
            campaign[key] = candidate;
        }
    });

    return Object.keys(campaign).length > 0 ? { ...campaign, updatedAt: value.updatedAt } : {};
}

export function saveConsent(storage, analytics, advertising, now = Date.now()) {
    const consent = {
        version: 1,
        analytics: analytics === true,
        advertising: advertising === true,
        updatedAt: now,
    };
    storage.setItem(CONSENT_STORAGE_KEY, JSON.stringify(consent));
    return consent;
}

export function clearGoogleCookies(documentRef, hostname, categories) {
    const cookieNames = documentRef.cookie
        .split(';')
        .map((cookie) => cookie.trim().split('=')[0])
        .filter(Boolean);
    const patterns = [];
    if (categories.analytics) patterns.push(/^_ga(?:_|$)/, /^_gid$/, /^_gat(?:_|$)/);
    if (categories.advertising) patterns.push(/^_gcl_/, /^_gac_/);
    const namesToClear = cookieNames.filter((name) => patterns.some((pattern) => pattern.test(name)));
    const segments = hostname.split('.');
    const domains = new Set(['']);
    if (hostname && hostname !== 'localhost') {
        domains.add(hostname);
        domains.add(`.${hostname}`);
        for (let index = 1; index < segments.length - 1; index += 1) {
            domains.add(`.${segments.slice(index).join('.')}`);
        }
    }

    namesToClear.forEach((name) => {
        domains.forEach((domain) => {
            const domainAttribute = domain ? `; domain=${domain}` : '';
            documentRef.cookie = `${name}=; Max-Age=0; path=/; SameSite=Lax${domainAttribute}`;
        });
    });
}

function readConsent(windowRef) {
    try {
        return normalizeConsent(JSON.parse(windowRef.localStorage.getItem(CONSENT_STORAGE_KEY)));
    } catch {
        return null;
    }
}

function pageType(pathname) {
    if (pathname === '/') return 'landing';
    if (pathname.startsWith('/book')) return 'booking';
    if (pathname.startsWith('/purchase')) return 'purchase';
    return 'other';
}

function initializeGoogleTagManager(windowRef, containerId, consent) {
    if (!isValidGtmContainerId(containerId) || windowRef.__easyEnglishGtmInitialized) {
        return;
    }

    windowRef.dataLayer = windowRef.dataLayer || [];
    windowRef.gtag = windowRef.gtag || function gtag() {
        windowRef.dataLayer.push(arguments);
    };

    windowRef.gtag('consent', 'default', {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
        wait_for_update: 500,
    });
    windowRef.gtag('consent', 'update', googleConsentState(consent));

    windowRef.dataLayer.push({
        'gtm.start': new Date().getTime(),
        event: 'gtm.js',
    });

    const script = windowRef.document.createElement('script');
    script.async = true;
    script.src = `https://www.googletagmanager.com/gtm.js?id=${encodeURIComponent(containerId)}`;
    windowRef.document.head.appendChild(script);
    windowRef.__easyEnglishGtmInitialized = true;
}

export function initializeAnalytics(windowRef = window, documentRef = document) {
    const containerId = documentRef.querySelector('[data-analytics-config]')?.dataset.gtmId || '';
    const validContainerId = isValidGtmContainerId(containerId);
    const banner = documentRef.querySelector('[data-cookie-consent]');
    const preferences = documentRef.querySelector('[data-cookie-preferences]');
    const manageButton = documentRef.querySelector('[data-cookie-manage]');
    const manageContainer = documentRef.querySelector('[data-cookie-manage-container]');
    const analyticsToggle = documentRef.querySelector('[data-consent-analytics]');
    const advertisingToggle = documentRef.querySelector('[data-consent-advertising]');
    const controller = new AbortController();
    const options = { signal: controller.signal };
    let consent = readConsent(windowRef);
    let gtmLoaded = false;

    setCookieManagePosition(manageContainer, consent !== null);

    const setBannerVisibility = () => {
        if (banner) banner.hidden = consent !== null;
    };

    const updateGoogleConsent = () => {
        if (!gtmLoaded || typeof windowRef.gtag !== 'function') return;
        windowRef.gtag('consent', 'update', googleConsentState(consent));
    };

    const loadGtmIfAllowed = () => {
        if (!canLoadGoogleTagManager(consent, containerId)) return;
        if (!gtmLoaded) {
            initializeGoogleTagManager(windowRef, containerId, consent);
            gtmLoaded = Boolean(containerId);
        } else {
            updateGoogleConsent();
        }
    };

    const persistConsent = (analytics, advertising) => {
        try {
            consent = saveConsent(windowRef.localStorage, analytics, advertising);
        } catch {
            consent = {
                version: 1,
                analytics: analytics === true,
                advertising: advertising === true,
                updatedAt: Date.now(),
            };
        }
        setBannerVisibility();
        setCookieManagePosition(manageContainer, true);
        loadGtmIfAllowed();
        updateGoogleConsent();

        if (!consent.analytics) {
            try {
                windowRef.localStorage.removeItem(CAMPAIGN_STORAGE_KEY);
            } catch {
                // Consent revocation must remain available if browser storage is unavailable.
            }
        } else if (!consent.advertising) {
            try {
                const campaign = JSON.parse(windowRef.localStorage.getItem(CAMPAIGN_STORAGE_KEY) || '{}');
                delete campaign.gclid;
                delete campaign.wbraid;
                delete campaign.gbraid;
                windowRef.localStorage.setItem(CAMPAIGN_STORAGE_KEY, JSON.stringify(campaign));
            } catch {
                // Campaign attribution is optional.
            }
        }

        clearGoogleCookies(documentRef, windowRef.location.hostname, {
            analytics: !consent.analytics,
            advertising: !consent.advertising,
        });

        if (preferences) preferences.hidden = true;
        if (banner) banner.hidden = true;
    };

    const captureCampaignParameters = () => {
        if (!consent?.analytics) return {};

        const current = new URLSearchParams(windowRef.location.search);
        const campaign = {};
        ['utm_source', 'utm_medium', 'utm_campaign'].forEach((key) => {
            const value = current.get(key);
            if (value && /^[a-zA-Z0-9._-]{1,100}$/.test(value)) campaign[key] = value;
        });

        try {
            const saved = normalizeCampaignParameters(
                JSON.parse(windowRef.localStorage.getItem(CAMPAIGN_STORAGE_KEY) || '{}')
            );
            const savedCampaign = Object.keys(saved).filter((key) => key !== 'updatedAt');
            const currentCampaign = Object.keys(campaign);
            const isSameCampaign = currentCampaign.length > 0
                && currentCampaign.length === savedCampaign.length
                && currentCampaign.every((key) => saved[key] === campaign[key]);
            const combined = currentCampaign.length > 0 && !isSameCampaign
                ? { ...campaign, updatedAt: Date.now() }
                : saved;

            if (Object.keys(combined).length > 0) {
                windowRef.localStorage.setItem(CAMPAIGN_STORAGE_KEY, JSON.stringify(combined));
            } else {
                windowRef.localStorage.removeItem(CAMPAIGN_STORAGE_KEY);
            }

            const { updatedAt, ...parameters } = combined;
            return parameters;
        } catch {
            return campaign;
        }
    };

    const track = (event, parameters = {}) => {
        if (!canTrackAnalytics(consent, containerId)) return;
        windowRef.dataLayer = windowRef.dataLayer || [];
        windowRef.dataLayer.push(createAnalyticsEvent(event, {
            page_type: pageType(windowRef.location.pathname),
            ...captureCampaignParameters(),
            ...parameters,
        }));
    };

    const trackAdsConversion = (conversionType, parameters = {}) => {
        if (!canTrackAdvertising(consent, containerId)) return;
        windowRef.dataLayer = windowRef.dataLayer || [];
        windowRef.dataLayer.push(createAnalyticsEvent('ads_conversion', {
            conversion_type: conversionType,
            page_type: pageType(windowRef.location.pathname),
            ...parameters,
        }));
    };

    captureCampaignParameters();

    if (consent) {
        loadGtmIfAllowed();
    }

    if (banner) {
        setBannerVisibility();
        banner.querySelector('[data-consent-accept-all]')?.addEventListener('click', () => persistConsent(true, true), options);
        banner.querySelector('[data-consent-reject]')?.addEventListener('click', () => persistConsent(false, false), options);
        banner.querySelector('[data-consent-open-preferences]')?.addEventListener('click', () => {
            if (preferences) preferences.hidden = false;
            if (analyticsToggle) analyticsToggle.checked = consent?.analytics === true;
            if (advertisingToggle) advertisingToggle.checked = consent?.advertising === true;
        }, options);
        preferences?.querySelector('[data-consent-save]')?.addEventListener('click', () => {
            persistConsent(analyticsToggle?.checked === true, advertisingToggle?.checked === true);
        }, options);
        preferences?.querySelector('[data-consent-cancel]')?.addEventListener('click', () => {
            preferences.hidden = true;
        }, options);
        manageButton?.addEventListener('click', () => {
            if (preferences) preferences.hidden = !preferences.hidden;
            if (analyticsToggle) analyticsToggle.checked = consent?.analytics === true;
            if (advertisingToggle) advertisingToggle.checked = consent?.advertising === true;
            if (banner) banner.hidden = false;
        }, options);
    }

    documentRef.addEventListener('click', (event) => {
        const target = event.target instanceof Element ? event.target.closest('a, button') : null;
        if (!target) return;

        const href = target.getAttribute('href') || '';
        if (href.startsWith('mailto:')) {
            track('email_click');
            return;
        }
        if (href.startsWith('tel:')) {
            track('phone_click');
            return;
        }
        if (/^https:\/\/(wa\.me|api\.whatsapp\.com)\//i.test(href)) {
            track('whatsapp_click');
            return;
        }

        const ctaLocation = target.dataset.analyticsCta;
        if (ctaLocation) {
            track('booking_cta_click', { cta_location: ctaLocation });
        }

        if (target.closest('#bundles') && href.includes('/purchase')) {
            track('pricing_cta_click', {
                package_id: target.dataset.packageId || undefined,
                package_name: target.dataset.packageName || undefined,
            });
            track('select_item', {
                item_list_name: 'homepage_packages',
                items: [{
                    item_id: target.dataset.packageId || 'unknown',
                    item_name: target.dataset.packageName || 'Package',
                }],
            });
        }
    }, options);

    const sectionNames = ['why', 'how', 'teachers', 'testimonials', 'availability', 'bundles', 'progress-reports', 'faq'];
    const sections = sectionNames
        .map((id) => documentRef.getElementById(id))
        .filter(Boolean);

    if ('IntersectionObserver' in windowRef) {
        const viewedSections = new Set();
        const sectionObserver = new windowRef.IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                const name = entry.target.id;
                if (!entry.isIntersecting || viewedSections.has(name)) return;
                viewedSections.add(name);
                track('section_view', { section_name: name });
            });
        }, { threshold: 0.35 });
        sections.forEach((section) => sectionObserver.observe(section));
        windowRef.addEventListener('pagehide', () => sectionObserver.disconnect(), { once: true, signal: controller.signal });

        const calendar = documentRef.querySelector('[data-availability]');
        if (calendar) {
            let calendarVisible = false;
            let calendarViewed = false;
            const trackCalendarView = (availableSlotsCount) => {
                if (!calendarVisible || calendarViewed) return;
                calendarViewed = true;
                track('booking_calendar_view', { available_slots_count: availableSlotsCount });
                calendarObserver.disconnect();
            };
            const calendarObserver = new windowRef.IntersectionObserver((entries) => {
                calendarVisible = entries.some((entry) => entry.isIntersecting);
                if (!calendarVisible) return;
                const count = Number(calendar.dataset.availableSlotsCount);
                if (Number.isFinite(count)) trackCalendarView(count);
            }, { threshold: 0.25 });
            calendarObserver.observe(calendar);
            documentRef.addEventListener('easyenglish:availability-rendered', (event) => {
                if (event.detail?.root !== calendar) return;
                const count = Number(event.detail.availableSlotsCount);
                if (Number.isFinite(count)) {
                    calendar.dataset.availableSlotsCount = String(count);
                    trackCalendarView(count);
                }
            }, options);
            windowRef.addEventListener('pagehide', () => calendarObserver.disconnect(), { once: true, signal: controller.signal });
        }
    }

    documentRef.querySelectorAll('[data-faq-section] details').forEach((details, index) => {
        details.addEventListener('toggle', () => {
            if (details.open) track('faq_expand', { faq_question_id: `faq_${index + 1}` });
        }, options);
    });

    documentRef.addEventListener('easyenglish:availability-slot-selected', (event) => {
        const startsAt = typeof event.detail?.startsAt === 'string' ? event.detail.startsAt : '';
        const match = startsAt.match(/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})/);
        if (!match) return;
        track('booking_date_select', { selected_date: match[1] });
        track('booking_slot_select', { selected_date: match[1], selected_time: match[2] });
        const bookingType = new URLSearchParams(windowRef.location.search).get('type') === 'bundle'
            ? 'bundle'
            : 'free_introductory';
        track('booking_details_start', { booking_type: bookingType });
    }, options);

    documentRef.addEventListener('easyenglish:booking-error', (event) => {
        track('booking_error', {
            error_type: typeof event.detail?.errorType === 'string' ? event.detail.errorType : 'submission_failed',
        });
    }, options);

    const scrollMilestones = new Set();
    const handleScrollDepth = () => {
        const scrollableHeight = documentRef.documentElement.scrollHeight - windowRef.innerHeight;
        if (scrollableHeight <= 0) return;
        const depth = Math.min(100, Math.round((windowRef.scrollY / scrollableHeight) * 100));
        [25, 50, 75, 90].forEach((milestone) => {
            if (depth >= milestone && !scrollMilestones.has(milestone)) {
                scrollMilestones.add(milestone);
                track('scroll_depth', { percent_scrolled: milestone });
            }
        });
    };
    windowRef.addEventListener('scroll', handleScrollDepth, { passive: true, signal: controller.signal });

    documentRef.querySelectorAll('[data-analytics-event]').forEach((element) => {
        const eventName = element.dataset.analyticsEvent;
        let parameters = {};
        try {
            parameters = JSON.parse(element.dataset.analyticsParameters || '{}');
        } catch {
            parameters = {};
        }

        if (eventName === 'booking_completed') {
            track('booking_completed', parameters);
            trackAdsConversion('lead', parameters);
            return;
        }

        if (eventName === 'purchase') {
            const transactionId = parameters.transaction_id;
            if (typeof transactionId !== 'string' || !transactionId) return;

            if (canTrackAnalytics(consent, containerId)) {
                const analyticsKey = `easyenglish.purchase.analytics.${transactionId}`;
                try {
                    if (!windowRef.localStorage.getItem(analyticsKey)) {
                        windowRef.localStorage.setItem(analyticsKey, '1');
                        track('purchase', parameters);
                    }
                } catch {
                    // Skip purchase tracking when duplicate protection cannot be persisted.
                }
            }

            if (canTrackAdvertising(consent, containerId)) {
                const advertisingKey = `easyenglish.purchase.advertising.${transactionId}`;
                try {
                    if (!windowRef.localStorage.getItem(advertisingKey)) {
                        windowRef.localStorage.setItem(advertisingKey, '1');
                        trackAdsConversion('purchase', parameters);
                    }
                } catch {
                    // Skip advertising conversion tracking when duplicate protection cannot be persisted.
                }
            }
            return;
        }

        track(eventName, parameters);
    });

    windowRef.addEventListener('pagehide', () => controller.abort(), { once: true });

    return {
        track,
        getConsent: () => consent,
        updateConsent: persistConsent,
    };
}

if (typeof window !== 'undefined' && typeof document !== 'undefined') {
    initializeAnalytics();
}
