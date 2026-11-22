# syntax=docker/dockerfile:1.7
#
# PriceLens API image (PHP-FPM). The same image runs: api (php-fpm), horizon, scheduler, reverb, migrate.
# Build context: repository root.
#   docker build -f docker/php/Dockerfile -t pricelens-api .

ARG PHP_IMAGE=php:8.4-fpm-bookworm

# ---- vendor: production composer dependencies ------------------------------
FROM composer:2.8 AS vendor
WORKDIR /app
COPY api/composer.json api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs
COPY api/ ./
RUN composer dump-autoload --optimize --classmap-authoritative --no-dev

# ---- runtime ---------------------------------------------------------------
FROM ${PHP_IMAGE} AS runtime

RUN apt-get update && apt-get install -y --no-install-recommends \
        libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev unzip \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql intl zip gd pcntl bcmath opcache sockets \
    && pecl install redis-6.2.0 \
    && docker-php-ext-enable redis \
    && apt-get purge -y --auto-remove libicu-dev libzip-dev libpng-dev libjpeg62-turbo-dev libwebp-dev libfreetype6-dev \
    && apt-get install -y --no-install-recommends libicu72 libzip4 libpng16-16 libjpeg62-turbo libwebp7 libfreetype6 \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-pricelens.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-pricelens.conf

ENV PHP_OPCACHE_VALIDATE_TIMESTAMPS=0
WORKDIR /var/www/api
COPY --from=vendor --chown=www-data:www-data /app /var/www/api
RUN rm -f .env database/database.sqlite \
    && mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views storage/logs storage/app/private bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

USER www-data
EXPOSE 9000
CMD ["php-fpm", "-F"]
