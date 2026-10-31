#!/usr/bin/env bash
# Deploy script VitoDeploy (tanpa Docker): dipanggil Vito setelah webhook push GitHub.
# Satu script untuk dua situs: checkout `staging` = server staging, checkout `main` = production.
set -euo pipefail

cd "$(dirname "$0")/.."
BRANCH=$(git rev-parse --abbrev-ref HEAD)

git pull --ff-only origin "$BRANCH"

composer install --no-interaction --prefer-dist --optimize-autoloader --no-dev
npm ci --no-audit --no-fund
npm run build

php artisan migrate --force --no-interaction
php artisan optimize
php artisan horizon:terminate
