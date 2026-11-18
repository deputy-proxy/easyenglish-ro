# Repository-specific development configuration

Last verified: 2026-11-18. Re-check the live workflows and manifests before every implementation; this file is a snapshot, not a substitute for inspection.

## Runtime and framework
- PHP requirement: `^8.4`; CI uses PHP 8.4.
- Laravel: 13.x.
- Filament: 5.x.
- Livewire: 4.x.
- Node.js: 22.
- Frontend package manager: npm, using committed `package-lock.json`.
- Main application CI database: SQLite.
- Production deployment target: Hostinger; deployment is conditional on the configured repository variable and successful validation.

## Current CI validation
Source: `.github/workflows/tests.yml`.

- PHP job: Composer install, prepare SQLite test database, run migrations, then `composer ci:check`.
- The Composer quality gate includes Pint formatting, PHPStan/Larastan, and Laravel tests.
- Frontend job: install PHP dependencies needed for Flux CSS, `npm ci`, `npm test`, and `npm run build`.
- OpenWA PoC job: install PoC dependencies, apply its compatibility patch, run PoC tests, run `npm audit --audit-level=high` in the PoC directory, validate the shell entrypoint, and build its Docker image.
- Hostinger deployment job runs only on the configured main-branch push after PHP, frontend, and OpenWA jobs pass.

## Local validation
- PHP: `composer ci:check`.
- Frontend: `npm ci`, `npm test`, `npm run build`.
- OpenWA PoC: from `poc/openwa-scheduled-calls`, use `npm ci --ignore-scripts`, run the compatibility patch, `npm test`, `npm audit --audit-level=high`, `sh -n docker-entrypoint.sh`, and the Docker build as specified in CI.
- Use SQLite migrations for the main application checks.
- Reconcile the exact commands with the workflow before running them; do not infer extra jobs.

## Known CI coverage gaps
- The current workflow audits the OpenWA PoC dependencies, but does not run `composer audit --locked` or `npm audit` against the root application dependencies.
- Do not claim root dependency auditing is covered until the workflow changes.
