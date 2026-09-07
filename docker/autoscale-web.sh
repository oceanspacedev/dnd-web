#!/usr/bin/env bash
# Scale service `web` according to average CPU of its replicas.
# Intended for compose.scale.yaml (Caddy lb refreshes DNS every 5s).
# Do not scale scheduler. Coolify/Dokploy should use the panel replica UI instead.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
PROJECT=${COMPOSE_PROJECT_NAME:-dnd-web-local}
MIN_REPLICAS=${MIN_WEB_REPLICAS:-1}
MAX_REPLICAS=${MAX_WEB_REPLICAS:-3}
CPU_LIMIT=${WEB_CPU_LIMIT:-1}
SCALE_UP=${SCALE_UP_RATIO:-0.45}
SCALE_DOWN=${SCALE_DOWN_RATIO:-0.25}
INTERVAL=${SCALE_INTERVAL_SECONDS:-8}
UP_STREAK_NEED=${SCALE_UP_STREAK:-2}
DOWN_STREAK_NEED=${SCALE_DOWN_STREAK:-4}
COOLDOWN=${SCALE_COOLDOWN_SECONDS:-20}
STATUS_FILE=${SCALE_STATUS_FILE:-/tmp/dnd-autoscale-status.log}
COMPOSE_ENV_FILE=${COMPOSE_ENV_FILE:-/tmp/dnd-web-local.env}

compose() {
  COMPOSE_DISABLE_ENV_FILE=1 docker compose \
    --env-file "$COMPOSE_ENV_FILE" \
    -f "$ROOT/compose.scale.yaml" \
    -f "$ROOT/compose.scale.local.yaml" \
    --project-name "$PROJECT" \
    "$@"
}

log() {
  local line
  line="$(date -Is) $*"
  printf '%s\n' "$line" | tee -a "$STATUS_FILE" >/dev/null
  # Keep stdout for operators; monitors should watch the status file.
  printf '%s\n' "$line" >&2
}

web_names() {
  compose ps --format '{{.Name}} {{.Service}} {{.State}}' \
    | awk '$2=="web" && $3=="running" {print $1}'
}

replica_count() {
  web_names | wc -l | tr -d ' '
}

cpu_ratio() {
  local names
  names=$(web_names | xargs)
  if [[ -z "$names" ]]; then
    echo "0"
    return
  fi
  # shellcheck disable=SC2086
  docker stats --no-stream --format '{{.Name}} {{.CPUPerc}}' $names \
    | python3 -c "
import sys
limit=float('$CPU_LIMIT') or 1.0
vals=[]
for line in sys.stdin:
    parts=line.split()
    if len(parts)<2:
        continue
    pct=parts[-1].strip().rstrip('%')
    try:
        vals.append(float(pct)/100.0/limit)
    except ValueError:
        pass
print(f'{sum(vals)/len(vals):.4f}' if vals else '0')
"
}

scale_web() {
  local target=$1
  log "scale web=$target"
  compose up --detach --no-build --no-recreate --no-deps --scale "web=${target}" web >/dev/null
}

: > "$STATUS_FILE"
log "start min=$MIN_REPLICAS max=$MAX_REPLICAS cpu_limit=$CPU_LIMIT up>$SCALE_UP down<$SCALE_DOWN"

current=$(replica_count)
if [[ "$current" -lt "$MIN_REPLICAS" ]]; then
  scale_web "$MIN_REPLICAS"
fi

up_streak=0
down_streak=0
last_scale_epoch=$(date +%s)

trap 'log "stop replicas=$(replica_count)"; exit 0' INT TERM

while :; do
  sleep "$INTERVAL"
  current=$(replica_count)
  if [[ "$current" -eq 0 ]]; then
    log "no running web replicas"
    continue
  fi
  ratio=$(cpu_ratio)
  now=$(date +%s)
  cooling=$((now - last_scale_epoch))
  log "replicas=$current cpu_ratio=$ratio cooling=${cooling}s"

  if awk -v r="$ratio" -v t="$SCALE_UP" 'BEGIN { exit !(r >= t) }'; then
    up_streak=$((up_streak + 1))
    down_streak=0
  elif awk -v r="$ratio" -v t="$SCALE_DOWN" 'BEGIN { exit !(r <= t) }'; then
    down_streak=$((down_streak + 1))
    up_streak=0
  else
    up_streak=0
    down_streak=0
  fi

  if [[ "$cooling" -lt "$COOLDOWN" ]]; then
    continue
  fi

  if [[ "$up_streak" -ge "$UP_STREAK_NEED" && "$current" -lt "$MAX_REPLICAS" ]]; then
    scale_web $((current + 1))
    last_scale_epoch=$now
    up_streak=0
    continue
  fi

  if [[ "$down_streak" -ge "$DOWN_STREAK_NEED" && "$current" -gt "$MIN_REPLICAS" ]]; then
    scale_web $((current - 1))
    last_scale_epoch=$now
    down_streak=0
  fi
done
