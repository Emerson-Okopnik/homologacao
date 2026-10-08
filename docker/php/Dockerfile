FROM php:8.4-fpm-alpine

ARG UID=1000
ARG GID=1000

RUN apk add --no-cache \
        bash git unzip icu-libs libzip libpq postgresql-client \
    && apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS icu-dev libzip-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql pgsql intl zip bcmath opcache pcntl \
    && pecl install redis pcov \
    && docker-php-ext-enable redis pcov \
    && apk del .build-deps

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php.ini /usr/local/etc/php/conf.d/zz-homologa.ini

RUN addgroup -g "${GID}" app && adduser -D -u "${UID}" -G app app

USER app
WORKDIR /var/www/backend

CMD ["php-fpm"]
