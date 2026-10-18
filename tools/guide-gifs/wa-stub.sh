#!/bin/sh
# Local WhatsApp gateway stub for the recordings (see wa-stub.php). Usage: sh wa-stub.sh   (Ctrl+C to stop)
# serve.sh starts it in the background on its own when it is not running yet. Messages land in .work/wa-outbox.jsonl.
set -eu
HERE=$(cd "$(dirname "$0")" && pwd)
. "$HERE/env.sh"
GUIDE_GIFS_WORK_DIR=${GUIDE_GIFS_WORK_DIR:-$HERE/.work}
export GUIDE_GIFS_WORK_DIR
mkdir -p "$GUIDE_GIFS_WORK_DIR"
echo "wa-stub on $WAG_URL, outbox $GUIDE_GIFS_WORK_DIR/wa-outbox.jsonl"
exec php -S "127.0.0.1:$WA_STUB_PORT" -t "$HERE" "$HERE/wa-stub.php"
