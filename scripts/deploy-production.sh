#!/usr/bin/env bash
# Install as /usr/local/sbin/homologa-deploy on the production host.
# This host-side copy must be owned by root and must not be writable by deploy.
set -Eeuo pipefail
umask 077

readonly PROJECT_DIR=/opt/verigrid
readonly DEPLOY_USER=deploy
readonly BACKUP_COMMAND=/usr/local/sbin/homologa-backup
readonly LOCK_FILE=/run/lock/homologa-deploy.lock

fail() {
  printf 'Deploy failed: %s\n' "$*" >&2
  exit 1
}

[[ $EUID -eq 0 ]] || fail 'run this command through sudo'
[[ $# -eq 1 ]] || fail 'usage: homologa-deploy COMMIT_SHA'
readonly requested_sha=$1
[[ $requested_sha =~ ^[0-9a-f]{40}$ ]] || fail 'the release must be a full Git commit SHA'

for program in awk curl docker flock git install mktemp runuser; do
  command -v "$program" >/dev/null || fail "missing command: $program"
done
[[ -d $PROJECT_DIR/.git ]] || fail "repository not found at $PROJECT_DIR"
id "$DEPLOY_USER" >/dev/null 2>&1 || fail "user not found: $DEPLOY_USER"
[[ -x $BACKUP_COMMAND ]] || fail "backup command not found: $BACKUP_COMMAND"

exec 9>"$LOCK_FILE"
flock -n 9 || fail 'another production deployment is running'

git_as_deploy=(runuser -u "$DEPLOY_USER" -- git -C "$PROJECT_DIR")
compose=(docker compose --env-file .env.production -f compose.production.yaml)
rollback_env=''
previous_sha=''
maintenance_started=false
release_activated=false
deployment_succeeded=false

read_env_value() {
  local file=$1 key=$2
  awk -v key="$key" '
    index($0, key "=") == 1 { print substr($0, length(key) + 2); found = 1; exit }
    END { if (!found) exit 1 }
  ' "$file"
}

image_repository() {
  local reference=${1%@*} tail
  tail=${reference##*/}
  if [[ $tail == *:* ]]; then
    printf '%s\n' "${reference%:*}"
  else
    printf '%s\n' "$reference"
  fi
}

write_release_env() {
  local source=$1 app_image=$2 web_image=$3 temporary
  temporary=$(mktemp "$PROJECT_DIR/.env.production.deploy.XXXXXXXX")
  if ! awk -v app="$app_image" -v web="$web_image" '
      /^APP_IMAGE=/ { print "APP_IMAGE=" app; app_seen = 1; next }
      /^WEB_IMAGE=/ { print "WEB_IMAGE=" web; web_seen = 1; next }
      { print }
      END { if (!app_seen || !web_seen) exit 1 }
    ' "$source" > "$temporary"; then
    rm -f -- "$temporary"
    fail 'APP_IMAGE or WEB_IMAGE is absent from .env.production'
  fi
  chown --reference="$source" "$temporary"
  chmod --reference="$source" "$temporary"
  mv -- "$temporary" "$source"
}

finish_deployment() {
  local exit_code=$?
  trap - EXIT INT TERM
  if [[ $deployment_succeeded == true ]]; then
    [[ -z $rollback_env ]] || rm -f -- "$rollback_env"
    return 0
  fi

  set +e
  printf 'Deployment of %s failed; restoring the previous application release.\n' "$requested_sha" >&2

  if [[ -n $rollback_env && -f $rollback_env ]]; then
    cp -a -- "$rollback_env" "$PROJECT_DIR/.env.production"
  fi
  if [[ -n $previous_sha ]]; then
    "${git_as_deploy[@]}" switch --detach --force "$previous_sha" >/dev/null 2>&1 || true
  fi
  if [[ $maintenance_started == true || $release_activated == true ]]; then
    (
      cd "$PROJECT_DIR"
      "${compose[@]}" up -d --no-deps --force-recreate app caddy worker scheduler
      "${compose[@]}" exec -T app php artisan up
    ) || printf 'Automatic application rollback failed; operator intervention is required.\n' >&2
  fi
  [[ -z $rollback_env ]] || rm -f -- "$rollback_env"
  exit "$exit_code"
}
trap finish_deployment EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

cd "$PROJECT_DIR"
[[ -f .env.production ]] || fail '.env.production is missing'
[[ ! -L .env.production ]] || fail '.env.production must not be a symbolic link'
"${git_as_deploy[@]}" diff --quiet || fail 'tracked production files have local modifications'
"${git_as_deploy[@]}" diff --cached --quiet || fail 'the production checkout has staged changes'

previous_sha=$("${git_as_deploy[@]}" rev-parse --verify HEAD)
rollback_env=$(mktemp /tmp/homologa-production-env.XXXXXXXX)
cp -a -- .env.production "$rollback_env"

"${git_as_deploy[@]}" fetch --prune origin main
target_sha=$("${git_as_deploy[@]}" rev-parse --verify "${requested_sha}^{commit}")
[[ $target_sha == "$requested_sha" ]] || fail 'the requested commit could not be verified'
"${git_as_deploy[@]}" merge-base --is-ancestor "$target_sha" refs/remotes/origin/main \
  || fail 'the requested commit does not belong to origin/main'
"${git_as_deploy[@]}" switch --detach "$target_sha"

"${compose[@]}" config --quiet
current_app_image=$(read_env_value .env.production APP_IMAGE)
current_web_image=$(read_env_value .env.production WEB_IMAGE)
app_repository=$(image_repository "$current_app_image")
web_repository=$(image_repository "$current_web_image")
[[ -n $app_repository && -n $web_repository ]] || fail 'invalid production image names'

release=${target_sha:0:12}
app_image="${app_repository}:${release}"
web_image="${web_repository}:${release}"

# Build while the current release is still serving traffic.
docker build --pull --target app --tag "$app_image" backend
docker build --pull --target web --tag "$web_image" backend
docker run --rm --network none --entrypoint composer "$app_image" check-platform-reqs --no-dev

running_services=$("${compose[@]}" ps --status running --services)
grep -qx app <<< "$running_services" || fail 'the production app service is not running'
grep -qx db <<< "$running_services" || fail 'the production database is not running'
grep -qx redis <<< "$running_services" || fail 'the production Redis service is not running'

# Stop application writes before the mandatory off-site backup and migration.
"${compose[@]}" exec -T app php artisan down --retry=60
maintenance_started=true
"${compose[@]}" stop scheduler worker
"$BACKUP_COMMAND"

write_release_env .env.production "$app_image" "$web_image"
"${compose[@]}" config --quiet
"${compose[@]}" run --rm --no-deps app php artisan migrate --force
"${compose[@]}" up -d --no-deps --force-recreate app caddy worker scheduler
release_activated=true
"${compose[@]}" exec -T app php artisan up

app_url=$(read_env_value .env.app APP_URL)
app_url=${app_url%\"}
app_url=${app_url#\"}
[[ $app_url == https://* ]] || fail 'APP_URL must use HTTPS in production'
ready_url="${app_url%/}/ready"

healthy=false
for attempt in {1..30}; do
  if curl --fail --silent --show-error --max-time 10 "$ready_url" >/dev/null; then
    healthy=true
    break
  fi
  sleep 5
done
[[ $healthy == true ]] || fail "readiness check failed: $ready_url"

running_services=$("${compose[@]}" ps --status running --services)
for service in app caddy worker scheduler db redis; do
  grep -qx "$service" <<< "$running_services" || fail "service is not running: $service"
done

deployment_succeeded=true
printf 'Production release deployed successfully: %s\n' "$target_sha"

