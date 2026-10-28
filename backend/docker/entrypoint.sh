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
if [ -n "${MAIL_PASSWORD_FILE:-}" ]; then
    test -s "$MAIL_PASSWORD_FILE" || { echo 'Segredo SMTP ausente ou vazio.' >&2; exit 1; }
    MAIL_PASSWORD=$(cat "$MAIL_PASSWORD_FILE")
    test -n "$MAIL_PASSWORD" || { echo 'Segredo SMTP vazio.' >&2; exit 1; }
    export MAIL_PASSWORD
fi

if [ "${APP_ENV:-}" = production ]; then
    test "${MAIL_MAILER:-}" = smtp || { echo 'Produção exige envio SMTP autenticado.' >&2; exit 1; }
    test -n "${MAIL_PASSWORD_FILE:-}" || { echo 'Defina MAIL_PASSWORD_FILE com o segredo SMTP.' >&2; exit 1; }
    test -n "${MAIL_HOST:-}" && test -n "${MAIL_USERNAME:-}" && test -n "${MAIL_FROM_ADDRESS:-}" || {
        echo 'Defina servidor, usuário SMTP e remetente em .env.app.' >&2; exit 1;
    }
    test -z "${MAIL_URL:-}" || { echo 'Configure SMTP pelas variáveis MAIL_*, sem MAIL_URL.' >&2; exit 1; }
    test "${MAIL_REQUIRE_TLS:-}" = true || { echo 'Produção exige MAIL_REQUIRE_TLS=true.' >&2; exit 1; }
    case "${MAIL_SCHEME:-}" in smtp|smtps) ;; *) echo 'MAIL_SCHEME deve ser smtp (STARTTLS) ou smtps (TLS implícito).' >&2; exit 1 ;; esac
    case "${MAIL_TIMEOUT:-}" in ''|*[!0-9]*) echo 'MAIL_TIMEOUT deve ser um inteiro entre 1 e 20 segundos.' >&2; exit 1 ;; esac
    test "$MAIL_TIMEOUT" -ge 1 && test "$MAIL_TIMEOUT" -le 20 || {
        echo 'MAIL_TIMEOUT deve estar entre 1 e 20 segundos.' >&2; exit 1;
    }
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
