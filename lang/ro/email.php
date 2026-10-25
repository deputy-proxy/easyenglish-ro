<?php

return [
    'greeting' => 'Bună, :name,',
    'footer' => 'Acesta este un e-mail de serviciu de la Easy English despre programarea sau pachetul tău, nu un mesaj de marketing. Dacă nu te așteptai la acest mesaj, contactează echipa Easy English.',
    'labels' => [
        'bundle' => 'Pachet',
        'amount_paid' => 'Sumă plătită',
        'paid_sessions' => 'Sesiuni plătite incluse',
        'free_sessions_available' => 'Sesiuni gratuite incluse disponibile în prezent',
        'bundle_status' => 'Starea actuală a pachetului',
        'bundle_expires' => 'Pachetul expiră',
        'appointment_date' => 'Ora programării (București)',
        'previous_appointment_date' => 'Ora programării anterioare',
        'duration' => 'Durată',
        'timezone' => 'Fus orar',
        'credit_outcome' => 'Situația creditului',
        'refund_amount' => 'Valoarea rambursării',
        'refund_status' => 'Starea rambursării',
        'refund_reason' => 'Motiv',
        'credits_remaining' => 'Sesiuni rămase',
        'minutes' => 'minute',
    ],
    'values' => [
        'credit_restored' => 'Creditul sesiunii a fost restituit în pachet.',
        'credit_forfeited' => 'Creditul sesiunii a fost pierdut conform politicii de anulare.',
        'succeeded' => 'Confirmată de Stripe',
        'failed' => 'Rambursarea nu a fost finalizată de Stripe; creditele rezervate au fost restituite, dacă era cazul.',
        'not_available' => 'Indisponibil',
        'active' => 'Activ',
        'refunded' => 'Rambursat',
        'expired' => 'Expirat',
        'pending_payment' => 'În așteptarea plății',
        'voided' => 'Anulat',
        'cancelled' => 'Anulat',
    ],
    'actions' => [
        'schedule_sessions' => 'Programează sesiunile',
        'manage_sessions' => 'Gestionează sesiunile',
    ],
    'events' => [
        'bundle_purchase_confirmed' => [
            'subject' => 'Cumpărarea pachetului Easy English a fost confirmată',
            'heading' => 'Cumpărarea pachetului a fost confirmată',
            'intro' => 'Plata a fost verificată, iar pachetul a fost activat la momentul cumpărării. Starea actuală este afișată mai jos. Programările individuale nu sunt create automat, așa că alege intervalele potrivite dacă mai ai credite disponibile.',
        ],
        'intro_booking_confirmed' => [
            'subject' => 'Sesiunea introductivă gratuită este confirmată',
            'heading' => 'Sesiunea introductivă este programată',
            'intro' => 'Conversația introductivă de 15 minute este confirmată. Păstrează WhatsApp disponibil la ora programată pentru ca profesorul să te poată suna.',
        ],
        'appointment_confirmed' => [
            'subject' => 'Programarea ta Easy English este confirmată',
            'heading' => 'Programarea este confirmată',
            'intro' => 'Conversația de 15 minute este programată. Păstrează WhatsApp instalat și disponibil la ora stabilită pentru apelul profesorului.',
        ],
        'appointment_reminder_1h' => [
            'subject' => 'Memento: sesiunea Easy English începe în aproximativ o oră',
            'heading' => 'Sesiunea începe în curând',
            'intro' => 'Conversația de 15 minute este programată să înceapă peste aproximativ o oră. Păstrează WhatsApp disponibil pentru apel.',
        ],
        'appointment_reminder_24h' => [
            'subject' => 'Memento: sesiunea Easy English este mâine',
            'heading' => 'Se apropie ora sesiunii',
            'intro' => 'Îți reamintim că sesiunea de conversație de 15 minute este programată peste aproximativ 24 de ore. Păstrează WhatsApp disponibil pentru apel.',
        ],
        'booking_cancelled' => [
            'subject' => 'Programarea Easy English a fost anulată',
            'heading' => 'Programare anulată',
            'intro' => 'Programarea a fost anulată. Detaliile de mai jos indică sesiunea anulată și situația creditului conform politicii aplicabile.',
        ],
        'booking_rescheduled' => [
            'subject' => 'Programarea Easy English a fost reprogramată',
            'heading' => 'Ora programării s-a schimbat',
            'intro' => 'Programarea a fost mutată la o altă oră. Folosește noul interval de mai jos și păstrează WhatsApp disponibil pentru apel.',
        ],
        'booking_no_show' => [
            'subject' => 'Actualizare despre sesiunea Easy English',
            'heading' => 'Sesiunea a fost înregistrată ca absență',
            'intro' => 'Echipa Easy English a înregistrat absența de la sesiune. Creditul aplicabil a fost pierdut conform politicii acceptate. Contactează echipa dacă această înregistrare este incorectă.',
        ],
        'teacher_cancellation' => [
            'subject' => 'Actualizare despre programarea Easy English',
            'heading' => 'Profesorul a trebuit să anuleze sesiunea',
            'intro' => 'Ne pare rău pentru schimbare. Creditul sesiunii este restituit. Dacă a fost necesară prelungirea pachetului, noua dată de expirare este afișată mai jos.',
        ],
        'refund_succeeded' => [
            'subject' => 'Rambursarea Easy English a fost confirmată',
            'heading' => 'Rambursarea a fost confirmată',
            'intro' => 'Stripe a confirmat rambursarea de mai jos. Soldul pachetului a fost actualizat în funcție de rambursarea confirmată.',
        ],
        'refund_failed' => [
            'subject' => 'Actualizare despre rambursarea Easy English',
            'heading' => 'Rambursarea nu a fost finalizată',
            'intro' => 'Stripe nu a finalizat rambursarea de mai jos. Creditele rezervate pentru această încercare au fost restituite, dacă era cazul. Contactează echipa Easy English dacă ai nevoie de ajutor.',
        ],
        'bundle_expiry_14d' => [
            'subject' => 'Pachetul Easy English expiră în aproximativ 14 zile',
            'heading' => 'Pachetul se apropie de expirare',
            'intro' => 'Pachetul tău expiră în aproximativ 14 zile. Programează sesiunile rămase înainte de data și ora de expirare afișate mai jos.',
        ],
        'bundle_expiry_3d' => [
            'subject' => 'Pachetul Easy English expiră în aproximativ 3 zile',
            'heading' => 'Pachetul expiră în curând',
            'intro' => 'Pachetul tău expiră în aproximativ 3 zile. Programează sesiunile rămase înainte de data și ora de expirare afișate mai jos.',
        ],
    ],
];
