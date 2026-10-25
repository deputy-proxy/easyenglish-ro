<!doctype html>
<html lang="{{ app()->getLocale() }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="{{ __('public.hero_text') }}">
    <title>Easy English · {{ __('public.hero_title') }}</title>
    @vite(['resources/css/app.css'])
    <style>
        :root {
            --ink: #14251f;
            --muted: #64736c;
            --green: #176b4d;
            --lime: #d9f36a;
            --paper: #f7f8f3;
            --line: #e1e7df
        }

        * {
            box-sizing: border-box
        }

        html {
            scroll-behavior: smooth
        }

        body {
            margin: 0;
            background: var(--paper);
            color: var(--ink);
            font-family: 'Instrument Sans', sans-serif
        }

        a {
            color: inherit;
            text-decoration: none
        }

        button,
        input,
        select,
        textarea {
            font: inherit
        }

        .wrap {
            width: min(1160px, calc(100% - 40px));
            margin: auto
        }

        .topbar {
            width: 100%;
            max-width: none;
            margin: 0;
            height: 100px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 max(20px, calc((100% - 1160px)/2));
            background: var(--green);
            color: white
        }

        .brand {
            display: flex;
            align-items: center;
            flex-shrink: 0
        }

        .brand-logo {
            display: block;
            width: 104px;
            height: 104px;
            object-fit: contain
        }

        .nav {
            display: flex;
            gap: 30px;
            align-items: center;
            font-size: 14px;
            color: white
        }

        .nav a:hover {
            color: var(--lime)
        }

        .locale {
            display: flex;
            gap: 4px;
            align-items: center
        }

        .locale button {
            border: 0;
            background: transparent;
            color: #ffffffb8;
            padding: 7px;
            cursor: pointer
        }

        .locale button.active {
            color: white;
            font-weight: 700
        }

        .locale span {
            color: #ffffff80
        }

        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: 0;
            border-radius: 999px;
            background: var(--green);
            color: white;
            font-weight: 700;
            padding: 14px 21px;
            cursor: pointer;
            transition: transform .15s, background .15s
        }

        .button:hover {
            background: #0d573c;
            transform: translateY(-1px)
        }

        .button.light {
            background: var(--lime);
            color: var(--ink)
        }

        .nav .button {
            background: var(--lime);
            color: var(--ink)
        }

        .nav .button:hover {
            background: white;
            color: var(--ink)
        }

        .button.outline {
            background: transparent;
            color: var(--green);
            border: 1px solid #b7cbbf
        }

        .hero {
            padding: 72px 0 58px;
            display: grid;
            grid-template-columns: 1.05fr .95fr;
            gap: 64px;
            align-items: center
        }

        .eyebrow {
            font-size: 11px;
            font-weight: 800;
            letter-spacing: 1.7px;
            text-transform: uppercase;
            color: var(--green);
            margin: 0 0 18px
        }

        .hero h1 {
            font-family: 'Instrument Sans', sans-serif;
            font-size: clamp(42px, 5.4vw, 68px);
            line-height: 1.04;
            letter-spacing: -3.4px;
            margin: 0;
            max-width: 650px
        }

        .hero h1 em {
            font-style: normal;
            color: var(--green)
        }

        .hero-copy {
            font-size: 18px;
            line-height: 1.65;
            color: var(--muted);
            max-width: 520px;
            margin: 24px 0 28px
        }

        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap
        }

        .hero-note {
            font-size: 12px;
            color: var(--muted);
            margin-top: 17px
        }

        .hero-art {
            background: #e9f0e5;
            border-radius: 28px;
            min-height: 380px;
            padding: 28px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-end
        }

        .hero-art:before {
            content: '';
            position: absolute;
            width: 340px;
            height: 340px;
            border: 1px solid #b6c9b7;
            border-radius: 50%;
            top: -95px;
            right: -65px
        }

        .hero-art:after {
            content: '';
            position: absolute;
            width: 240px;
            height: 240px;
            border: 1px solid #b6c9b7;
            border-radius: 50%;
            top: -45px;
            right: -15px
        }

        .art-card {
            position: relative;
            z-index: 1;
            background: #fff;
            border-radius: 20px;
            padding: 25px;
            width: min(100%, 330px);
            box-shadow: 0 18px 45px #173e2710
        }

        .art-kicker {
            font-size: 12px;
            color: var(--green);
            font-weight: 700
        }

        .art-card h3 {
            font-family: 'Instrument Sans', sans-serif;
            font-size: 27px;
            letter-spacing: -1px;
            margin: 12px 0 8px
        }

        .art-card p {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.6;
            margin: 0 0 18px
        }

        .art-time {
            background: var(--paper);
            padding: 11px 13px;
            border-radius: 11px;
            font-size: 13px;
            display: flex;
            justify-content: space-between
        }

        .trust {
            border-top: 1px solid var(--line);
            border-bottom: 1px solid var(--line);
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            padding: 21px 0
        }

        .trust div {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            font-size: 13px;
            font-weight: 600
        }

        .trust-mark {
            display: grid;
            place-items: center;
            width: 25px;
            height: 25px;
            border-radius: 50%;
            background: #e1ebdd;
            color: var(--green);
            font-weight: 800
        }

        .section {
            padding: 82px 0
        }

        .section-head {
            max-width: 650px;
            margin-bottom: 32px
        }

        .section h2 {
            font-family: 'Instrument Sans', sans-serif;
            font-size: clamp(30px, 3.5vw, 43px);
            letter-spacing: -1.8px;
            line-height: 1.13;
            margin: 0 0 14px
        }

        .section-copy {
            color: var(--muted);
            line-height: 1.7;
            max-width: 620px
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px
        }

        .step,
        .bundle {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 20px;
            padding: 25px
        }

        .step-no {
            font-size: 12px;
            font-weight: 800;
            color: var(--green);
            background: #e9f1e5;
            border-radius: 9px;
            padding: 9px 11px;
            display: inline-block
        }

        .step h3 {
            font-family: 'Instrument Sans', sans-serif;
            font-size: 20px;
            margin: 22px 0 8px
        }

        .step p,
        .bundle p {
            font-size: 14px;
            color: var(--muted);
            line-height: 1.65;
            margin: 0
        }

        .bundles {
            background: #eef2e9
        }

        .bundle-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px
        }

        .bundle {
            position: relative;
            display: flex;
            flex-direction: column;
            align-items: flex-start
        }

        .bundle.featured {
            border: 2px solid var(--green);
            padding: 24px
        }

        .popular {
            font-size: 10px;
            letter-spacing: 1px;
            font-weight: 800;
            background: var(--lime);
            border-radius: 999px;
            padding: 7px 10px;
            margin-bottom: 12px
        }

        .bundle-name {
            font-size: 13px;
            color: var(--muted);
            font-weight: 700
        }

        .price {
            font-family: 'Instrument Sans', sans-serif;
            font-size: 42px;
            letter-spacing: -1.8px;
            font-weight: 800;
            margin: 10px 0 4px
        }

        .price small {
            font-family: 'Instrument Sans', sans-serif;
            font-size: 14px;
            letter-spacing: 0;
            font-weight: 600
        }

        .bundle-total {
            font-weight: 700;
            color: var(--ink) !important;
            margin: 10px 0 18px !important
        }

        .bundle-details {
            display: grid;
            gap: 9px;
            margin: 19px 0 24px;
            font-size: 13px;
            color: #4d5c54
        }

        .bundle-details span:before {
            content: '✓';
            color: var(--green);
            font-weight: 800;
            margin-right: 8px
        }

        .bundle .button {
            margin-top: auto;
            width: 100%
        }

        .availability {
            background: #fff;
            border: 1px solid var(--line);
            border-radius: 22px;
            padding: 24px
        }

        .weekbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 20px
        }

        .weekbar h3 {
            margin: 0;
            font-family: 'Instrument Sans', sans-serif;
            font-size: 19px
        }

        .weeknav {
            display: flex;
            gap: 8px
        }

        .weeknav button {
            width: 38px;
            height: 38px;
            border: 1px solid var(--line);
            border-radius: 50%;
            background: white;
            cursor: pointer
        }

        .weekgrid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
            gap: 8px
        }

        .day {
            border: 1px solid var(--line);
            border-radius: 13px;
            padding: 12px 9px;
            min-width: 0
        }

        .day-head {
            display: flex;
            justify-content: space-between;
            gap: 4px;
            font-size: 12px;
            font-weight: 700;
            margin-bottom: 12px
        }

        .slots {
            display: grid;
            gap: 6px
        }

        .slot {
            font-size: 12px;
            padding: 8px 2px;
            border-radius: 7px;
            background: #edf4e9;
            color: var(--green);
            border: 0;
            cursor: pointer
        }

        .slot:hover {
            background: var(--lime);
            color: var(--ink)
        }

        .slot-empty {
            font-size: 11px;
            color: #9aa49d;
            padding: 7px 0
        }

        .timezone {
            font-size: 12px;
            color: var(--muted);
            margin-top: 14px
        }

        .faq {
            display: grid;
            grid-template-columns: .7fr 1.3fr;
            gap: 55px
        }

        .faq-list details {
            padding: 18px 0;
            border-bottom: 1px solid var(--line)
        }

        .faq-list summary {
            font-weight: 700;
            cursor: pointer;
            list-style: none;
            display: flex;
            justify-content: space-between;
            gap: 16px
        }

        .faq-list summary:after {
            content: '+';
            font-size: 20px;
            color: var(--green)
        }

        .faq-list details[open] summary:after {
            content: '−'
        }

        .faq-list p {
            color: var(--muted);
            line-height: 1.7;
            font-size: 14px
        }

        .closing {
            background: var(--ink);
            color: white;
            border-radius: 24px;
            padding: 46px;
            display: flex;
            justify-content: space-between;
            gap: 30px;
            align-items: center
        }

        .closing h2 {
            max-width: 620px;
            margin-bottom: 0
        }

        .footer {
            padding: 35px 0 24px;
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: center;
            color: var(--muted);
            font-size: 12px
        }

        .footer-links {
            display: flex;
            gap: 18px;
            flex-wrap: wrap
        }

        .error {
            color: #b42318;
            font-size: 13px;
            margin-top: 5px
        }

        @media(max-width:800px) {
            .topbar {
                height: 88px;
                padding: 0 20px
            }

            .brand-logo {
                width: 88px;
                height: 88px
            }

            .nav {
                gap: 12px
            }

            .nav a:not(.nav-cta) {
                display: none
            }

            .hero {
                grid-template-columns: 1fr;
                gap: 35px;
                padding: 48px 0
            }

            .hero-art {
                min-height: 300px
            }

            .trust {
                gap: 12px
            }

            .trust div {
                font-size: 11px;
                text-align: center;
                flex-direction: column
            }

            .steps,
            .bundle-grid {
                grid-template-columns: 1fr
            }

            .section {
                padding: 58px 0
            }

            .weekgrid {
                grid-template-columns: repeat(7, minmax(105px, 1fr));
                overflow: visible
            }

            .availability {
                overflow-x: auto;
                padding: 16px
            }

            .day {
                min-height: 150px
            }

            .faq {
                grid-template-columns: 1fr;
                gap: 20px
            }

            .closing {
                padding: 30px;
                align-items: flex-start;
                flex-direction: column
            }

            .footer {
                align-items: flex-start;
                flex-direction: column
            }

            .hero h1 {
                letter-spacing: -2px
            }
        }

        @media(prefers-reduced-motion:reduce) {
            html {
                scroll-behavior: auto
            }

            *,
            *:before,
            *:after {
                transition: none !important
            }
        }
    </style>
</head>

<body>
    <header class="wrap topbar">
        <a class="brand" href="{{ route('home') }}" aria-label="Easy English home">
            <svg width="20%" height="20%" viewBox="0 0 560 512" version="1.1" xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" xml:space="preserve" xmlns:serif="http://www.serif.com/" style="fill-rule:evenodd;clip-rule:evenodd;stroke-linejoin:round;stroke-miterlimit:2;">
                <g transform="matrix(1.152159,0,0,1.152159,-39.3185,-47.74451)">
                    <g transform="matrix(1,0,0,1,0,33.343262)">
                        <g transform="matrix(4.154657,0,0,4.154657,48.046046,-21.433887)">
                            <path d="M80.898,18.398L45.3,18.398C40.3,18.398 36.198,22.5 36.198,27.5L36.198,49.398C36.198,54.398 40.3,58.499 45.3,58.499L64.402,58.499L78.504,71.198C78.703,71.397 79.004,71.499 79.402,71.499C79.602,71.499 79.801,71.397 80.004,71.3C80.504,70.999 80.805,70.499 80.703,69.901L78.805,58.401L81.004,58.401C86.004,58.401 90.106,54.3 90.106,49.3L90.102,27.499C90,22.499 85.899,18.397 80.899,18.397L80.898,18.398ZM87.398,49.398C87.398,53 84.5,55.898 80.898,55.898L76.898,55.898C76.796,55.898 76.796,56 76.699,56C76.597,56 76.597,56 76.5,56.101C76.398,56.101 76.3,56.203 76.3,56.203C76.199,56.304 76.199,56.304 76.101,56.402C76.101,56.503 76,56.503 76,56.601C75.898,56.703 75.898,56.8 75.898,56.902L75.898,57.101C75.898,57.203 75.796,57.203 75.796,57.3L75.796,57.601L77.398,67L65.8,56.199C65.698,56.097 65.601,56.097 65.499,55.999L65.398,55.898C65.198,55.796 65.097,55.796 64.898,55.796L45.3,55.8C41.698,55.8 38.8,52.902 38.8,49.3L38.8,27.499C38.8,23.897 41.698,20.999 45.3,20.999L80.902,20.999C84.503,20.999 87.402,23.897 87.402,27.499L87.398,49.398Z" style="fill:white;fill-rule:nonzero;"/>
                        </g>
                        <g transform="matrix(4.154657,0,0,4.154657,48.046046,-21.433887)">
                            <path d="M18.199,68.602L19.199,68.602L17.301,80.102C17.199,80.602 17.5,81.204 18,81.5C18.199,81.602 18.398,81.7 18.601,81.7C18.902,81.7 19.203,81.598 19.5,81.399L33.602,68.7L49.801,68.7C54.102,68.7 57.602,65.399 58,61.2L45.301,61.2C38.801,61.2 33.602,55.899 33.602,49.501L33.602,32.802L18.2,32.802C13.7,32.802 10.001,36.501 10.001,41.001L10.001,60.399C10.001,64.899 13.7,68.602 18.2,68.602L18.199,68.602Z" style="fill:white;fill-rule:nonzero;"/>
                        </g>
                    </g>
                    <g transform="matrix(1,0,0,1,-130.151379,25.187453)">
                        <text x="167.237px" y="397.947px" style="font-family:'ArialMT', 'Arial', sans-serif;font-size:75px;fill:white;">Easy</text>
                        <g transform="matrix(75,0,0,75,604.822033,397.946824)">
                        </g>
                        <text x="333.972px" y="397.947px" style="font-family:'Arial-BoldMT', 'Arial', sans-serif;font-weight:700;font-size:75px;fill:white;">English</text>
                    </g>
                </g>
            </svg>
        </a>
        <nav class="nav" aria-label="{{ __('public.nav_label') }}"><a href="#how">{{ __('public.nav_how') }}</a><a
                href="#bundles">{{ __('public.nav_bundles') }}</a><a href="#faq">{{ __('public.nav_faq') }}</a><a
                class="button nav-cta" href="#availability">{{ __('public.nav_book') }}</a></nav>
        <form class="locale" method="post" action="{{ route('locale.set') }}">@csrf<button name="locale" value="ro"
                class="{{ app()->getLocale()==='ro'?'active':'' }}" aria-label="Română">RO</button><span>/</span><button
                name="locale" value="en" class="{{ app()->getLocale()==='en'?'active':'' }}"
                aria-label="English">EN</button></form>
    </header>
    <main>
        <section class="wrap hero">
            <div>
                <p class="eyebrow">{{ __('public.hero_eyebrow') }}</p>
                <h1>{{ __('public.hero_title_a') }} <em>{{ __('public.hero_title_em') }}</em></h1>
                <p class="hero-copy">{{ __('public.hero_text') }}</p>
                <div class="hero-actions"><a class="button" href="#availability">{{ __('public.hero_cta') }}</a><a
                        class="button outline" href="#bundles">{{ __('public.hero_secondary') }}</a></div>
                <p class="hero-note">{{ __('public.hero_note') }}</p>
            </div>
            <div class="hero-art">
                <div class="art-card"><span class="art-kicker">{{ __('public.hero_card_label') }}</span>
                    <h3>{{ __('public.hero_card_title') }}</h3>
                    <p>{{ __('public.hero_card_text') }}</p>
                    <div class="art-time"><span>{{ __('public.art_duration') }}</span><strong>15 min</strong></div>
                </div>
            </div>
        </section>
        <div class="wrap trust">
            <div><span class="trust-mark">✓</span>{{ __('public.trust_minutes') }}</div>
            <div><span class="trust-mark">✓</span>{{ __('public.trust_account') }}</div>
            <div><span class="trust-mark">✓</span>{{ __('public.trust_online') }}</div>
        </div>
        <section class="section wrap" id="how">
            <div class="section-head">
                <p class="eyebrow">{{ __('public.how_eyebrow') }}</p>
                <h2>{{ __('public.how_title') }}</h2>
                <p class="section-copy">{{ __('public.how_text') }}</p>
            </div>
            <div class="steps">
                <article class="step"><span class="step-no">01</span>
                    <h3>{{ __('public.step_one_title') }}</h3>
                    <p>{{ __('public.step_one_text') }}</p>
                </article>
                <article class="step"><span class="step-no">02</span>
                    <h3>{{ __('public.step_two_title') }}</h3>
                    <p>{{ __('public.step_two_text') }}</p>
                </article>
                <article class="step"><span class="step-no">03</span>
                    <h3>{{ __('public.step_three_title') }}</h3>
                    <p>{{ __('public.step_three_text') }}</p>
                </article>
            </div>
        </section>
        <section class="section bundles" id="bundles">
            <div class="wrap">
                <div class="section-head">
                    <p class="eyebrow">{{ __('public.packages_eyebrow') }}</p>
                    <h2>{{ __('public.packages_title') }}</h2>
                    <p class="section-copy">{{ __('public.packages_text') }}</p>
                </div>
                <p class="section-copy" style="margin:0 0 24px">{{ __('public.shared_free_notice') }}</p>
                <div class="bundle-grid">@foreach($bundles as $bundle)<article
                        class="bundle {{ $bundle->price_ron===390?'featured':'' }}">@if($bundle->price_ron===390)<span
                            class="popular">{{ __('public.package_popular') }}</span>@endif<span class="bundle-name">{{
                            __('public.bundle_label') }} {{ $loop->iteration }}</span>
                        <div class="price">{{ number_format($bundle->price_ron, 0, ',', '.') }} <small>RON</small></div>
                        <p>{{ trans_choice('public.package_free_paid', $bundle->paid_credits, ['count' =>
                            $bundle->paid_credits]) }}</p>
                        <p class="bundle-total">{{ trans_choice('public.package_total', $bundle->paid_credits+1,
                            ['count' => $bundle->paid_credits+1]) }}</p>
                        <div class="bundle-details"><span>{{ __('public.package_duration') }}</span><span>{{
                                __('public.package_validity') }}</span><span>{{ __('public.package_tax') }}</span></div>
                        <a class="button {{ $bundle->price_ron===390?'light':'' }}"
                            href="{{ route('purchase', $bundle) }}">{{ __('public.package_cta') }}</a>
                    </article>@endforeach</div>
            </div>
        </section>
        <section class="section wrap" id="availability">
            <div class="section-head">
                <p class="eyebrow">{{ __('public.availability_eyebrow') }}</p>
                <h2>{{ __('public.availability_title') }}</h2>
                <p class="section-copy">{{ __('public.availability_text') }}</p>
            </div>
            <div class="availability" data-availability data-week="{{ $weekStart->format('Y-m-d') }}"
                data-book-url="{{ route('booking.create') }}">
                <div class="weekbar">
                    <h3>{{ __('public.booking_week_title') }}</h3>
                    <div class="weeknav"><button type="button" data-prev
                            aria-label="{{ __('public.previous_week') }}">‹</button><button type="button" data-next
                            aria-label="{{ __('public.next_week') }}">›</button></div>
                </div>
                <div class="weekgrid" data-weekgrid aria-live="polite">
                    <p>{{ __('public.availability_loading') }}</p>
                </div>
                <p class="timezone">{{ __('public.timezone_note') }}</p>
            </div>
        </section>
        <section class="section wrap" id="faq">
            <div class="faq">
                <div>
                    <p class="eyebrow">{{ __('public.faq_eyebrow') }}</p>
                    <h2>{{ __('public.faq_title') }}</h2>
                </div>
                <div class="faq-list">
                    <details>
                        <summary>{{ __('public.faq_q1') }}</summary>
                        <p>{{ __('public.faq_a1') }}</p>
                    </details>
                    <details>
                        <summary>{{ __('public.faq_q2') }}</summary>
                        <p>{{ __('public.faq_a2') }}</p>
                    </details>
                    <details>
                        <summary>{{ __('public.faq_q3') }}</summary>
                        <p>{{ __('public.faq_a3') }}</p>
                    </details>
                    <details>
                        <summary>{{ __('public.faq_q4') }}</summary>
                        <p>{{ __('public.faq_a4') }}</p>
                    </details>
                </div>
            </div>
        </section>
        <section class="wrap">
            <div class="closing">
                <h2>{{ __('public.closing_title') }}</h2><a class="button light" href="#availability">{{
                    __('public.closing_cta') }}</a>
            </div>
        </section>
    </main>
    <footer class="wrap footer"><span>{{ __('public.footer_line') }}</span>
        <div class="footer-links"><a href="{{ route('policy','privacy') }}">{{ __('public.footer_privacy') }}</a><a
                href="{{ route('policy','terms') }}">{{ __('public.footer_terms')
                }}</a>@if(config('easyenglish.contact_email'))<a
                href="mailto:{{ config('easyenglish.contact_email') }}">{{ __('public.footer_contact')
                }}</a>@else<span>{{ __('public.footer_contact') }} · {{ __('public.footer_contact_pending')
                }}</span>@endif</div>
    </footer>
    <script>
        (() => { const root = document.querySelector('[data-availability]'); if (!root) return; const grid = root.querySelector('[data-weekgrid]'); let week = root.dataset.week; const base = root.dataset.bookUrl; const previous = root.querySelector('[data-prev]'); const next = root.querySelector('[data-next]'); const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' }); const monday = d => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toLocaleDateString('en-CA') }; const add = (date, days) => { const x = new Date(date + 'T12:00:00'); x.setDate(x.getDate() + days); return x.toLocaleDateString('en-CA') }; const render = days => { grid.innerHTML = ''; days.forEach(day => { const col = document.createElement('div'); col.className = 'day'; const head = document.createElement('div'); head.className = 'day-head'; const label = document.createElement('span'); label.textContent = day.label; const number = document.createElement('span'); number.textContent = day.day; head.append(label, number); const slots = document.createElement('div'); slots.className = 'slots'; if (!day.slots.length) { const empty = document.createElement('span'); empty.className = 'slot-empty'; empty.textContent = @json(__('public.slot_none')); slots.append(empty) } else day.slots.forEach(slot => { const button = document.createElement('button'); button.type = 'button'; button.className = 'slot'; button.textContent = slot.time; button.setAttribute('aria-label', day.date + ' ' + slot.time); button.addEventListener('click', () => { location.href = base + '?type=intro&slot=' + encodeURIComponent(slot.starts_at) + '#booking' }); slots.append(button) }); col.append(head, slots); grid.append(col) }); previous.disabled = week <= monday(today); previous.style.opacity = previous.disabled ? '.35' : '1' }; const load = () => { grid.innerHTML = '<p>' + @json(__('public.availability_loading')) + '</p>'; fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(data => render(data.days)).catch(() => { grid.textContent = @json(__('public.availability_error')) }) }; previous.addEventListener('click', () => { if (!previous.disabled) { week = add(week, -7); load() } }); next.addEventListener('click', () => { week = add(week, 7); load() }); load(); })();
    </script>
</body>

</html>
