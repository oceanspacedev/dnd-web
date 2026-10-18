#!/bin/sh
# Build the demo databases for the guide recordings:
#   dnd_demo_panduan  migrated + seeded by demo_seed.php (Faker id_ID names only), dumped to .work/demo_base.sql
#   dnd_demo_posisi / dnd_demo_akses / dnd_demo_aturan / dnd_demo_kpimanajerial / dnd_demo_kpioperasional
#                     one copy per guide, restored from that dump by reset.sh (the KPI guides also get the KPI layer)
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WORK=${GUIDE_GIFS_WORK_DIR:-$HERE/.work}
MYSQL_USER=${MYSQL_USER:-root}
# WAG_URL/WAG_TOKEN -> local stub, MAIL_MAILER=log, CACHE_STORE=array (overrides .env, see env.sh).
. "$HERE/env.sh"

if [ -f "$REPO/bootstrap/cache/config.php" ]; then
  echo "refusing: bootstrap/cache/config.php exists, so DB_DATABASE would be ignored. Run 'php artisan config:clear' first." >&2
  exit 1
fi

mkdir -p "$WORK"
mysql -u"$MYSQL_USER" -e "CREATE DATABASE IF NOT EXISTS dnd_demo_panduan CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
(cd "$REPO" && DB_DATABASE=dnd_demo_panduan php -d memory_limit=1G "$HERE/demo_seed.php")
mysqldump -u"$MYSQL_USER" --single-transaction --set-gtid-purged=OFF dnd_demo_panduan > "$WORK/demo_base.sql"
echo "dump: $WORK/demo_base.sql"

for guide in posisi akses aturan kpimanajerial kpioperasional; do
  sh "$HERE/reset.sh" "$guide"
done
