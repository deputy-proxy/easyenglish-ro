<x-layouts::app.sidebar :title="$title ?? null">
    <flux:main>
        {{ $slot }}
    </flux:main>
    <x-public-site-footer />
</x-layouts::app.sidebar>
