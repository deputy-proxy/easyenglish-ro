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
| `teacher_booking_confirmed` | Assigned teacher | Booking locale | Learner details, level, goal, notes, appointment and booking action |
| `teacher_booking_rescheduled` | Assigned teacher | Booking locale | Previous and updated appointment time |
| `teacher_call_link_ready` | Assigned teacher | Booking locale | Learner/session details, call URL and booking action |
| `teacher_booking_cancelled` | Previously assigned teacher | Booking locale | Cancellation/reassignment details and booking action when still relevant |

The event message factory remains authoritative for exact event-time snapshots, eligibility, locale, recipient and CTA data. This presentation work does not change those business rules.

### Separate direct-mail flows

| Flow | Recipient | Locale | Required content |
| --- | --- | --- | --- |
| Contact email verification | Customer | Challenge locale (Romanian/English) | Six-digit code, 30-minute expiry, backup signed link and anti-sharing warning |
| OpenWA disconnection | Staff | Application locale (Romanian/English) | Impact, session ID, UTC detection time, reason and operational recovery checklist |

Verification and OpenWA messages are separate direct-mail flows and are not members of the `NotificationEvent` enum.

## Localization

- Transactional subjects and message content continue to come from `lang/ro/email.php` and `lang/en/email.php`.
- The existing persisted booking/entitlement locale is retained.
- Teacher transactional messages use the persisted booking locale, matching the customer booking language.
- Contact verification uses the challenge's persisted locale.
- The OpenWA staff alert uses the application locale, including its subject and plain-text alternative.
- Keep Romanian diacritics in UTF-8 and avoid hardcoded English strings in customer-facing transactional views.

## Email-client implementation notes

- Use presentation tables and inline CSS; do not rely on JavaScript, external stylesheets, flexbox/grid, or background images.
- Keep the outer container fluid with a 600px maximum width.
- Use Arial/Helvetica/system fallbacks for broad availability.
- Use a PNG logo because SVG support varies across email clients. The logo is embedded as an inline CID image when the email is sent, so recipients do not need to fetch it from the application server. HTML preview files fall back to the public asset URL. The image is supplementary; the message remains understandable if images are blocked.
- Keep CTA destinations visible in plain-text output.
- Optional detail rows and CTA sections are omitted when no data is available.
- Test Gmail, Outlook and at least one Romanian mailbox provider before production launch. Local HTML rendering is not proof of identical rendering across all email clients.

## Previewing and testing with Artisan

The supported preview workflow is the Laravel Artisan command. It renders all 20 email types (18 transactional events plus verification and OpenWA operations) in both Romanian and English, without sending email:

```sh
php artisan emails:preview
```

The command writes 40 HTML files and 40 plain-text files to the `email-previews` directory on Laravel's `local` filesystem (normally `storage/app/private/email-previews`). It prints the exact absolute path after rendering. Open the `.html` files in a browser to inspect the layout; the `.txt` files show the plain-text alternatives.

Render one email variant when iterating on a specific template:

```sh
php artisan emails:preview --event=appointment_confirmed --locale=ro
php artisan emails:preview --event=teacher_call_link_ready --locale=en
php artisan emails:preview --event=verification --locale=ro
php artisan emails:preview --event=openwa_disconnected --locale=both
```

Valid transactional `--event` values are the 18 `NotificationEvent` values listed in the inventory above. The two direct-mail event values are `verification` and `openwa_disconnected`. `--locale` accepts `ro`, `en`, or `both`.

To send one real test email, explicitly select one event, one locale, and a recipient:

```sh
php artisan emails:preview --event=appointment_confirmed --locale=ro --send --to=you@example.com
```

The sent subject is prefixed with `[PREVIEW]`. Sending is deliberately disabled unless `--send` is supplied. The command refuses to send all events or both locales at once, and requires `--force` as an additional safeguard in production. Configure a working Laravel mail transport first; with the local example configuration (`MAIL_MAILER=log`), messages are written to the application log rather than delivered to a mailbox. All generated messages use fake sample data, including a non-functional verification code and non-routable `preview.invalid` links. Do not forward them to customers or use the sample verification link.

The legacy `php scripts/render-email-previews.php` entry point now delegates to `php artisan emails:preview` for compatibility.

Run the focused rendering and command tests with:

```sh
php artisan test tests/Feature/EmailTemplateRenderingTest.php tests/Feature/EmailPreviewCommandTest.php tests/Feature/ContactVerificationTest.php tests/Feature/NotificationDeliveryTest.php tests/Feature/OpenWaSessionDisconnectionAlertTest.php
```

For actual mailbox rendering, send a single preview to a controlled inbox and inspect it in Gmail, Outlook, and at least one Romanian mailbox provider. Local HTML rendering cannot guarantee identical rendering across email clients. Never use real verification tokens, customer details, or live credentials in preview files or screenshots.
