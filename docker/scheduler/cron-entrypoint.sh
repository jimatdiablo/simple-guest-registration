#!/bin/sh
set -eu

mkdir -p /etc/sgr

ENV_FILE="/etc/sgr/cron-env.sh"
: > "$ENV_FILE"

printenv | while IFS='=' read -r name value; do
  case "$name" in
    ''|*[!A-Za-z0-9_]*)
      continue
      ;;
  esac

  escaped_value=$(printf "%s" "$value" | sed "s/'/'\\''/g")
  printf "export %s='%s'\n" "$name" "$escaped_value" >> "$ENV_FILE"
done

chmod 600 "$ENV_FILE"

MODEM_SYNC_CRON_EXPR="${MODEM_SYNC_CRON:-*/5 * * * *}"

cat > /etc/cron.d/sgr-auto-checkout <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

* * * * * root . /etc/sgr/cron-env.sh; php /var/www/html/bin/auto_checkout.php >> /proc/1/fd/1 2>> /proc/1/fd/2
$MODEM_SYNC_CRON_EXPR root . /etc/sgr/cron-env.sh; php /var/www/html/bin/modem_sync.php >> /proc/1/fd/1 2>> /proc/1/fd/2
EOF

chmod 0644 /etc/cron.d/sgr-auto-checkout

echo "[$(date -Iseconds)] sgr scheduler starting cron"
exec cron -f