<?php

namespace Database\Seeders;

use App\Models\Faq;
use Illuminate\Database\Seeder;

class FaqSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['locale' => 'ro', 'category' => 'Sesiuni', 'question' => 'Cum se desfășoară o sesiune EasyEnglish?', 'answer' => 'Discuți individual cu un profesor, într-o conversație practică adaptată nivelului și obiectivelor tale. Fiecare sesiune durează 15 minute.', 'sort_order' => 10],
            ['locale' => 'ro', 'category' => 'Sesiuni', 'question' => 'Pentru cine sunt potrivite sesiunile?', 'answer' => 'Sesiunile sunt gândite pentru adulții care vor să exerseze engleza vorbită, să capete încredere și să comunice mai ușor la serviciu sau în viața de zi cu zi.', 'sort_order' => 20],
            ['locale' => 'ro', 'category' => 'Prețuri', 'question' => 'Cât costă sesiunile?', 'answer' => 'Poți alege un pachet de 5 sesiuni la 156 RON, 11 sesiuni la 390 RON sau 21 de sesiuni la 780 RON. Fiecare pachet include o sesiune introductivă gratuită, iar prețurile includ taxele aplicabile.', 'sort_order' => 30],
            ['locale' => 'ro', 'category' => 'Programări', 'question' => 'Cum îmi rezerv o sesiune?', 'answer' => 'Alegi un interval disponibil din calendarul de pe site, apoi urmezi pașii pentru confirmarea rezervării. Disponibilitatea este afișată în fusul orar al României.', 'sort_order' => 40],
            ['locale' => 'ro', 'category' => 'Programări', 'question' => 'Prima sesiune este gratuită?', 'answer' => 'Da. Poți rezerva o sesiune introductivă gratuită de 15 minute. Oferta este disponibilă o singură dată pentru fiecare persoană, după verificarea datelor de contact.', 'sort_order' => 50],
            ['locale' => 'ro', 'category' => 'Sesiuni', 'question' => 'Unde are loc conversația?', 'answer' => 'Sesiunile au loc prin apel WhatsApp. Vei primi detaliile necesare după confirmarea rezervării.', 'sort_order' => 60],
            ['locale' => 'ro', 'category' => 'Programări', 'question' => 'Pot anula sau reprograma o sesiune?', 'answer' => 'Da. Opțiunile disponibile depind de momentul în care soliciți modificarea și de tipul rezervării. Condițiile aplicabile sunt afișate în timpul rezervării și în contul tău.', 'sort_order' => 70],
            ['locale' => 'ro', 'category' => 'Progres', 'question' => 'Cum îmi urmăresc progresul?', 'answer' => 'După sesiuni, profesorul poate înregistra feedback despre competențele exersate. Rapoartele disponibile în cont te ajută să urmărești evoluția în timp.', 'sort_order' => 80],
            ['locale' => 'en', 'category' => 'Sessions', 'question' => 'What happens during an EasyEnglish session?', 'answer' => 'You have a one-to-one conversation with a teacher, focused on your level and goals. Each session lasts 15 minutes.', 'sort_order' => 10],
            ['locale' => 'en', 'category' => 'Sessions', 'question' => 'Who are the sessions for?', 'answer' => 'They are designed for adults who want to practise spoken English, build confidence, and communicate more comfortably at work or in everyday life.', 'sort_order' => 20],
            ['locale' => 'en', 'category' => 'Pricing', 'question' => 'How much do the sessions cost?', 'answer' => 'You can choose a 5-session bundle for RON 156, an 11-session bundle for RON 390, or a 21-session bundle for RON 780. Each bundle includes one free introductory session, and prices include applicable taxes.', 'sort_order' => 30],
            ['locale' => 'en', 'category' => 'Booking', 'question' => 'How do I book a session?', 'answer' => 'Choose an available time in the calendar on the website and follow the steps to confirm your booking. Availability is shown in Romania time.', 'sort_order' => 40],
            ['locale' => 'en', 'category' => 'Booking', 'question' => 'Is the first session free?', 'answer' => 'Yes. You can book one free 15-minute introductory session. The offer is available once per person after contact details have been verified.', 'sort_order' => 50],
            ['locale' => 'en', 'category' => 'Sessions', 'question' => 'Where do the conversations take place?', 'answer' => 'Sessions take place through a WhatsApp call. You will receive the necessary details after your booking is confirmed.', 'sort_order' => 60],
            ['locale' => 'en', 'category' => 'Booking', 'question' => 'Can I cancel or reschedule a session?', 'answer' => 'Yes. The available options depend on when you request the change and on the type of booking. The applicable terms are shown during booking and in your account.', 'sort_order' => 70],
            ['locale' => 'en', 'category' => 'Progress', 'question' => 'How can I track my progress?', 'answer' => 'After sessions, your teacher can record feedback on the skills you practised. Progress reports in your account help you follow your development over time.', 'sort_order' => 80],
        ];

        foreach ($items as $item) {
            Faq::query()->firstOrCreate(
                ['locale' => $item['locale'], 'question' => $item['question']],
                [...$item, 'is_published' => true],
            );
        }
    }
}
