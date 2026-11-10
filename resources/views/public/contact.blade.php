<!doctype html>
<html lang="ro">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="Ai o întrebare despre sesiunile EasyEnglish? Scrie-ne și revenim către tine.">
    <title>Contact · EasyEnglish</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-paper font-sans text-ink antialiased">
    <div class="min-h-screen">
        <x-public-site-header />
        <main class="mx-auto w-[calc(100%-2.5rem)] max-w-[1160px] py-12 sm:py-16 md:py-[82px]">
            <div class="mx-auto max-w-[1040px]">
                <a href="{{ route('home') }}" class="mb-8 inline-flex items-center gap-2 text-sm font-bold text-primary transition-colors hover:text-primary-hover focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"><span aria-hidden="true">←</span>Înapoi la pagina principală</a>
                <div class="mb-10 max-w-[720px] md:mb-14">
                    <p class="type-eyebrow mb-[18px]">SUNTEM AICI PENTRU TINE</p>
                    <h1 class="type-section-title mb-5">Hai să vorbim.</h1>
                    <p class="type-section-copy max-w-[62ch]">Ai o întrebare despre sesiuni, pachete sau felul în care învățăm împreună? Scrie-ne câteva rânduri și revenim către tine cât de curând.</p>
                </div>
                <div class="grid gap-10 border-t border-line pt-8 md:grid-cols-[minmax(0,0.8fr)_minmax(420px,1.2fr)] md:gap-16 md:pt-10">
                    <aside class="space-y-7">
                        <div><h2 class="type-card-title mb-2">Un mesaj este suficient.</h2><p class="type-section-copy">Nu trebuie să ai toate detaliile pregătite. Spune-ne ce ai nevoie, iar noi te ajutăm să găsești următorul pas.</p></div>
                        @php($contactEmail = config('easyenglish.contact_email'))
                        @if (is_string($contactEmail) && filter_var($contactEmail, FILTER_VALIDATE_EMAIL))
                            <div class="border-t border-line pt-5"><p class="type-eyebrow mb-2">E-MAIL</p><a class="text-base font-semibold text-primary transition-colors hover:text-primary-hover" href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a></div>
                        @endif
                        <div class="border-t border-line pt-5"><p class="type-eyebrow mb-2">CÂND REVINIM</p><p class="type-section-copy">Îți vom răspunde folosind adresa de e-mail pe care o completezi în formular.</p></div>
                    </aside>
                    <section aria-labelledby="contact-form-title">
                        @if (session('contact_success'))
                            <div role="status" aria-live="polite" class="mb-6 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-medium text-emerald-900">{{ session('contact_success') }}</div>
                        @endif
                        <form method="POST" action="{{ route('contact.submit') }}" class="space-y-5">
                            @csrf
                            <h2 id="contact-form-title" class="type-card-title">Trimite-ne un mesaj</h2>
                            <div class="absolute -left-[10000px] top-auto h-px w-px overflow-hidden" aria-hidden="true"><label for="contact-website">Nu completa acest câmp</label><input id="contact-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>
                            <div class="grid gap-5 sm:grid-cols-2">
                                <div><label for="contact-name" class="mb-2 block text-sm font-semibold">Nume <span aria-hidden="true">*</span></label><input id="contact-name" name="name" type="text" value="{{ old('name') }}" autocomplete="name" required maxlength="120" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-base text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 @error('name') border-red-500 @enderror" @error('name') aria-invalid="true" aria-describedby="contact-name-error" @enderror>@error('name')<p id="contact-name-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                                <div><label for="contact-email" class="mb-2 block text-sm font-semibold">E-mail <span aria-hidden="true">*</span></label><input id="contact-email" name="email" type="email" value="{{ old('email') }}" autocomplete="email" required maxlength="254" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-base text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 @error('email') border-red-500 @enderror" @error('email') aria-invalid="true" aria-describedby="contact-email-error" @enderror>@error('email')<p id="contact-email-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                            </div>
                            <div><label for="contact-subject" class="mb-2 block text-sm font-semibold">Subiect <span aria-hidden="true">*</span></label><input id="contact-subject" name="subject" type="text" value="{{ old('subject') }}" required maxlength="180" class="w-full rounded-xl border border-line bg-white px-4 py-3 text-base text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 @error('subject') border-red-500 @enderror" @error('subject') aria-invalid="true" aria-describedby="contact-subject-error" @enderror>@error('subject')<p id="contact-subject-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                            <div><label for="contact-message" class="mb-2 block text-sm font-semibold">Mesaj <span aria-hidden="true">*</span></label><textarea id="contact-message" name="message" rows="6" required minlength="10" maxlength="10000" class="w-full resize-y rounded-xl border border-line bg-white px-4 py-3 text-base text-ink outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/15 @error('message') border-red-500 @enderror" @error('message') aria-invalid="true" aria-describedby="contact-message-error" @enderror>{{ old('message') }}</textarea>@error('message')<p id="contact-message-error" class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror</div>
                            <p class="text-xs leading-relaxed text-muted">Prin trimiterea formularului, ne transmiți datele necesare pentru a-ți răspunde. Consultă <a href="{{ route('privacy') }}" class="font-semibold text-primary underline underline-offset-2">nota de informare privind confidențialitatea</a>.</p>
                            <button type="submit" class="inline-flex min-h-12 w-full items-center justify-center rounded-full bg-primary px-7 py-3 text-sm font-bold text-white transition hover:bg-primary-hover focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary sm:w-auto">Trimite mesajul <span class="ml-2" aria-hidden="true">→</span></button>
                        </form>
                    </section>
                </div>
            </div>
        </main>
        <x-public-site-footer />
    </div>
</body>
</html>
