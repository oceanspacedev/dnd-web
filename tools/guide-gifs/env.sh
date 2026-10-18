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
# Reminder e-mails (if a flow ever triggers one) go to the log, never to a mail server.
export MAIL_MAILER=log
# Per-request cache: no shared file cache with the dev server or between guides (see README).
export CACHE_STORE=array
