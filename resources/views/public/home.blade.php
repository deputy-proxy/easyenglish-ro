<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('public.hero_text') }}">
    <title>Easy English · {{ __('public.hero_title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

</head>

<body class="bg-paper font-sans text-ink antialiased">
    <header class="absolute inset-x-0 top-0 z-20 flex h-[76px] items-center justify-between gap-3 px-4 sm:px-6 md:h-[88px] md:px-[clamp(22px,5.5vw,96px)]">
        <a class="shrink-0" href="{{ route('home') }}" aria-label="Easy English home">
            <img class="block w-[130px] [filter:brightness(0)_saturate(1)_invert(12%)_sepia(28%)_saturate(2554%)_hue-rotate(195deg)_brightness(91%)_contrast(96%)]" src="{{ \App\Support\VersionedAsset::url('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
        </a>
        <nav aria-label="{{ __('public.nav_label') }}" class="flex shrink-0 items-center">
            <a class="inline-flex h-[20.87px] items-center justify-center rounded-[6px] border border-ink px-2.5 py-0 text-[11px] leading-none font-bold text-ink transition-colors hover:bg-ink hover:text-white" href="{{ url('/admin/login') }}">
                Autentificare
            </a>
        </nav>
    </header>
    <main>
        <section class="relative isolate flex w-full flex-col items-center overflow-hidden bg-white px-5 pb-7 pt-[100px] text-center text-ink sm:px-7 md:min-h-[min(900px,100svh)] md:justify-start md:px-6 md:pb-[150px] md:pt-[176px]">
            <div class="pointer-events-none absolute inset-0 hidden overflow-hidden bg-white md:block" aria-hidden="true">
                <img class="absolute bottom-0 left-0 top-auto w-[min(40vw,635px)] max-h-full max-w-none object-contain object-left-bottom" src="{{ \App\Support\VersionedAsset::url('images/hero-teacher.png') }}" alt="" fetchpriority="high">
                <img class="absolute bottom-0 right-0 top-auto w-[min(40vw,635px)] max-h-full max-w-none object-contain object-right-bottom" src="{{ \App\Support\VersionedAsset::url('images/hero-student.png') }}" alt="" fetchpriority="high">
            </div>

            <div class="relative z-10 flex w-full max-w-[900px] flex-col items-center">
                <h1 class="m-0 leading-[1.06]">
                    <span class="block md:whitespace-nowrap text-2xl">{{ __('public.hero_title_a') }}</span>
                    <span class="block font-bold text-6xl">{{ __('public.hero_title_em') }}</span>
                </h1>
                <p class="my-5 max-w-[38ch] text-lg font-medium text-muted">
                    {{ __('public.hero_text') }}
                </p>
                <a class="inline-flex min-h-[54px] w-full max-w-[360px] items-center justify-center rounded-full bg-primary px-5 py-4 text-base leading-tight font-bold !text-white transition-colors hover:bg-primary-hover sm:text-lg md:min-h-0 md:w-auto md:max-w-none md:px-[34px] md:py-[18px] md:text-[19px]" href="#availability">
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
                <div class="grid h-[320px] grid-cols-2 gap-0">
                    <img class="relative left-4 h-[320px] w-full object-cover object-left" src="{{ \App\Support\VersionedAsset::url('images/hero-teacher.png') }}" alt="{{ app()->getLocale() === 'ro' ? 'Profesor de engleză' : 'English teacher' }}" fetchpriority="high">
                    <img class="relative -left-4 h-[320px] w-full object-cover object-right" src="{{ \App\Support\VersionedAsset::url('images/hero-student.png') }}" alt="{{ app()->getLocale() === 'ro' ? 'Cursant' : 'Learner' }}" fetchpriority="high">
                </div>
                <div class="hero-conversation-mobile absolute inset-x-2 bottom-2 z-20" data-mobile-conversation aria-hidden="true">
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
                    <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">“Good morning. Today I'll talk about...”</p>
                    <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">Try speaking a little slower.</p>
                    <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">Can we practise questions, too?</p>
                    <p class="hero-conversation-mobile__message hero-conversation-mobile__message--teacher">You're going to do well!</p>
                    <p class="hero-conversation-mobile__message hero-conversation-mobile__message--learner">Thank you! I feel more confident now.</p>
                </div>
                </div>
            </div>

            <div class="relative z-10 mt-5 flex w-full max-w-[850px] flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs font-semibold text-ink md:absolute md:bottom-[26px] md:left-1/2 md:mt-0 md:w-[min(850px,calc(100%-48px))] md:-translate-x-1/2 md:gap-x-8 md:px-6 md:py-4 md:text-sm">
                <div class="flex items-center gap-2"><span aria-hidden="true">◴</span>{{ __('public.trust_minutes') }}</div>
                <div class="flex items-center gap-2"><span aria-hidden="true">◉</span>{{ app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp' }}</div>
                <div class="flex items-center gap-2"><span aria-hidden="true">↗</span>{{ app()->getLocale() === 'ro' ? 'Progres real și predictibil' : 'Real, measurable progress' }}</div>
            </div>
        </section>
        <section class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-7 py-14 md:grid-cols-[0.9fr_1.1fr] md:items-center md:gap-[70px] md:py-[82px]" id="why">
            <div>
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.motivation_eyebrow') }}</p>
                <h2 class="mb-4 text-[clamp(34px,4vw,48px)] leading-[1.08] tracking-[-2px]">{{ __('public.motivation_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.motivation_text') }}</p>
            </div>
            <div class="border-t border-line">
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-primary">01</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_job') }}</p></article>
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-primary">02</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_meeting') }}</p></article>
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-primary">03</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_travel') }}</p></article>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="how">
            <div class="mb-8 max-w-[650px]">
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.how_eyebrow') }}</p>
                <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.how_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.how_text') }}</p>
            </div>
            <div class="grid auto-rows-fr gap-5 md:grid-cols-2">
                @foreach (['one', 'two', 'three', 'four'] as $index => $step)
                    <article class="flex h-full flex-col overflow-hidden rounded-[20px] border border-line bg-white p-5 sm:p-6">
                        <div class="flex h-[230px] shrink-0 items-center justify-center">
                            <img
                                class="block h-full w-full object-contain"
                                src="{{ asset('images/step-'.($index + 1).'.png') }}"
                                alt="{{ __('public.step_'.$step.'_title') }}"
                                loading="lazy"
                                decoding="async"
                            >
                        </div>
                        <div class="mt-4 flex-1">
                            <span class="inline-flex rounded-[9px] bg-primary-soft px-3 py-2 text-xs font-extrabold text-primary">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                            <h3 class="mt-3 mb-2 text-xl">{{ __('public.step_'.$step.'_title') }}</h3>
                            <p class="m-0 text-sm leading-[1.65] text-muted">{{ __('public.step_'.$step.'_text') }}</p>
                        </div>
                    </article>
                @endforeach
            </div>
        </section>
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="teachers">
            <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
                <div class="mb-8 max-w-[650px]">
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.teachers_eyebrow') }}</p>
                    <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.teachers_title') }}</h2>
                    <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.teachers_text') }}</p>
                </div>
                @if ($teachers->isNotEmpty())
                    <div class="grid grid-cols-1 justify-items-center gap-5 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        @foreach ($teachers as $teacher)
                            <article class="teacher-card group relative aspect-[3/4] w-full max-w-[300px] overflow-hidden rounded-[28px] bg-primary-strong shadow-sm transition duration-300 hover:-translate-y-1 focus-within:-translate-y-1">
                                @if ($teacher->teacher_photo_path)
                                    <img class="absolute inset-0 size-full object-cover" src="{{ '/storage/'.ltrim($teacher->teacher_photo_path, '/') }}" alt="{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}" loading="lazy">
                                @else
                                    <div class="absolute inset-0 flex items-center justify-center bg-gradient-to-br from-primary-soft via-primary to-primary-strong" aria-hidden="true"><span class="text-8xl font-semibold tracking-tight text-white/90">{{ mb_substr($teacher->name, 0, 1) }}</span></div>
                                @endif
                                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-transparent via-[#173c99]/35 to-[#101b45] opacity-90 transition-opacity duration-300 group-hover:opacity-0 group-focus-within:opacity-0"></div>
                                <div class="pointer-events-none absolute inset-0 bg-gradient-to-b from-transparent via-black/65 to-[#101b45] opacity-0 transition-opacity duration-300 group-hover:opacity-100 group-focus-within:opacity-100"></div>
                                <div class="absolute inset-x-0 bottom-0 flex flex-col p-6 text-white sm:p-7">
                                    <h3 class="m-0 text-2xl font-semibold tracking-tight">{{ Illuminate\Support\Str::of($teacher->name)->before(' ') }}</h3>
                                    <p class="mt-1 mb-0 text-sm text-white/75">{{ __('public.teacher_role') }}</p>
                                    <div class="grid grid-rows-[1fr] transition-all duration-300 lg:grid-rows-[0fr] lg:group-hover:grid-rows-[1fr] lg:group-focus-within:grid-rows-[1fr]">
                                        <div class="overflow-hidden">
                                            @if (filled($teacher->teacher_bio))
                                                <p class="pt-3 text-sm leading-relaxed text-white/90">{{ $teacher->teacher_bio }}</p>
                                            @else
                                                <p class="pt-3 text-sm leading-relaxed text-white/90">{{ __('public.teacher_bio_fallback') }}</p>
                                            @endif
                                            <a class="mt-5 inline-flex w-max items-center rounded-full border border-white/90 px-5 py-2 text-sm font-semibold text-white transition-colors hover:bg-white hover:text-ink focus-visible:bg-white focus-visible:text-ink" href="#availability">{{ __('public.teacher_cta') }}</a>
                                        </div>
                                    </div>
                                </div>
                            </article>
                        @endforeach
                    </div>
                @else
                    <p class="rounded-[20px] border border-line bg-white p-6 leading-[1.7] text-muted">{{ __('public.teachers_empty') }}</p>
                @endif
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="testimonials">
            <div class="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                <div class="max-w-[650px]">
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.testimonials_eyebrow') }}</p>
                    <h2 class="mb-0 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.testimonials_title') }}</h2>
                </div>
                @if ($testimonials->isNotEmpty())
                    <div class="flex shrink-0 items-center gap-2" data-testimonial-navigation>
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
                <div class="testimonial-carousel" data-testimonials-carousel>
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
                                    </span>
                                </figcaption>
                            </figure>
                        @endforeach
                    </div>

                    <div class="testimonial-selector-list" role="group" aria-label="{{ __('public.testimonials_choose') }}">
                        @foreach ($testimonials as $testimonial)
                            <button class="testimonial-selector" type="button" data-testimonial-selector aria-label="{{ __('public.testimonials_show', ['name' => $testimonial->author_name ?: __('public.testimonials_anonymous')]) }}" aria-pressed="{{ $loop->first ? 'true' : 'false' }}" data-active="{{ $loop->first ? 'true' : 'false' }}">
                                <span class="testimonial-selector-avatar" aria-hidden="true">{{ $testimonial->author_name ? mb_strtoupper(mb_substr($testimonial->author_name, 0, 1)) : '—' }}</span>
                                <span class="min-w-0 flex-1 text-left">
                                    <span class="testimonial-selector-name">{{ $testimonial->author_name ?: __('public.testimonials_anonymous') }}</span>
                                    @if ($testimonial->author_context)
                                        <span class="testimonial-selector-context">{{ $testimonial->author_context }}</span>
                                    @endif
                                    <span class="testimonial-selector-progress" aria-hidden="true"><span data-testimonial-progress></span></span>
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>
            @else
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.testimonials_empty') }}</p>
            @endif
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="availability">
            <div class="mb-8 max-w-[650px]">
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.availability_eyebrow') }}</p>
                <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.availability_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.availability_text') }}</p>
            </div>
            <div class="overflow-hidden rounded-[22px] bg-white p-3 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6" data-availability data-week="{{ $weekStart->format('Y-m-d') }}"
                data-book-url="{{ route('booking.create') }}">
                <div class="mb-3 flex items-center justify-between gap-3 sm:mb-5">
                    <div class="min-w-0">
                        <h3 class="m-0 text-base font-bold text-ink sm:text-[19px]">{{ __('public.booking_week_title') }}</h3>
                        <p class="mt-1 mb-0 text-[11px] text-muted sm:text-xs">{{ __('public.availability_week_hint') }}</p>
                    </div>
                    <div class="flex shrink-0 gap-2"><button class="size-[36px] rounded-full border border-primary-border bg-white text-xl text-ink transition hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus disabled:opacity-35 sm:size-[38px]" type="button" data-prev
                            aria-label="{{ __('public.previous_week') }}">‹</button><button class="size-[36px] rounded-full border border-primary-border bg-white text-xl text-ink transition hover:bg-primary-soft focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus sm:size-[38px]" type="button" data-next
                            aria-label="{{ __('public.next_week') }}">›</button></div>
                </div>
                <p class="mb-3 min-h-5 text-xs font-semibold text-primary-strong" data-week-status aria-live="polite">{{ __('public.availability_loading') }}</p>
                <div class="grid min-w-0 grid-cols-7 gap-1 sm:gap-2" data-weekgrid aria-live="polite">
                    <p class="col-span-7">{{ __('public.availability_loading') }}</p>
                </div>
                <p class="mt-3 text-[11px] leading-relaxed text-muted sm:mt-3.5 sm:text-xs">{{ __('public.timezone_note') }}</p>
            </div>
        </section>
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="bundles">
            <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
                <div class="mb-8 max-w-[650px]">
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.packages_eyebrow') }}</p>
                    <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.packages_title') }}</h2>
                    <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.packages_text') }}</p>
                </div>
                <p class="mb-6 leading-[1.7] text-muted">{{ __('public.shared_free_notice') }}</p>
                <div class="grid gap-[18px] md:grid-cols-3">@foreach($bundles as $bundle)<article
                        class="relative flex flex-col items-start rounded-[20px] bg-white p-[25px] shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)]">@if($bundle->price_ron===390)<span
                            class="mb-3 rounded-full bg-primary-soft px-2.5 py-[7px] text-[10px] font-extrabold tracking-[1px] text-primary">{{ __('public.package_popular') }}</span>@endif<span class="text-[13px] font-bold text-muted">{{
                            __('public.bundle_label') }} {{ $loop->iteration }}</span>
                        <div class="my-2.5 mb-1 text-[42px] leading-tight font-extrabold tracking-[-1.8px]">{{ number_format($bundle->price_ron, 0, ',', '.') }} <small class="text-sm font-semibold tracking-normal">RON</small></div>
                        <p class="m-0 text-sm leading-[1.65] text-muted">{{ trans_choice('public.package_free_paid', $bundle->paid_credits, ['count' => $bundle->paid_credits]) }}</p>
                        <p class="mt-2.5 mb-[18px] font-bold text-ink">{{ trans_choice('public.package_total', $bundle->paid_credits+1,
                            ['count' => $bundle->paid_credits+1]) }}</p>
                        <div class="mb-6 mt-[19px] grid gap-[9px] text-[13px] text-muted"><span><span class="mr-2 font-extrabold text-success">✓</span>{{ __('public.package_duration') }}</span><span><span class="mr-2 font-extrabold text-success">✓</span>{{ __('public.package_validity') }}</span><span><span class="mr-2 font-extrabold text-success">✓</span>{{ __('public.package_tax') }}</span></div>
                        <a class="mt-auto inline-flex w-full items-center justify-center rounded-full bg-primary px-[21px] py-3.5 font-bold text-white transition hover:-translate-y-px hover:bg-primary-hover "
                            href="{{ route('purchase', $bundle) }}">{{ __('public.package_cta') }}</a>
                    </article>@endforeach</div>
            </div>
        </section>
        <section class="bg-surface-subtle py-14 md:py-[82px]" id="progress-reports">
            <div class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-8 md:grid-cols-[0.85fr_1.15fr] md:items-center md:gap-14">
                <div>
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.progress_eyebrow') }}</p>
                    <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.progress_title') }}</h2>
                    <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.progress_text') }}</p>
                </div>
                <article class="rounded-[22px] bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6" aria-labelledby="public-progress-chart-title">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 id="public-progress-chart-title" class="m-0 text-lg font-bold text-ink">{{ __('public.progress_chart_title') }}</h3>
                            <p class="mt-1 mb-0 text-sm text-muted">{{ __('public.progress_chart_subtitle') }}</p>
                        </div>
                        <span class="rounded-full bg-primary-soft px-3 py-1.5 text-[10px] font-extrabold tracking-[0.08em] text-primary uppercase">{{ __('public.progress_chart_sample') }}</span>
                    </div>
                    <svg viewBox="0 0 440 340" role="img" aria-label="{{ __('public.progress_chart_aria') }}" class="mx-auto mt-4 block w-full max-w-[520px] overflow-visible text-ink">
                        <g fill="none" stroke="currentColor" stroke-opacity=".12" stroke-width="1">
                            <polygon points="220,159.2 243.6,176.8 234.5,198.1 205.5,198.1 196.4,176.8" />
                            <polygon points="220,134.4 267.1,169.6 249,212.2 191,212.2 172.9,169.6" />
                            <polygon points="220,109.6 290.7,162.4 263.5,226.2 176.5,226.2 149.3,162.4" />
                            <polygon points="220,84.8 314.2,155.2 278,240.3 162,240.3 125.8,155.2" />
                            <polygon points="220,60 337.9,145.7 292.6,284.5 147.4,284.5 102.1,145.7" />
                            <path d="M220 184 220 60 M220 184 337.9 145.7 M220 184 292.6 284.5 M220 184 147.4 284.5 M220 184 102.1 145.7" />
                        </g>
                        <polygon points="220,140.6 255.4,172.5 249.2,224.1 187.2,229.1 178.7,170.6" fill="#94a3b8" fill-opacity=".08" stroke="#94a3b8" stroke-width="2" stroke-dasharray="5 5" stroke-linejoin="round" />
                        <polygon points="220,119.5 276.6,165.6 262.3,242.2 174.8,246.2 156.3,163.3" fill="#60a5fa" fill-opacity=".10" stroke="#60a5fa" stroke-width="2.5" stroke-linejoin="round" />
                        <polygon points="220,89.8 302.6,157.2 279.8,266.3 158.8,268.3 128,154.1" fill="#1747bd" fill-opacity=".14" stroke="#1747bd" stroke-width="3" stroke-linejoin="round" />
                        <g fill="#1747bd">
                            <circle cx="220" cy="89.8" r="4"/><circle cx="302.6" cy="157.2" r="4"/><circle cx="279.8" cy="266.3" r="4"/><circle cx="158.8" cy="268.3" r="4"/><circle cx="128" cy="154.1" r="4"/>
                        </g>
                        <g fill="currentColor" font-size="12" font-weight="600">
                            <text x="220" y="25" text-anchor="middle">{{ __('public.progress_metric_vocabulary') }}</text>
                            <text x="369" y="137" text-anchor="start">{{ __('public.progress_metric_pronunciation') }}</text>
                            <text x="310" y="315" text-anchor="middle">{{ __('public.progress_metric_fluency') }}</text>
                            <text x="130" y="315" text-anchor="middle">{{ __('public.progress_metric_grammar') }}</text>
                            <text x="70" y="137" text-anchor="end">{{ __('public.progress_metric_confidence') }}</text>
                        </g>
                    </svg>
                    <div class="mt-2 flex flex-wrap justify-center gap-x-5 gap-y-2 text-xs font-semibold text-muted">
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 border-t-2 border-dashed border-slate-400"></span>{{ __('public.progress_chart_baseline') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-blue-400"></span>{{ __('public.progress_chart_after_five') }}</span>
                        <span class="inline-flex items-center gap-2"><span class="h-0.5 w-5 bg-primary"></span>{{ __('public.progress_chart_after_ten') }}</span>
                    </div>
                    <p class="mt-5 border-t border-line pt-4 text-xs leading-relaxed text-muted">{{ __('public.progress_note') }}</p>
                </article>
            </div>
        </section>
        <section class="py-14 md:py-[82px]" id="faq">
            <div class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-8 md:grid-cols-[1.15fr_0.85fr] md:items-center md:gap-14">
                <article class="rounded-[22px] bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.07),0_2px_4px_rgba(0,0,0,0.07),0_4px_8px_rgba(0,0,0,0.07),0_8px_16px_rgba(0,0,0,0.07),0_16px_32px_rgba(0,0,0,0.07),0_32px_64px_rgba(0,0,0,0.07)] sm:p-6" aria-label="{{ __('public.faq_title') }}">
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-primary">{{ __('public.faq_q1') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a1') }}</p>
                    </details>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-primary">{{ __('public.faq_q2') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a2') }}</p>
                    </details>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-primary">{{ __('public.faq_q3') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a3') }}</p>
                    </details>
                    <details class="py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-primary">{{ __('public.faq_q4') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a4') }}</p>
                    </details>
                </article>
                <div>
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-primary uppercase">{{ __('public.faq_eyebrow') }}</p>
                    <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.faq_title') }}</h2>
                </div>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
            <div class="flex flex-col items-start justify-between gap-[30px] rounded-3xl bg-ink p-[30px] text-white md:flex-row md:items-center md:p-[46px]">
                <h2 class="mb-0 max-w-[620px] text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.closing_title') }}</h2><a class="inline-flex items-center justify-center rounded-full bg-primary px-[21px] py-3.5 font-bold text-white transition hover:bg-primary-hover" href="#availability">{{
                    __('public.closing_cta') }}</a>
            </div>
        </section>
    </main>
    <footer class="mx-auto flex w-[calc(100%-2.5rem)] max-w-[1160px] flex-col items-start justify-between gap-5 py-9 pb-6 text-xs text-muted md:flex-row md:items-center"><span>{{ __('public.footer_line') }}</span>
        <div class="flex flex-wrap items-center gap-x-[18px] gap-y-3"><a class="hover:text-primary" href="{{ route('policy','privacy') }}">{{ __('public.footer_privacy') }}</a><a
                class="hover:text-primary" href="{{ route('policy','terms') }}">{{ __('public.footer_terms')
                }}</a>@if(config('easyenglish.contact_email'))<a
                class="hover:text-primary" href="mailto:{{ config('easyenglish.contact_email') }}">{{ __('public.footer_contact')
                }}</a>@else<span>{{ __('public.footer_contact') }} · {{ __('public.footer_contact_pending')
                }}</span>@endif
            <form class="flex items-center gap-1" method="post" action="{{ route('locale.set') }}">@csrf<button name="locale" value="ro"
                    class="p-[7px] text-muted hover:text-primary {{ app()->getLocale() === 'ro' ? 'font-bold text-primary' : '' }}" aria-label="Română">RO</button><span>/</span><button
                    name="locale" value="en" class="p-[7px] text-muted hover:text-primary {{ app()->getLocale() === 'en' ? 'font-bold text-primary' : '' }}"
                    aria-label="English">EN</button></form>
        </div>
    </footer>
    <script>
        (() => {
            const conversation = document.querySelector('[data-mobile-conversation]');
            const track = conversation?.querySelector('[data-conversation-track]');
            if (!conversation || !track) return;

            const messages = Array.from(track.children);
            const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            let visibleCount = 0;
            let scrollOffset = 0;

            const revealNext = () => {
                if (visibleCount >= messages.length) {
                    window.setTimeout(() => {
                        track.style.transition = 'none';
                        track.style.transform = 'translateY(0)';
                        messages.forEach(message => message.classList.remove('is-visible'));
                        visibleCount = 0;
                        scrollOffset = 0;
                        void track.offsetHeight;
                        track.style.transition = '';
                        revealNext();
                    }, 1800);
                    return;
                }

                messages[visibleCount].classList.add('is-visible');
                visibleCount += 1;

                if (visibleCount > 3) {
                    const previousMessage = messages[visibleCount - 4];
                    const gap = Number.parseFloat(getComputedStyle(track).rowGap) || 0;
                    scrollOffset += previousMessage.getBoundingClientRect().height + gap;
                    track.style.transform = `translateY(-${scrollOffset}px)`;
                }

                window.setTimeout(revealNext, 2000);
            };

            if (reducedMotion) {
                messages.slice(0, 3).forEach(message => message.classList.add('is-visible'));
                return;
            }

            revealNext();
        })();
    </script>
    <script>
        (() => {
            const root = document.querySelector('[data-availability]');
            if (!root) return;

            const grid = root.querySelector('[data-weekgrid]');
            const status = root.querySelector('[data-week-status]');
            let week = root.dataset.week;
            const base = root.dataset.bookUrl;
            const previous = root.querySelector('[data-prev]');
            const next = root.querySelector('[data-next]');
            const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' });
            const emptyMessage = @json(__('public.availability_empty'));
            const lowMessage = @json(__('public.availability_low'));
            const countMessage = @json(__('public.availability_week_count'));
            const noTimesLabel = @json(__('public.slot_none'));
            const loadError = @json(__('public.availability_error'));
            const monday = date => {
                const value = new Date(date + 'T12:00:00');
                value.setDate(value.getDate() - ((value.getDay() + 6) % 7));
                return value.toLocaleDateString('en-CA');
            };
            const add = (date, days) => {
                const value = new Date(date + 'T12:00:00');
                value.setDate(value.getDate() + days);
                return value.toLocaleDateString('en-CA');
            };
            const render = days => {
                grid.innerHTML = '';
                const slotCount = days.reduce((total, day) => total + day.slots.length, 0);

                days.forEach(day => {
                    const column = document.createElement('div');
                    column.className = 'min-w-0 rounded-[10px] border border-primary-border bg-surface p-1.5 sm:rounded-[13px] sm:p-3';

                    const heading = document.createElement('div');
                    heading.className = 'mb-2 flex min-h-9 flex-col items-center justify-center gap-0.5 text-[10px] font-bold text-ink sm:mb-3 sm:min-h-0 sm:flex-row sm:justify-between sm:gap-1 sm:text-xs';

                    const label = document.createElement('span');
                    label.textContent = day.label;
                    const number = document.createElement('span');
                    number.textContent = day.day;
                    number.className = 'text-muted';
                    heading.append(label, number);

                    const slots = document.createElement('div');
                    slots.className = 'grid gap-1 sm:gap-1.5';

                    if (!day.slots.length) {
                        const empty = document.createElement('span');
                        empty.className = 'flex min-h-10 items-center justify-center text-[11px] text-muted';
                        empty.textContent = '—';
                        empty.setAttribute('aria-label', noTimesLabel);
                        slots.append(empty);
                    } else {
                        day.slots.forEach(slot => {
                            const button = document.createElement('button');
                            button.type = 'button';
                            button.className = 'min-h-10 rounded-[7px] border-0 bg-primary-soft px-0 py-2 text-[10px] leading-none font-semibold text-primary-strong transition hover:bg-primary hover:text-white focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-focus sm:text-xs';
                            button.textContent = slot.time;
                            button.setAttribute('aria-label', day.date + ' ' + slot.time);
                            button.addEventListener('click', () => {
                                location.href = base + '?type=intro&slot=' + encodeURIComponent(slot.starts_at) + '#booking';
                            });
                            slots.append(button);
                        });
                    }

                    column.append(heading, slots);
                    grid.append(column);
                });

                status.textContent = slotCount === 0
                    ? emptyMessage
                    : slotCount <= 3
                        ? lowMessage
                        : countMessage.replace(':count', String(slotCount));
                previous.disabled = week <= monday(today);
            };
            const load = () => {
                status.textContent = @json(__('public.availability_loading'));
                grid.innerHTML = '<p class="col-span-7 text-xs text-muted">' + @json(__('public.availability_loading')) + '</p>';
                fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), {
                    headers: { Accept: 'application/json' },
                })
                    .then(response => {
                        if (!response.ok) throw new Error('Availability request failed');
                        return response.json();
                    })
                    .then(data => render(data.days))
                    .catch(() => {
                        status.textContent = loadError;
                        grid.innerHTML = '';
                    });
            };

            previous.addEventListener('click', () => {
                if (!previous.disabled) {
                    week = add(week, -7);
                    load();
                }
            });
            next.addEventListener('click', () => {
                week = add(week, 7);
                load();
            });
            load();
        })();
    </script>
</body>

</html>
