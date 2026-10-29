#!/usr/bin/env bash
# Execute com sudo, no Ubuntu, antes do primeiro provisionamento.
# Preserva arquivos já preenchidos; não rotaciona credenciais de um banco existente.
set -Eeuo pipefail
umask 077

if (( EUID != 0 )); then
  echo 'Execute: sudo bash scripts/prepare-production.sh' >&2
  exit 1
fi
project_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/.." && pwd -P)
cd "$project_dir"
test -f compose.production.yaml
for path in secrets data data/storage; do
  if [[ -L "$path" ]]; then
    echo "Recusando preparar diretório simbólico: $path" >&2
    exit 1
  fi
done
if [[ -e data/storage && ! -d data/storage ]]; then
  echo 'data/storage precisa ser um diretório.' >&2
  exit 1
fi
if [[ -d data/storage && -n "$(find data/storage -type l -print -quit)" ]]; then
  echo 'Remova os links simbólicos do storage antes de ajustar permissões.' >&2
  exit 1
fi

for file in .env.production .env.app .env.db .env.web; do
  if [[ -L "$file" ]]; then
    echo "Arquivo de configuração simbólico: $file" >&2
    exit 1
  fi
  if [[ ! -e "$file" ]]; then
    cp -- "$file.example" "$file"
  fi
  if [[ -L "$file" || ! -f "$file" ]]; then
    echo "Arquivo de configuração inválido: $file" >&2
    exit 1
  fi
  chown 0:0 "$file"
  chmod 600 "$file"
done

fill_empty() {
  local file=$1 key=$2 value=$3 temporary
  if grep -qx "${key}=" "$file"; then
    temporary=$(mktemp "${file}.XXXXXX")
    awk -v key="$key" -v value="$value" \
      '$0 == key "=" { print key "=" value; next } { print }' "$file" > "$temporary"
    chmod 600 "$temporary"
    mv -- "$temporary" "$file"
  fi
}
fill_empty .env.app APP_KEY "base64:$(openssl rand -base64 32)"
fill_empty .env.db POSTGRES_PASSWORD "$(openssl rand -hex 32)"

# O diretório 0700 protege os arquivos no host. 0444 permite a leitura dos mounts
# individuais pelos usuários www-data e redis dentro dos contêineres Compose.
install -d -o 0 -g 0 -m 700 secrets
for file in db_password redis_password; do
  path="secrets/${file}.txt"
  if [[ -L "$path" || ( -e "$path" && ! -f "$path" ) ]]; then
    echo "Arquivo de segredo inválido: $path" >&2
    exit 1
  fi
  if [[ ! -e "$path" ]]; then
    openssl rand -hex 32 > "$path"
  fi
  test -s "$path" || { echo "Segredo vazio: $path" >&2; exit 1; }
  [[ -n "$(cat "$path")" ]] || { echo "Segredo vazio: $path" >&2; exit 1; }
  chown 0:0 "$path"
  chmod 444 "$path"
done

# A chave da API é emitida pelo Resend. Não gerar um valor aleatório sem validade.
resend_secret=secrets/resend_api_key.txt
if [[ -L "$resend_secret" || ( -e "$resend_secret" && ! -f "$resend_secret" ) ]]; then
  echo "Arquivo de segredo inválido: $resend_secret" >&2
  exit 1
fi
if [[ ! -e "$resend_secret" ]]; then
  install -o 0 -g 0 -m 444 /dev/null "$resend_secret"
fi
chown 0:0 "$resend_secret"
chmod 444 "$resend_secret"

install -d -m 750 data
install -d -o 33 -g 33 -m 750 data/storage \
  data/storage/app/private data/storage/app/public \
  data/storage/framework/cache/data data/storage/framework/sessions \
  data/storage/framework/views data/storage/logs
chown -R 33:33 data/storage
find data/storage -type d -exec chmod 750 {} +
find data/storage -type f -exec chmod 640 {} +

echo 'Configuração preparada sem exibir segredos.'
echo 'Preencha domínios, remetente do Resend e tags da release em .env.production/.env.app/.env.web.'
echo 'Preencha secrets/resend_api_key.txt com a chave emitida pelo Resend; o arquivo novo fica vazio.'
echo 'A aplicação recusa iniciar em produção enquanto o Resend e o remetente não estiverem configurados.'
echo 'Para migração, restaure a APP_KEY existente e os documentos antes de liberar tráfego.'
