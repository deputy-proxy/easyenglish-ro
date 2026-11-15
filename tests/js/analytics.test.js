import assert from 'node:assert/strict';
import test from 'node:test';
import {
    CONSENT_MAX_AGE_MS,
    canLoadGoogleTagManager,
    canTrackAdvertising,
    canTrackAnalytics,
    clearGoogleCookies,
    createAnalyticsEvent,
    googleConsentState,
    normalizeCampaignParameters,
    normalizeConsent,
    saveConsent,
} from '../../resources/js/analytics.js';

test('defaults to no optional consent when there is no saved preference', () => {
    assert.equal(normalizeConsent(null), null);
    assert.equal(normalizeConsent({ version: 1, analytics: true }), null);
});

test('expires consent preferences after the configured retention period', () => {
    const now = 1_800_000_000_000;
    assert.equal(normalizeConsent({
        version: 1,
        analytics: true,
        advertising: false,
        updatedAt: now - CONSENT_MAX_AGE_MS - 1,
    }, now), null);
});

test('maps optional consent to Google Consent Mode v2 states', () => {
    assert.deepEqual(googleConsentState(null), {
        analytics_storage: 'denied',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
    });

    assert.deepEqual(googleConsentState({ analytics: true, advertising: false }), {
        analytics_storage: 'granted',
        ad_storage: 'denied',
        ad_user_data: 'denied',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
    });

    assert.deepEqual(googleConsentState({ analytics: false, advertising: true }), {
        analytics_storage: 'denied',
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
    });

    assert.deepEqual(googleConsentState({ analytics: true, advertising: true }), {
        analytics_storage: 'granted',
        ad_storage: 'granted',
        ad_user_data: 'granted',
        ad_personalization: 'denied',
        functionality_storage: 'granted',
        security_storage: 'granted',
    });
});

test('normalizes only explicitly enabled optional categories', () => {
    const consent = normalizeConsent({
        version: 1,
        analytics: 'yes',
        advertising: true,
        updatedAt: 1_800_000_000_000,
    }, 1_800_000_000_000);

    assert.deepEqual(consent, {
        version: 1,
        analytics: false,
        advertising: true,
        updatedAt: 1_800_000_000_000,
    });
});

test('creates a dataLayer event without adding personal information', () => {
    assert.deepEqual(createAnalyticsEvent('booking_slot_select', {
        selected_date: '2026-11-18',
        selected_time: '14:00',
    }), {
        event: 'booking_slot_select',
        selected_date: '2026-11-18',
        selected_time: '14:00',
    });
});

test('loads GTM only with a valid container ID and an explicit optional consent choice', () => {
    assert.equal(canLoadGoogleTagManager(null, 'GTM-ABC123'), false);
    assert.equal(canLoadGoogleTagManager({ analytics: false, advertising: false }, 'GTM-ABC123'), false);
    assert.equal(canLoadGoogleTagManager({ analytics: true, advertising: false }, 'GTM-ABC123'), true);
    assert.equal(canLoadGoogleTagManager({ analytics: false, advertising: true }, 'GTM-ABC123'), true);
    assert.equal(canLoadGoogleTagManager({ analytics: true, advertising: false }, 'not-a-container'), false);
});

test('sends analytics events only when analytics consent and a valid GTM container are present', () => {
    assert.equal(canTrackAnalytics({ analytics: false, advertising: true }, 'GTM-ABC123'), false);
    assert.equal(canTrackAnalytics({ analytics: true, advertising: false }, ''), false);
    assert.equal(canTrackAnalytics({ analytics: true, advertising: false }, 'GTM-ABC123'), true);
});

test('allows advertising conversion events only with advertising consent', () => {
    assert.equal(canTrackAdvertising({ analytics: true, advertising: false }, 'GTM-ABC123'), false);
    assert.equal(canTrackAdvertising({ analytics: false, advertising: true }, 'GTM-ABC123'), true);
    assert.equal(canTrackAdvertising({ analytics: false, advertising: true }, ''), false);
});

test('persists explicit consent choices and allows visitors to revoke both optional categories', () => {
    const values = new Map();
    const storage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    const now = Date.now();
    const accepted = saveConsent(storage, true, true, now);
    assert.deepEqual(normalizeConsent(JSON.parse(storage.getItem('easyenglish.consent.v1')), now), accepted);

    const revoked = saveConsent(storage, false, false, now + 100);
    assert.equal(revoked.analytics, false);
    assert.equal(revoked.advertising, false);
    assert.equal(canLoadGoogleTagManager(revoked, 'GTM-ABC123'), false);
    assert.equal(canTrackAnalytics(revoked, 'GTM-ABC123'), false);
});

test('clears analytics and advertising cookies when their consent is withdrawn', () => {
    const writes = [];
    const documentRef = {
        get cookie() {
            return '_ga=abc; _ga_123=def; _gid=ghi; _gcl_au=jkl; _gac_123=mno; session=essential';
        },
        set cookie(value) {
            writes.push(value);
        },
    };

    clearGoogleCookies(documentRef, 'www.easyenglish.ro', {
        analytics: true,
        advertising: false,
    });

    assert.ok(writes.some((value) => value.startsWith('_ga=;')));
    assert.ok(writes.some((value) => value.startsWith('_ga_123=;')));
    assert.ok(writes.some((value) => value.startsWith('_gid=;')));
    assert.equal(writes.some((value) => value.startsWith('_gcl_au=;')), false);
    assert.equal(writes.some((value) => value.startsWith('_gac_123=;')), false);
    assert.equal(writes.some((value) => value.startsWith('session=;')), false);
});

test('expires campaign attribution after 30 days and accepts only approved UTM fields', () => {
    const now = Date.now();
    assert.deepEqual(normalizeCampaignParameters({
        utm_source: 'newsletter',
        utm_medium: 'email',
        utm_campaign: 'winter_offer',
        gclid: 'not-forwarded',
        email: 'not-forwarded',
        updatedAt: now,
    }, now), {
        utm_source: 'newsletter',
        utm_medium: 'email',
        utm_campaign: 'winter_offer',
        updatedAt: now,
    });

    assert.deepEqual(normalizeCampaignParameters({
        utm_source: 'old_campaign',
        updatedAt: now - (30 * 24 * 60 * 60 * 1000) - 1,
    }, now), {});
});
