export async function submitBookingAuthForm(form, {
    fetcher = (...args) => globalThis.fetch(...args),
    csrfToken = globalThis.document?.querySelector('meta[name="csrf-token"]')?.content ?? '',
    formDataFactory = (target, submitter) => submitter ? new globalThis.FormData(target, submitter) : new globalThis.FormData(target),
    submitter = null,
    onState = () => {},
    onSuccess = () => {},
    onError = () => {},
    onRedirect = (url) => globalThis.window?.location?.assign(url),
} = {}) {
    if (form.dataset.submitting === 'true') {
        return { status: 'already_submitting' };
    }

    const submitButton = submitter ?? form.querySelector('button[type="submit"], input[type="submit"]');
    const wasDisabled = submitButton?.disabled ?? false;

    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.setAttribute('aria-disabled', 'true');
    }

    onState('submitting');

    try {
        const action = submitter?.getAttribute('formaction') || form.getAttribute('action') || form.dataset.bookingEndpoint || form.action;
        const method = submitter?.getAttribute('formmethod') || form.getAttribute('method') || 'POST';
        const response = await fetcher(action, {
            method: method.toUpperCase(),
            body: formDataFactory(form, submitter),
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': csrfToken,
            },
        });
        const payload = await response.json().catch(() => null);

        if (payload?.status === 'dashboard_redirect' && typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
            onState(payload.status);
            onRedirect(payload.redirect_url);

            return payload;
        }

        if (response.status === 409 && payload?.status === 'two_factor_required') {
            onState('two_factor_required');
            if (typeof payload.redirect_url === 'string' && payload.redirect_url !== '') {
                onRedirect(payload.redirect_url);
            }

            return payload;
        }

        if (response.status === 419) {
            const result = { status: 'session_expired' };
            onState(result.status);
            onError(result);

            return result;
        }

        if (response.status === 429) {
            const result = { ...(payload ?? {}), status: payload?.status ?? 'rate_limited', message: payload?.message };
            onState(result.status);
            onError(result);

            return result;
        }

        if (response.status === 422 && payload?.status === 'authentication_failed') {
            onState('authentication_error');
            onError(payload);

            return payload;
        }

        if (response.status === 422) {
            const result = { ...(payload ?? {}), status: payload?.status ?? 'validation_error' };
            onState(result.status);
            onError(result);

            return result;
        }

        if (response.status === 401) {
            const result = { status: 'authentication_error' };
            onState(result.status);
            onError(result);

            return result;
        }

        if (!response.ok || typeof payload?.status !== 'string' || typeof payload.html !== 'string') {
            const result = { ...(payload ?? {}), status: payload?.status ?? 'server_error', message: payload?.message };
            onState(result.status);
            onError(result);

            return result;
        }

        onState('success');
        onSuccess(payload);

        return payload;
    } catch {
        const result = { status: 'network_error' };
        onState(result.status);
        onError(result);

        return result;
    } finally {
        form.dataset.submitting = 'false';
        form.removeAttribute('aria-busy');

        if (submitButton && form.isConnected !== false) {
            submitButton.disabled = wasDisabled;
            submitButton.removeAttribute('aria-disabled');
        }
    }
}
