<footer class="type-meta mx-auto flex w-[calc(100%-2.5rem)] max-w-[1160px] flex-col items-center gap-3 py-9 pb-6 text-center text-muted" data-public-site-footer data-mobile-booking-cta-blocker>
    <p class="m-0 sm:whitespace-nowrap">{{ __('public.footer_line') }}</p>
    <nav class="flex flex-wrap items-center justify-center gap-x-[18px] gap-y-2" aria-label="Linkuri utile">
        <a class="transition-colors hover:text-primary" href="{{ route('policy', 'privacy') }}">{{ __('public.footer_privacy') }}</a>
        <a class="transition-colors hover:text-primary" href="{{ route('policy', 'terms') }}">{{ __('public.footer_terms') }}</a>
        @php($contactEmail = config('easyenglish.contact_email'))
        @if (is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL))
            <a class="transition-colors hover:text-primary" href="mailto:{{ $contactEmail }}">{{ __('public.footer_contact') }}</a>
        @else
            <span>{{ __('public.footer_contact') }} · {{ __('public.footer_contact_pending') }}</span>
        @endif
    </nav>
</footer>
