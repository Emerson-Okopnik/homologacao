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
if [ -n "${RESEND_API_KEY_FILE:-}" ]; then
    test -s "$RESEND_API_KEY_FILE" || { echo 'Chave da API do Resend ausente ou vazia.' >&2; exit 1; }
    RESEND_API_KEY=$(cat "$RESEND_API_KEY_FILE")
    test -n "$RESEND_API_KEY" || { echo 'Chave da API do Resend vazia.' >&2; exit 1; }
    export RESEND_API_KEY
fi

if [ "${APP_ENV:-}" = production ]; then
    test "${MAIL_MAILER:-}" = resend || { echo 'Produção exige o transporte Resend.' >&2; exit 1; }
    test -n "${RESEND_API_KEY_FILE:-}" || { echo 'Defina RESEND_API_KEY_FILE com a chave da API do Resend.' >&2; exit 1; }
    test -n "${RESEND_API_KEY:-}" || { echo 'Defina uma chave da API do Resend válida.' >&2; exit 1; }
    test -n "${MAIL_FROM_ADDRESS:-}" || { echo 'Defina o remetente de um domínio verificado no Resend.' >&2; exit 1; }
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
