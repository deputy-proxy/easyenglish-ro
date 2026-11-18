#!/usr/bin/env bash
set -Eeuo pipefail

RELEASE_ARCHIVE="${1:?Usage: hostinger-deploy.sh RELEASE_ARCHIVE RELEASE_SHA}"
RELEASE_SHA="${2:?Usage: hostinger-deploy.sh RELEASE_ARCHIVE RELEASE_SHA}"
[[ "${RELEASE_SHA}" =~ ^[0-9a-f]{7,40}$ ]] || { echo 'Invalid Git SHA.' >&2; exit 1; }

HOME_DIR="${HOME:?HOME is not set}"
BASE_DIR="${HOME_DIR}/domains/easyenglish.ro"
APP_DIR="${BASE_DIR}/easyenglish-app"
PUBLIC_DIR="${BASE_DIR}/public_html"
BACKUP_DIR="${HOME_DIR}/.easyenglish-deploy-backups"
PHP_BIN="${HOSTINGER_PHP_BIN:-php}"

case "${RELEASE_ARCHIVE}" in "${HOME_DIR}"/*) ;; *) echo 'Archive must be inside HOME.' >&2; exit 1 ;; esac
for required_path in "${APP_DIR}/.env" "${APP_DIR}/artisan" "${APP_DIR}/vendor/autoload.php" "${PUBLIC_DIR}/index.php"; do
    [[ -f "${required_path}" ]] || { echo "Missing required production file: ${required_path}" >&2; exit 1; }
done
[[ -f "${RELEASE_ARCHIVE}" ]] || { echo 'Release archive does not exist.' >&2; exit 1; }

grep -Eq '^APP_ENV=(production|"production")$' "${APP_DIR}/.env" || { echo 'Production .env must set APP_ENV=production.' >&2; exit 1; }
grep -Eq '^APP_DEBUG=(false|"false")$' "${APP_DIR}/.env" || { echo 'Production .env must set APP_DEBUG=false.' >&2; exit 1; }
grep -Eq '^APP_URL=(https://easyenglish\.ro|"https://easyenglish\.ro")$' "${APP_DIR}/.env" || { echo 'Production .env must set APP_URL=https://easyenglish.ro.' >&2; exit 1; }
grep -Eq '^APP_KEY=base64:.+' "${APP_DIR}/.env" || { echo 'Production .env must contain a generated APP_KEY.' >&2; exit 1; }
grep -Eq '^DB_CONNECTION=mysql$' "${APP_DIR}/.env" || { echo 'Production .env must use DB_CONNECTION=mysql.' >&2; exit 1; }

"${PHP_BIN}" -r 'exit(PHP_VERSION_ID >= 80400 ? 0 : 1);' || {
    echo 'Hostinger CLI PHP must be 8.4+. Set HOSTINGER_PHP_BIN if the default php executable is older.' >&2
    exit 1
}
if tar -tzf "${RELEASE_ARCHIVE}" | awk '/(^|\/)\.env($|\.)|(^|\/)storage\/|(^|\/)public\/storage($|\/)/ { found=1 } END { exit found ? 0 : 1 }'; then
    echo 'Refusing to deploy an archive containing .env or persistent runtime data.' >&2
    exit 1
fi

STAGING_DIR="$(mktemp -d "${HOME_DIR}/.easyenglish-release-${RELEASE_SHA}.XXXXXX")"
trap 'rm -rf -- "${STAGING_DIR}"' EXIT
tar -xzf "${RELEASE_ARCHIVE}" -C "${STAGING_DIR}"
for required_path in artisan vendor/autoload.php bootstrap/app.php public/index.php public/build/manifest.json; do
    [[ -f "${STAGING_DIR}/${required_path}" ]] || { echo "Release missing: ${required_path}" >&2; exit 1; }
done

mkdir -p "${BACKUP_DIR}"
chmod 700 "${BACKUP_DIR}"
BACKUP_PATH="${BACKUP_DIR}/before-${RELEASE_SHA}-$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
tar -czf "${BACKUP_PATH}" \
    --exclude='easyenglish-app/.env*' \
    --exclude='easyenglish-app/storage' \
    --exclude='easyenglish-app/.git' \
    --exclude='public_html/.well-known' \
    --exclude='public_html/storage' \
    -C "${BASE_DIR}" easyenglish-app public_html
chmod 600 "${BACKUP_PATH}"

cd "${APP_DIR}"
"${PHP_BIN}" artisan down --retry=60

# Replace application code while preserving the server-owned .env, storage and optional Git metadata.
find "${APP_DIR}" -mindepth 1 -maxdepth 1 \
    ! -name '.env' ! -name 'storage' ! -name '.git' \
    -exec rm -rf -- {} +
cp -a "${STAGING_DIR}/." "${APP_DIR}/"

mkdir -p "${APP_DIR}/storage/app/private" "${APP_DIR}/storage/app/public" \
    "${APP_DIR}/storage/framework/cache/data" "${APP_DIR}/storage/framework/sessions" \
    "${APP_DIR}/storage/framework/views" "${APP_DIR}/storage/logs" "${APP_DIR}/bootstrap/cache"
chmod 600 "${APP_DIR}/.env"
chmod -R ug+rwX "${APP_DIR}/storage" "${APP_DIR}/bootstrap/cache"

# Keep Hostinger ACME files, the existing public storage link/directory, and user config.
find "${PUBLIC_DIR}" -mindepth 1 -maxdepth 1 \
    ! -name '.well-known' ! -name 'storage' ! -name '.user.ini' \
    -exec rm -rf -- {} +
cp -a "${APP_DIR}/public/." "${PUBLIC_DIR}/"

# Correct Laravel's relative storage symlink when publishing into public_html.
if [[ -L "${PUBLIC_DIR}/storage" ]]; then
    ln -sfn ../easyenglish-app/storage/app/public "${PUBLIC_DIR}/storage"
elif [[ ! -e "${PUBLIC_DIR}/storage" ]]; then
    ln -s ../easyenglish-app/storage/app/public "${PUBLIC_DIR}/storage"
fi

# Point Laravel's front controller at the private sibling application directory.
sed -i \
    -e "s#__DIR__.'/../storage/framework/maintenance.php#__DIR__.'/../easyenglish-app/storage/framework/maintenance.php#" \
    -e "s#__DIR__.'/../vendor/autoload.php#__DIR__.'/../easyenglish-app/vendor/autoload.php#" \
    -e "s#__DIR__.'/../bootstrap/app.php#__DIR__.'/../easyenglish-app/bootstrap/app.php#" \
    "${PUBLIC_DIR}/index.php"

cd "${APP_DIR}"
"${PHP_BIN}" artisan optimize:clear
"${PHP_BIN}" artisan migrate --force
"${PHP_BIN}" artisan optimize
"${PHP_BIN}" artisan up
rm -f -- "${RELEASE_ARCHIVE}"

find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'before-*.tar.gz' -printf '%T@ %p\n' \
    | sort -nr | tail -n +4 | cut -d' ' -f2- \
    | while IFS= read -r old_backup; do [[ -n "${old_backup}" ]] && rm -f -- "${old_backup}"; done

echo "Hostinger deployment completed for ${RELEASE_SHA}. Backup: ${BACKUP_PATH}"
