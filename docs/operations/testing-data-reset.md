# Testing data reset

Use the dedicated Artisan command to clear EasyEnglish customer and transactional data between end-to-end test runs. It is designed to free customer email addresses and phone numbers for reuse without rebuilding the database.

```sh
# Preview the affected tables and row counts. This does not mutate data.
php artisan app:reset-testing-data

# Execute after an interactive confirmation.
php artisan app:reset-testing-data --execute

# Execute non-interactively (for a deliberate local/staging test reset).
php artisan app:reset-testing-data --force
```

The command is disabled in production. It removes customer-role user accounts and student profiles, lesson bookings and related call/feedback/audit records, bundle entitlements and purchase/payment/refund records, free-session claims, customer notifications and delivery attempts, contact-verification challenges, enquiries, WhatsApp contacts/messages/webhook events, and Stripe webhook event records.

It preserves admin, teacher, and staff accounts; teacher profiles and calendars; availability rules and exceptions; bundle definitions and prices; discount voucher definitions; testimonials, FAQs, and other site content; application configuration; and Laravel's own login session table. In this command, “sessions” means lesson bookings, not Laravel login sessions.

The deletion list is an explicit allow-list and runs inside a database transaction. Review the preview before executing it. Do not use this command against production data.
