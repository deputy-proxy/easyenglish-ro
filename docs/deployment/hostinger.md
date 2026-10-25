# Hostinger deployment and runtime runbook

This guide describes the supported deployment shape for Easy English's Laravel 13 / Filament 5 application on Hostinger shared hosting. Confirm the exact PHP version, extensions, cron cadence, and process limits available on the selected Hostinger plan before production deployment. The locked Composer dependencies require PHP 8.4 or newer; CI currently validates PHP 8.4.

## Runtime requirements

- PHP 8.4+ with the extensions required by `composer check-platform-reqs` for the locked dependencies. In particular, verify `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `mbstring`, `openssl`, `PDO`, the selected PDO database driver, `session`, `tokenizer`, `xml`, and `zip` against the actual hosting runtime.
- Composer 2 and Node.js 22/npm for building assets during deployment. Node is a build-time requirement; production serves the compiled files in `public/build`.
- MySQL/MariaDB for production, unless a deliberate deployment decision establishes another supported database. SQLite is the local/test default only.
- Writable `storage/` and `bootstrap/cache/` directories; HTTPS; a valid application key; and a database user limited to the application database.

Use `composer.lock` and `package-lock.json` as the source of truth. Do not run `composer update` or change dependency versions as part of routine deployment.

## First-time local setup

From the repository root:

```sh
cp .env.example .env
mkdir -p database
touch database/database.sqlite
composer setup
composer ci:check
```

`composer setup` installs locked PHP dependencies, generates a local key if needed, migrates the local database, installs frontend dependencies, and builds assets. The SQLite file must exist before migrations when `DB_CONNECTION=sqlite`. The repository setup script creates it automatically.

For routine development, use `composer run dev`. Run `composer ci:check` before opening a pull request; it executes the configured Pint check, PHPStan analysis, and test suite. CI separately builds the frontend.

## Production environment

Create the production `.env` on the server or through the hosting control panel. Never commit it, put secrets in deployment scripts, or copy local credentials to production. Set at least:

```dotenv
APP_NAME="Easy English"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-production-domain.example
APP_KEY=base64:GENERATE_A_UNIQUE_KEY
APP_LOCALE=ro
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=your_database
DB_USERNAME=your_database_user
DB_PASSWORD=your_database_password

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stack
LOG_LEVEL=info

MAIL_MAILER=smtp
MAIL_HOST=your-smtp-host
MAIL_PORT=587
MAIL_TIMEOUT=10
MAIL_USERNAME=your-smtp-username
MAIL_PASSWORD=your-smtp-password
MAIL_SCHEME=tls
MAIL_FROM_ADDRESS=your-verified-sender@example.com
MAIL_FROM_NAME="Easy English"
```

Replace every example value before deployment. Generate a unique key with `php artisan key:generate --show` and set the result as `APP_KEY`; do not rotate an existing production key casually because encrypted application data and sessions may become unreadable. Use the actual MySQL credentials and SMTP details supplied by the hosting/provider. Configure and verify SPF, DKIM, and DMARC for the sender domain.

Stripe Checkout uses server-side `STRIPE_KEY`, `STRIPE_SECRET`, and `STRIPE_WEBHOOK_SECRET`; configure them only after the webhook endpoint has been created in the matching Stripe account/mode. Follow [the Stripe integration runbook](../integrations/stripe.md) to configure required events and validate test-mode checkout, webhook delivery, and reconciliation before enabling live payments. Checkout remains disabled until `STRIPE_CHECKOUT_ENABLED=true`, the secret and webhook signing secret are configured, contact details are final, and legal/privacy review is complete. WhatsApp production messaging remains disabled until its account, credentials, templates/consent, and live delivery tests have been completed.

## Deployment procedure

1. Take a database backup and confirm a recent backup can be restored.
2. Deploy the reviewed release to the application directory. Keep `.env`, user uploads, and other runtime data outside any release replacement operation.
3. Install the locked PHP dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction`. Build frontend assets using Node 22 and `npm ci && npm run build` in the release/build environment, then deploy `public/build` with the release.
4. Confirm the web root points to Laravel's `public/` directory. Do not expose `.env`, `vendor/`, or the repository root through the web server.
5. Confirm production environment values, HTTPS, writable directories, and the configured database connection.
6. Run `php artisan migrate --force` after reviewing the migration set and backup. Do not use `migrate:fresh` on production.
7. Run `php artisan optimize` after configuration is correct. If deployment changes routes/config/views, use `php artisan optimize:clear` before rebuilding caches as needed.
8. Verify `/up`, login/admin access, logs, database connectivity, asset loading, scheduled task execution, and queue processing.
9. Keep the previous known-good release available for rollback. For rollback, restore the code release and follow the migration's documented rollback/forward-fix strategy; a code rollback does not automatically reverse database changes.

## Scheduler and database queue on shared hosting

The app uses Laravel's scheduler and a database-backed queue. A database queue does **not** run jobs by itself: a worker must consume the `jobs` table. Do not rely on a permanently running process on shared hosting.

Configure two cron entries in Hostinger, using the absolute path to the deployed release and the PHP binary selected for the hosting account. The following are templates; replace paths and validate the plan's limits.

Run the scheduler every minute if the plan permits:

```sh
* * * * * cd /home/ACCOUNT/domains/DOMAIN/public_html/app && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
```

The scheduler includes `notifications:dispatch` every minute to generate reminders and queue due transactional emails. Review [the email integration runbook](../integrations/email.md) for outbox states, retry policy, SMTP configuration, and delivery troubleshooting.

Run a bounded queue worker periodically, for example every five minutes:

```sh
*/5 * * * * cd /home/ACCOUNT/domains/DOMAIN/public_html/app && /usr/bin/php artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30 >> storage/logs/queue-cron.log 2>&1
```

The exact cadence and limits are operational defaults, not verified limits for every Hostinger plan. Test them on the target account. Keep each worker invocation shorter than the hosting execution limit. Ensure the queue connection's `retry_after` (currently 90 seconds) is greater than the worker timeout, and adjust the worker timeout/limits if observed job runtimes require it. Use idempotent jobs because failed or interrupted work can be retried. If cron cadence is too slow for a job's deadline, choose an appropriate hosting plan or an external worker rather than assuming the queue is real-time.

Check `php artisan schedule:list` after deploying scheduled tasks. Confirm cron runs by inspecting application logs and a harmless scheduled operation. Check `php artisan queue:failed` for failed jobs and use `php artisan queue:retry ID` only after identifying and correcting the failure. Do not repeatedly retry a deterministic failure without fixing its cause.

## Database, backups, and recovery

- Schedule database backups in Hostinger and retain copies outside the live hosting account.
- Before migrations, take a fresh backup; periodically perform a restore test to a separate database.
- Protect backups because they contain contact details and may contain booking/payment metadata.
- Never log passwords, API secrets, payment tokens, full webhook payloads containing personal data, or unnecessary learner notes.
- Keep `APP_DEBUG=false` in production and use restricted access to logs and administrative tools.
- For an incident, capture the timestamp, affected booking/payment reference, relevant redacted logs, and recent deployment/migration. Preserve payment provider identifiers needed for reconciliation without copying secrets into tickets.
- After restoring a backup, verify database consistency, app key availability, storage files, queue/failed-job state, and payment-provider reconciliation before reopening booking traffic.

## Troubleshooting checklist

| Symptom | Checks |
| --- | --- |
| 500 response | Check Laravel logs, PHP version/extensions, `APP_DEBUG=false`, permissions, and environment configuration. Never enable debug mode on public production traffic. |
| Database connection failure | Verify host/database/user/password, grants, PDO driver, and that the target database is reachable from the hosting account. |
| Missing frontend assets | Confirm `npm ci && npm run build` completed and the matching `public/build/manifest.json` and assets were deployed together. |
| Scheduled reminders do not run | Check Hostinger cron history, command paths, `php artisan schedule:list`, application logs, and timezone assumptions. |
| Transactional emails remain queued | Check the database queue cron, `MAIL_*` configuration, `notification_deliveries`, and the Operations → Notification delivery admin view. See [the email integration runbook](../integrations/email.md). |
| Jobs remain queued | Verify `QUEUE_CONNECTION=database`, queue table migrations, cron worker cadence, worker output, and `php artisan queue:failed`. |
| Email delivery failure | Verify SMTP host/port/TLS, sender identity, provider limits, SPF/DKIM/DMARC, and redacted transport errors. |
| Payment/notification state is unclear | Do not infer payment success from a browser redirect or notification result. Reconcile against the authoritative provider event and database state; record the investigation. |
| Stripe Checkout does not open | Verify `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, the active bundle, HTTPS `APP_URL`, and redacted Laravel logs. See [Stripe integration troubleshooting](../integrations/stripe.md). |
| Stripe webhook deliveries fail | Verify the endpoint URL, Stripe mode, signing secret, server clock, raw-body signature validation, and `stripe_webhook_events`; check Stripe delivery logs and application logs. |
| Payment or refund remains pending | Confirm the scheduled task runs, inspect the provider's current status, and run `php artisan stripe:reconcile` manually if authorized. Do not edit balances or create a second refund while status is ambiguous. |

## Security and access control

- Keep `APP_DEBUG=false`, use HTTPS, and store all credentials in server-side environment configuration.
- Do not expose public staff registration. Provision the first staff account through a controlled, documented process and remove temporary access after use.
- Authentication alone is not staff authorization; administrative routes/actions must explicitly check the user's staff permission.
- Restrict database and backup access to the smallest required set of accounts.
- Use verified provider signatures for webhooks, not browser authentication. Rate-limit public endpoints and make webhook/job processing idempotent.
- Keep production Stripe and WhatsApp secrets out of CI, source control, screenshots, and logs. Test with fakes/mocks or provider test mode.
- Before launch, complete the security review, privacy/retention policy, Romanian tax/invoicing review, SMTP verification, WhatsApp setup/live tests, and the [launch checklist](../operations/launch-checklist.md) when available.
See [the WhatsApp integration runbook](../integrations/whatsapp.md) before configuring Meta credentials. Keep `WHATSAPP_ENABLED=false` until approved templates, webhook signatures, opt-in/opt-out behavior, and live delivery are verified.
