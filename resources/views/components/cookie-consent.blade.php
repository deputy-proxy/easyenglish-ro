<div
    class="fixed inset-x-3 bottom-3 z-[100] mx-auto max-w-3xl rounded-2xl border border-line bg-white p-5 text-ink shadow-[0_18px_60px_rgba(20,37,31,0.18)] sm:inset-x-6 sm:bottom-6 sm:p-6"
    data-cookie-consent
    role="dialog"
    aria-labelledby="cookie-consent-title"
    aria-describedby="cookie-consent-description"
    hidden
>
    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="max-w-xl">
            <h2 id="cookie-consent-title" class="m-0 text-base font-bold">{{ __('cookies.title') }}</h2>
            <p id="cookie-consent-description" class="mt-2 mb-0 text-sm leading-relaxed text-muted">
                {{ __('cookies.description') }} <a class="font-semibold text-primary underline" href="{{ route('privacy') }}">{{ __('cookies.privacy_link') }}</a>.
            </p>
        </div>
        <button class="shrink-0 text-sm font-semibold text-muted underline" type="button" data-consent-open-preferences>{{ __('cookies.customize') }}</button>
    </div>

    <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <button class="min-h-11 rounded-full border border-line px-5 text-sm font-semibold text-ink transition hover:bg-surface-subtle" type="button" data-consent-reject>{{ __('cookies.reject_optional') }}</button>
        <button class="min-h-11 rounded-full bg-primary px-5 text-sm font-semibold text-white transition hover:bg-primary-hover" type="button" data-consent-accept-all>{{ __('cookies.accept_all') }}</button>
    </div>

    <div class="mt-4 border-t border-line pt-4" data-cookie-preferences hidden>
        <p class="mb-3 text-sm font-semibold">{{ __('cookies.categories_heading') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                <input class="mt-1 size-4 accent-primary" type="checkbox" checked disabled>
                <span><strong class="block text-ink">{{ __('cookies.necessary_title') }}</strong>{{ __('cookies.necessary_description') }}</span>
            </label>
            <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                <input class="mt-1 size-4 accent-primary" type="checkbox" data-consent-analytics>
                <span><strong class="block text-ink">{{ __('cookies.analytics_title') }}</strong>{{ __('cookies.analytics_description') }}</span>
            </label>
            <label class="flex items-start gap-3 text-sm leading-relaxed text-muted">
                <input class="mt-1 size-4 accent-primary" type="checkbox" data-consent-advertising>
                <span><strong class="block text-ink">{{ __('cookies.advertising_title') }}</strong>{{ __('cookies.advertising_description') }}</span>
            </label>
        </div>
        <div class="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
            <button class="min-h-11 rounded-full border border-line px-5 text-sm font-semibold text-ink" type="button" data-consent-cancel>{{ __('cookies.cancel') }}</button>
            <button class="min-h-11 rounded-full bg-primary px-5 text-sm font-semibold text-white" type="button" data-consent-save>{{ __('cookies.save') }}</button>
        </div>
    </div>
</div>

<button
    class="fixed bottom-0 left-0 z-[110] rounded-tr-lg border border-b-0 border-l-0 border-line bg-white/95 px-3 py-2 text-xs font-semibold text-muted shadow-sm transition hover:text-primary sm:px-4"
    type="button"
    data-cookie-manage
>
    {{ __('cookies.manage') }}
</button>
