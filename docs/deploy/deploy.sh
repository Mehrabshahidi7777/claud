#!/usr/bin/env bash
#
# Bring the server up to the latest commit of the branch it is on.
#
#   cd /var/www/peygir && bash docs/deploy/deploy.sh
#
# Run as the peygir user, never as root. If npm cannot reach its registry
# from the server, build on your own machine, upload public/build, and run
# with SKIP_ASSETS=1.

set -euo pipefail

cd "$(dirname "$0")/../.."

if [ "$(id -u)" -eq 0 ]; then
    echo "Run this as the peygir user: sudo -u peygir bash docs/deploy/deploy.sh" >&2
    exit 1
fi

branch="$(git rev-parse --abbrev-ref HEAD)"

echo "==> Pulling ${branch}"
git pull --ff-only origin "${branch}"

echo "==> PHP dependencies"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

if [ "${SKIP_ASSETS:-0}" != "1" ]; then
    echo "==> Building assets"
    npm ci --no-audit --no-fund
    npm run build
fi

if [ ! -f public/build/manifest.json ]; then
    echo "public/build/manifest.json is missing: build the assets before going live." >&2
    exit 1
fi

# Down only for the part that changes the schema, so a slow npm install
# does not take the site offline.
echo "==> Migrating"
php artisan down --retry=15
trap 'php artisan up' EXIT
php artisan migrate --force
php artisan optimize

echo "==> Restarting the queue worker"
php artisan queue:restart

php artisan up
trap - EXIT

echo "==> Health check"
php artisan app:check || true

echo "Done: $(git log -1 --format='%h %s')"
