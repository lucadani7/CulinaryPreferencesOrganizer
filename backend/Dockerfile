# Production image for Render (local development uses Laravel Sail, see compose.yaml).
# Apache + mod_php serves public/; docker/start.sh prepares the app and runs the migrations.

# ---------- 1. PHP dependencies, without dev packages
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY . .
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev --no-scripts

# ---------- 2. Runtime
FROM php:8.5-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql intl zip gd bcmath \
    # OPcache is built into PHP 8.5; older images need the extension installed
    && (php -m | grep -qi '^Zend OPcache$' || docker-php-ext-install opcache) \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/vhost.conf /etc/apache2/sites-available/000-default.conf

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html
RUN php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs storage/app/public \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod +x docker/start.sh

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    PORT=10000

EXPOSE 10000
CMD ["docker/start.sh"]
