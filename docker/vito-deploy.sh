#!/usr/bin/env bash
# Deploy script untuk situs VitoDeploy; dipanggil Vito setelah webhook push GitHub (Auto Deployment).
# Satu script untuk dua situs: checkout `staging` = server staging, checkout `main` = production.
# Stack tetap Docker Compose (Octane/FrankenPHP): Vito hanya menarik kode dan menjalankan compose.
# Compose file dipilih lewat COMPOSE_FILE di .env situs (default compose.yaml),
# mis. COMPOSE_FILE=compose.yaml:compose.vps.yaml bila server memakai overlay Caddy VPS.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
cd "$ROOT"

BRANCH=$(git rev-parse --abbrev-ref HEAD)
case "$BRANCH" in
    staging|main) ;;
    *)
        echo "Checkout situs di branch '$BRANCH', bukan staging/main; deploy dibatalkan." >&2
        exit 1
        ;;
esac

echo "Deploy $BRANCH di $ROOT"
git fetch --prune origin "$BRANCH"
git pull --ff-only origin "$BRANCH"
git log --oneline -1

# Service `release` menjalankan probe storage + migrate; web/worker/scheduler menunggu release sehat.
docker compose config --quiet
docker compose up --detach --build --remove-orphans
docker compose ps

# Image lama tanpa tag dari build sebelumnya; jangan prune volume (db_data, storage_data).
docker image prune --force >/dev/null
