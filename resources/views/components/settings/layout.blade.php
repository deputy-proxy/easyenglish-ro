<div class="grid gap-6 lg:grid-cols-[220px_minmax(0,1fr)] lg:items-start">
    <nav class="dashboard-panel flex flex-wrap gap-2 p-3 lg:flex-col" aria-label="{{ __('Settings') }}">
        <a href="{{ route('profile.edit') }}" wire:navigate @if (request()->routeIs('profile.edit')) aria-current="page" @endif class="rounded-full px-4 py-2.5 text-sm font-semibold transition {{ request()->routeIs('profile.edit') ? 'bg-primary text-white' : 'text-muted hover:bg-primary-soft hover:text-primary-strong dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('Profile') }}</a>
        <a href="{{ route('security.edit') }}" wire:navigate @if (request()->routeIs('security.edit')) aria-current="page" @endif class="rounded-full px-4 py-2.5 text-sm font-semibold transition {{ request()->routeIs('security.edit') ? 'bg-primary text-white' : 'text-muted hover:bg-primary-soft hover:text-primary-strong dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('Security') }}</a>
        <a href="{{ route('appearance.edit') }}" wire:navigate @if (request()->routeIs('appearance.edit')) aria-current="page" @endif class="rounded-full px-4 py-2.5 text-sm font-semibold transition {{ request()->routeIs('appearance.edit') ? 'bg-primary text-white' : 'text-muted hover:bg-primary-soft hover:text-primary-strong dark:text-zinc-300 dark:hover:bg-zinc-800' }}">{{ __('Appearance') }}</a>
    </nav>
    <section class="dashboard-panel min-w-0 p-5 sm:p-7">
        <div class="mb-6 border-b border-line pb-5 dark:border-zinc-700">
            <h2 class="text-xl font-semibold tracking-tight text-ink dark:text-white">{{ $heading ?? '' }}</h2>
            @if (filled($subheading ?? null))
                <p class="mt-2 text-sm leading-6 text-muted dark:text-zinc-400">{{ $subheading }}</p>
            @endif
        </div>
        <div class="w-full max-w-2xl">
            {{ $slot }}
        </div>
    </section>
</div>
