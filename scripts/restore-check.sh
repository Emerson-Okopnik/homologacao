#!/usr/bin/env bash
# Decrypt on a recovery workstation, never on the production VM. No Compose calls.
# Restores only to a new disposable PostgreSQL container without network/host ports.
set -Eeuo pipefail
umask 077
fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $# == 2 ]] || fail 'Usage: restore-check.sh ARCHIVE.tar.gz.age AGE_IDENTITY_FILE'
archive=$1
identity=$2
[[ -s "$archive" && -s "$identity" ]] || fail 'Archive and private age identity are required.'
for program in age docker python3 openssl mktemp; do
  command -v "$program" >/dev/null || fail "Missing command: $program"
done
image=${RESTORE_POSTGRES_IMAGE:-postgres:17-bookworm}
[[ "$image" == postgres:17-bookworm || "$image" == postgres:17-bookworm@sha256:* ]] || fail 'Use PostgreSQL 17 bookworm, optionally pinned by digest.'
work=$(mktemp -d "${TMPDIR:-/tmp}/homologa-restore.XXXXXXXX")
token=$(openssl rand -hex 12)
container="homologa-restore-$token"
volume="homologa-restore-$token"
container_created=0
volume_created=0
cleanup() {
  # Never address production volumes or containers; remove only exact IDs we created.
  if (( container_created )); then docker rm --force "$container" >/dev/null || true; fi
  if (( volume_created )); then docker volume rm "$volume" >/dev/null || true; fi
  [[ ! -d "$work" ]] || rm -rf -- "$work"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
age --decrypt -i "$identity" -o "$work/payload.tar.gz" "$archive"
python3 - "$work" <<'PY'
import hashlib, json, pathlib, re, sys, tarfile
work = pathlib.Path(sys.argv[1])
expected = {'database.dump', 'database.contents.txt', 'uploads.tar.gz', 'config.tar.gz',
            'checkout.txt', 'running-images.txt', 'running-containers.txt', 'manifest.json', 'SHA256SUMS'}
with tarfile.open(work / 'payload.tar.gz', 'r:gz') as archive:
    found = set()
    for member in archive.getmembers():
        name = member.name.removeprefix('./')
        if name in ('', '.') and member.isdir():
            continue
        if name not in expected or not member.isfile() or name in found:
            raise SystemExit('Unsafe or unexpected backup archive member: ' + member.name)
        found.add(name)
        with archive.extractfile(member) as source, (work / name).open('wb') as target:
            import shutil
            shutil.copyfileobj(source, target)
    if found != expected:
        raise SystemExit('Incomplete backup archive.')
checks = {}
for line in (work / 'SHA256SUMS').read_text(encoding='utf-8').splitlines():
    match = re.fullmatch(r'([0-9a-f]{64})  ([a-zA-Z0-9.-]+)', line)
    if not match or match[2] in checks:
        raise SystemExit('Invalid checksum manifest.')
    checks[match[2]] = match[1]
if set(checks) != expected - {'SHA256SUMS'}:
    raise SystemExit('Checksum manifest does not cover the complete backup.')
for name, checksum in checks.items():
    digest = hashlib.sha256()
    with (work / name).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    if digest.hexdigest() != checksum:
        raise SystemExit('Checksum mismatch: ' + name)
manifest = json.loads((work / 'manifest.json').read_text(encoding='utf-8'))
if manifest.get('format_version') != 1:
    raise SystemExit('Unsupported backup format.')
# Nested uploads/configs are validated without extracting secrets or files into host paths.
for filename in ('uploads.tar.gz', 'config.tar.gz'):
    with tarfile.open(work / filename, 'r:gz') as nested:
        names = set()
        for member in nested.getmembers():
            name = member.name.removeprefix('./')
            path = pathlib.PurePosixPath(name)
            if path.is_absolute() or '..' in path.parts or not (member.isfile() or member.isdir()):
                raise SystemExit('Unsafe nested archive member: ' + member.name)
            names.add(name.rstrip('/'))
            if member.isfile():
                with nested.extractfile(member) as stream:
                    while stream.read(1024 * 1024):
                        pass
        required = {'app', 'app/private'} if filename == 'uploads.tar.gz' else {
            '.env.production', '.env.app', '.env.db', '.env.web', 'compose.production.yaml',
            'infra/Caddyfile', 'secrets/db_password.txt', 'secrets/redis_password.txt'}
        if not required <= names:
            raise SystemExit('Missing uploads/configuration in ' + filename)
print('All payload hashes and upload/configuration archives are valid.')
PY
# Refuse collisions. Creation flags become true only after creation succeeds.
if docker container inspect "$container" >/dev/null 2>&1 || docker volume inspect "$volume" >/dev/null 2>&1; then
  fail 'Disposable resource name collision; run again.'
fi
docker volume create --label "homologa.restore-check=$token" "$volume" >/dev/null
[[ $(docker volume inspect --format '{{index .Labels "homologa.restore-check"}}' "$volume") == "$token" ]] || fail 'Disposable volume ownership was not confirmed.'
volume_created=1
docker create --name "$container" --label "homologa.restore-check=$token" \
  --network none --memory 2g --cpus 1 --mount "type=volume,src=$volume,dst=/var/lib/postgresql/data" \
  -e POSTGRES_HOST_AUTH_METHOD=trust "$image" >/dev/null
container_created=1
docker start "$container" >/dev/null
ready=0
for (( attempt=0; attempt<60; attempt++ )); do
  # The image's temporary initialization server listens only on a Unix socket.
  # TCP loopback becomes ready only after initialization and the final restart.
  if docker exec "$container" pg_isready -h 127.0.0.1 -U postgres >/dev/null 2>&1; then ready=1; break; fi
  sleep 1
done
(( ready )) || fail 'Disposable PostgreSQL did not become ready in 60 seconds.'
docker exec -i "$container" pg_restore --list < "$work/database.dump" > /dev/null
docker exec "$container" createdb -U postgres restore_check
docker exec -i "$container" pg_restore -U postgres -d restore_check --no-owner --no-acl --exit-on-error < "$work/database.dump"
tables=$(docker exec "$container" psql -U postgres -d restore_check -Atc "SELECT count(*) FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema') AND table_type = 'BASE TABLE';")
[[ $tables =~ ^[0-9]+$ ]] && (( tables > 0 )) || fail 'No application tables were restored.'
printf 'Isolated restore passed: %s application tables; uploads/configs and hashes verified.\n' "$tables"
printf '%s\n' 'Record business row counts and test application behavior separately; this check does not measure full recovery time.'
