@php($learningActive = in_array($activeView, ['profile', 'progress'], true))
<nav class="flex flex-wrap items-center gap-1 border-b border-line pb-3 dark:border-zinc-800" aria-label="{{ __('public.dashboard_primary_navigation') }}">
    @foreach ([
        'overview' => __('public.dashboard_overview'),
        'sessions' => __('public.dashboard_sessions'),
        'learning' => __('public.dashboard_learning'),
        'purchases' => __('public.dashboard_purchases'),
    ] as $view => $label)
        @php($isActive = $view === 'learning' ? $learningActive : $activeView === $view)
        <a href="{{ route('dashboard', ['view' => $view === 'learning' ? 'profile' : $view]) }}" wire:navigate @if ($isActive) aria-current="page" @endif class="rounded-full px-4 py-2.5 text-sm font-bold transition {{ $isActive ? 'bg-primary text-white' : 'text-muted hover:bg-primary-soft hover:text-primary-strong dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ $label }}</a>
    @endforeach
    <a href="{{ route('dashboard', ['view' => 'account']) }}" wire:navigate @if ($activeView === 'account') aria-current="page" @endif class="rounded-full px-4 py-2.5 text-sm font-bold transition {{ $activeView === 'account' ? 'bg-primary text-white' : 'text-muted hover:bg-primary-soft hover:text-primary-strong dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('public.dashboard_account') }}</a>
</nav>
