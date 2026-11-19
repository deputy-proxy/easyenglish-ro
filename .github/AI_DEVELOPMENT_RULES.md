# AI Development Rules

## Purpose
These rules define the shared development workflow. Repository-specific configuration, specifications, and existing conventions remain authoritative where they provide more specific guidance.

## Before implementation
1. Read the issue, acceptance criteria, and any implementation plan.
2. Read `AGENTS.md`, `.chatgpt/osingle.md`, and `.chatgpt/repository.md` when present.
3. Inspect the relevant code, tests, `composer.json`, lockfiles, frontend package configuration, and applicable GitHub Actions workflows.
4. Read applicable skills under `.agents/skills/`.
5. Reconcile plans with the current repository state. Do not follow stale assumptions blindly.

## Issue-driven workflow
- Tie implementation work to a GitHub issue unless the user explicitly requests otherwise.
- Use a dedicated branch. Never commit directly to `main`.
- Keep each change limited to the requested scope.
- Add or update regression tests when executable behavior changes.
- Update documentation when behavior, architecture, security, integrations, or operational procedures change.

## Validation
- Use the commands and versions actually configured in this repository. Do not assume another repository's Composer scripts or CI matrix applies here.
- Run focused tests first, then the required repository validation.
- Inspect the actual GitHub Actions checks for the branch or pull request. Local success alone is not proof that CI is green.
- Diagnose failures from the first meaningful error and fix root causes rather than suppressing checks.
- Never weaken or delete tests simply to obtain a passing build.

## Architecture and security
- Follow established application and domain boundaries. Keep business rules in the appropriate application/domain layer.
- Enforce authorization server-side; UI visibility is not a security boundary.
- Validate external input and account for authentication, authorization, retries, timeouts, idempotency, and failure states in integrations.
- Never commit credentials, tokens, private keys, or environment-specific secrets.
- Preserve historical and audit data. Do not silently rewrite historical records.

## Pull requests and completion
- Use descriptive branch names and pull request titles consistent with repository conventions.
- Keep pull requests focused and explain meaningful implementation decisions.
- Merge only after required checks pass and the requested behavior is verified.
- Do not claim tests, CI, deployment, or merge succeeded unless the corresponding result was inspected.
- Preserve repository-specific development infrastructure and persistent sandboxes according to the repository's documented lifecycle.

## Scope control
Do not perform unrelated refactoring, dependency upgrades, formatting sweeps, or speculative changes. Prefer the smallest maintainable change that satisfies the acceptance criteria.