<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#ffffff">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="description" content="{{ __('public.hero_text') }}">
    <title>Easy English · {{ __('public.hero_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="bg-paper font-sans text-ink antialiased">
    <x-public-site-header :hero="true" />
    <div class="public-hero-shell" data-hero-shell>
        <section class="relative isolate flex w-full flex-col items-center overflow-hidden bg-white px-5 pt-[calc(64px+env(safe-area-inset-top,0px)+24px)] pb-5 md:pb-[150px] text-center text-ink sm:px-7 md:min-h-[100dvh] md:justify-start md:px-6 md:pb-[150px] md:pt-[140px]" data-hero>
            <div class="pointer-events-none absolute inset-0 hidden overflow-hidden bg-white md:block" aria-hidden="true">
                <img class="absolute bottom-0 left-0 top-auto w-[min(40vw,635px)] max-h-full max-w-none object-contain object-left-bottom" src="{{ \App\Support\VersionedAsset::url('images/hero-teacher.png') }}" alt="" fetchpriority="high" data-hero-image>
                <img class="absolute bottom-0 right-0 top-auto w-[min(40vw,635px)] max-h-full max-w-none object-contain object-right-bottom" src="{{ \App\Support\VersionedAsset::url('images/hero-student.png') }}" alt="" fetchpriority="high" data-hero-image>
            </div>

            <div class="relative z-10 flex w-full max-w-[900px] flex-col items-center">
                <h1 class="m-0 leading-[1.06]" data-hero-title>
                    <span class="type-hero-kicker block md:whitespace-nowrap" data-hero-title-line>{{ __('public.hero_title_a') }}</span>
                    <span class="type-hero-title block" data-hero-title-line>{{ __('public.hero_title_em') }}</span>
                </h1>
                <p class="type-hero-copy my-5 max-w-[38ch]" data-hero-description>
                    {{ __('public.hero_text') }}
                </p>
                <a class="type-action inline-flex min-h-[54px] w-full max-w-[360px] items-center justify-center rounded-full bg-primary px-5 py-4 !text-white transition-colors hover:bg-primary-hover md:min-h-0 md:w-auto md:max-w-none md:px-[34px] md:py-[18px]" href="{{ $bookingState['show_calendar'] ? '#availability' : '#bundles' }}" data-hero-cta>
                    {{ app()->getLocale() === 'ro' ? 'Programează prima sesiune gratuită' : 'Book your first free session' }}
                </a>
            </div>

            <div class="hero-conversation pointer-events-none absolute inset-0 z-10 block" aria-hidden="true">
                <div class="hero-conversation__side hero-conversation__side--teacher">
                    <p class="hero-conversation__bubble" style="--conversation-delay: 0s">What can I help you with today?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 4s">What's your presentation about?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 8s">Who will be listening?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 12s">Let's give it a clear structure.</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 16s">That's a strong opening!</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 20s">Try speaking a little slower.</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 24s">You're going to do well!</p>
                </div>
                <div class="hero-conversation__side hero-conversation__side--learner">
                    <p class="hero-conversation__bubble" style="--conversation-delay: 2s">I have a presentation next week.</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 6s">It's about renewable energy.</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 10s">My classmates. I have five minutes.</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 14s">An introduction, three points, and a conclusion?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 18s">"Good morning. Today I'll talk about..."</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 22s">Can we practise questions, too?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 26s">Thank you! I feel more confident now.</p>
                </div>
            </div>

            <div class="hero-visual-mobile relative z-10 mt-4 w-full max-w-[480px] overflow-hidden rounded-[24px] md:hidden" role="group" aria-label="{{ app()->getLocale() === 'ro' ? 'Profesor și cursant exersând o conversație' : 'Teacher and learner practising a conversation' }}">
                <div class="grid grid-cols-2 items-end gap-0">
                    <img class="block aspect-[1/2] w-full object-contain object-bottom" src="{{ \App\Support\VersionedAsset::url('images/teacher-mobile-hero.webp') }}" alt="{{ app()->getLocale() === 'ro' ? 'Profesor de engleză' : 'English teacher' }}" fetchpriority="high" data-hero-mobile-image>
                    <img class="block aspect-[1/2] w-full object-contain object-bottom" src="{{ \App\Support\VersionedAsset::url('images/student-mobile-hero.webp') }}" alt="{{ app()->getLocale() === 'ro' ? 'Cursant' : 'Learner' }}" fetchpriority="high" data-hero-mobile-image>
                </div>
                <div class="hero-conversation-mobile absolute inset-x-2 bottom-2 z-20" data-mobile-conversation data-visible-messages="5" aria-hidden="true">
                    <div class="hero-conversation-mobile__viewport" data-conversation-viewport>
                        <div class="hero-conversation-mobile__track" data-conversation-track>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">What can I help you with today?</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">I have a presentation next week.</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">What's your presentation about?</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">It's about renewable energy.</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">Who will be listening?</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">My classmates. I have five minutes.</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">Let's give it a clear structure.</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">An introduction, three points, and a conclusion?</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">That's a strong opening!</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">Good morning. Today I'll talk about...</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">Try speaking a little slower.</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">Can we practise questions, too?</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">You're going to do well!</p>
                            <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">Thank you! I feel more confident now.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="relative z-10 mt-3 flex w-full max-w-[850px] flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs font-semibold text-ink md:absolute md:bottom-[26px] md:left-1/2 md:mt-0 md:w-[min(850px,calc(100%-48px))] md:-translate-x-1/2 md:gap-x-8 md:px-6 md:py-4 md:text-sm" data-hero-trust>
                <div class="hero-trust-item is-active flex items-center justify-center gap-2" data-hero-trust-item><span aria-hidden="true">◷</span>{{ __('public.trust_flexibility') }}</div>
                <div class="hero-trust-item flex items-center justify-center gap-2" data-hero-trust-item><span aria-hidden="true">◎</span>{{ __('public.trust_teachers') }}</div>
                <div class="hero-trust-item flex items-center justify-center gap-2" data-hero-trust-item><span aria-hidden="true">↗</span>{{ __('public.trust_speaking') }}</div>
            </div>
        </section>
    </div>
    <main>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="why" data-pain-point-stack>
            <div class="mb-8 max-w-[780px] md:mb-10">
                <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.motivation_eyebrow') }}</p>
                <h2 class="type-section-title mb-4" data-reveal><span class="md:hidden">{{ __('public.motivation_title') }}</span><span class="hidden whitespace-pre-line md:block">{{ __('public.motivation_title_desktop') }}</span></h2>
                <p class="type-section-copy" data-reveal>{{ __('public.motivation_text') }}</p>
            </div>
            <div class="pain-point-stack-list">
                @foreach (['job', 'meeting', 'travel', 'interview', 'presentation', 'clients', 'phone'] as $index => $situation)
                    <article class="pain-point-card relative flex min-h-[232px] flex-col justify-center rounded-[24px] border border-line bg-white px-6 py-8 sm:px-8 md:min-h-[224px] md:px-10" data-pain-point-card>
                        <div class="pain-point-card__content w-full" data-pain-point-content>
                            <h3 class="pain-point-card__title m-0 w-full text-[clamp(1.25rem,2.8vw,2rem)] font-semibold leading-[1.25] tracking-[-0.035em] text-ink" data-pain-point-title>{{ __('public.motivation_'.$situation) }}</h3>
                            <p class="pain-point-card__description mt-3 mb-0 max-w-[58ch] text-sm leading-relaxed text-muted sm:text-base" data-pain-point-description>{{ __('public.motivation_'.$situation.'_description') }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="how">
            <div class="mb-8 max-w-[650px]">
                <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.how_eyebrow') }}</p>
                <h2 class="type-section-title mb-3.5" data-reveal>{{ __('public.how_title') }}</h2>
                <p class="type-section-copy" data-reveal>{{ __('public.how_text') }}</p>
            </div>
            <div
                class="how-steps-track"
                data-how-steps
                data-reveal-stagger
                role="region"
                aria-label="{{ __('public.how_steps_label') }}"
                tabindex="0"
            >
                @foreach (['one', 'two', 'three', 'four'] as $index => $step)
                    <article class="how-step-card flex h-full min-w-0 flex-col overflow-hidden rounded-[20px] border {{ $index === 3 ? 'border-primary/25 bg-primary-soft/20' : 'border-line bg-white' }}" data-reveal-item>
                        <div class="aspect-[4/3] w-full shrink-0">
                            <img
                                class="block size-full object-contain"
                                src="{{ \App\Support\VersionedAsset::url('images/step-'.($index + 1).'.png') }}"
                                alt="{{ __('public.step_'.$step.'_title') }}"
                                loading="lazy"
                                decoding="async"
                                data-image-reveal
                            >
                        </div>
                        <div class="mt-4 flex-1 px-5 pb-5 sm:px-6 sm:pb-6">
                            <span class="inline-flex rounded-[9px] bg-primary-soft px-3 py-2 text-xs font-extrabold text-primary">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3 class="type-card-title mt-3 mb-2">{{ __('public.step_'.$step.'_title') }}</h3>
                            <p class="type-card-copy m-0">{{ __('public.step_'.$step.'_text') }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="teachers" data-mobile-booking-cta-blocker>
            <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
                <div class="mb-8 max-w-[650px]">
                    <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.teachers_eyebrow') }}</p>
                    <h2 class="type-section-title mb-3.5" data-reveal><span class="md:hidden">{{ __('public.teachers_title') }}</span><span class="hidden whitespace-pre-line md:block">{{ __('public.teachers_title_desktop') }}</span></h2>
                    <p class="type-section-copy" data-reveal>{{ __('public.teachers_text') }}</p>
                </div>
                @if ($teachers->isNotEmpty())
                    <div class="mb-5 flex items-center justify-end gap-2" data-teacher-navigation>
                        <button class="testimonial-arrow" type="button" data-teacher-previous aria-label="{{ __('public.teachers_previous') }}" @disabled($teachers->count() < 2)>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7 7-7-7 7-7" /></svg>
                        </button>
                        <button class="testimonial-arrow testimonial-arrow--next" type="button" data-teacher-next aria-label="{{ __('public.teachers_next') }}" @disabled($teachers->count() < 2)>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-7-7 7 7-7 7" /></svg>
                        </button>
                    </div>
                    <div class="teacher-carousel" data-teacher-carousel>
                        @foreach ($teachers as $teacher)
                            <article class="teacher-card relative aspect-[3/4] w-full overflow-hidden rounded-[28px] bg-primary-strong shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)]" data-teacher-card>
                                @if ($teacher->teacherProfile?->photo_path)
                                    <img class="absolute inset-0 size-full object-cover" src="{{ '/storage/'.ltrim($teacher->teacherProfile?->photo_path, '/') }}" alt="{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}" loading="lazy">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-primary-soft via-primary to-primary-strong" aria-hidden="true"><span class="text-8xl font-semibold tracking-tight text-white/90">{{ mb_substr($teacher->name, 0, 1) }}</span></div>
                                @endif
                                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-transparent via-[#173c99]/20 to-[#101b45]/75 opacity-80" aria-hidden="true"></div>
                                <div class="absolute inset-x-0 bottom-0 flex items-center justify-between gap-3 p-6 text-white sm:p-7">
                                    <div class="min-w-0">
                                        <h3 class="m-0 text-xl font-semibold leading-tight tracking-tight sm:text-2xl">{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}</h3>
                                    </div>
                                    <button class="teacher-card__more" type="button" data-teacher-open aria-label="{{ __('public.teacher_more', ['name' => Illuminate\Support\Str::of($teacher->name)->before(' ')]) }}" aria-haspopup="dialog">
                                        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14" /></svg>
                                    </button>
                                </div>
                                <dialog class="teacher-dialog" data-teacher-dialog aria-labelledby="teacher-dialog-title-{{ $teacher->id }}">
                                    <div class="teacher-dialog__background" aria-hidden="true">
                                        @if ($teacher->teacherProfile?->photo_path)
                                            <img src="{{ '/storage/'.ltrim($teacher->teacherProfile?->photo_path, '/') }}" alt="">
                                        @else
                                            <div class="teacher-dialog__background-fallback"></div>
                                        @endif
                                    </div>
                                    <div class="teacher-dialog__content">
                                        <button class="teacher-dialog__close" type="button" data-teacher-close aria-label="{{ __('public.teacher_close') }}"><svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="m18 6-12 12M6 6l12 12" /></svg></button>
                                        <div class="teacher-dialog__profile">
                                            @if ($teacher->teacherProfile?->photo_path)
                                                <img class="teacher-dialog__photo" src="{{ '/storage/'.ltrim($teacher->teacherProfile?->photo_path, '/') }}" alt="{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}">
                                            @else
                                                <div class="teacher-dialog__photo teacher-dialog__photo--fallback" aria-hidden="true">{{ mb_substr($teacher->name, 0, 1) }}</div>
                                            @endif
                                            <div class="teacher-dialog__biography">
                                                <h3 class="mb-3 text-2xl font-semibold leading-tight tracking-tight sm:text-3xl" id="teacher-dialog-title-{{ $teacher->id }}">{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}</h3>
                                                <p class="m-0 leading-[1.75] text-muted whitespace-pre-line">{{ filled($teacher->teacherProfile?->bio) ? $teacher->teacherProfile?->bio : __('public.teacher_bio_fallback') }}</p>
                                            </div>
                                        </div>
                                    </div>
                                </dialog>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="rounded-[20px] border border-line bg-white p-6 leading-[1.7] text-muted">{{ __('public.teachers_empty') }}</p>
                @endif
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="testimonials" data-mobile-booking-cta-blocker>
            <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div class="max-w-[650px]">
                    <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.testimonials_eyebrow') }}</p>
                    <h2 class="type-section-title mb-0" data-reveal>{{ __('public.testimonials_title') }}</h2>
                </div>
                @if ($testimonials->isNotEmpty())
                    <div class="flex shrink-0 items-center gap-2" data-testimonial-navigation data-reveal>
                        <button class="testimonial-arrow" type="button" data-testimonial-previous aria-label="{{ __('public.testimonials_previous') }}" @disabled($testimonials->count() < 2)>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5m7 7-7-7 7-7" /></svg>
                        </button>
                        <button class="testimonial-arrow testimonial-arrow--next" type="button" data-testimonial-next aria-label="{{ __('public.testimonials_next') }}" @disabled($testimonials->count() < 2)>
                            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-7-7 7 7-7 7" /></svg>
                        </button>
                    </div>
                @endif
            </div>

            @if ($testimonials->isNotEmpty())
                <div class="testimonial-carousel" data-testimonials-carousel data-autoplay-duration="7000" data-reveal>
                    <div class="testimonial-feature" aria-live="polite" aria-atomic="true">
                        <span class="testimonial-quote-mark" aria-hidden="true">“</span>
                        @foreach ($testimonials as $testimonial)
                            <figure class="testimonial-panel{{ $loop->first ? ' is-entering' : '' }}" data-testimonial-panel @if (! $loop->first) hidden @endif>
                                <blockquote class="testimonial-quote">{{ $testimonial->quote }}</blockquote>
                                <figcaption class="testimonial-attribution">
                                    <span class="testimonial-avatar" aria-hidden="true">{{ $testimonial->author_name ? mb_strtoupper(mb_substr($testimonial->author_name, 0, 1)) : '—' }}</span>
                                    <span class="min-w-0">
                                        <span class="block font-bold text-ink">{{ $testimonial->author_name ?: __('public.testimonials_anonymous') }}</span>
                                        @if ($testimonial->author_context)
                                            <span class="mt-1 block text-sm text-muted">{{ $testimonial->author_context }}</span>
                                        @endif
                                        <span class="testimonial-author-progress" aria-hidden="true"><span data-testimonial-progress></span></span>
                                    </span>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>

                </div>
            @else
                <p class="type-section-copy">{{ __('public.testimonials_empty') }}</p>
            @endif
        </section>
        @include('components.booking.availability-section', compact('bookingState', 'weekStart', 'contactsVerified', 'verification', 'eligibility', 'phoneAlreadyVerified', 'whatsappConsent', 'bookingFormData', 'bundles'))
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="bundles" data-mobile-booking-cta-blocker>
            <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
                <div class="mb-8 max-w-[650px]">
                    <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.packages_eyebrow') }}</p>
                    <h2 class="type-section-title mb-3.5" data-reveal>{{ $bookingState['show_bundles_message'] && $bookingState['display_name'] ? __('public.bundles_personalized_title', ['name' => $bookingState['display_name']]) : __('public.packages_title') }}</h2>
                    <p class="type-section-copy" data-reveal>{{ $bookingState['show_bundles_message'] && $bookingState['display_name'] ? __('public.bundles_personalized_text') : __('public.packages_text') }}</p>
                </div>
                <div class="grid gap-[18px] md:grid-cols-3" data-reveal-stagger>
                    @foreach($bundles as $bundle)
                        @php($price = $bundle->priceBreakdown())
                        @php($discount = $price['discount'])
                        <article class="relative flex flex-col items-start rounded-[20px] border border-transparent bg-white p-[25px] shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] {{ $bundle->featured ? 'featured-bundle-card' : '' }}" data-reveal-item>
                            <span class="text-[13px] font-bold text-muted">{{ $bundle->localizedName() }}</span>
                            @if($discount)
                                <div class="mt-2.5 mb-1 flex flex-wrap items-center gap-x-2 gap-y-2">
                                    <span class="text-2xl leading-tight font-extrabold tracking-tight text-muted line-through">{{ number_format($price['original_price_ron'], 0, ',', '.') }} RON</span>
                                    <span class="inline-flex rounded-full bg-primary-soft px-2.5 py-1.5 text-[10px] font-extrabold tracking-tight text-primary">{{ __('public.discount_save_percentage', ['value' => $price['savings_percentage']]) }}</span>
                                    <span class="text-2xl leading-tight font-extrabold tracking-tight text-ink">{{ number_format($price['current_price_ron'], 0, ',', '.') }} RON</span>
                                </div>
                            @else
                                <div class="my-2.5 mb-1 text-[42px] leading-tight font-extrabold tracking-[-1.8px] text-ink">{{ number_format($price['current_price_ron'], 0, ',', '.') }} <small class="text-sm font-semibold tracking-normal">RON</small></div>
                            @endif
                            <p class="mt-2.5 mb-[18px] font-bold text-ink">{{ $bundle->localizedValue($bundle->tagline) }}</p>
                            <p class="m-0 text-sm leading-[1.65] text-muted">{{ $bundle->localizedValue($bundle->short_description) }}</p>
                            <div class="mb-6 mt-[19px] grid gap-[9px] text-[13px] text-muted">
                                @foreach($bundle->features ?? [] as $feature)
                                    <span><span class="mr-2 font-extrabold text-success">✓</span>{{ $bundle->localizedValue($feature['data'] ?? $feature) }}</span>
                                @endforeach
                            </div>
                            <a class="mt-auto inline-flex w-full items-center justify-center rounded-full bg-primary px-[21px] py-3.5 font-bold text-white transition hover:-translate-y-px hover:bg-primary-hover" href="{{ route('purchase', $bundle) }}">{{ __('public.package_cta') }}</a>
                        </article>
                    @endforeach
                </div>
            </div>
        </section>
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="progress-reports">
            <div class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-8 md:grid-cols-[0.85fr_1.15fr] md:items-center md:gap-14">
                <div>
                    <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.progress_eyebrow') }}</p>
                    <h2 class="type-section-title mb-3.5" data-reveal>{{ __('public.progress_title') }}</h2>
                    <p class="type-section-copy" data-reveal>{{ __('public.progress_text') }}</p>
                </div>
                <article class="rounded-[22px] bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6" aria-labelledby="public-progress-chart-title" data-reveal>
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 id="public-progress-chart-title" class="type-card-title m-0 text-ink">{{ __('public.progress_chart_title') }}</h3>
                            <p class="mt-1 mb-0 text-sm text-muted">{{ __('public.progress_chart_subtitle') }}</p>
                        </div>
                        <span class="rounded-full bg-primary-soft px-3 py-1.5 text-[10px] font-extrabold tracking-[0.08em] text-primary uppercase">{{ __('public.progress_chart_sample') }}</span>
                    </div>
                    <svg viewBox="0 0 440 340" role="img" aria-label="{{ __('public.progress_chart_aria') }}" class="mx-auto mt-4 block w-full max-w-[520px] overflow-visible text-ink" data-progress-chart>
                        <g fill="none" stroke="currentColor" stroke-opacity=".12" stroke-width="1" data-chart-grid>
                            <polygon points="220,159.2 243.6,176.8 234.5,198.1 205.5,198.1 196.4,176.8" />
                            <polygon points="220,134.4 267.1,169.6 249,212.2 191,212.2 172.9,169.6" />
                            <polygon points="220,109.6 290.7,162.4 263.5,226.2 176.5,226.2 149.3,162.4" />
                            <polygon points="220,84.8 314.2,155.2 278,240.3 162,240.3 125.8,155.2" />
                            <polygon points="220,60 337.9,145.7 292.6,284.5 147.4,284.5 102.1,145.7" />
                            <path d="M220 184 220 60 M220 184 337.9 145.7 M220 184 292.6 284.5 M220 184 147.4 284.5 M220 184 102.1 145.7" />
                        </g>
                        <polygon data-chart-shape points="220,140.6 255.4,172.5 249.2,224.1 187.2,229.1 178.7,170.6" fill="#94a3b8" fill-opacity=".08" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 5" stroke-linejoin="round" />
                        <polygon data-chart-shape points="220,119.5 276.6,165.6 262.3,242.2 174.8,246.2 156.3,163.3" fill="#60a5fa" fill-opacity=".10" stroke="#60a5fa" stroke-width="2.5" stroke-linejoin="round" />
                        <polygon data-chart-shape points="220,89.8 302.6,157.2 279.8,266.3 158.8,268.3 128,154.1" fill="#1747bd" fill-opacity=".14" stroke="#1747bd" stroke-width="3" stroke-linejoin="round" />
                        <g fill="#1747bd" data-chart-points>
                            <circle cx="220" cy="89.8" r="4"/><circle cx="302.6" cy="157.2" r="4"/><circle cx="279.8" cy="266.3" r="4"/><circle cx="158.8" cy="268.3" r="4"/><circle cx="128" cy="154.1" r="4"/>
                        </g>
                        <g fill="currentColor" font-size="12" font-weight="600" data-chart-labels>
                            <text x="220" y="25" text-anchor="middle">{{ __('public.progress_metric_vocabulary') }}</text>
                            <text x="369" y="137" text-anchor="start">{{ __('public.progress_metric_pronunciation') }}</text>
                            <text x="310" y="315" text-anchor="middle">{{ __('public.progress_metric_fluency') }}</text>
                            <text x="130" y="315" text-anchor="middle">{{ __('public.progress_metric_grammar') }}</text>
                            <text x="70" y="137" text-anchor="end">{{ __('public.progress_metric_confidence') }}</text>
                        </g>
                    </svg>
                    <div class="mt-2 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs font-semibold text-muted" data-chart-legend>
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 border-t-2 border-dashed border-slate-400"></span>{{ __('public.progress_chart_baseline') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-blue-400"></span>{{ __('public.progress_chart_after_five') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-primary"></span>{{ __('public.progress_chart_after_ten') }}</span>
                    </div>
                    <p class="mt-5 border-t border-line pt-4 text-xs leading-relaxed text-muted">{{ __('public.progress_note') }}</p>
                </article>
            </div>
        </section>
        @if ($faqs->isNotEmpty())
            <section class="py-14 md:py-[82px]" id="faq" data-faq-section data-mobile-booking-cta-blocker>
                <div class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-8 md:grid-cols-[1.15fr_0.85fr] md:items-center md:gap-14">
                    <div class="order-1 md:order-2">
                        <p class="mb-[18px] type-eyebrow" data-reveal>{{ __('public.faq_eyebrow') }}</p>
                        <h2 class="type-section-title mb-3.5" data-reveal><span class="md:hidden">{{ __('public.faq_title') }}</span><span class="hidden whitespace-pre-line md:block">{{ __('public.faq_title_desktop') }}</span></h2>
                        <p class="type-section-copy" data-reveal>{{ __('public.faq_text') }}</p>
                    </div>
                    <article class="order-2 flex h-[560px] flex-col overflow-hidden rounded-[22px] bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6 md:order-1" aria-label="{{ __('public.faq_title') }}" data-reveal-stagger>
                        @foreach ($faqs as $faq)
                            <details name="faq" class="flex min-h-0 flex-1 flex-col overflow-hidden border-b border-line last:border-b-0" data-reveal-item>
                                <summary class="flex shrink-0 list-none cursor-pointer items-center justify-between gap-4 py-[18px] font-bold marker:text-primary"><span>{{ $faq->question }}</span><svg class="size-4 shrink-0 text-primary" data-faq-chevron aria-hidden="true" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="m5 7.5 5 5 5-5" /></svg></summary>
                                <p class="min-h-0 flex-1 overflow-y-auto pb-2 text-sm leading-[1.7] text-muted whitespace-pre-line" data-faq-answer>{{ $faq->answer }}</p>
                            </details>
                        @endforeach
                    </article>
                </div>
            </section>
        @endif
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]" data-mobile-booking-cta-blocker>
            <div class="flex flex-col items-start justify-between gap-[30px] rounded-3xl bg-ink p-[30px] text-white md:flex-row md:items-center md:p-[46px]" data-reveal-stagger data-reveal-background>
                <h2 class="type-section-title mb-0 max-w-[620px]" data-reveal-item>{{ __('public.closing_title') }}</h2><a class="type-action inline-flex items-center justify-center rounded-3xl bg-primary px-[21px] py-3.5 font-bold text-white transition hover:bg-primary-hover md:rounded-full" href="{{ $bookingState['show_calendar'] ? '#availability' : '#bundles' }}" data-reveal-item>{{
                    __('public.closing_cta') }}</a>
            </div>
        </section>
        <a
            class="mobile-booking-cta type-action"
            href="{{ $bookingState['show_calendar'] ? '#availability' : '#bundles' }}"
            data-mobile-booking-cta
            hidden
            inert
            aria-hidden="true"
        >{{ __('public.mobile_booking_cta') }}</a>
    </main>
    <x-public-site-footer />

</body>

</html>
