# Hostinger deployment and runtime runbook

This guide describes the supported deployment shape for Easy English's Laravel 13 / Filament 5 application on Hostinger shared hosting. Confirm the exact PHP version, extensions, cron cadence, and process limits available on the selected Hostinger plan before production deployment. The locked Composer dependencies require PHP 8.4 or newer; CI currently validates PHP 8.4.

## Runtime requirements

- PHP 8.4+ with the extensions required by `composer check-platform-reqs` for the locked dependencies. In particular, verify `ctype`, `curl`, `dom`, `fileinfo`, `filter`, `hash`, `intl`, `mbstring`, `openssl`, `PDO`, the selected PDO database driver, `session`, `tokenizer`, `xml`, and `zip` against the actual hosting runtime.
- Composer 2 and Node.js 22/npm for building Laravel assets during deployment. For Laravel itself, Node is a build-time requirement and production serves compiled files in `public/build`. OpenWA is a separate persistent Node.js service on Railway, not a process hosted by this Hostinger account; see the [OpenWA production and security runbook](../integrations/openwa.md).
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
APP_URL=https://easyenglish.ro
APP_KEY=base64:GENERATE_A_UNIQUE_KEY
# The public web middleware fixes the website locale to Romanian.
# These defaults remain English for CLI and internal operations.
APP_LOCALE=en
APP_FALLBACK_LOCALE=en

DB_CONNECTION=mysql
DB_HOST=srv1713.hstgr.io
DB_PORT=3306
DB_DATABASE=u583712040_easyenglish_ro
DB_USERNAME=u583712040_easyenglish_ro
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

### Current production layout

The existing Hostinger layout is intentional and must remain unchanged:

~~~text
/home/u583712040/domains/easyenglish.ro/
  public_html/             # Hostinger document root and Laravel application root
    .env                   # Server-owned secret configuration
    artisan
    app/
    bootstrap/
    config/
    database/
    vendor/
    storage/
    public/                # Laravel's actual public web assets and front controller
    .htaccess              # Root rewrite into public/
~~~

The Hostinger document root is /home/u583712040/domains/easyenglish.ro/public_html. The Laravel application files remain inside that directory. The root .htaccess rewrites web requests into public/; do not move the application to a sibling easyenglish-app directory and do not replace this layout with the alternative layout described in older deployment notes.

Because .env, vendor/, storage/, app/, config/, database/, and other private application files are physically inside the document root, the root rewrite and access-denial rules are security-critical. Verify direct HTTP requests to sensitive paths are denied before launch. Do not rely on the fact that the homepage works as proof that private files are protected.

### Deployment status and automation gate

The Hostinger inspection on 2026-11-18 confirmed the layout above, PHP 8.4.27, an active SSL certificate, HTTPS redirect, CDN enabled, and no Hostinger built-in Git repository configured for this website.

**Important: the repository's current GitHub Actions deploy script is not compatible with the live layout.** scripts/deploy/hostinger-deploy.sh currently expects the application and .env under /home/u583712040/domains/easyenglish.ro/easyenglish-app and publishes a separate public_html tree. The live application instead resides directly in public_html. Do not enable HOSTINGER_DEPLOY_ENABLED or run that script until the script, packaging assumptions, rollback behavior, and tests have been updated and verified against the current layout.

The deployment workflow is gated by the repository Actions variable HOSTINGER_DEPLOY_ENABLED and runs only after the PHP, frontend, and OpenWA CI jobs pass on a push to main. Hostinger's own Git auto-deployment is not configured and should remain disabled.

Before any production deployment:

1. Take a fresh database backup and confirm the restore procedure.
2. Confirm the current production .env is present and protected. Never print or copy its contents into logs, GitHub Actions, tickets, or chat.
3. Confirm the CLI PHP executable is PHP 8.4 or newer. The web runtime is PHP 8.4.27, but the CLI binary must be checked independently.
4. Update the deploy script to deploy in place under public_html while preserving the server-owned .env, storage/, root .htaccess, public/storage link, Hostinger ACME files, and other server-owned configuration.
5. Ensure the release archive does not contain .env, storage/, local database files, or other production runtime data.
6. Back up the current application tree and public assets outside the web root before replacing code.
7. Enter maintenance mode, publish the reviewed release, run migrations only after backup and review, clear/rebuild caches, and restore normal service.
8. Verify /up, public assets, login/dashboard, admin and teacher authorization, database connectivity, logs, scheduler, and queue processing.
9. Record the deployed commit SHA, deployment time, operator, backup reference, and smoke-test results.

Do not run composer update or migrate:fresh on production. A code rollback does not automatically reverse database migrations; prefer a reviewed forward fix unless the incident plan explicitly requires a database restore.

### Scheduler and database queue on shared hosting

The app uses Laravel's scheduler and a database-backed queue. A database queue does not run jobs by itself: a worker must consume the jobs table. Do not rely on a permanently running process on shared hosting.

The current production account has no cron entries for easyenglish.ro. Existing jobs pointing to scl.ro/ee are for the legacy application and must not be treated as EasyEnglish production workers.

After verifying the CLI PHP binary and Hostinger execution limits, configure two cron entries using the current application root:

Run the scheduler every minute if the plan permits:

~~~sh
* * * * * cd /home/u583712040/domains/easyenglish.ro/public_html && /path/to/php84 artisan schedule:run >> /dev/null 2>&1
~~~

Run a bounded database queue worker periodically, for example every five minutes:

~~~sh
*/5 * * * * cd /home/u583712040/domains/easyenglish.ro/public_html && /path/to/php84 artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30 >> storage/logs/queue-cron.log 2>&1
~~~

Replace /path/to/php84 with the verified absolute PHP 8.4 CLI path. Do not copy the example path literally.

The scheduler in routes/console.php currently includes:

- bookings:expire-holds every minute;
- stripe:reconcile every five minutes;
- notifications:dispatch every minute;
- call-events:dispatch every minute;
- openwa:monitor every minute;
- teachers:sync-calendars every five minutes;
- bookings:reconcile-calendar-events every five minutes;
- verification:prune daily.

The calendar and notification jobs, plus confirmed-booking event writes, require the database queue worker. A stopped worker means jobs accumulate and calendar state may become stale. Review the email, Stripe, Google Calendar, and OpenWA runbooks for each integration's recovery behavior.

The exact cadence and worker limits are operational defaults, not verified limits for every Hostinger plan. Test them on this account and record measured cadence, execution limits, and safe batch sizes in the launch checklist. Keep each worker invocation shorter than the hosting execution limit. Ensure the queue connection's retry_after is greater than the worker timeout. Use idempotent jobs because interrupted work can be retried.

Check schedule registration with php artisan schedule:list. Check failed jobs with php artisan queue:failed and retry only after identifying and correcting the cause.

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

## Data retention (disabled until approval)

The proposed 12-month session-goal/notes anonymization command is `php artisan bookings:anonymize-old-session-notes`. Run without `--apply` for a count-only dry run. Write mode requires both `--apply` and `DATA_RETENTION_ENABLED=true`. Keep the flag false until the approved retention schedule, lawful bases, exceptions/legal holds, and accounting-record constraints are documented. Do not add the command to the scheduler before that approval. See [the retention policy](../legal/retention-policy.md) and [privacy notice draft](../legal/privacy-notice.md).

## Security and access control

- Keep `APP_DEBUG=false`, use HTTPS, and store all credentials in server-side environment configuration.
- Public account registration is disabled. Provision staff through the interactive `php artisan staff:provision EMAIL --name="Name"` command from a trusted shell; it prompts for a password without echoing it. Do not put passwords in command arguments, deployment scripts, or shell history. Restrict shell access and remove temporary operator access after provisioning.
- Authentication alone is not staff authorization; administrative routes/actions must explicitly check the user's staff permission.
- Restrict database and backup access to the smallest required set of accounts.
- Use verified provider signatures for webhooks, not browser authentication. Rate-limit public endpoints and make webhook/job processing idempotent.
- Keep production Stripe and WhatsApp secrets out of CI, source control, screenshots, and logs. Test with fakes/mocks or provider test mode.
- Before launch, complete the security review, privacy/retention policy, Romanian tax/invoicing review, SMTP verification, WhatsApp setup/live tests, and the [launch checklist](../operations/launch-checklist.md) when available.
See [the WhatsApp integration runbook](../integrations/whatsapp.md) before configuring Meta credentials. The OpenWA scheduled-call service is hosted separately on Railway. Hostinger cannot resolve Railway private `*.railway.internal` DNS because Railway private networking is scoped to a project/environment. Configure Laravel with the OpenWA public HTTPS domain and a high-entropy Bearer service token in Hostinger environment variables; never put the token in source control or browser code. The Node API returns `503` when its service token is absent, and all `/v1/*` routes require Bearer authentication. Pairing UI routes are disabled by default and require a separate temporary feature flag plus Basic authentication. Keep `OPENWA_ENABLED=false` and `OPENWA_ROLLOUT_STAGE=off` until #220 and explicit rollout approval. See the [OpenWA operations decision record](../decisions/openwa-production-operations.md). See the [staff runbook](../operations/staff-runbook.md) for routine operations and the [launch checklist](../operations/launch-checklist.md) for release gates. Keep `META_WHATSAPP_VERIFICATION_ENABLED=false` until the approved authentication template, webhook signatures, and live OTP delivery are verified. Ordinary WhatsApp notifications use the separate OpenWA messaging capability and are not routed through Meta.

## Security and access-control baseline

- Set `APP_ENV=production` and `APP_DEBUG=false`; never expose `.env`, application logs, database files, backups, or source-control metadata from the web root. The document root must be Laravel's `public/` directory.
- Keep `APP_KEY`, database credentials, Stripe secrets, SMTP credentials, Meta access token, Meta app secret, and webhook verification token in the Hostinger environment/secret store. Never put them in source, public assets, command arguments, screenshots, or logs. Rotate credentials if exposure is suspected.
- Public account registration is disabled. Filament's `canAccessPanel` gate checks the explicit `is_staff` flag; authentication alone is not sufficient. Provision staff only through the controlled `php artisan staff:provision EMAIL --name="Name"` process from a trusted shell, with a unique password and MFA/passkey enrollment. No default staff credentials exist.
- Public booking submission and checkout creation are throttled; availability, signed booking-management links, and provider webhook endpoints have explicit request limits. Stripe and WhatsApp webhooks bypass browser CSRF only at their respective endpoints and must validate provider signatures before trusting payloads. Do not remove signature verification to troubleshoot webhook delivery.
- Laravel Blade output uses escaped interpolation for user-provided session goals and notes. Keep logs restricted and avoid logging message bodies, customer notes, contact details, access tokens, signatures, or raw provider payloads. Current webhook error logs record exception classes rather than raw request bodies.
- Admin access and staff mutations are covered by feature tests; run `composer ci:check` after changes. Review new routes for authorization, validation, rate limiting, idempotency, and redacted failure reporting before merging.
- Production security review still requires checking the actual Hostinger PHP/web-server settings, HTTPS/secure cookie behavior, file permissions, backup access, and secret configuration. Track evidence and unresolved risks in the [launch checklist](../operations/launch-checklist.md).
