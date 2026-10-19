#!/bin/sh
# Reset one guide's demo DB to the seeded state and drop its cached scoring rules.
# Usage: sh reset.sh posisi|akses|aturan|kpimanajerial|kpioperasional
# Only ever touches dnd_demo_<guide> (never the database in .env). The two KPI guides get the KPI layer of
# demo_seed.php on top of the shared dump (KPI masters, KPIs of the current and previous month, demo WhatsApp numbers).
set -eu
case "${1:-}" in
  posisi|akses|aturan|kpimanajerial|kpioperasional) ;;
  *) echo "usage: $0 posisi|akses|aturan|kpimanajerial|kpioperasional" >&2; exit 2 ;;
esac
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WORK=${GUIDE_GIFS_WORK_DIR:-$HERE/.work}
DB="dnd_demo_$1"
# WAG_URL/WAG_TOKEN -> local stub, MAIL_MAILER=log, CACHE_STORE=array (overrides .env, see env.sh).
. "$HERE/env.sh"

if [ -f "$REPO/bootstrap/cache/config.php" ]; then
  echo "refusing: bootstrap/cache/config.php exists, so DB_DATABASE would be ignored. Run 'php artisan config:clear' first." >&2
  exit 1
fi
if [ ! -f "$WORK/demo_base.sql" ]; then
  echo "missing $WORK/demo_base.sql: run setup-db.sh first" >&2
  exit 1
fi

mysql -u"${MYSQL_USER:-root}" -e "CREATE DATABASE IF NOT EXISTS $DB CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u"${MYSQL_USER:-root}" "$DB" < "$WORK/demo_base.sql"
cd "$REPO"
case "$1" in
  kpimanajerial|kpioperasional)
    DB_DATABASE="$DB" php -d memory_limit=1G "$HERE/demo_seed.php" kpi ;;
esac
DB_DATABASE="$DB" php -d memory_limit=1G "$HERE/guard.php" --clear-scoring-cache
echo "reset ok: $DB"
