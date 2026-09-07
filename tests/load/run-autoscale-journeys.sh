#!/usr/bin/env bash
# Run realistic user-journey k6 while docker/autoscale-web.sh scales `web`.
# Expects the scaled Compose stack to already be healthy on BASE_URL.
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
OUT=${K6_RESULTS_DIR:-/tmp/dnd-k6-results}
BASE_URL=${BASE_URL:-http://127.0.0.1:8080}
TOKENS=${TOKENS_FILE:-$OUT/k6-tokens.env}
STATUS_FILE=${SCALE_STATUS_FILE:-/tmp/dnd-autoscale-status.log}
COMPOSE_ENV_FILE=${COMPOSE_ENV_FILE:-/tmp/dnd-web-local.env}
PROJECT=${COMPOSE_PROJECT_NAME:-dnd-web-local}

mkdir -p "$OUT"
chmod 0777 "$OUT"

if [[ ! -f "$TOKENS" ]]; then
  echo "missing $TOKENS" >&2
  exit 1
fi

set -a
# shellcheck disable=SC1090
source "$TOKENS"
set +a

export COMPOSE_ENV_FILE PROJECT SCALE_STATUS_FILE="$STATUS_FILE"
export MIN_WEB_REPLICAS=${MIN_WEB_REPLICAS:-1}
export MAX_WEB_REPLICAS=${MAX_WEB_REPLICAS:-3}
export WEB_CPU_LIMIT=${WEB_CPU_LIMIT:-1}

: > "$STATUS_FILE"
"$ROOT/docker/autoscale-web.sh" >"$OUT/autoscale.stdout.log" 2>"$OUT/autoscale.log" &
SCALER_PID=$!
cleanup() {
  kill "$SCALER_PID" 2>/dev/null || true
  wait "$SCALER_PID" 2>/dev/null || true
}
trap cleanup EXIT INT TERM

sleep 2

status=0
docker run --rm --network host \
  -v "$ROOT/tests/load:/scripts:ro" \
  -v "$OUT:/results" \
  -e BASE_URL="$BASE_URL" \
  -e ADMIN_TOKEN="$ADMIN_TOKEN" \
  -e MANAGER_TOKEN="${MANAGER_TOKEN:-$ADMIN_TOKEN}" \
  -e STAFF_TOKENS="$STAFF_TOKENS" \
  -e PANEL_USER="${PANEL_USER:-admin}" \
  -e PANEL_PASSWORD="${PANEL_PASSWORD:-complete123}" \
  -e THINK_MIN="${THINK_MIN:-0.6}" \
  -e THINK_MAX="${THINK_MAX:-1.8}" \
  -e PROFILE="${PROFILE:-office}" \
  grafana/k6:latest run \
    --summary-export /results/user-journeys.json \
    /scripts/k6-user-journeys.js || status=$?

echo "k6_exit=$status" | tee "$OUT/k6-exit-codes.txt"
cp "$STATUS_FILE" "$OUT/autoscale-status.log" 2>/dev/null || true

COMPOSE_DISABLE_ENV_FILE=1 docker compose \
  --env-file "$COMPOSE_ENV_FILE" \
  -f "$ROOT/compose.scale.yaml" \
  -f "$ROOT/compose.scale.local.yaml" \
  --project-name "$PROJECT" \
  ps >"$OUT/compose-ps.txt" || true

exit 0
