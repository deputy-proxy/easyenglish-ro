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

        .footer-locale button {
            color: var(--muted);
        }

        .footer-locale button.active {
            color: var(--green);
        }

        .footer-locale span {
            color: #a0aaa3;
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
            min-height: 460px;
            padding: 24px;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: flex-end;
            isolation: isolate
        }

        .hero-people {
            position: absolute;
            inset: 0;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
            padding: 12px
        }

        .hero-person {
            min-width: 0;
            overflow: hidden;
            border-radius: 20px;
            background: #dce7dc
        }

        .hero-person:first-child { transform: translateY(18px) }
        .hero-person:last-child { transform: translateY(-18px) }

        .hero-person img {
            width: 100%;
            height: 100%;
            min-height: 400px;
            object-fit: cover
        }

        .hero-person:first-child img { object-position: 52% center }
        .hero-person:last-child img { object-position: 48% center }

        .hero-art:before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(180deg, #14251f00 35%, #14251f99 100%);
            z-index: 1;
            pointer-events: none
        }

        .hero-art:after { display: none }

        .art-card {
            position: relative;
            z-index: 2;
            margin: 0 0 0 auto;
            background: #ffffffed;
            border: 1px solid #ffffffb3;
            border-radius: 20px;
            padding: 20px;
            width: min(100%, 310px);
            backdrop-filter: blur(12px);
            box-shadow: 0 18px 45px #14251f26
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

        .motivation {
            display: grid;
            grid-template-columns: .9fr 1.1fr;
            gap: 70px;
            align-items: center
        }

        .motivation-intro h2 {
            font-size: clamp(34px, 4vw, 48px);
            line-height: 1.08;
            letter-spacing: -2px;
            margin: 0 0 16px
        }

        .motivation-list {
            border-top: 1px solid var(--line)
        }

        .motivation-list article {
            display: grid;
            grid-template-columns: 44px 1fr;
            gap: 18px;
            align-items: start;
            padding: 20px 0;
            border-bottom: 1px solid var(--line)
        }

        .motivation-list span {
            color: var(--green);
            font-size: 12px;
            font-weight: 800;
            padding-top: 4px
        }

        .motivation-list p {
            font-size: 18px;
            line-height: 1.45;
            margin: 0;
            font-weight: 600
        }

        .steps {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px
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
                min-height: 380px;
                padding: 16px
            }

            .hero-person img {
                min-height: 340px
            }

            .art-card {
                padding: 18px
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
            .bundle-grid,
            .motivation {
                grid-template-columns: 1fr
            }

            .motivation {
                gap: 28px
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

        /* Figma-inspired, full-bleed conversation hero */
        body { background: #fff; color: #fff; }
        .topbar.wrap { position: absolute; z-index: 10; top: 0; left: 0; right: 0; width: 100%; height: 88px; padding: 0 30px; background: transparent; }
        .brand { width: auto; }
        .brand-logo { display: block; width: 192px; height: auto; }
        .nav .button { background: transparent; color: #fff; border: 1px solid rgba(255,255,255,.9); border-radius: 5px; padding: 10px 13px; font-size: 14px; font-weight: 500; }
        .nav .button:hover { background: rgba(255,255,255,.15); color: #fff; }
        .hero.wrap { position: relative; isolation: isolate; width: 100%; max-width: none; min-height: min(950px, 100svh); margin: 0; padding: 132px 24px 125px; display: flex; flex-direction: column; justify-content: flex-start; align-items: center; gap: 0; overflow: hidden; border-radius: 0 0 20px 20px; background: #3348bd; text-align: center; }
        .hero-backdrop { position: absolute; z-index: -2; inset: 0; overflow: hidden; background: #6d74bd; }
        .hero-backdrop img { position: absolute; top: 10%; height: 100%; width: 66%; object-fit: cover; }
        .hero-backdrop img:first-child { left: 0; object-position: 50% center; -webkit-mask-image: linear-gradient(90deg,#000 55%,transparent 100%); mask-image: linear-gradient(90deg,#000 55%,transparent 100%); }
        .hero-backdrop img:last-child { right: 0; object-position: 50% center; -webkit-mask-image: linear-gradient(270deg,#000 55%,transparent 100%); mask-image: linear-gradient(270deg,#000 55%,transparent 100%); }
        .hero:before { content: ''; position: absolute; z-index: -1; inset: 0; background: linear-gradient(180deg,rgba(77,45,115,.58) 0%,rgba(52,70,175,.58) 52%,rgba(0,47,191,.94) 100%); }
        .hero-content { width: min(100%, 1060px); display: flex; flex-direction: column; align-items: center; }
        .hero .eyebrow { display: none; }
        .hero h1 { max-width: 1120px; color: #fff; font-size: clamp(42px, 4.1vw, 66px); font-weight: 500; line-height: 1.14; letter-spacing: -1.8px; margin: 0; }
        .hero h1 em { display: block; color: #fff; font-weight: 750; }
        .hero-copy { max-width: 820px; margin: 82px 0 84px; color: #fff; font-size: clamp(19px, 2vw, 32px); line-height: 1.23; font-weight: 500; }
        .hero-actions { justify-content: center; }
        .hero .button { background: #fff; color: #001c4c; border-radius: 999px; padding: 17px 35px; font-size: 19px; font-weight: 600; }
        .hero .button:hover { background: #f0f3ff; color: #001c4c; }
        .hero .button.outline, .hero-secondary { display: none; }
        .hero-note { display: none; }
        .hero-art { display: none; }
        .trust.wrap { position: absolute; z-index: 2; left: 50%; bottom: 26px; transform: translateX(-50%); width: min(986px, calc(100% - 48px)); padding: 16px 24px; border: 1px solid rgba(255,255,255,.25); border-radius: 999px; background: rgba(10,30,110,.22); backdrop-filter: blur(18px); color: #fff; }
        .trust div { gap: 10px; font-size: 14px; font-weight: 500; }
        .trust-mark { width: 22px; height: 22px; background: transparent; color: #fff; }
        .section, .bundles, .footer { color: var(--ink); }
        @media(max-width:800px) {
            .topbar.wrap { height: 76px; padding: 0 18px; }
            .brand-logo { width: 160px; }
            .hero.wrap { min-height: 850px; padding: 125px 22px 155px; border-radius: 0 0 16px 16px; }
            .hero h1 { font-size: clamp(38px, 8vw, 56px); letter-spacing: -1.4px; }
            .hero-copy { margin: 48px 0 46px; font-size: clamp(19px, 4.2vw, 25px); }
            .hero-backdrop img { width: 100%; opacity: .72; }
            .hero-backdrop img:first-child { -webkit-mask-image: linear-gradient(180deg,#000 30%,transparent 100%); mask-image: linear-gradient(180deg,#000 30%,transparent 100%); }
            .hero-backdrop img:last-child { display: none; }
            .trust.wrap { bottom: 18px; width: calc(100% - 32px); border-radius: 22px; padding: 14px 16px; display: grid; grid-template-columns: 1fr; gap: 10px; }
            .trust div { justify-content: flex-start; text-align: left; flex-direction: row; font-size: 12px; }
        }
        @media(max-width:420px) {
            .hero.wrap { min-height: 820px; padding-top: 115px; }
            .hero h1 { font-size: 36px; }
            .hero-copy { margin: 40px 0; }
            .hero .button { padding: 15px 20px; font-size: 16px; }
        }

    </style>
</head>

<body>
    <header class="wrap topbar">
        <a class="brand" href="{{ route('home') }}" aria-label="Easy English home">
            <img class="brand-logo" src="{{ asset('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
        </a>
        <nav class="nav" aria-label="{{ __('public.nav_label') }}"><a class="button nav-cta" href="{{ route('login') }}">{{ app()->getLocale() === 'ro' ? 'Autentificare' : 'Log in' }}</a></nav>
    </header>
    <main>
        <section class="hero wrap">
            <div class="hero-backdrop" aria-hidden="true">
                <img src="{{ asset('images/hero-teacher.jpg') }}" alt="" fetchpriority="high">
                <img src="{{ asset('images/hero-learner.jpg') }}" alt="" fetchpriority="high">
            </div>
            <div class="hero-content">
                <h1>{{ __('public.hero_title_a') }} <em>{{ __('public.hero_title_em') }}</em></h1>
                <p class="hero-copy">{{ __('public.hero_text') }}</p>
                <div class="hero-actions"><a class="button" href="#availability">{{ app()->getLocale() === 'ro' ? 'Programează prima ședință complet gratuit' : 'Book your first session completely free' }}</a></div>
            </div>
            <div class="wrap trust">
                <div><span class="trust-mark" aria-hidden="true">◴</span>{{ __('public.trust_minutes') }}</div>
                <div><span class="trust-mark" aria-hidden="true">◉</span>{{ app()->getLocale() === 'ro' ? 'Conversație pe WhatsApp' : 'Conversation on WhatsApp' }}</div>
                <div><span class="trust-mark" aria-hidden="true">↗</span>{{ app()->getLocale() === 'ro' ? 'Progres real și predictibil' : 'Real, measurable progress' }}</div>
            </div>
        </section>
        <section class="section wrap motivation" id="why">
            <div class="motivation-intro">
                <p class="eyebrow">{{ __('public.motivation_eyebrow') }}</p>
                <h2>{{ __('public.motivation_title') }}</h2>
                <p class="section-copy">{{ __('public.motivation_text') }}</p>
            </div>
            <div class="motivation-list">
                <article><span>01</span><p>{{ __('public.motivation_job') }}</p></article>
                <article><span>02</span><p>{{ __('public.motivation_meeting') }}</p></article>
                <article><span>03</span><p>{{ __('public.motivation_travel') }}</p></article>
            </div>
        </section>
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
                <article class="step"><span class="step-no">04</span>
                    <h3>{{ __('public.step_four_title') }}</h3>
                    <p>{{ __('public.step_four_text') }}</p>
                </article>
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
                }}</span>@endif
            <form class="locale footer-locale" method="post" action="{{ route('locale.set') }}">@csrf<button name="locale" value="ro"
                    class="{{ app()->getLocale()==='ro'?'active':'' }}" aria-label="Română">RO</button><span>/</span><button
                    name="locale" value="en" class="{{ app()->getLocale()==='en'?'active':'' }}"
                    aria-label="English">EN</button></form>
        </div>
    </footer>
    <script>
        (() => { const root = document.querySelector('[data-availability]'); if (!root) return; const grid = root.querySelector('[data-weekgrid]'); let week = root.dataset.week; const base = root.dataset.bookUrl; const previous = root.querySelector('[data-prev]'); const next = root.querySelector('[data-next]'); const today = new Date().toLocaleDateString('en-CA', { timeZone: 'Europe/Bucharest' }); const monday = d => { const x = new Date(d + 'T12:00:00'); x.setDate(x.getDate() - ((x.getDay() + 6) % 7)); return x.toLocaleDateString('en-CA') }; const add = (date, days) => { const x = new Date(date + 'T12:00:00'); x.setDate(x.getDate() + days); return x.toLocaleDateString('en-CA') }; const render = days => { grid.innerHTML = ''; days.forEach(day => { const col = document.createElement('div'); col.className = 'day'; const head = document.createElement('div'); head.className = 'day-head'; const label = document.createElement('span'); label.textContent = day.label; const number = document.createElement('span'); number.textContent = day.day; head.append(label, number); const slots = document.createElement('div'); slots.className = 'slots'; if (!day.slots.length) { const empty = document.createElement('span'); empty.className = 'slot-empty'; empty.textContent = @json(__('public.slot_none')); slots.append(empty) } else day.slots.forEach(slot => { const button = document.createElement('button'); button.type = 'button'; button.className = 'slot'; button.textContent = slot.time; button.setAttribute('aria-label', day.date + ' ' + slot.time); button.addEventListener('click', () => { location.href = base + '?type=intro&slot=' + encodeURIComponent(slot.starts_at) + '#booking' }); slots.append(button) }); col.append(head, slots); grid.append(col) }); previous.disabled = week <= monday(today); previous.style.opacity = previous.disabled ? '.35' : '1' }; const load = () => { grid.innerHTML = '<p>' + @json(__('public.availability_loading')) + '</p>'; fetch('{{ route('availability') }}?week=' + encodeURIComponent(week), { headers: { Accept: 'application/json' } }).then(r => r.json()).then(data => render(data.days)).catch(() => { grid.textContent = @json(__('public.availability_error')) }) }; previous.addEventListener('click', () => { if (!previous.disabled) { week = add(week, -7); load() } }); next.addEventListener('click', () => { week = add(week, 7); load() }); load(); })();
    </script>
</body>

</html>
