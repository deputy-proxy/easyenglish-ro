import assert from 'node:assert/strict';
import test from 'node:test';
import { submitBookingAuthForm } from '../../resources/js/booking/booking-auth.js';

function makeForm() {
    const button = {
        disabled: false,
        attributes: {},
        setAttribute(name, value) { this.attributes[name] = value; },
        removeAttribute(name) { delete this.attributes[name]; },
    };
    return {
        action: '/booking/register',
        dataset: {},
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
