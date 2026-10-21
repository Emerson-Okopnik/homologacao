#!/bin/sh
set -eu
umask 0027

if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    test -s "$DB_PASSWORD_FILE" || { echo 'Segredo do banco ausente ou vazio.' >&2; exit 1; }
    DB_PASSWORD=$(cat "$DB_PASSWORD_FILE")
    test -n "$DB_PASSWORD" || { echo 'Segredo do banco vazio.' >&2; exit 1; }
    export DB_PASSWORD
fi
if [ -n "${REDIS_PASSWORD_FILE:-}" ]; then
    test -s "$REDIS_PASSWORD_FILE" || { echo 'Segredo do Redis ausente ou vazio.' >&2; exit 1; }
    REDIS_PASSWORD=$(cat "$REDIS_PASSWORD_FILE")
    test -n "$REDIS_PASSWORD" || { echo 'Segredo do Redis vazio.' >&2; exit 1; }
    export REDIS_PASSWORD
fi

test -n "${APP_KEY:-}" || { echo 'Defina APP_KEY em .env.app antes de iniciar.' >&2; exit 1; }
if [ "${DB_USERNAME:-}" = postgres ]; then
    echo 'A API deve usar um papel PostgreSQL sem privilégios administrativos.' >&2
    exit 1
fi

mkdir -p storage/app/private storage/app/public storage/framework/cache/data \
    storage/framework/sessions storage/framework/views storage/logs
php artisan package:discover --ansi
php artisan config:cache
php artisan route:cache
exec "$@"
