<header class="dashboard-topbar border-b border-line bg-white/95 backdrop-blur">
    <div class="mx-auto flex min-h-[76px] max-w-[1240px] flex-wrap items-center justify-between gap-4 px-5 sm:px-7 lg:px-10">
        <a href="{{ route('home') }}" aria-label="EasyEnglish home" class="shrink-0">
            <img class="dashboard-brand-logo block w-[132px]" src="{{ \App\Support\VersionedAsset::url('images/logo-white.svg') }}" alt="EasyEnglish" width="192" height="40">
        </a>
        <div class="flex flex-wrap items-center justify-end gap-3 sm:gap-5">
            @if (($context ?? 'customer') === 'teacher' && auth()->user()->is_staff)
                <a href="{{ url('/admin') }}" class="hidden text-sm font-semibold text-muted underline-offset-4 hover:text-ink hover:underline sm:inline">{{ __('public.teacher_open_staff_admin') }}</a>
            @endif
            <form method="POST" action="{{ route('locale.set') }}" class="flex items-center rounded-full border border-line bg-paper p-1" aria-label="{{ __('public.dashboard_account_language_heading') }}">
                @csrf
                <button name="locale" value="ro" aria-pressed="{{ app()->getLocale() === 'ro' ? 'true' : 'false' }}" class="rounded-full px-2.5 py-1.5 text-xs font-bold transition {{ app()->getLocale() === 'ro' ? 'bg-ink text-white' : 'text-muted hover:text-ink' }}">RO</button>
                <button name="locale" value="en" aria-pressed="{{ app()->getLocale() === 'en' ? 'true' : 'false' }}" class="rounded-full px-2.5 py-1.5 text-xs font-bold transition {{ app()->getLocale() === 'en' ? 'bg-ink text-white' : 'text-muted hover:text-ink' }}">EN</button>
            </form>
            <div class="hidden text-right sm:block">
                <p class="max-w-40 truncate text-sm font-semibold text-ink">{{ auth()->user()->name }}</p>
                <p class="max-w-40 truncate text-xs text-muted">{{ auth()->user()->email }}</p>
            </div>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" data-test="logout-button" class="inline-flex min-h-10 items-center justify-center rounded-full border border-ink px-4 py-2 text-xs font-bold text-ink transition hover:bg-ink hover:text-white">{{ __('Log out') }}</button>
            </form>
        </div>
    </div>
</header>
