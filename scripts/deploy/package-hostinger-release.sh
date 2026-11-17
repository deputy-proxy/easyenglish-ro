#!/usr/bin/env bash
set -Eeuo pipefail

OUTPUT_PATH="${1:?Usage: package-hostinger-release.sh OUTPUT_PATH [REPOSITORY_ROOT]}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPOSITORY_ROOT="$(cd -- "${2:-${SCRIPT_DIR}/../..}" && pwd)"
mkdir -p "$(dirname -- "${OUTPUT_PATH}")"

tar -czf "${OUTPUT_PATH}" \
    --exclude='./.git' --exclude='./.env' --exclude='./.env.*' \
    --exclude='./storage' --exclude='./public/storage' --exclude='./database/*.sqlite' --exclude='./database/*.sqlite-*' --exclude='./node_modules' \
    --exclude='./poc' --exclude='./tests' --exclude='./docs' \
    --exclude='./.chatgpt' --exclude='./.github' --exclude='./.claude' \
    --exclude='./.agents' --exclude='./.codex' --exclude='./.mcp.json' \
    --exclude='./README.md' --exclude='./CLAUDE.md' --exclude='./AGENTS.md' \
    --exclude='./phpunit.xml' --exclude='./phpstan.neon' --exclude='./pint.json' \
    --exclude='./boost.json' --exclude='./package.json' --exclude='./package-lock.json' \
    --exclude='./vite.config.js' --exclude='./scripts/render-email-previews.php' \
    -C "${REPOSITORY_ROOT}" .

ARCHIVE_CONTENTS="$(tar -tzf "${OUTPUT_PATH}")"
for required_path in ./artisan ./composer.json ./vendor/autoload.php ./public/index.php ./public/build/manifest.json; do
    if ! grep -Fqx -- "${required_path}" <<< "${ARCHIVE_CONTENTS}"; then
        echo "Release archive is missing required path: ${required_path}" >&2
        exit 1
    fi
done
if grep -Eq '(^|/)\.env($|\.)|(^|/)storage/|(^|/)public/storage($|/)' <<< "${ARCHIVE_CONTENTS}"; then
    echo 'Release archive contains environment or persistent runtime data.' >&2
    exit 1
fi
echo "Created Hostinger release archive: ${OUTPUT_PATH}"
