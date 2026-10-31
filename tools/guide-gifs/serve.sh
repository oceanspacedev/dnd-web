#!/bin/sh
# Serve the app for one guide recording against that guide's demo DB.
# Usage: sh serve.sh posisi|akses|aturan|kpimanajerial|kpioperasional
# Ports match GUIDES in lib.js: posisi 8011, akses 8012, aturan 8013, kpimanajerial 8014, kpioperasional 8015.
# WhatsApp always goes to the local stub on 127.0.0.1:8019 (env.sh); it is started in the background when it is not
# running yet and keeps running after this server stops (stop it with: pkill -f "127.0.0.1:8019").
set -eu
case "${1:-}" in
  posisi) PORT=8011 ;;
  akses) PORT=8012 ;;
  aturan) PORT=8013 ;;
  kpimanajerial) PORT=8014 ;;
  kpioperasional) PORT=8015 ;;
  *) echo "usage: $0 posisi|akses|aturan|kpimanajerial|kpioperasional" >&2; exit 2 ;;
esac
HERE=$(cd "$(dirname "$0")" && pwd)
REPO=$(cd "$HERE/../.." && pwd)
WORK=${GUIDE_GIFS_WORK_DIR:-$HERE/.work}
DB="dnd_demo_$1"
# WAG_URL/WAG_TOKEN -> local stub, mail -> mail-stub.php, CACHE_STORE=array (overrides .env, see env.sh).
. "$HERE/env.sh"

if [ -f "$REPO/bootstrap/cache/config.php" ]; then
  echo "refusing: bootstrap/cache/config.php exists, so DB_DATABASE would be ignored. Run 'php artisan config:clear' first." >&2
  exit 1
fi

# Refuses unless the app resolves to the demo DB and the local WhatsApp stub under exactly this environment.
DB_DATABASE="$DB" php -d memory_limit=1G "$HERE/guard.php"

if ! curl -fsS --max-time 2 "$WAG_URL/health" 2>/dev/null | grep -q 'wa-stub ok'; then
  if lsof -nP -iTCP:"$WA_STUB_PORT" -sTCP:LISTEN >/dev/null 2>&1; then
    echo "refusing: port $WA_STUB_PORT is taken by something that is not wa-stub" >&2
    exit 1
  fi
  mkdir -p "$WORK"
  nohup sh "$HERE/wa-stub.sh" > "$WORK/wa-stub.log" 2>&1 &
  tries=0
  until curl -fsS --max-time 1 "$WAG_URL/health" 2>/dev/null | grep -q 'wa-stub ok'; do
    tries=$((tries + 1))
    if [ "$tries" -gt 25 ]; then echo "wa-stub did not start, see $WORK/wa-stub.log" >&2; exit 1; fi
    sleep 0.2
  done
  echo "wa-stub started on $WAG_URL (log: $WORK/wa-stub.log)"
fi

cd "$REPO/public"
echo "$DB on http://127.0.0.1:$PORT (WhatsApp -> $WAG_URL)"
DB_DATABASE="$DB" exec php -d memory_limit=1G -S "127.0.0.1:$PORT" \
  ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php
