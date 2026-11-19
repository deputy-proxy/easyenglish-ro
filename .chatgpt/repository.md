# Repository-specific development configuration

Last verified: 2026-11-19. Exact resolved versions are recorded in the cross-repository matrix at [Cr8or](https://github.com/deputy-proxy/cr8or/blob/main/.chatgpt/repository-versions.md). Re-check manifests, lockfiles, and workflows before each implementation.

## Runtime and resolved framework versions
- PHP constraint: `^8.4`; CI uses PHP 8.4.
- Laravel 13.30.1; Filament 5.8.0; Livewire 4.4.4.
- PHPUnit 13.3.2; Pest 5.1.4; Larastan 3.11.0; Pint 1.31.0.
- Node.js 22; npm lockfile resolves Vite 8.3.5, Tailwind CSS 4.3.3, Laravel Vite plugin 3.2.0, GSAP 3.15.0.

## Current CI validation
Source: `.github/workflows/tests.yml`.
- PHP job: Composer install, prepare SQLite test database, run migrations, then `composer ci:check` (Pint, Larastan/PHPStan, Laravel tests).
- Frontend job: `npm ci`, `npm test`, `npm run build`.
- OpenWA PoC job: install PoC dependencies, apply compatibility patch, run PoC tests and high-severity npm audit, validate shell entrypoint, build Docker image.
- Hostinger deployment runs only on configured main-branch pushes after PHP, frontend, and OpenWA jobs pass.

## Local validation
- PHP: `composer ci:check`.
- Frontend: `npm ci`, `npm test`, `npm run build`.
- OpenWA PoC: from `poc/openwa-scheduled-calls`, follow the exact install, patch, test, audit, shell syntax, and Docker build commands in CI.
- Main application database checks use SQLite.

## Known gaps
- The current workflow audits OpenWA PoC dependencies but does not run root `composer audit --locked` or root `npm audit`.
