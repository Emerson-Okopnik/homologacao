#!/usr/bin/env bash
# Ubuntu host only. Config is a trusted shell file, owned by the invoking user.
# Online DB + files are separate snapshots; pause writes/jobs for a coordinated capture.
set -Eeuo pipefail
umask 077

fail() { printf '%s\n' "$*" >&2; exit 1; }
config=${1:-/etc/homologa-backup.conf}
[[ $# -le 1 ]] || fail 'Usage: backup.sh [config-file]'
[[ -f "$config" && ! -L "$config" ]] || fail 'Backup configuration must be a regular file.'
[[ $(stat -c %u "$config") == "$EUID" ]] || fail 'Configuration must belong to the invoking user.'
mode=$(stat -c %a "$config")
(( (8#$mode & 0022) == 0 )) || fail 'Configuration must not be writable by group or others.'
# shellcheck source=/dev/null
source "$config"
: "${PROJECT_DIR:?}" "${AGE_RECIPIENT:?}" "${OCI_NAMESPACE:?}" "${OCI_BUCKET:?}"
: "${OCI_REGION:?}" "${OCI_BIN:?}" "${BACKUP_ROOT:?}" "${MAX_ARCHIVE_BYTES:?}"
LOCAL_RETENTION_DAYS=${LOCAL_RETENTION_DAYS:-2}
[[ $MAX_ARCHIVE_BYTES =~ ^[1-9][0-9]*$ && $LOCAL_RETENTION_DAYS =~ ^[0-9]+$ ]] || fail 'Invalid size or retention limit.'
for program in docker age tar sha256sum openssl git python3 flock stat find realpath; do
  command -v "$program" >/dev/null || fail "Missing command: $program"
done
[[ -x "$OCI_BIN" ]] || fail 'OCI_BIN must identify the installed OCI CLI.'
[[ "$PROJECT_DIR" = /* && "$BACKUP_ROOT" = /* && "$BACKUP_ROOT" != / ]] || fail 'Use absolute project and backup paths.'
cd "$PROJECT_DIR"
project=$(pwd -P)
[[ ! -L "$BACKUP_ROOT" ]] || fail 'BACKUP_ROOT cannot be a symbolic link.'
backup_root=$(realpath -m -- "$BACKUP_ROOT")
[[ "$backup_root" != / ]] || fail 'BACKUP_ROOT cannot be the filesystem root.'
case "$backup_root/" in "$project/"*) fail 'Keep backups outside the project tree.' ;; esac
case "$project/" in "$backup_root/"*) fail 'BACKUP_ROOT cannot be an ancestor of the project.' ;; esac
if [[ -d "$BACKUP_ROOT" ]]; then
  [[ $(stat -c %u "$BACKUP_ROOT") == "$EUID" ]] || fail 'BACKUP_ROOT must belong to the invoking user.'
  chmod 700 "$BACKUP_ROOT"
else
  install -d -m 700 "$BACKUP_ROOT"
fi
[[ $(cd "$BACKUP_ROOT" && pwd -P) == "$backup_root" ]] || fail 'BACKUP_ROOT changed while preparing the backup.'
[[ ! -L "$backup_root/.backup.lock" ]] || fail 'Backup lock cannot be a symbolic link.'
exec 9>"$backup_root/.backup.lock"
flock -n 9 || fail 'Another backup is running.'
compose=(docker compose --env-file .env.production -f compose.production.yaml)
required=(.env.production .env.app .env.db .env.web compose.production.yaml infra/Caddyfile secrets/db_password.txt secrets/redis_password.txt)
for file in "${required[@]}"; do
  [[ -s "$file" && ! -L "$file" ]] || fail "Missing/empty or symbolic configuration file: $file"
done
[[ -d data/storage/app/private ]] || fail 'Persistent private upload directory is missing.'
links=$(find data/storage/app infra secrets -type l -print -quit)
[[ -z "$links" ]] || fail 'Uploads/configuration contain a symbolic link; resolve it before backing up.'
"${compose[@]}" config --quiet

work=$(mktemp -d "$backup_root/.work.XXXXXXXX")
partial=''
cleanup() {
  # Both targets were created inside the verified backup root by this invocation.
  [[ -z "$partial" ]] || rm -f -- "$partial"
  [[ ! -d "$work" ]] || rm -rf -- "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
trap 'printf "%s\n" "Backup failed. Review the journal, success marker and remote object state." >&2' ERR
stamp=$(date -u +%Y%m%dT%H%M%SZ)
name="homologa-${stamp}-$(openssl rand -hex 8).tar.gz.age"
archive="$backup_root/$name"
partial="$archive.partial"

db_name=$("${compose[@]}" exec -T db sh -ec 'printf "%s" "$POSTGRES_DB"')
[[ -n "$db_name" ]] || fail 'POSTGRES_DB is empty.'
"${compose[@]}" exec -T db sh -ec 'exec pg_dump -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Fc --no-owner --no-acl' > "$work/database.dump"
[[ -s "$work/database.dump" ]] || fail 'Database dump is empty.'
"${compose[@]}" exec -T db pg_restore --list < "$work/database.dump" > "$work/database.contents.txt"
tar -czf "$work/uploads.tar.gz" -C data/storage app
# Configs, APP_KEY and DB/Redis secrets are plaintext only in this mode-0700 workspace.
# The host stores only the public age recipient. Keep the private identity outside OCI.
tar -czf "$work/config.tar.gz" -- .env.production .env.app .env.db .env.web compose.production.yaml infra secrets
git -c safe.directory="$project" rev-parse HEAD > "$work/checkout.txt"
"${compose[@]}" images --format json > "$work/running-images.txt"
containers_list=$("${compose[@]}" ps --all --quiet)
mapfile -t containers <<< "$containers_list"
[[ -n "$containers_list" ]] || fail 'No production containers were found.'
[[ ${#containers[@]} -gt 0 ]] || fail 'No production containers were found.'
docker inspect --format '{{.Name}} {{.Config.Image}} {{.Image}}' "${containers[@]}" > "$work/running-containers.txt"
python3 - "$work" "$stamp" "$db_name" <<'PY'
import json, pathlib, sys
work, timestamp, database = sys.argv[1:]
pathlib.Path(work, 'manifest.json').write_text(json.dumps({
    'format_version': 1, 'created_utc': timestamp, 'database': database,
    'database_format': 'PostgreSQL custom dump',
    'storage_path': 'data/storage/app', 'contains_secrets': True,
    'consistency': 'Online database and uploads captured separately; not an atomic snapshot.',
}, indent=2) + '\n', encoding='utf-8')
PY
(cd "$work" && sha256sum database.dump database.contents.txt uploads.tar.gz config.tar.gz checkout.txt running-images.txt running-containers.txt manifest.json > SHA256SUMS)
tar -C "$work" -czf - . | age -r "$AGE_RECIPIENT" -o "$partial"
size=$(stat -c %s "$partial")
(( size > 0 && size <= MAX_ARCHIVE_BYTES )) || fail 'Encrypted archive exceeds MAX_ARCHIVE_BYTES; review size, retention and cost.'
mv -- "$partial" "$archive"
partial=''
md5=$(openssl dgst -md5 -binary "$archive" | openssl base64 -A)
oci_args=(--auth instance_principal --output json --region "$OCI_REGION" --namespace-name "$OCI_NAMESPACE" --bucket-name "$OCI_BUCKET" --name "$name")
"$OCI_BIN" os object put "${oci_args[@]}" --file "$archive" --no-multipart --no-overwrite --verify-checksum --content-md5 "$md5" > "$work/upload.json"
"$OCI_BIN" os object head "${oci_args[@]}" > "$work/remote.json"
python3 - "$work/upload.json" "$work/remote.json" "$size" "$md5" <<'PY'
import json, sys
uploaded = json.load(open(sys.argv[1], encoding='utf-8'))
remote = json.load(open(sys.argv[2], encoding='utf-8'))
if uploaded.get('opc-content-md5') != sys.argv[4]:
    raise SystemExit('Upload checksum was not confirmed; success marker was not updated.')
if int(remote.get('content-length', -1)) != int(sys.argv[3]) or remote.get('content-md5') != sys.argv[4]:
    raise SystemExit('Remote size/checksum differs; success marker was not updated.')
PY
(cd "$backup_root" && sha256sum "$name") > "$archive.sha256"
# Only encrypted, project-prefixed local copies are pruned after a verified upload.
# Bucket lifecycle and the independent copy are managed by a separate identity.
find "$backup_root" -maxdepth 1 -type f -name 'homologa-*.tar.gz.age' -mtime "+$LOCAL_RETENTION_DAYS" -print0 |
  while IFS= read -r -d '' old; do rm -f -- "$old" "$old.sha256"; done
printf '%s %s\n' "$(date -u +%FT%TZ)" "$name" > "$backup_root/.last-success.tmp"
mv -- "$backup_root/.last-success.tmp" "$backup_root/last-success.txt"
printf 'Verified backup uploaded: %s (%s bytes)\n' "$name" "$size"
