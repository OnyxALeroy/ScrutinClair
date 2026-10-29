#!/bin/sh
set -e

# Run Symfony command to clear import logs and DB entries on container start
if [ -f /var/www/symfony/bin/console ]; then
  cd /var/www/symfony || exit 0
  # allow container startup even if DB not ready: try a few times
  tries=0
  until php bin/console app:clear-imports --no-interaction || [ $tries -ge 10 ]; do
    tries=$((tries+1))
    sleep 1
  done
fi

# Exec the default php-fpm process
exec php-fpm
