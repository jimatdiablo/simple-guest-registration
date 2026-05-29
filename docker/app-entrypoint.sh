#!/bin/sh
set -eu

if [ "${SGR_RUN_MIGRATIONS:-true}" != "false" ]; then
  php /var/www/html/bin/migrate.php
fi

exec docker-php-entrypoint "$@"
