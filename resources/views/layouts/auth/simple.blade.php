<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="auth-page antialiased">
        <main class="auth-shell">
            <div class="auth-pattern" aria-hidden="true">
                @for ($row = 0; $row < 12; $row++)
                    <div class="auth-pattern__row" style="--auth-row: {{ $row }};"></div>
                @endfor
            </div>

            <div class="auth-content">
                <section class="auth-card" aria-label="{{ __('Authentication') }}">
                    <a href="{{ route('home') }}" class="auth-brand" wire:navigate aria-label="{{ config('app.name', 'EasyEnglish') }} - {{ __('Home') }}">
                        <img src="{{ asset('images/easyenglish-symbol-blue.svg') }}" alt="" width="54" height="44">
                    </a>

                    <div class="auth-card__content">
                        {{ $slot }}
                    </div>
                </section>
            </div>
        </main>

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
