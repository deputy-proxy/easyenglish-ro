# EasyEnglish

EasyEnglish is an EdVenture product for adults who want practical, focused English-speaking practice with a teacher. Learners book individual 15-minute sessions, meet their assigned teacher through WhatsApp, and use their account to manage sessions, purchases, and learning progress.

The product is deliberately focused on conversation practice rather than a self-service course catalogue or a public teacher marketplace. The public website is Romanian-language; the sessions themselves are conducted in English.

## Product

### Learner experience

The public website explains the service, presents the teaching team and product information, and provides the booking and purchase entry points.

A learner can:

- Browse available 15-minute appointment slots in the Europe/Bucharest timezone.
- Create an account or sign in before completing a booking.
- Verify the required contact channels for the selected booking flow.
- Book a free introductory session, subject to the one-free-session-per-person eligibility rule.
- Purchase an administrator-configured prepaid session bundle through Stripe Checkout.
- Apply eligible campaign vouchers to a bundle purchase.
- Book sessions using available bundle credits.
- Receive transactional email and WhatsApp notifications.
- Manage eligible bookings through signed links, including cancellation and rescheduling.
- Access the customer dashboard to review upcoming and past sessions, purchases and credits, account details, support information, and learning progress.
- Join an arranged WhatsApp session using the call link made available for the confirmed booking.

All appointments are 15 minutes. Availability is calculated from active teachers' schedules, calendar busy periods, exceptions, existing bookings, booking notice, and the inter-session buffer. The public calendar exposes aggregated availability and does not reveal teacher identities before confirmation. Teacher assignment is handled internally.

The current product rules and active bundle prices are configuration/data driven. The Filament bundle catalog is the source of truth for active products and prices; historical purchase records retain their price and discount snapshots.

### Learning progress

After sessions, teachers can record structured feedback against five learning dimensions:

- Vocabulary
- Pronunciation
- Fluency
- Grammar
- Confidence

Progress reports provide a record of assessed development over time. The customer dashboard separates assessment history, published progress reports, learner profile information, and suggested next steps. Assessed levels and self-reported profile information have distinct provenance; a learner's self-reported level is not silently treated as a teacher assessment.

Teachers use a dedicated workspace to see their assigned sessions, relevant learner context, feedback tasks, weekly workload, availability, and Google Calendar connection status. Teacher queries are scoped server-side to the authenticated teacher's own assignments. The teacher workspace does not expose customer contact details, payment data, private booking notes, or unrelated learner history.

### Staff operations

Authorized staff use the Filament administration panel at `/admin` to manage operational records, including:

- Customers and staff/teacher accounts.
- Teacher profiles, availability rules, and exceptions.
- Bookings, booking history, and audit records.
- Lesson bundle definitions, customer entitlements, and credit transactions.
- Payments, refunds, and payment disputes.
- Discount vouchers and redemptions.
- Progress reports and session feedback.
- Enquiries, FAQs, testimonials, and public-facing content.
- Notification deliveries, WhatsApp contacts, and scheduled call events.

Staff access is distinct from teacher access. A teacher account does not automatically grant Filament administration permissions.

## Booking and commercial model

The booking system is built around individual appointments and prepaid credit entitlements. A bundle purchase does not itself book all included sessions.

Current domain rules include:

- 15-minute sessions.
- Europe/Bucharest as the scheduling timezone.
- A minimum booking notice of 24 hours.
- A 15-minute buffer between appointments.
- A short application-level hold while a learner completes an individual appointment booking.
- One free introductory session per person, with eligibility shared with the free session included in a bundle.
- Bundle activation after confirmed payment and validity measured in calendar months according to the configured product policy.
- Credit reservation, consumption, restoration, forfeiture, expiry, and refund adjustments recorded through an auditable credit ledger.
- Cancellation and rescheduling outcomes determined by the policy accepted for the booking.

The current bundle catalog, discounts, and voucher rules are managed in the application. Do not treat old example prices or historical marketing values as authoritative current prices.

Stripe Checkout is used for paid purchases. A browser success redirect is not proof of payment: signed Stripe webhooks and server-side reconciliation establish payment state. Webhook processing and purchase activation are designed to be idempotent.

## Application architecture

EasyEnglish is a Laravel monolith for the public website, customer account area, teacher workspace, and staff administration. These application surfaces share the same domain models and database. External providers are integrated through dedicated adapters/services, while Laravel remains the system of record for customers, bookings, teacher assignment, entitlements, payments, and notification delivery.

### Application layers

| Layer | Implementation | Responsibility |
| --- | --- | --- |
| HTTP entry points | Laravel routes and controllers | Public pages, booking and purchase flows, customer dashboard, teacher workspace, webhooks, and signed booking management |
| Customer and teacher UI | Blade and Livewire | Server-rendered pages, account workflows, interactive booking and dashboard experiences |
| Staff UI | Filament 5 | Internal operational management and authorized staff actions |
| Domain models | Eloquent | Customers, teachers, bookings, availability, bundles, credits, payments, progress, and notifications |
| Domain services | `app/Services` | Booking rules, availability, payment processing, progress data, provider integrations, and notification orchestration |
| Background work | Laravel jobs and database queue | Calendar synchronization, notification delivery, and scheduled call-event operations |
| Scheduling | Laravel Scheduler | Expiring holds, payment/calendar reconciliation, notification and call-event dispatch, provider monitoring, and verification cleanup |

### Main domain areas

**Identity and access**

Laravel authentication and Fortify provide account registration, login, password recovery, email verification, two-factor authentication, and account security. Passkeys are supported by the application. Role and ownership checks protect staff operations, teacher routes, customer records, and signed booking-management links.

**Booking and availability**

- `AvailabilityService` calculates bookable slots from teacher schedules, exceptions, Google Calendar busy intervals, existing bookings, notice requirements, and buffers.
- `BookingService` coordinates slot holds, teacher assignment, booking creation, credit reservation, audit history, notifications, calendar synchronization, and scheduled call-event work.
- `BundleEntitlementService` and the credit transaction ledger manage prepaid session balances and their lifecycle.
- `CancellationPolicyService` centralizes cancellation and rescheduling rules.
- A database-backed booking mutex and transactional state changes protect booking mutations against concurrent requests.

Teacher availability is the union of eligible teachers' schedules, less busy periods and booking conflicts. The public response is aggregated; teacher assignment remains internal and is performed by the application.

**Payments and commercial operations**

- `StripeCheckoutService` creates checkout sessions using server-authoritative bundle and voucher pricing.
- Stripe webhook processing verifies provider signatures, records provider events, and applies payment state transitions idempotently.
- Reconciliation commands recover payment states that cannot be conclusively resolved from an individual webhook.
- Refund, dispute, voucher, and purchase snapshot records preserve an auditable commercial history.

**Notifications and contact verification**

The notification outbox records intended deliveries and their outcomes separately from the underlying booking/payment state. Queued delivery supports retries and operational inspection.

- Meta WhatsApp Cloud API is used for contact-verification messages/OTPs.
- OpenWA is used for the configured transactional WhatsApp messaging integration.
- Email is delivered through the configured mail provider.
- Signed provider webhooks update delivery and contact-verification state.

**Calendar integration**

Each teacher can connect a Google Calendar account. OAuth credentials are stored securely, calendar synchronization derives busy intervals without exposing private event content to the public availability response, and booking changes are synchronized to the relevant teacher calendar. Reconciliation jobs handle missed or uncertain calendar updates.

**Scheduled WhatsApp call events**

The scheduled-call capability is isolated in a separate Node.js OpenWA service. Laravel calls its authenticated HTTPS API; it does not embed Chromium or WhatsApp Web automation in the PHP application. Laravel remains authoritative for booking and teacher assignment, while the separate service manages provider-side call-event creation, retrieval, and cancellation. This is distinct from WhatsApp messaging and from Meta's official Cloud API.

OpenWA uses unofficial WhatsApp Web automation and carries provider/account operational risk. It is deployed separately from the Hostinger Laravel application, with its own persistent session storage, encryption, readiness checks, and monitoring.

**Learning progress**

Session feedback and progress reports are persisted separately from bookings and payment records. Progress chart data is prepared by application services and exposed to the learner through authorized dashboard views. Report publication and archival state control what is visible to the customer.

### Persistence and key records

The main Eloquent domain models include:

- Identity and profiles: `User`, `StudentProfile`, `TeacherProfile`.
- Scheduling: `Booking`, `AvailabilityRule`, `AvailabilityException`, `TeacherCalendar`, `TeacherCalendarBusyInterval`.
- Bundles and credits: `BundleDefinition`, `BundleEntitlement`, `CreditTransaction`, `FreeSessionClaim`.
- Payments: `Payment`, `StripeWebhookEvent`, `PaymentRefund`, `PaymentDispute`, `DiscountVoucher`, `DiscountVoucherRedemption`.
- Learning: `SessionFeedback`, `ProgressReport`.
- Communication: `NotificationDelivery`, `Message`, `WhatsAppContact`, `ContactVerificationChallenge`.
- Operations and public content: `BookingAudit`, `BookingCallEvent`, `Enquiry`, `Faq`, `Testimonial`.

Database migrations define and evolve the schema. Historical payment and bundle snapshots are retained so catalog changes do not rewrite past purchases.

## External integrations

| Integration | Purpose |
| --- | --- |
| Stripe | Bundle checkout, payment webhooks, refunds, disputes, and reconciliation |
| Google Calendar | Teacher OAuth connection, busy-time synchronization, and booking event synchronization |
| Meta WhatsApp Cloud API | Contact verification and OTP delivery |
| OpenWA service | Transactional WhatsApp messaging and scheduled WhatsApp call events |
| Email provider | Verification and transactional email |
| Hostinger | Laravel application hosting, MySQL database, scheduled tasks, and queue execution |
| Railway | Separate persistent OpenWA service runtime |

Integration configuration, security boundaries, setup, recovery, and operational limitations are documented in the relevant runbooks under `docs/integrations/`.

## Technology stack

| Area | Technology |
| --- | --- |
| Runtime | PHP 8.4+ |
| Backend | Laravel 13 |
| Staff administration | Filament 5 |
| UI | Blade, Livewire 4, Livewire Flux |
| Styling | Tailwind CSS 4 |
| Browser interactions | Alpine.js / Livewire and GSAP where needed |
| Asset build | Vite 8, Node.js, npm |
| Database | MySQL/MariaDB in production; SQLite for local setup and tests |
| Payments | Stripe PHP SDK |
| PDF/email | Laravel mail and configured provider integrations |

The application is a server-rendered Laravel application. It does not use a separate React, Vue, or Inertia frontend.

## Repository structure

```
app/
├── Console/Commands/       # Reconciliation, dispatch, monitoring, and maintenance commands
├── Enums/                  # Domain status and event types
├── Filament/               # Staff administration resources and pages
├── Http/
│   ├── Controllers/        # Public, booking, webhook, and teacher endpoints
│   └── Middleware/         # Teacher access, locale, and request protections
├── Jobs/                   # Queued calendar, notification, and call-event work
├── Mail/                   # Transactional and operational email
├── Models/                 # Eloquent domain records
├── Policies/               # Resource authorization
├── Providers/              # Application, Fortify, and Filament providers
└── Services/
    ├── Booking/             # Availability, booking lifecycle, entitlements, policy
    ├── Calendar/            # Google Calendar integration
    ├── CallEvents/          # OpenWA scheduled-call provider
    ├── Notifications/       # Email/WhatsApp outbox and provider adapters
    ├── Payments/            # Stripe checkout, webhooks, refunds, disputes, vouchers
    ├── Progress/            # Learner progress and chart data
    └── Verification/        # Contact and free-session eligibility

database/
├── factories/
├── migrations/
└── seeders/

resources/
├── css/
├── js/
└── views/
    ├── public/              # Public site, booking, customer dashboard
    ├── teacher/             # Teacher workspace
    ├── livewire/            # Interactive account components
    └── emails/              # Transactional email templates

routes/
├── web.php
├── settings.php
└── console.php

docs/
├── architecture/
├── integrations/
├── legal/
├── operations/
└── product/
```

## Local development

### Requirements

- PHP 8.4+
- Composer 2
- Node.js 22 and npm
- Git
- SQLite for the default local database, or MySQL/MariaDB when matching production

The default local environment uses SQLite and the log mailer. You do not need live Stripe, SMTP, Google, Meta, or OpenWA credentials to install the application and run the automated test suite.

### Setup

```sh
git clone https://github.com/deputy-proxy/easyenglish-ro.git
cd easyenglish-ro
cp .env.example .env
composer setup
composer run dev
```

Configure any optional provider credentials in `.env` when developing the corresponding integration. Never commit production secrets.

### Validation

Run the configured PHP checks and tests:

```sh
composer ci:check
```

Build frontend assets:

```sh
npm run build
```

Run JavaScript tests:

```sh
npm test
```

Run Laravel tests directly:

```sh
php artisan test
```

## Production architecture and operations

The Laravel application is hosted on Hostinger with the existing application layout under the domain's `public_html` directory and Hostinger Git auto-deployment. Production persistence uses MySQL/MariaDB.

The Laravel scheduler and database-backed queue are driven by Hostinger cron. Scheduled tasks include expiring booking holds, Stripe reconciliation, notification dispatch, call-event dispatch, OpenWA monitoring, teacher calendar synchronization, booking-calendar reconciliation, and verification cleanup. Queue jobs are processed by the configured cron-triggered worker; the deployment runbook documents the exact commands and operating constraints.

The OpenWA service is deployed separately on Railway because it requires a persistent Node.js/Chromium process and durable session storage. The Laravel application communicates with it through an authenticated HTTPS service API.

Production setup, deployment, backups, cron, queue processing, provider credentials, and troubleshooting are documented in the [Hostinger deployment runbook](docs/deployment/hostinger.md).

## Product and operations documentation

The README describes the product and the architecture present in the codebase. Detailed rules and operational procedures live in focused documents:

- [Booking and availability](docs/product/booking-and-availability.md)
- [Public booking journey](docs/product/public-booking-journey.md)
- [Customer dashboard architecture](docs/product/customer-dashboard-overview.md)
- [Customer dashboard](docs/product/customer-dashboard.md)
- [Teacher dashboard](docs/product/teacher-dashboard.md)
- [Learner profile](docs/product/learner-profile.md)
- [Learner progress and assessments](docs/product/learner-progress-and-assessments.md)
- [Progress assessment dimensions and rubric](docs/product/progress-assessments.md)
- [Bundle pricing and discounts](docs/product/bundle-pricing-and-discounts.md)
- [Discount vouchers](docs/product/discount-vouchers.md)
- [Cancellations, refunds, and rescheduling](docs/product/cancellations-refunds-rescheduling.md)
- [Google Calendar integration](docs/integrations/google-calendar.md)
- [Stripe integration](docs/integrations/stripe.md)
- [Email integration](docs/integrations/email.md)
- [WhatsApp integration](docs/integrations/whatsapp.md)
- [OpenWA service architecture](docs/integrations/openwa.md)
- [Contact verification](docs/integrations/verification.md)
- [Analytics](docs/integrations/analytics.md)
- [Customer identity and record ownership](docs/architecture/customer-identity-and-record-ownership.md)
- [Staff operations runbook](docs/operations/staff-runbook.md)
- [Production launch checklist](docs/operations/launch-checklist.md)

## Operational status

The repository contains the application workflows and provider integrations described above, but code availability is not the same as production activation. Live payment, messaging, calendar, email, and scheduled-call behavior depends on provider configuration, account approvals, secrets, deployment health, and real-world acceptance testing.

Use the production launch checklist and the integration runbooks to verify those dependencies. Do not infer that a provider is live merely because its adapter or webhook endpoint exists in the codebase.
