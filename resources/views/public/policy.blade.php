<!doctype html>
<html lang="{{ app()->getLocale() }}">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="{{ $page === 'privacy' ? __('public.privacy_intro') : __('public.terms_intro') }}">
<title>{{ $page === 'privacy' ? __('public.privacy_title') : __('public.terms_title') }} · EasyEnglish</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper font-sans text-ink antialiased"><div class="min-h-screen">
<x-public-site-header />
<main class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-12 sm:py-16 md:py-[82px]">
<article class="mx-auto max-w-[820px]">
<a href="{{ route('home') }}" class="mb-8 inline-flex items-center gap-2 text-sm font-bold text-primary">{{ __('public.back_home') }}</a>
<header class="mb-10 border-b border-line pb-8">
<p class="mb-[18px] type-eyebrow">{{ $page === 'privacy' ? 'CONFIDENȚIALITATE' : 'TERMENI ȘI CONDIȚII' }}</p>
<h1 class="type-section-title mb-5">{{ $page === 'privacy' ? __('public.privacy_title') : __('public.terms_title') }}</h1>
<p class="type-section-copy">{{ $page === 'privacy' ? __('public.privacy_intro') : __('public.terms_intro') }}</p>
<p class="mt-4 text-xs text-muted">{{ __('public.policy_updated') }}</p>
</header>
@foreach ($page === 'privacy' ? __('public.privacy_sections') : __('public.terms_sections') as $section)
<section class="border-b border-line py-7 first:pt-0 md:py-9">
<h2 class="type-card-title mb-3">{{ $section['title'] }}</h2>
<p class="type-section-copy max-w-[74ch]">{{ $section['body'] }}</p>
</section>
@endforeach
<section class="mt-8 rounded-2xl border border-line bg-white p-6 md:p-8">
<h2 class="type-card-title mb-3">{{ config('easyenglish.company.name') }}</h2>
<p class="type-section-copy">CUI: {{ config('easyenglish.company.cui') }}<br>Registrul Comerțului: {{ config('easyenglish.company.trade_register') }}<br>{{ config('easyenglish.company.address') }}</p>
<p class="mt-3 text-sm"><a class="font-semibold text-primary underline" href="mailto:{{ config('easyenglish.contact_email') }}">{{ config('easyenglish.contact_email') }}</a></p>
</section>
</article></main><x-public-site-footer /></div></body></html>
