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
