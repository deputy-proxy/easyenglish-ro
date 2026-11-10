<?php

namespace App\Http\Controllers;

use App\Models\Enquiry;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContactController extends Controller
{
    public function show(): View
    {
        $this->applyLocale();

        return view('public.contact');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->applyLocale();

        $validated = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'email' => ['required', 'string', 'email:rfc', 'max:254'],
            'subject' => ['required', 'string', 'min:3', 'max:180'],
            'message' => ['required', 'string', 'min:10', 'max:10000'],
            'website' => ['nullable', 'string', 'max:0'],
        ], [
            'name.required' => 'Completează numele.', 'name.min' => 'Numele trebuie să conțină cel puțin 2 caractere.', 'name.max' => 'Numele poate avea cel mult 120 de caractere.',
            'email.required' => 'Completează adresa de e-mail.', 'email.email' => 'Introdu o adresă de e-mail validă.', 'email.max' => 'Adresa de e-mail este prea lungă.',
            'subject.required' => 'Completează subiectul mesajului.', 'subject.min' => 'Subiectul trebuie să conțină cel puțin 3 caractere.', 'subject.max' => 'Subiectul poate avea cel mult 180 de caractere.',
            'message.required' => 'Scrie mesajul tău.', 'message.min' => 'Mesajul trebuie să conțină cel puțin 10 caractere.', 'message.max' => 'Mesajul poate avea cel mult 10.000 de caractere.',
            'website.max' => 'Solicitarea nu a putut fi procesată.',
        ]);

        Enquiry::query()->create([
            'name' => trim($validated['name']),
            'email' => mb_strtolower(trim($validated['email'])),
            'subject' => trim($validated['subject']),
            'message' => trim($validated['message']),
            'status' => Enquiry::STATUS_NEW,
        ]);

        return redirect()->route('contact')->with('contact_success', 'Mesajul tău a fost trimis. Îți mulțumim că ne-ai scris!');
    }

    private function applyLocale(): void
    {
        if (! session()->has('locale')) {
            session()->put('locale', 'ro');
        }

        app()->setLocale('ro');
        CarbonImmutable::setLocale('ro');
    }
}
