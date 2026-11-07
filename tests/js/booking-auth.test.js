import assert from 'node:assert/strict';
import test from 'node:test';
import { submitBookingAuthForm } from '../../resources/js/booking/booking-auth.js';
import { requiresPhoneReverification } from '../../resources/js/booking/phone-change.js';

function makeForm() {
    const button = {
        disabled: false,
        attributes: {},
        getAttribute(name) { return this.attributes[name] ?? null; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    return {
        action: '/booking/register',
        dataset: {},
        getAttribute(name) { return this.attributes[name] ?? null; },
        isConnected: true,
        attributes: {},
        button,
        querySelector() { return button; },
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
}

function response(status, payload) {
    return { status, ok: status >= 200 && status < 300, json: async () => payload };
}

test('submits registration with CSRF/session headers and advances to the returned booking step', async () => {
    const form = makeForm();
    const states = [];
    let request;
    let success;
    let redirects = 0;
    const result = await submitBookingAuthForm(form, {
        csrfToken: 'csrf-token',
        formDataFactory: (target) => ({ form: target }),
        fetcher: async (url, options) => { request = { url, ...options }; return response(201, { status: 'authenticated', next_step: 'contact_verification', html: '<form></form>', slot: '2026-11-06T09:00:00' }); },
        onState: (state) => states.push(state),
        onSuccess: (payload) => { success = payload; },
        onRedirect: () => { redirects++; },
    });

    assert.equal(request.url, '/booking/register');
    assert.equal(request.method, 'POST');
    assert.equal(request.credentials, 'same-origin');
    assert.equal(request.headers['X-CSRF-TOKEN'], 'csrf-token');
    assert.equal(request.headers.Accept, 'application/json');
    assert.equal(request.headers['X-Requested-With'], 'XMLHttpRequest');
    assert.equal(result.next_step, 'contact_verification');
    assert.equal(success.html, '<form></form>');
    assert.equal(redirects, 0);
    assert.deepEqual(states, ['submitting', 'success']);
    assert.equal(form.dataset.submitting, 'false');
    assert.equal(form.button.disabled, false);
    assert.equal(form.attributes['aria-busy'], undefined);
});

test('exposes an accessible loading state and disables the submit button while the request is pending', async () => {
    const form = makeForm();
    const states = [];
    let resolveResponse;
    const pending = submitBookingAuthForm(form, {
        formDataFactory: () => ({}),
        fetcher: () => new Promise((resolve) => { resolveResponse = resolve; }),
        onState: (state) => states.push(state),
    });

    await Promise.resolve();
    assert.equal(form.button.disabled, true);
    assert.equal(form.button.attributes['aria-disabled'], 'true');
    assert.equal(form.attributes['aria-busy'], 'true');
    assert.deepEqual(states, ['submitting']);

    resolveResponse(response(200, { status: 'authenticated', next_step: 'booking', html: '<form></form>' }));
    await pending;
    assert.equal(form.button.disabled, false);
    assert.equal(form.attributes['aria-busy'], undefined);
});

test('uses the explicit registration endpoint when the form action attribute is missing', async () => {
    const form = makeForm();
    form.action = 'https://example.test/?slot=2026-11-11T09%3A00%3A00';
    form.dataset.bookingEndpoint = '/book/register';
    let requestedUrl;

    await submitBookingAuthForm(form, {
        formDataFactory: () => ({}),
        fetcher: async (url) => { requestedUrl = url; return response(201, { status: 'authenticated', html: '<form></form>' }); },
    });

    assert.equal(requestedUrl, '/book/register');
});

test('submits the clicked inline verification action instead of the form default action', async () => {
    const form = makeForm();
    form.action = '/book';
    form.method = 'post';
    const submitter = form.button;
    submitter.attributes.formaction = '/verification/phone';
    submitter.attributes.formmethod = 'post';
    let request;
    let submittedBy;

    await submitBookingAuthForm(form, {
        submitter,
        formDataFactory: (target, button) => { submittedBy = button; return { form: target }; },
        fetcher: async (url, options) => {
            request = { url, ...options };
            return response(200, { status: 'eligible', next_step: 'eligible', html: '<form></form>' });
        },
    });

    assert.equal(request.url, '/verification/phone');
    assert.equal(request.method, 'POST');
    assert.equal(submittedBy, submitter);
});

test('renders validation failures without navigating away', async () => {
    const form = makeForm();
    const states = [];
    let errors;
    const result = await submitBookingAuthForm(form, {
        formDataFactory: () => ({}),
        fetcher: async () => response(422, { message: 'Invalid data', errors: { email: ['Enter a valid email.'] } }),
        onState: (state) => states.push(state),
        onError: (payload) => { errors = payload.errors; },
    });

    assert.equal(result.status, 'validation_error');
    assert.deepEqual(states, ['submitting', 'validation_error']);
    assert.deepEqual(errors.email, ['Enter a valid email.']);
    assert.equal(form.button.disabled, false);
});

test('distinguishes invalid credentials from registration validation errors', async () => {
    const states = [];
    await submitBookingAuthForm(makeForm(), {
        formDataFactory: () => ({}),
        fetcher: async () => response(422, { status: 'authentication_failed', errors: { email: ['Invalid credentials.'] } }),
        onState: (state) => states.push(state),
    });
    assert.deepEqual(states, ['submitting', 'authentication_error']);
});

test('routes accounts with two-factor authentication through the existing secure challenge', async () => {
    const states = [];
    let redirect;
    const result = await submitBookingAuthForm(makeForm(), {
        formDataFactory: () => ({}),
        fetcher: async () => response(409, { status: 'two_factor_required', redirect_url: '/login' }),
        onState: (state) => states.push(state),
        onRedirect: (url) => { redirect = url; },
    });
    assert.equal(result.status, 'two_factor_required');
    assert.equal(redirect, '/login');
    assert.deepEqual(states, ['submitting', 'two_factor_required']);
});

test('exposes expired-session, rate-limit and server-error states', async (t) => {
    const cases = [
        [419, null, 'session_expired'],
        [429, { message: 'Too many attempts.' }, 'rate_limited'],
        [500, { message: 'Unavailable.' }, 'server_error'],
    ];
    for (const [status, payload, expected] of cases) {
        await t.test(String(status), async () => {
            const states = [];
            const result = await submitBookingAuthForm(makeForm(), {
                formDataFactory: () => ({}),
                fetcher: async () => response(status, payload),
                onState: (state) => states.push(state),
            });
            assert.equal(result.status, expected);
            assert.deepEqual(states, ['submitting', expected]);
        });
    }
});

test('exposes network failures and restores the submit control', async () => {
    const form = makeForm();
    const states = [];
    const result = await submitBookingAuthForm(form, {
        formDataFactory: () => ({}),
        fetcher: async () => { throw new Error('offline'); },
        onState: (state) => states.push(state),
    });
    assert.equal(result.status, 'network_error');
    assert.deepEqual(states, ['submitting', 'network_error']);
    assert.equal(form.button.disabled, false);
});

test('prevents duplicate submissions while a previous request is pending', async () => {
    const form = makeForm();
    form.dataset.submitting = 'true';
    let fetchCount = 0;
    const result = await submitBookingAuthForm(form, { fetcher: async () => { fetchCount++; } });
    assert.equal(result.status, 'already_submitting');
    assert.equal(fetchCount, 0);
});


test('requires phone reverification only when a verified WhatsApp contact changes', () => {
    assert.equal(requiresPhoneReverification('+40 722 123 456', '+40722123456', { contactsVerified: true, emailOnly: false }), false);
    assert.equal(requiresPhoneReverification('+40722123457', '+40722123456', { contactsVerified: true, emailOnly: false }), true);
    assert.equal(requiresPhoneReverification('+40722123457', '+40722123456', { contactsVerified: true, emailOnly: true }), false);
    assert.equal(requiresPhoneReverification('+40722123457', '+40722123456', { contactsVerified: false, emailOnly: false }), false);
});

test('preserves structured verification error states from the server', async (t) => {
    const cases = [
        [422, { status: 'expired', message: 'Expired.', errors: { challenge: ['Expired.'] } }, 'expired'],
        [422, { status: 'invalid_code', message: 'Invalid code.', errors: { phone_code: ['Invalid code.'] } }, 'invalid_code'],
        [422, { status: 'attempts_locked', message: 'Locked.', errors: { phone_code: ['Locked.'] } }, 'attempts_locked'],
        [429, { status: 'resend_wait', message: 'Wait.' }, 'resend_wait'],
        [503, { status: 'delivery_failed', message: 'Delivery failed.' }, 'delivery_failed'],
    ];

    for (const [status, payload, expected] of cases) {
        await t.test(expected, async () => {
            const result = await submitBookingAuthForm(makeForm(), {
                formDataFactory: () => ({}),
                fetcher: async () => response(status, payload),
            });
            assert.equal(result.status, expected);
            assert.equal(result.message, payload.message);
        });
    }
});
