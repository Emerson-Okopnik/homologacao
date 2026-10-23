# Build nativo na A1 ou docker buildx build --platform linux/arm64.
FROM php:8.5.12-fpm-bookworm AS app

# OPcache já integra o PHP 8.5; não compilar como extensão compartilhada.
RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql pgsql intl zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY . .
RUN composer install --no-dev --prefer-dist --no-interaction --no-scripts --optimize-autoloader \
    && composer check-platform-reqs --no-dev \
    && mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
        storage/framework/sessions storage/framework/views storage/logs bootstrap/cache resources/views \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chown root:root /var/www/html \
    && chmod 755 /var/www/html

COPY docker/php.production.ini /usr/local/etc/php/conf.d/zz-production.ini
COPY docker/fpm.production.conf /usr/local/etc/php-fpm.d/zz-production.conf
COPY --chmod=755 docker/entrypoint.sh /usr/local/bin/homologa-entrypoint
USER www-data
ENTRYPOINT ["/usr/local/bin/homologa-entrypoint"]
CMD ["php-fpm", "-F"]

# O public/ sempre vem do mesmo estágio/release da API.
FROM caddy:2-alpine AS web
COPY --from=app /var/www/html/public /var/www/html/public
