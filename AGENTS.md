# Agent Instructions

This repository is a Laravel application.

Before making changes:
1. Read `.chatgpt/osingle.md` and `.chatgpt/repository.md` when present.
2. Read `.github/AI_DEVELOPMENT_RULES.md` when present.
3. Inspect the relevant application code, tests, Composer scripts, frontend scripts, and GitHub Actions workflows. Treat the repository's actual configuration as authoritative.
4. Read the relevant skill in `.agents/skills/` before working in that skill's domain.

Follow existing architecture and conventions. Keep changes scoped to the request, add regression tests for behavior changes, run the relevant checks, and verify GitHub Actions before declaring work complete. Never commit directly to `main`, bypass failing checks, or commit secrets.