<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7f8fc">
    <title>{{ __('public.purchase_title', ['name' => $bundle->localizedName()]) }} · Easy English</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-paper font-sans text-ink antialiased">
    <x-public-site-header />

    <main class="relative isolate overflow-hidden">
        <div aria-hidden="true" class="pointer-events-none absolute -top-48 right-[-9rem] -z-10 size-[34rem] rounded-full bg-primary-soft/80 blur-3xl"></div>
        <div aria-hidden="true" class="pointer-events-none absolute bottom-[-15rem] left-[-12rem] -z-10 size-[30rem] rounded-full bg-white blur-3xl"></div>

        <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-9 sm:py-14 lg:py-[76px]">
            <div class="mb-8 max-w-[720px]" data-reveal>
                <p class="mb-3 text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.bundle_label') }}</p>
                <h1 class="m-0 text-[clamp(32px,5vw,52px)] leading-[1.06] font-semibold tracking-[-2px]">{{ __('public.purchase_title', ['name' => $bundle->localizedName()]) }}</h1>
                <p class="mt-4 max-w-[620px] text-base leading-[1.7] text-muted sm:text-lg">{{ __('public.purchase_text', ['price' => number_format($priceBreakdown['current_price_ron'], 0, ',', '.'), 'total' => $bundle->paid_credits]) }}</p>
            </div>

            @if(session('checkout_cancelled'))
                <div class="mb-6 rounded-2xl border border-primary-border bg-primary-soft px-5 py-4 text-sm leading-6 text-ink" role="status">{{ session('checkout_cancelled') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-6 rounded-2xl border border-red-200 bg-red-50 px-5 py-4 text-sm leading-6 text-red-800" role="alert">
                    @foreach($errors->all() as $error)<p class="m-0">{{ $error }}</p>@endforeach
                </div>
            @endif
            @if(session('status'))
                <div class="mb-6 rounded-2xl border border-primary-border bg-primary-soft px-5 py-4 text-sm leading-6 text-ink" role="status">{{ session('status') }}</div>
            @endif

            <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,0.92fr)_minmax(0,1.08fr)] lg:gap-8" data-reveal-stagger>
                <section class="overflow-hidden rounded-[24px] border border-line bg-white shadow-[0_18px_60px_rgba(20,35,75,0.06)]" data-reveal-item aria-labelledby="order-summary-title">
                    <div class="border-b border-line px-6 py-6 sm:px-8 sm:py-7">
                        <div class="flex items-center justify-between gap-3">
                            <p class="m-0 text-[11px] font-extrabold tracking-[1.5px] text-primary uppercase">{{ __('public.purchase_summary_label') }}</p>
                            <span class="rounded-full bg-primary-soft px-3 py-1 text-xs font-bold text-primary">{{ __('public.package_duration') }}</span>
                        </div>
                        <h2 id="order-summary-title" class="mt-5 mb-1 text-2xl font-semibold tracking-[-0.8px]">{{ $bundle->localizedName() }}</h2>
                        <p class="m-0 text-sm leading-6 text-muted">{{ __('public.purchase_summary_description', ['count' => $bundle->paid_credits]) }}</p>
                        @if(! $voucherLocked)
                            <form class="mt-5 rounded-2xl border border-line bg-surface-subtle p-4" method="post" action="{{ route('purchase.voucher.apply', $bundle) }}">
                                @csrf
                                <label class="mb-2 block text-sm font-semibold" for="voucher_code">{{ __('public.voucher_code_label') }}</label>
                                <div class="flex flex-col gap-2 sm:flex-row">
                                    <input class="min-h-[48px] min-w-0 flex-1 rounded-xl border border-line bg-white px-4 text-sm uppercase outline-none focus:border-primary focus:ring-4 focus:ring-primary/10" id="voucher_code" name="voucher_code" type="text" value="{{ old('voucher_code', $voucherCode) }}" autocomplete="off" maxlength="64" placeholder="{{ __('public.voucher_code_placeholder') }}">
                                    <button class="min-h-[48px] rounded-full bg-primary px-5 py-3 text-sm font-bold text-white transition hover:bg-primary-hover" type="submit">{{ __('public.voucher_apply') }}</button>
                                </div>
                            </form>
                        @elseif($voucherCode)
                            <div class="mt-5 rounded-2xl border border-primary-border bg-primary-soft p-4 text-sm">
                                <span class="font-semibold">{{ __('public.voucher_code_label') }}:</span> {{ $voucherCode }}
                            </div>
                        @endif
                        @if($priceBreakdown['discount'])
                            <div class="mt-5 flex flex-wrap items-center gap-2">
                                <span class="rounded-full bg-primary-soft px-3 py-1 text-xs font-bold text-primary">{{ ($priceBreakdown['discount_type'] ?? null) === 'voucher' ? __('public.voucher_discount_label', ['code' => $priceBreakdown['discount']['voucher_code'] ?? '']) : $bundle->localizedValue($priceBreakdown['discount']['title'] ?? []) }}</span>
                                <span class="text-xs font-bold text-success">
                                    @if(in_array($priceBreakdown['discount_type'], ['percentage', 'voucher'], true))
                                        {{ __('public.discount_save_percentage', ['value' => $priceBreakdown['percentage']]) }}
                                        @if($priceBreakdown['discount_type'] === 'voucher')
                                            · {{ __('public.discount_save_amount', ['amount' => number_format($priceBreakdown['savings_ron'], 0, ',', '.')]) }}
                                        @endif
                                    @else
                                        {{ __('public.discount_save_amount', ['amount' => number_format($priceBreakdown['savings_ron'], 0, ',', '.')]) }}
                                    @endif
                                </span>
                            </div>
                            <p class="mt-5 mb-0 text-sm font-semibold text-muted line-through">{{ number_format($priceBreakdown['original_price_ron'], 0, ',', '.') }} RON</p>
                        @endif
                        @if($voucherCode && ! $voucherLocked)
                            <form class="mt-3" method="post" action="{{ route('purchase.voucher.remove', $bundle) }}">@csrf @method('DELETE')<button class="text-xs font-semibold text-muted underline underline-offset-4 hover:text-primary" type="submit">{{ __('public.voucher_remove') }}</button></form>
                        @endif
                        <div class="mt-7 flex flex-wrap items-end gap-x-2 gap-y-1">
                            <span class="text-[clamp(42px,6vw,58px)] leading-none font-bold tracking-[-2.8px] tabular-nums">{{ number_format($priceBreakdown['current_price_ron'], 0, ',', '.') }}</span>
                            <span class="pb-1 text-lg font-semibold text-muted">RON</span>
                        </div>
                        <p class="mt-2 mb-0 text-sm text-muted">{{ __('public.package_tax') }}</p>
                    </div>

                    <div class="space-y-4 px-6 py-6 sm:px-8">
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-muted">{{ __('public.purchase_summary_sessions') }}</span>
                            <strong class="text-sm font-bold tabular-nums">{{ $bundle->paid_credits }}</strong>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-muted">{{ __('public.purchase_summary_duration') }}</span>
                            <strong class="text-sm font-bold">15 min</strong>
                        </div>
                        <div class="flex items-center justify-between gap-4">
                            <span class="text-sm text-muted">{{ __('public.purchase_summary_validity') }}</span>
                            <strong class="text-right text-sm font-bold">{{ __('public.package_validity') }}</strong>
                        </div>
                        <div class="border-t border-line pt-5">
                            <div class="flex gap-3 rounded-2xl border border-primary-border bg-primary-soft p-4">
                                <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-primary" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="9"/><path d="M12 11v5m0-8h.01"/></svg>
                                <p class="m-0 text-sm leading-[1.65] text-ink">{{ __('public.purchase_free_intro_notice') }}</p>
                            </div>
                        </div>
                        <p class="m-0 text-xs leading-5 text-muted">
                            {{ __('public.purchase_policy_notice') }}
                            <a class="font-semibold text-primary underline decoration-primary-border underline-offset-4 hover:text-primary-hover" href="{{ route('terms') }}">{{ __('public.footer_terms') }}</a>
                            ·
                            <a class="font-semibold text-primary underline decoration-primary-border underline-offset-4 hover:text-primary-hover" href="{{ route('privacy') }}">{{ __('public.footer_privacy') }}</a>
                        </p>
                    </div>
                </section>

                <section class="rounded-[24px] border border-line bg-white p-6 shadow-[0_18px_60px_rgba(20,35,75,0.06)] sm:p-8" data-reveal-item aria-labelledby="checkout-form-title">
                    @if($checkoutReady)
                        <div class="mb-7">
                            <p class="mb-2 text-[11px] font-extrabold tracking-[1.5px] text-primary uppercase">{{ __('public.purchase_checkout_eyebrow') }}</p>
                            <h2 id="checkout-form-title" class="m-0 text-2xl font-semibold tracking-[-0.8px]">{{ __('public.purchase_form_title') }}</h2>
                            <p class="mt-2 mb-0 text-sm leading-6 text-muted">{{ __('public.purchase_form_description') }}</p>
                        </div>

                        <form class="space-y-5" method="post" action="{{ route('purchase.checkout', $bundle) }}">
                            @csrf
                            <input type="hidden" name="purchase_token" value="{{ $purchaseToken }}">
                            <input type="hidden" name="displayed_price_ron" value="{{ $priceBreakdown['current_price_ron'] }}">
                            <input type="hidden" name="name" value="{{ auth()->user()->name }}">

                            <div>
                                <label class="mb-2 block text-sm font-semibold" for="email">{{ __('public.field_email') }}</label>
                                <input class="min-h-[52px] w-full rounded-xl border border-line bg-surface-subtle px-4 text-base text-ink outline-none transition focus:border-primary focus:bg-white focus:ring-4 focus:ring-primary/10 read-only:cursor-not-allowed read-only:text-muted" id="email" name="email" type="email" value="{{ auth()->user()?->email ?? old('email', $verification?->customer_email) }}" autocomplete="email" @if(auth()->check()) readonly @endif required maxlength="254">
                            </div>

                            <div>
                                <label class="mb-2 block text-sm font-semibold" for="phone">{{ __('public.field_phone') }}</label>
                                <input class="min-h-[52px] w-full rounded-xl border border-line bg-surface-subtle px-4 text-base text-ink outline-none transition focus:border-primary focus:bg-white focus:ring-4 focus:ring-primary/10" id="phone" name="phone" type="tel" value="{{ old('phone', auth()->user()?->phone ?? $verification?->customer_phone) }}" autocomplete="tel" inputmode="tel" pattern="[+0-9 () .-]{7,32}" required maxlength="32">
                            </div>

                            @if(config('services.contact_verification.mode', 'email_and_whatsapp') !== 'email_only')
                                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line bg-surface-subtle p-4 text-sm leading-6 text-muted">
                                    <input class="mt-1 size-4 shrink-0 accent-primary" type="checkbox" name="whatsapp_service_consent" value="1" required @checked(old('whatsapp_service_consent'))>
                                    <span>{{ __('public.whatsapp_service_consent') }}</span>
                                </label>
                            @endif

                            <div class="rounded-xl bg-surface-subtle px-4 py-3 text-sm leading-6 text-muted">
                                <div class="flex items-start gap-2.5">
                                    <svg aria-hidden="true" class="mt-0.5 size-5 shrink-0 text-success" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="10" width="16" height="11" rx="2"/><path d="M8 10V7a4 4 0 1 1 8 0v3m-4 4v3"/></svg>
                                    <p class="m-0">{{ __('public.purchase_secure_note') }}</p>
                                </div>
                            </div>

                            <button class="inline-flex min-h-[56px] w-full items-center justify-center gap-3 rounded-full bg-primary px-6 py-4 text-base font-bold text-white shadow-[0_8px_20px_rgba(20,80,223,0.2)] transition duration-200 hover:-translate-y-0.5 hover:bg-primary-hover hover:shadow-[0_12px_26px_rgba(20,80,223,0.25)]" type="submit">
                                {{ __('public.purchase_checkout_cta') }}
                                <svg aria-hidden="true" class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
                            </button>
                        </form>
                    @else
                        <div class="flex min-h-[360px] flex-col items-start justify-center">
                            <span class="mb-5 grid size-12 place-items-center rounded-2xl bg-primary-soft text-primary">
                                <svg aria-hidden="true" class="size-6" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 8v4m0 4h.01"/><circle cx="12" cy="12" r="9"/></svg>
                            </span>
                            <h2 class="m-0 text-2xl font-semibold tracking-[-0.8px]">{{ __('public.checkout_unavailable_title') }}</h2>
                            <p class="mt-3 mb-6 max-w-[42ch] text-sm leading-6 text-muted">{{ __('public.checkout_unavailable_text') }}</p>
                            <a class="inline-flex min-h-[50px] items-center justify-center rounded-full bg-primary px-6 py-3 text-sm font-bold text-white transition-colors hover:bg-primary-hover" href="{{ route('home') }}#bundles">{{ __('public.checkout_unavailable_cta') }}</a>
                        </div>
                    @endif
                </section>
            </div>
            <p class="mt-8 text-center text-xs leading-5 text-muted" data-reveal>{{ __('public.purchase_footer_note') }}</p>
        </div>
    </main>
<x-public-site-footer /></body>
</html>
