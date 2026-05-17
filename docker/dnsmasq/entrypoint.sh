#!/bin/sh
set -eu

POISON_DNS_TARGET_IP="${POISON_DNS_TARGET_IP:-192.168.160.4}"
POISON_DNS_UPSTREAM_SERVERS="${POISON_DNS_UPSTREAM_SERVERS:-1.1.1.1,8.8.8.8}"
DNSMASQ_CONF="/tmp/sgr-dnsmasq.conf"

cat > "$DNSMASQ_CONF" <<EOF
port=53
domain-needed
bogus-priv
no-resolv
filter-AAAA
log-queries
log-facility=-
address=/#/${POISON_DNS_TARGET_IP}
address=/registration.local/${POISON_DNS_TARGET_IP}
EOF

old_ifs="$IFS"
IFS=","
for upstream in $POISON_DNS_UPSTREAM_SERVERS; do
    upstream="$(printf '%s' "$upstream" | sed 's/^ *//;s/ *$//')"
    if [ -n "$upstream" ]; then
        printf 'server=%s\n' "$upstream" >> "$DNSMASQ_CONF"
    fi
done
IFS="$old_ifs"

exec dnsmasq --no-daemon --conf-file="$DNSMASQ_CONF"
