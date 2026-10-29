#!/bin/sh
# Executado pela imagem PostgreSQL somente durante a criação de um volume vazio.
set -eu

: "${POSTGRES_USER:?}" "${POSTGRES_DB:?}" "${APP_DB_USERNAME:?}" "${APP_DB_PASSWORD_FILE:?}"
if [ "$APP_DB_USERNAME" = "$POSTGRES_USER" ]; then
    echo 'O usuário da aplicação deve ser diferente do administrador PostgreSQL.' >&2
    exit 1
fi
test -s "$APP_DB_PASSWORD_FILE"
app_password=$(cat "$APP_DB_PASSWORD_FILE")
test -n "$app_password" || { echo 'Segredo do banco vazio.' >&2; exit 1; }

psql --no-psqlrc --set ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set app_user="$APP_DB_USERNAME" --set app_password="$app_password" --set app_database="$POSTGRES_DB" <<'SQL'
CREATE ROLE :"app_user" LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION PASSWORD :'app_password';
ALTER DATABASE :"app_database" OWNER TO :"app_user";
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE, CREATE ON SCHEMA public TO :"app_user";
SQL
