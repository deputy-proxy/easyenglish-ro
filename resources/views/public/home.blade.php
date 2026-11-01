<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('public.hero_text') }}">
    <title>Easy English · {{ __('public.hero_title') }}</title>
    @vite(['resources/css/app.css'])

</head>

<body class="bg-paper font-sans text-ink antialiased">
    <header class="absolute inset-x-0 top-0 z-20 flex h-[76px] items-center justify-between gap-3 px-4 sm:px-6 md:h-[88px] md:px-[clamp(22px,5.5vw,96px)]">
        <a class="shrink-0" href="{{ route('home') }}" aria-label="Easy English home">
            <img class="block w-[138px] sm:w-[165px] md:w-[218px] [filter:brightness(0)_saturate(1)_invert(12%)_sepia(28%)_saturate(2554%)_hue-rotate(195deg)_brightness(91%)_contrast(96%)]" src="{{ asset('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
        </a>
        <nav aria-label="{{ __('public.nav_label') }}" class="flex shrink-0 items-center">
            <a class="inline-flex min-h-10 max-w-[142px] items-center justify-center rounded-full bg-[#1450df] px-4 py-2.5 text-center text-xs leading-tight font-bold !text-white transition-colors hover:bg-[#0d3db7] sm:max-w-none sm:text-sm md:px-7 md:py-[15px] md:text-[15px]" href="#availability">
                {{ app()->getLocale() === 'ro' ? 'Sesiune gratuită' : 'Free session' }}
            </a>
        </nav>
    </header>
    <main>
        <section class="relative isolate flex w-full flex-col items-center overflow-hidden bg-white px-5 pb-8 pt-[112px] text-center text-[#14234b] sm:px-7 md:min-h-[min(900px,100svh)] md:justify-start md:px-6 md:pb-[150px] md:pt-[176px]">
            <div class="pointer-events-none absolute inset-0 hidden overflow-hidden bg-white md:block" aria-hidden="true">
                <img class="absolute bottom-0 left-0 top-auto w-auto max-h-full max-w-[75%] object-contain object-left-bottom" src="{{ asset('images/hero-teacher.png') }}" alt="" fetchpriority="high">
                <img class="absolute bottom-0 right-0 top-auto w-auto max-h-full max-w-[75%] object-contain object-right-bottom" src="{{ asset('images/hero-student.png') }}" alt="" fetchpriority="high">
            </div>

            <div class="hero-conversation pointer-events-none absolute inset-0 z-10 hidden xl:block" aria-hidden="true">
                <div class="hero-conversation__side hero-conversation__side--teacher">
                    <p class="hero-conversation__bubble" style="--conversation-delay: 0s">Hi! How was your weekend?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 8s">Nice! Where did you go?</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 16s">Sounds lovely. Tell me more!</p>
                </div>
                <div class="hero-conversation__side hero-conversation__side--learner">
                    <p class="hero-conversation__bubble" style="--conversation-delay: 4s">It was great. I went hiking!</p>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 12s">To the mountains. It was beautiful!</p>
                    <div class="hero-conversation__bubble hero-conversation__typing" style="--conversation-delay: 18s"><span></span><span></span><span></span></div>
                    <p class="hero-conversation__bubble" style="--conversation-delay: 20s">I'd love to!</p>
                </div>
            </div>

            <div class="relative z-10 flex w-full max-w-[900px] flex-col items-center">
                <h1 class="m-0 max-w-[900px] text-[clamp(2.15rem,8vw,4.75rem)] leading-[1.06] font-extrabold tracking-[-0.045em] md:text-[clamp(44px,5.2vw,76px)]">
                    <span class="block md:whitespace-nowrap md:text-[clamp(34px,3.7vw,56px)]">{{ __('public.hero_title_a') }}</span>
                    <span class="block font-extrabold">{{ __('public.hero_title_em') }}</span>
                </h1>
                <p class="my-6 max-w-[38ch] text-[17px] leading-[1.5] font-medium text-[#53617b] sm:text-[19px] md:my-[34px] md:mb-[42px] md:text-[clamp(19px,1.8vw,26px)]">
                    {{ __('public.hero_text') }}
                </p>
                <a class="inline-flex min-h-[54px] w-full max-w-[360px] items-center justify-center rounded-full bg-[#1450df] px-5 py-4 text-base leading-tight font-bold !text-white transition-colors hover:bg-[#0d3db7] sm:text-lg md:min-h-0 md:w-auto md:max-w-none md:px-[34px] md:py-[18px] md:text-[19px]" href="#availability">
                    {{ app()->getLocale() === 'ro' ? 'Programează prima sesiune gratuită' : 'Book your first free session' }}
                </a>
            </div>

            <div class="relative z-10 mt-7 grid w-full max-w-[480px] grid-cols-2 gap-3 md:hidden" aria-label="{{ app()->getLocale() === 'ro' ? 'Profesor și cursant' : 'Teacher and learner' }}">
                <img class="h-[220px] w-full rounded-2xl object-cover object-left" src="{{ asset('images/hero-teacher.png') }}" alt="{{ app()->getLocale() === 'ro' ? 'Profesor de engleză' : 'English teacher' }}" fetchpriority="high">
                <img class="h-[220px] w-full rounded-2xl object-cover object-right" src="{{ asset('images/hero-student.png') }}" alt="{{ app()->getLocale() === 'ro' ? 'Cursant' : 'Learner' }}" fetchpriority="high">
            </div>

            <div class="relative z-10 mt-6 flex w-full max-w-[850px] flex-wrap items-center justify-center gap-x-5 gap-y-2 text-xs font-semibold text-[#14234b] md:absolute md:bottom-[26px] md:left-1/2 md:mt-0 md:w-[min(850px,calc(100%-48px))] md:-translate-x-1/2 md:gap-x-8 md:px-6 md:py-4 md:text-sm">
                <div class="flex items-center gap-2"><span aria-hidden="true">◴</span>{{ __('public.trust_minutes') }}</div>
                <div class="flex items-center gap-2"><span aria-hidden="true">◉</span>{{ app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp' }}</div>
                <div class="flex items-center gap-2"><span aria-hidden="true">↗</span>{{ app()->getLocale() === 'ro' ? 'Progres real și predictibil' : 'Real, measurable progress' }}</div>
            </div>
        </section>
        <section class="mx-auto grid w-[calc(100%-2.5rem)] max-w-[1160px] gap-7 py-14 md:grid-cols-[0.9fr_1.1fr] md:items-center md:gap-[70px] md:py-[82px]" id="why">
            <div>
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-green uppercase">{{ __('public.motivation_eyebrow') }}</p>
                <h2 class="mb-4 text-[clamp(34px,4vw,48px)] leading-[1.08] tracking-[-2px]">{{ __('public.motivation_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.motivation_text') }}</p>
            </div>
            <div class="border-t border-line">
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-green">01</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_job') }}</p></article>
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-green">02</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_meeting') }}</p></article>
                <article class="grid grid-cols-[44px_1fr] items-start gap-[18px] border-b border-line py-5"><span class="pt-1 text-xs font-extrabold text-green">03</span><p class="m-0 text-lg leading-[1.45] font-semibold">{{ __('public.motivation_travel') }}</p></article>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="how">
            <div class="mb-8 max-w-[650px]">
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-green uppercase">{{ __('public.how_eyebrow') }}</p>
                <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.how_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.how_text') }}</p>
            </div>
            <div class="grid gap-3.5 sm:grid-cols-2 xl:grid-cols-4">
                <article class="rounded-[20px] border border-line bg-white p-[25px]"><span class="inline-block rounded-[9px] bg-[#e9f1e5] px-3 py-2.5 text-xs font-extrabold text-green">01</span>
                    <h3 class="mt-[22px] mb-2 text-xl">{{ __('public.step_one_title') }}</h3>
                    <p class="m-0 text-sm leading-[1.65] text-muted">{{ __('public.step_one_text') }}</p>
                </article>
                <article class="rounded-[20px] border border-line bg-white p-[25px]"><span class="inline-block rounded-[9px] bg-[#e9f1e5] px-3 py-2.5 text-xs font-extrabold text-green">02</span>
                    <h3 class="mt-[22px] mb-2 text-xl">{{ __('public.step_two_title') }}</h3>
                    <p class="m-0 text-sm leading-[1.65] text-muted">{{ __('public.step_two_text') }}</p>
                </article>
                <article class="rounded-[20px] border border-line bg-white p-[25px]"><span class="inline-block rounded-[9px] bg-[#e9f1e5] px-3 py-2.5 text-xs font-extrabold text-green">03</span>
                    <h3 class="mt-[22px] mb-2 text-xl">{{ __('public.step_three_title') }}</h3>
                    <p class="m-0 text-sm leading-[1.65] text-muted">{{ __('public.step_three_text') }}</p>
                </article>
                <article class="rounded-[20px] border border-line bg-white p-[25px]"><span class="inline-block rounded-[9px] bg-[#e9f1e5] px-3 py-2.5 text-xs font-extrabold text-green">04</span>
                    <h3 class="mt-[22px] mb-2 text-xl">{{ __('public.step_four_title') }}</h3>
                    <p class="m-0 text-sm leading-[1.65] text-muted">{{ __('public.step_four_text') }}</p>
                </article>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="availability">
            <div class="mb-8 max-w-[650px]">
                <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-green uppercase">{{ __('public.availability_eyebrow') }}</p>
                <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.availability_title') }}</h2>
                <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.availability_text') }}</p>
            </div>
            <div class="overflow-hidden rounded-[22px] border border-line bg-white p-4 sm:p-6" data-availability data-week="{{ $weekStart->format('Y-m-d') }}"
                data-book-url="{{ route('booking.create') }}">
                <div class="mb-5 flex items-center justify-between gap-3">
                    <h3 class="m-0 text-[19px]">{{ __('public.booking_week_title') }}</h3>
                    <div class="flex gap-2"><button class="size-[38px] rounded-full border border-line bg-white text-xl hover:bg-paper disabled:opacity-35" type="button" data-prev
                            aria-label="{{ __('public.previous_week') }}">‹</button><button class="size-[38px] rounded-full border border-line bg-white text-xl hover:bg-paper" type="button" data-next
                            aria-label="{{ __('public.next_week') }}">›</button></div>
                </div>
                <div class="overflow-x-auto"><div class="grid min-w-[735px] grid-cols-7 gap-2" data-weekgrid aria-live="polite">
                    <p>{{ __('public.availability_loading') }}</p>
                </div></div>
                <p class="mt-3.5 text-xs text-muted">{{ __('public.timezone_note') }}</p>
            </div>
        </section>
        <section class="bg-[#eef2e9] py-14 md:py-[82px]" id="bundles">
            <div class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
                <div class="mb-8 max-w-[650px]">
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-green uppercase">{{ __('public.packages_eyebrow') }}</p>
                    <h2 class="mb-3.5 text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.packages_title') }}</h2>
                    <p class="max-w-[620px] leading-[1.7] text-muted">{{ __('public.packages_text') }}</p>
                </div>
                <p class="mb-6 leading-[1.7] text-muted">{{ __('public.shared_free_notice') }}</p>
                <div class="grid gap-[18px] md:grid-cols-3">@foreach($bundles as $bundle)<article
                        class="relative flex flex-col items-start rounded-[20px] border bg-white p-[25px] {{ $bundle->price_ron === 390 ? 'border-2 border-green' : 'border-line' }}">@if($bundle->price_ron===390)<span
                            class="mb-3 rounded-full bg-lime px-2.5 py-[7px] text-[10px] font-extrabold tracking-[1px]">{{ __('public.package_popular') }}</span>@endif<span class="text-[13px] font-bold text-muted">{{
                            __('public.bundle_label') }} {{ $loop->iteration }}</span>
                        <div class="my-2.5 mb-1 text-[42px] leading-tight font-extrabold tracking-[-1.8px]">{{ number_format($bundle->price_ron, 0, ',', '.') }} <small class="text-sm font-semibold tracking-normal">RON</small></div>
                        <p class="m-0 text-sm leading-[1.65] text-muted">{{ trans_choice('public.package_free_paid', $bundle->paid_credits, ['count' => $bundle->paid_credits]) }}</p>
                        <p class="mt-2.5 mb-[18px] font-bold text-ink">{{ trans_choice('public.package_total', $bundle->paid_credits+1,
                            ['count' => $bundle->paid_credits+1]) }}</p>
                        <div class="mb-6 mt-[19px] grid gap-[9px] text-[13px] text-[#4d5c54]"><span><span class="mr-2 font-extrabold text-green">✓</span>{{ __('public.package_duration') }}</span><span><span class="mr-2 font-extrabold text-green">✓</span>{{ __('public.package_validity') }}</span><span><span class="mr-2 font-extrabold text-green">✓</span>{{ __('public.package_tax') }}</span></div>
                        <a class="mt-auto inline-flex w-full items-center justify-center rounded-full bg-green px-[21px] py-3.5 font-bold text-white transition hover:-translate-y-px hover:bg-[#0d573c] {{ $bundle->price_ron === 390 ? 'bg-lime text-ink hover:bg-white' : '' }}"
                            href="{{ route('purchase', $bundle) }}">{{ __('public.package_cta') }}</a>
                    </article>@endforeach</div>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-14 md:py-[82px]" id="faq">
            <div class="grid gap-5 md:grid-cols-[0.7fr_1.3fr] md:gap-[55px]">
                <div>
                    <p class="mb-[18px] text-[11px] font-extrabold tracking-[1.7px] text-green uppercase">{{ __('public.faq_eyebrow') }}</p>
                    <h2 class="text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.faq_title') }}</h2>
                </div>
                <div>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-green">{{ __('public.faq_q1') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a1') }}</p>
                    </details>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-green">{{ __('public.faq_q2') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a2') }}</p>
                    </details>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-green">{{ __('public.faq_q3') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a3') }}</p>
                    </details>
                    <details class="border-b border-line py-[18px]">
                        <summary class="cursor-pointer font-bold marker:text-green">{{ __('public.faq_q4') }}</summary>
                        <p class="text-sm leading-[1.7] text-muted">{{ __('public.faq_a4') }}</p>
                    </details>
                </div>
            </div>
        </section>
        <section class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px]">
            <div class="flex flex-col items-start justify-between gap-[30px] rounded-3xl bg-ink p-[30px] text-white md:flex-row md:items-center md:p-[46px]">
                <h2 class="mb-0 max-w-[620px] text-[clamp(30px,3.5vw,43px)] leading-[1.13] tracking-[-1.8px]">{{ __('public.closing_title') }}</h2><a class="inline-flex items-center justify-center rounded-full bg-lime px-[21px] py-3.5 font-bold text-ink transition hover:bg-white" href="#availability">{{
                    __('public.closing_cta') }}</a>
            </div>
        </section>
    </main>
    <footer class="mx-auto flex w-[calc(100%-2.5rem)] max-w-[1160px] flex-col items-start justify-between gap-5 py-9 pb-6 text-xs text-muted md:flex-row md:items-center"><span>{{ __('public.footer_line') }}</span>
        <div class="flex flex-wrap items-center gap-x-[18px] gap-y-3"><a class="hover:text-green" href="{{ route('policy','privacy') }}">{{ __('public.footer_privacy') }}</a><a
                class="hover:text-green" href="{{ route('policy','terms') }}">{{ __('public.footer_terms')
                }}</a>@if(config('easyenglish.contact_email'))<a
                class="hover:text-green" href="mailto:{{ config('easyenglish.contact_email') }}">{{ __('public.footer_contact')
                }}</a>@else<span>{{ __('public.footer_contact') }} · {{ __('public.footer_contact_pending')
                }}</span>@endif
            <form class="flex items-center gap-1" method="post" action="{{ route('locale.set') }}">@csrf<button name="locale" value="ro"
                    class="p-[7px] text-muted hover:text-green {{ app()->getLocale() === 'ro' ? 'font-bold text-green' : '' }}" aria-label="Română">RO</button><span>/</span><button
                    name="locale" value="en" class="p-[7px] text-muted hover:text-green {{ app()->getLocale() === 'en' ? 'font-bold text-green' : '' }}"
                    aria-label="English">EN</button></form>
        </div>
    </footer>
    <script>
        (() => { const root = document.querySelector('[data-availability]'); if (!root) return; const grid = root.querySelector('[data-weekgrid]'); let week = root.dataset.week; const base = root.dataset.bookUrl; const previous = root.querySelector('[data-prev]'); const next = root.querySelector('[data-next]'); const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' }); const monday = d => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toLocaleDateString('en-CA') }; const add = (date, days) => { const x = new Date(date + 'T12:00:00'); x.setDate(x.getDate() + days); return x.toLocaleDateString('en-CA') }; const render = days => { grid.innerHTML = ''; days.forEach(day => { const col = document.createElement('div'); col.className = 'min-w-0 rounded-[13px] border border-line p-3'; const head = document.createElement('div'); head.className = 'mb-3 flex justify-between gap-1 text-xs font-bold'; const label = document.createElement('span'); label.textContent = day.label; const number = document.createElement('span'); number.textContent = day.day; head.append(label, number); const slots = document.createElement('div'); slots.className = 'grid gap-1.5'; if (!day.slots.length) { const empty = document.createElement('span'); empty.className = 'py-[7px] text-[11px] text-[#9aa49d]'; empty.textContent = @json(__('public.slot_none')); slots.append(empty) } else day.slots.forEach(slot => { const button = document.createElement('button'); button.type = 'button'; button.className = 'rounded-[7px] border-0 bg-[#edf4e9] px-0.5 py-2 text-xs text-green cursor-pointer hover:bg-lime hover:text-ink'; button.textContent = slot.time; button.setAttribute('aria-label', day.date + ' ' + slot.time); button.addEventListener('click', () => { location.href = base + '?type=intro&slot=' + encodeURIComponent(slot.starts_at) + '#booking' }); slots.append(button) }); col.append(head, slots); grid.append(col) }); previous.disabled = week <= monday(today);
            }; const load = () => { grid.innerHTML = '<p class="col-span-7">' + @json(__('public.availability_loading')) + '</p>'; fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(data => render(data.days)).catch(() => { grid.textContent = @json(__('public.availability_error')) }) }; previous.addEventListener('click', () => { if (!previous.disabled) { week = add(week, -7); load() } }); next.addEventListener('click', () => { week = add(week, 7); load() }); load(); })();
    </script>
</body>

</html>
