#!/bin/sh
# Container start-up on Render: listen on $PORT, cache the configuration, migrate, then serve.
set -e
cd /var/www/html

PORT="${PORT:-10000}"
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:[0-9]*>/<VirtualHost *:${PORT}>/" /etc/apache2/sites-available/000-default.conf

php artisan storage:link --force >/dev/null 2>&1 || true
php artisan config:cache
php artisan route:cache
php artisan view:cache
# the caches above were written as root; Apache runs PHP as www-data
chown -R www-data:www-data storage bootstrap/cache

php artisan migrate --force

# RUN_SEED=true on the first deploy: allergens, categories, the ADMIN_EMAIL administrator
# (and demo data when SEED_DEMO=true). Set it back to false afterwards.
if [ "${RUN_SEED:-false}" = "true" ]; then
    php artisan db:seed --force
fi

exec apache2-foreground
