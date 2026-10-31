# Shared environment for every process that boots the app for a guide recording. Sourced (". env.sh") by
# setup-db.sh, reset.sh, serve.sh and wa-stub.sh; lib.js reads the same values for the php calls of the flows.
#
# .env holds the REAL WhatsApp gateway URL and token. phpdotenv never replaces a variable that is already set in the
# process environment, so these exports win over .env: a demo server, seeder or tinker call can only reach the local
# stub (wa-stub.php), and the dummy token is useless anywhere else. The values are forced, not defaults, so a
# WAG_URL/WAG_TOKEN exported in the shell can never leak in. guard.php checks the result before a server starts.
WA_STUB_PORT=8019
export WAG_URL="http://127.0.0.1:$WA_STUB_PORT"
export WAG_TOKEN="guide-gifs-dummy-token"
# Mail never reaches a mail server: the sendmail mailer runs mail-stub.php, which only appends the message to
# .work/mail-outbox.jsonl. A delivering mailer (not log/array) is what makes the login page show the "Email" button, as in
# production. The sourcing script must set HERE (this folder) first; lib.js passes it in. The sender is forced to a
# reserved example.com address so the real MAIL_FROM_ADDRESS in .env is never used.
if [ -z "${HERE:-}" ] || [ ! -f "$HERE/mail-stub.php" ]; then
  echo "env.sh: set HERE to the tools/guide-gifs folder before sourcing" >&2
  exit 1
fi
export MAIL_MAILER=sendmail
export MAIL_SENDMAIL_PATH="php $HERE/mail-stub.php -t -i"
export MAIL_FROM_ADDRESS="dnd-demo@example.com"
# Per-request cache: no shared file cache with the dev server or between guides (see README).
export CACHE_STORE=array
# Recordings show the app as users see it, whatever the local .env says: no Laravel Debugbar (on with APP_DEBUG=true),
# and the default checklist deadline of config/kpi.php (5 days), which the guides describe.
export DEBUGBAR_ENABLED=false
export KPI_CHECKLIST_LOCK_DAYS=5
# A DB_URL (from .env or the shell) is merged over the connection config when it opens and would replace DB_DATABASE,
# so seeding, migrate:fresh and the demo servers could hit the real database. Forced empty; demo_db.php refuses any URL.
export DB_URL=
