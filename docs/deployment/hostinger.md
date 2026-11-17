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

1. Take a database backup and confirm a recent backup can be restored.
2. Complete the first-time production .env configuration on Hostinger. Keep .env, user uploads, and other runtime data on the server and outside every release archive.
3. Confirm the GitHub production environment SSH secrets are configured. Keep HOSTINGER_DEPLOY_ENABLED unset/false until .env, SSH access, and database connectivity are verified.
4. Merge the reviewed release to main. PHP, frontend, and OpenWA checks must all pass before deployment becomes eligible to run. Set HOSTINGER_DEPLOY_ENABLED=true only after production is approved.
5. Keep the Laravel application in /home/u583712040/domains/easyenglish.ro/easyenglish-app and the Hostinger document root at /home/u583712040/domains/easyenglish.ro/public_html. The document root must contain only Laravel public/ contents. Its index.php must load ../easyenglish-app/vendor/autoload.php, ../easyenglish-app/bootstrap/app.php, and the maintenance file under ../easyenglish-app/storage/. Never expose .env, vendor/, or the repository root through the web server.
6. The deployment job builds locked PHP dependencies using PHP 8.4 and frontend assets using Node.js 22. The remote script preserves .env and storage/, backs up the previous code/public tree, publishes assets, and runs php artisan migrate --force. Do not run composer update or migrate:fresh on production.
7. Verify /up, login/admin access, logs, database connectivity, asset loading, scheduled task execution, and queue processing after deployment.
8. Keep the previous code/public backup available for rollback. A code rollback does not automatically reverse database changes.

## GitHub Actions deployment to Hostinger

Hostinger's built-in Git auto-deployment must remain disabled. It publishes repository contents under the website document root, which would put private Laravel application files back inside public_html.

The repository tests workflow includes a deploy-hostinger job. It depends on all three existing CI jobs (PHP, frontend, and OpenWA PoC) and is eligible only after a successful push to main. Pull requests and non-main branches never deploy. Main deployments are serialized and are not canceled midway through a release. Deployment is disabled by default until the repository Actions variable HOSTINGER_DEPLOY_ENABLED is set to the literal value true.

### Server directory layout

The Hostinger account uses this layout:

~~~text
/home/u583712040/domains/easyenglish.ro/
  easyenglish-app/       # Private Laravel app, vendor, .env, and storage
  public_html/           # Only the contents of easyenglish-app/public/
~~~

The deploy script preserves the server-owned easyenglish-app/.env and easyenglish-app/storage/, publishes public assets into public_html, and rewrites the deployed front controller to load Laravel from ../easyenglish-app. It also preserves public_html/.well-known and .user.ini. If public_html/storage is a symlink, it is retargeted to ../easyenglish-app/storage/app/public because its relative target changes when copied; an existing real storage directory is left untouched.

Before enabling deployment, complete the first production setup on Hostinger. The server .env must set APP_ENV=production, APP_DEBUG=false, APP_URL=https://easyenglish.ro, a generated APP_KEY, and DB_CONNECTION=mysql. The app directory must already contain .env, artisan, and vendor/autoload.php. Verify that the PHP CLI is version 8.4 or newer and that storage/ and bootstrap/cache/ are writable. The deploy workflow remains disabled until this has been verified. Never put the database password, APP_KEY, .env, or provider credentials into GitHub Actions or source control.

### Configure SSH deployment access

1. In Hostinger hPanel, open SSH Access for easyenglish.ro and record the SSH hostname, port, and account username. Do not assume the MySQL host is also the SSH host.
2. Generate a dedicated Ed25519 key pair on a trusted workstation:

~~~sh
ssh-keygen -t ed25519 -C "github-actions-easyenglish-ro" -f ~/.ssh/easyenglish-hostinger-deploy -N ""
~~~

3. Add the public key from ~/.ssh/easyenglish-hostinger-deploy.pub to the Hostinger account's authorized SSH keys. Keep the private key local; never commit it.
4. Connect interactively once and verify the server host-key fingerprint against Hostinger's trusted account/server information. Then capture the known-hosts entry:

~~~sh
ssh-keyscan -p SSH_PORT SSH_HOST > easyenglish-hostinger-known-hosts
ssh-keygen -lf easyenglish-hostinger-known-hosts
~~~

Replace the placeholders with the values shown in hPanel. Do not treat ssh-keyscan alone as proof of server identity.
5. In GitHub repository Settings → Environments, create an environment named production. Add these environment secrets:

| Secret | Value |
| --- | --- |
| HOSTINGER_SSH_HOST | SSH hostname from Hostinger hPanel |
| HOSTINGER_SSH_PORT | SSH port from Hostinger hPanel |
| HOSTINGER_SSH_USER | Hostinger SSH username |
| HOSTINGER_SSH_PRIVATE_KEY | Full private key contents, including BEGIN/END lines |
| HOSTINGER_SSH_KNOWN_HOSTS | Verified known-hosts entry |

6. In GitHub repository Settings → Secrets and variables → Actions → Variables, add HOSTINGER_DEPLOY_ENABLED with value true only after the production .env, SSH access, database connectivity, and backup readiness have been verified.
7. If the Hostinger shell's default php executable is not PHP 8.4, add the optional environment variable HOSTINGER_PHP_BIN with the absolute path to the PHP 8.4 CLI executable. Confirm the path in SSH using php -v and command -v php, or the provider's version-specific CLI command.

Do not reuse the Hostinger Git deploy key as the GitHub Actions SSH key. The former authorizes Hostinger to pull from GitHub; the latter authorizes GitHub Actions to connect to the Hostinger account.

### Deployment behavior and recovery

The deployment job builds locked production Composer dependencies with PHP 8.4, verifies platform requirements, builds Vite assets with Node.js 22, and packages a release without .env, storage/, node_modules, tests, or development documentation. It transfers the archive over SCP, then runs the remote deploy script over SSH.

The remote script verifies the PHP CLI version and required production environment flags, rejects release archives containing environment or persistent runtime data, and checks for the Laravel entry point and Vite manifest. It creates a code/public backup under $HOME/.easyenglish-deploy-backups outside the web root, excluding .env and storage/. It then enters maintenance mode, updates app code while preserving .env and storage/, publishes the public files, retargets the storage symlink when needed, rewrites the Hostinger-specific front-controller paths, runs optimize:clear, migrate --force, and optimize, then exits maintenance mode. The GitHub runner finally checks https://easyenglish.ro/up.

If deployment fails after maintenance mode is enabled, the script deliberately leaves the site in maintenance mode rather than exposing a partially updated application. Identify the failure before restoring the previous code/public backup. A code rollback does not reverse database migrations; prefer a forward fix unless the incident recovery plan explicitly requires a database restore.

To restore an earlier backup from SSH, replace BACKUP_FILENAME with the actual archive name:

~~~sh
BASE="$HOME/domains/easyenglish.ro"
APP="$BASE/easyenglish-app"
PUBLIC="$BASE/public_html"

find "$APP" -mindepth 1 -maxdepth 1 ! -name ".env" ! -name "storage" ! -name ".git" -exec rm -rf -- {} +
find "$PUBLIC" -mindepth 1 -maxdepth 1 ! -name ".well-known" ! -name "storage" ! -name ".user.ini" -exec rm -rf -- {} +
tar -xzf "$HOME/.easyenglish-deploy-backups/BACKUP_FILENAME" -C "$BASE"

cd "$APP"
php artisan optimize:clear
php artisan optimize
php artisan up
~~~

The backup contains the previous application code and public tree, not production .env, persistent storage, or a database backup. Do not reverse migrations or restore a database backup unless the incident recovery plan explicitly requires it.

## Scheduler and database queue on shared hosting

The app uses Laravel's scheduler and a database-backed queue. A database queue does **not** run jobs by itself: a worker must consume the `jobs` table. Do not rely on a permanently running process on shared hosting.

Configure two cron entries in Hostinger, using the application directory shown below and the PHP 8.4 CLI executable selected for the hosting account. Verify the CLI version and executable path over SSH before saving the entries; validate the plan's execution limits.

Run the scheduler every minute if the plan permits:

```sh
* * * * * cd /home/u583712040/domains/easyenglish.ro/easyenglish-app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler includes `notifications:dispatch` every minute to generate reminders and queue due transactional emails, plus `teachers:sync-calendars` every five minutes to queue a full Google Calendar reconciliation for each connected teacher, and `bookings:reconcile-calendar-events` every five minutes to recover missing or failed booking-event writes. The reconciliation jobs and confirmed-booking event writes require the database queue worker below; a stopped worker means calendars become stale and those teachers are safely removed from public availability after ten minutes. Review [the email integration runbook](../integrations/email.md) for outbox states, retry policy, SMTP configuration, and delivery troubleshooting.

Run a bounded queue worker periodically, for example every five minutes:

```sh
*/5 * * * * cd /home/u583712040/domains/easyenglish.ro/easyenglish-app && php artisan queue:work database --stop-when-empty --max-time=50 --max-jobs=100 --tries=3 --timeout=30 >> storage/logs/queue-cron.log 2>&1
```

The exact cadence and limits are operational defaults, not verified limits for every Hostinger plan. Test them on the target account and record the measured cadence, execution limits, and safe batch sizes in the [launch checklist](../operations/launch-checklist.md). Keep each worker invocation shorter than the hosting execution limit. Ensure the queue connection's `retry_after` (currently 90 seconds) is greater than the worker timeout, and adjust the worker timeout/limits if observed job runtimes require it. Use idempotent jobs because failed or interrupted work can be retried. If cron cadence is too slow for a job's deadline, choose an appropriate hosting plan or an external worker rather than assuming the queue is real-time.

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
