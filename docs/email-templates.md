# EasyEnglish email templates

## Approved visual direction

The approved reference is the **first Option 1, Simple & Modern** from the original four-concept comparison. The design is deliberately restrained and informational:

- Centered email canvas, maximum width 600px, on a pale neutral background.
- Deep navy brand header with the exact EasyEnglish white logo.
- White content surface with a prominent, centered navy heading.
- Green success indicator only for successful confirmation/outcome events.
- Left-aligned greeting and concise explanatory copy.
- Pale-blue details panel with readable label/value rows.
- Primary-blue, table-based CTA when an action exists.
- Quiet support note and navy brand footer.

Do not use photos, illustrations, newsletter modules, promotional copy, animation, or decorative marketing sections in transactional messages.

## Brand tokens

The email templates use the approved landing-page values from `resources/css/app.css`:

| Token | Value |
| --- | --- |
| Primary | `#1450DF` |
| Primary hover | `#0D3DB7` |
| Navy ink | `#14234B` |
| Muted text | `#53617B` |
| Paper | `#F7F8FC` |
| Primary soft | `#EDF2FF` |
| Primary border | `#DCE5FF` |
| White | `#FFFFFF` |
| Success | `#176B4D` |

The exact source logo is `public/images/logo-white.svg`. The email-safe PNG derivative is `public/images/email/logo-white.png`, rendered from that SVG at 3x dimensions. Keep the SVG as the source of truth and regenerate the PNG if the source logo changes.

## Shared presentation components

- `resources/views/emails/partials/header.blade.php`: centered white logo on navy.
- `resources/views/emails/partials/footer.blade.php`: brand footer and existing Romanian tagline.
- `resources/views/emails/partials/shell-start.blade.php` and `shell-end.blade.php`: shared 600px email frame.
- `resources/views/emails/transactional.blade.php`: transactional HTML layout.
- `resources/views/emails/transactional-text.blade.php`: plain-text counterpart.
- `resources/views/emails/verification.blade.php` and `verification-text.blade.php`: direct verification email.
- `resources/views/emails/openwa-disconnected.blade.php` and `openwa-disconnected-text.blade.php`: operational alert.

All HTML uses table-based structure and inline styles. The content remains readable when images are blocked; the logo has alt text. Rounded corners are progressive enhancement. CTA buttons are table-wrapped links and are omitted when no URL and label are present. Long detail values are allowed to wrap.

## Template inventory and routing

### Customer transactional events

| Event | Recipient | Locale | Primary content/action |
| --- | --- | --- | --- |
| `intro_booking_confirmed` | Customer | Booking locale | Free introductory session confirmation and booking details |
| `appointment_confirmed` | Customer | Booking locale | Appointment confirmation and manage-booking action |
| `appointment_reminder_24h` | Customer | Booking locale | Reminder and appointment details |
| `appointment_reminder_1h` | Customer | Booking locale | Imminent reminder and appointment details |
| `booking_cancelled` | Customer | Booking locale | Cancellation outcome and credit/policy details |
| `booking_rescheduled` | Customer | Booking locale | Previous and updated appointment time |
| `booking_no_show` | Customer | Booking locale | No-show outcome and credit information |
| `teacher_cancellation` | Customer | Booking locale | Teacher cancellation and next-step information |
| `bundle_purchase_confirmed` | Customer | Entitlement locale | Purchase, credits, expiry and schedule-sessions action |
| `refund_succeeded` | Customer | Entitlement locale | Successful refund details |
| `refund_failed` | Customer | Entitlement locale | Refund failure and recovery details |
| `bundle_expiry_14d` | Customer | Entitlement locale | Expiry reminder and remaining credits |
| `bundle_expiry_3d` | Customer | Entitlement locale | Urgent expiry reminder and remaining credits |
| `customer_call_link_ready` | Customer | Booking locale | WhatsApp call details and join action |

### Teacher transactional events

| Event | Recipient | Locale | Primary content/action |
| --- | --- | --- | --- |
| `teacher_booking_confirmed` | Assigned teacher | English | Learner details, level, goal, notes, appointment and booking action |
| `teacher_booking_rescheduled` | Assigned teacher | English | Previous and updated appointment time |
| `teacher_call_link_ready` | Assigned teacher | English | Learner/session details, call URL and booking action |
| `teacher_booking_cancelled` | Previously assigned teacher | English | Cancellation/reassignment details and booking action when still relevant |

The event message factory remains authoritative for exact event-time snapshots, eligibility, locale, recipient and CTA data. This presentation work does not change those business rules.

### Separate direct-mail flows

| Flow | Recipient | Locale | Required content |
| --- | --- | --- | --- |
| Contact email verification | Customer | Challenge locale (Romanian/English) | Six-digit code, 30-minute expiry, backup signed link and anti-sharing warning |
| OpenWA disconnection | Staff | English | Impact, session ID, UTC detection time, reason and operational recovery checklist |

Verification and OpenWA messages are separate direct-mail flows and are not members of the `NotificationEvent` enum.

## Localization

- Transactional subjects and message content continue to come from `lang/ro/email.php` and `lang/en/email.php`.
- The existing persisted booking/entitlement locale is retained.
- Teacher operational messages remain English, matching the current message factory behaviour.
- Contact verification uses the challenge's persisted locale.
- The OpenWA staff alert remains English.
- Keep Romanian diacritics in UTF-8 and avoid hardcoded English strings in customer-facing transactional views.

## Email-client implementation notes

- Use presentation tables and inline CSS; do not rely on JavaScript, external stylesheets, flexbox/grid, or background images.
- Keep the outer container fluid with a 600px maximum width.
- Use Arial/Helvetica/system fallbacks for broad availability.
- Use a PNG logo because SVG support varies across email clients. The image is supplementary; the message remains understandable if images are blocked.
- Keep CTA destinations visible in plain-text output.
- Optional detail rows and CTA sections are omitted when no data is available.
- Test Gmail, Outlook and at least one Romanian mailbox provider before production launch. Local HTML rendering is not proof of identical rendering across all email clients.

## Local preview and tests

The seven representative HTML and plain-text samples are committed in `docs/email-previews/`. Regenerate them after changing the email layout or sample data with `php scripts/render-email-previews.php`. The samples cover a customer confirmation, reminder without a CTA, cancellation, teacher assignment, email verification, and the OpenWA operational alert in both Romanian and English.

The mailables can also be rendered from Laravel Tinker without sending email. For example:

```php
$mail = new App\\Mail\\TransactionalNotificationEmail([
    'locale' => 'ro',
    'subject' => 'Confirmarea sesiunii',
    'heading' => 'Confirmarea sesiunii tale',
    'greeting' => 'Salut, Andrei,',
    'intro' => 'Sesiunea ta a fost rezervată cu succes.',
    'details' => [['label' => 'Data', 'value' => '17.11.2026, 14:00']],
    'ctaUrl' => 'https://easyenglish.ro',
    'ctaLabel' => 'Gestionează programarea',
    'footer' => 'Acesta este un e-mail de serviciu.',
    'success' => true,
]);
file_put_contents(storage_path('app/email-preview.html'), $mail->render());
file_put_contents(storage_path('app/email-preview.txt'), $mail->renderText());
```

For verification and OpenWA previews, instantiate `App\\Mail\\ContactVerificationEmail` and `App\\Mail\\OpenWaSessionDisconnected` with non-production sample values and render them the same way.

Run focused email tests with:

```sh
php artisan test tests/Feature/EmailTemplateRenderingTest.php tests/Feature/ContactVerificationTest.php tests/Feature/NotificationDeliveryTest.php tests/Feature/OpenWaSessionDisconnectionAlertTest.php
```

Never use real verification tokens, customer details, or live credentials in preview files or screenshots.
