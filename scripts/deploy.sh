#!/usr/bin/env bash
# PriceLens release for a docker-compose host (spec §21 "Release Workflow").
#
#   IMAGE_TAG=<git sha> IMAGE_REGISTRY=ghcr.io/<owner> \
#   CANARY_API_KEY=pl_live_... CANARY_REFERENCE_URL=https://fixture.example/p/canary \
#   SMOKE_BASE_URL=https://api.pricelens.example \
#   scripts/deploy.sh
#
# Steps:
#   1. pull the tagged images (and retag ghcr.io/<owner>/pricelens-<name>:TAG as pricelens/<name>:TAG for compose)
#   2. php artisan migrate --force (expand/contract migrations only), in a one-off container on the new image
#   3. php artisan optimize: once in a one-off container as a pre-flight, then in every new PHP container
#   4. rolling restart of api, web, renderer: per container, start a new one next to it, wait until healthy,
#      then remove the old one
#   5. php artisan horizon:terminate (graceful), then recreate horizon + scheduler on the new image
#   6. php artisan reverb:restart, then recreate reverb; recreate nginx (new public assets)
#   7. smoke test: GET /api/v1/ready, plus a canary search that must reach `completed` with >= 1 offer in time
# Any failure exits non-zero and prints how to roll back.
#
# Environment:
#   IMAGE_TAG             (required) tag to deploy, e.g. the commit SHA CI pushed
#   IMAGE_REGISTRY        registry prefix; images are $IMAGE_REGISTRY/pricelens-{api,nginx,renderer,web}:$IMAGE_TAG.
#                         Empty = `docker compose pull` of the compose image names (pricelens/<name>:$IMAGE_TAG)
#   COMPOSE_FILE          compose files, ':'-separated (default docker-compose.yml:docker-compose.prod.yml)
#   SMOKE_BASE_URL        where nginx answers /api/v1 (default http://localhost:${HTTP_PORT:-8080})
#   SMOKE_INSECURE        1 = curl -k (self-signed staging certs)
#   CANARY_API_KEY        tenant API key for the canary search (required unless SKIP_CANARY=1)
#   CANARY_REFERENCE_URL  product page of a fixture site the canary searches for (required unless SKIP_CANARY=1)
#   CANARY_DOMAINS        optional comma list of domains for the canary search
#   CANARY_TIMEOUT        seconds to reach `completed` (default 60)
#   ROLL_SERVICES         services rolled one container at a time (default "api web renderer")
#   HEALTH_TIMEOUT        seconds a new container may take to become healthy (default 120)
#   STOP_TIMEOUT          seconds given to an old container to stop (default 30)
#   DRAIN_SECONDS         wait between a new container turning healthy and stopping the old one (default 11,
#                         just over nginx's 10 s resolver TTL)
#   PERSIST_IMAGE_TAG     1 (default) = write IMAGE_TAG to .env after a successful deploy, so a later plain
#                         `docker compose up` keeps running this release
#   DRY_RUN               1 = print the docker commands instead of running them
set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

: "${IMAGE_TAG:?set IMAGE_TAG to the image tag to deploy (e.g. the commit SHA)}"
IMAGE_REGISTRY="${IMAGE_REGISTRY:-}"
export IMAGE_TAG
export COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml:docker-compose.prod.yml}"
SMOKE_BASE_URL="${SMOKE_BASE_URL:-http://localhost:${HTTP_PORT:-8080}}"
SMOKE_BASE_URL="${SMOKE_BASE_URL%/}"
SMOKE_INSECURE="${SMOKE_INSECURE:-0}"
SKIP_CANARY="${SKIP_CANARY:-0}"
CANARY_API_KEY="${CANARY_API_KEY:-}"
CANARY_REFERENCE_URL="${CANARY_REFERENCE_URL:-}"
CANARY_DOMAINS="${CANARY_DOMAINS:-}"
CANARY_TIMEOUT="${CANARY_TIMEOUT:-60}"
read -r -a ROLL_SERVICES <<<"${ROLL_SERVICES:-api web renderer}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-120}"
STOP_TIMEOUT="${STOP_TIMEOUT:-30}"
DRAIN_SECONDS="${DRAIN_SECONDS:-11}"
PERSIST_IMAGE_TAG="${PERSIST_IMAGE_TAG:-1}"
DRY_RUN="${DRY_RUN:-0}"

IMAGES=(api nginx renderer web)
PHP_SERVICES=(api horizon scheduler reverb)
PREVIOUS_TAG=""

log() { printf '%s [deploy] %s\n' "$(date -u +%H:%M:%S)" "$*" >&2; }
die() { log "ERROR: $*"; exit 1; }

run() {
  if [[ "${DRY_RUN}" == "1" ]]; then
    printf '+ %s\n' "$*" >&2
  else
    "$@"
  fi
}

compose() { run docker compose "$@"; }

# Read-only compose/docker queries run even in DRY_RUN.
compose_ids() { docker compose ps -q "$1" 2>/dev/null || true; }

curl_api() {
  local -a opts=(-sS --max-time 15)
  [[ "${SMOKE_INSECURE}" == "1" ]] && opts+=(-k)
  curl "${opts[@]}" "$@"
}

json_get() { # json_get <expression on `d`> ; reads JSON on stdin
  if command -v jq >/dev/null 2>&1; then
    jq -r "$1"
  elif command -v python3 >/dev/null 2>&1; then
    python3 -c 'import json,sys
d=json.load(sys.stdin)
expr=sys.argv[1]
if expr == ".data | length":
    print(len(d.get("data") or []))
else:
    cur=d
    for part in expr.lstrip(".").split("."):
        cur=(cur or {}).get(part) if isinstance(cur, dict) else None
    print("null" if cur is None else cur)' "$1"
  else
    die "jq or python3 is required to parse API responses"
  fi
}

on_exit() {
  local code=$?
  ((code == 0)) && return 0
  log "deploy of ${IMAGE_TAG} FAILED (exit ${code})."
  if [[ -n "${PREVIOUS_TAG}" && "${PREVIOUS_TAG}" != "${IMAGE_TAG}" ]]; then
    log "roll back with: IMAGE_TAG=${PREVIOUS_TAG} SKIP_CANARY=${SKIP_CANARY} $0"
    log "(migrations are expand/contract, so the previous release runs against the migrated schema)"
  fi
  exit "${code}"
}
trap on_exit EXIT

preflight() {
  command -v docker >/dev/null || die "docker not found"
  docker compose version >/dev/null 2>&1 || die "docker compose v2 not found"
  command -v curl >/dev/null || die "curl not found"
  if [[ "${SKIP_CANARY}" != "1" ]]; then
    [[ -n "${CANARY_API_KEY}" ]] || die "CANARY_API_KEY is required (or SKIP_CANARY=1)"
    [[ -n "${CANARY_REFERENCE_URL}" ]] || die "CANARY_REFERENCE_URL is required (or SKIP_CANARY=1)"
  fi
  docker compose config -q || die "compose configuration is invalid (COMPOSE_FILE=${COMPOSE_FILE})"

  local id
  id="$(compose_ids api | head -n1)"
  if [[ -n "${id}" ]]; then
    PREVIOUS_TAG="$(docker inspect -f '{{.Config.Image}}' "${id}" | sed 's/.*://')"
  fi
  log "deploying ${IMAGE_TAG} (previous: ${PREVIOUS_TAG:-none}) with COMPOSE_FILE=${COMPOSE_FILE}"
}

pull_images() {
  if [[ -z "${IMAGE_REGISTRY}" ]]; then
    log "pulling compose images"
    compose pull --quiet "${IMAGES[@]}"
    return
  fi
  local name src
  for name in "${IMAGES[@]}"; do
    src="${IMAGE_REGISTRY%/}/pricelens-${name}:${IMAGE_TAG}"
    log "pulling ${src}"
    run docker pull --quiet "${src}" >/dev/null
    run docker tag "${src}" "pricelens/${name}:${IMAGE_TAG}"
  done
}

migrate_and_optimize() {
  log "running migrations"
  compose run --rm --no-deps -T api php artisan migrate --force
  # The caches live inside each container, so this run is only a pre-flight: it fails the deploy before
  # anything restarts if config or routes can't be cached. Each new PHP container runs optimize again below.
  log "pre-flight: php artisan optimize"
  compose run --rm --no-deps -T api php artisan optimize
}

# Probe used when a container has no Docker HEALTHCHECK.
probe() {
  local svc=$1 id=$2
  case "${svc}" in
    api | horizon | scheduler | reverb)
      if [[ "${svc}" == "api" ]]; then
        docker exec "${id}" php -r 'exit(@fsockopen("127.0.0.1", 9000) ? 0 : 1);'
      else
        docker exec "${id}" php -r 'exit(0);'
      fi
      ;;
    renderer)
      docker exec "${id}" node -e 'fetch("http://127.0.0.1:" + (process.env.PORT || 3100) + "/health").then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))'
      ;;
    web)
      docker exec "${id}" node -e 'fetch("http://127.0.0.1:" + (process.env.PORT || 3000) + "/").then(r => process.exit(r.status < 500 ? 0 : 1)).catch(() => process.exit(1))'
      ;;
    *) return 0 ;;
  esac
}

wait_healthy() {
  local svc=$1 id=$2 deadline state health
  [[ "${DRY_RUN}" == "1" ]] && return 0
  deadline=$((SECONDS + HEALTH_TIMEOUT))
  while ((SECONDS < deadline)); do
    state="$(docker inspect -f '{{.State.Status}}' "${id}" 2>/dev/null || echo missing)"
    [[ "${state}" == "exited" || "${state}" == "dead" || "${state}" == "missing" ]] &&
      die "${svc} container ${id:0:12} is ${state}: $(docker logs --tail 20 "${id}" 2>&1 | tr '\n' ' ')"
    health="$(docker inspect -f '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "${id}")"
    if [[ "${health}" == "healthy" ]] || { [[ "${health}" == "none" && "${state}" == "running" ]] && probe "${svc}" "${id}" 2>/dev/null; }; then
      log "${svc} ${id:0:12} is healthy"
      return 0
    fi
    sleep 2
  done
  die "${svc} container ${id:0:12} not healthy after ${HEALTH_TIMEOUT}s"
}

optimize_in() {
  local svc=$1 id=$2
  case " ${PHP_SERVICES[*]} " in
    *" ${svc} "*) run docker exec "${id}" php artisan optimize --quiet ;;
  esac
}

# Surge rolling update: for each old container, add one new container (new image) next to it, wait for it to
# become healthy, then stop and remove the old one. Capacity never drops below the current replica count.
roll_service() {
  local svc=$1 count id new_id candidate
  local -a old_ids current_ids added_ids=()
  mapfile -t old_ids < <(compose_ids "${svc}")
  count=${#old_ids[@]}

  if ((count == 0)); then
    log "${svc}: not running, starting it"
    compose up -d --no-deps --no-build --pull never "${svc}"
    for new_id in $(compose_ids "${svc}"); do
      wait_healthy "${svc}" "${new_id}"
      optimize_in "${svc}" "${new_id}"
    done
    return
  fi

  log "${svc}: rolling ${count} container(s)"
  for id in "${old_ids[@]}"; do
    compose up -d --no-deps --no-build --pull never --no-recreate --scale "${svc}=$((count + 1))" "${svc}"
    if [[ "${DRY_RUN}" != "1" ]]; then
      new_id=""
      mapfile -t current_ids < <(compose_ids "${svc}")
      for candidate in "${current_ids[@]}"; do
        if [[ " ${old_ids[*]} ${added_ids[*]} " != *" ${candidate} "* ]]; then
          new_id="${candidate}"
        fi
      done
      [[ -n "${new_id}" ]] || die "${svc}: no new container appeared"
      added_ids+=("${new_id}")
      wait_healthy "${svc}" "${new_id}"
      optimize_in "${svc}" "${new_id}"
    fi
    # nginx re-resolves upstream names every 10 s (resolver valid=10s): let it pick up the new container first.
    [[ "${DRY_RUN}" == "1" ]] || sleep "${DRAIN_SECONDS}"
    log "${svc}: retiring ${id:0:12}"
    run docker stop --time "${STOP_TIMEOUT}" "${id}" >/dev/null
    run docker rm "${id}" >/dev/null
  done
}

restart_workers() {
  local id
  log "horizon: graceful terminate (workers finish their current job)"
  for id in $(compose_ids horizon); do
    run docker exec "${id}" php artisan horizon:terminate || log "horizon:terminate failed in ${id:0:12} (continuing; recreate sends SIGTERM)"
  done
  compose up -d --no-deps --no-build --pull never --force-recreate horizon scheduler
  for svc in horizon scheduler; do
    for id in $(compose_ids "${svc}"); do
      wait_healthy "${svc}" "${id}"
      optimize_in "${svc}" "${id}"
    done
  done

  log "reverb: restart"
  for id in $(compose_ids reverb); do
    run docker exec "${id}" php artisan reverb:restart || log "reverb:restart failed in ${id:0:12} (continuing)"
  done
  compose up -d --no-deps --no-build --pull never --force-recreate reverb
  for id in $(compose_ids reverb); do
    wait_healthy reverb "${id}"
    optimize_in reverb "${id}"
  done

  log "nginx: recreate on the new image"
  compose up -d --no-deps --no-build --pull never nginx
}

smoke_ready() {
  local deadline code body
  [[ "${DRY_RUN}" == "1" ]] && return 0
  deadline=$((SECONDS + 60))
  while ((SECONDS < deadline)); do
    code="$(curl_api -o /dev/null -w '%{http_code}' "${SMOKE_BASE_URL}/api/v1/ready" || true)"
    if [[ "${code}" == "200" ]]; then
      log "smoke: ${SMOKE_BASE_URL}/api/v1/ready -> 200"
      return 0
    fi
    sleep 3
  done
  body="$(curl_api "${SMOKE_BASE_URL}/api/v1/ready" || true)"
  die "smoke: /api/v1/ready -> ${code}: ${body:0:500}"
}

canary_search() {
  local body domains_json="" response id status deadline offers count
  if [[ "${SKIP_CANARY}" == "1" ]]; then
    log "canary: skipped (SKIP_CANARY=1)"
    return 0
  fi
  [[ "${DRY_RUN}" == "1" ]] && return 0

  if [[ -n "${CANARY_DOMAINS}" ]]; then
    domains_json="\"sources\":{\"domains\":[\"${CANARY_DOMAINS//,/\",\"}\"]},"
  fi
  body="{\"reference_url\":\"${CANARY_REFERENCE_URL}\",${domains_json}\"options\":{\"fresh\":true,\"max_results\":10}}"

  response="$(curl_api -X POST "${SMOKE_BASE_URL}/api/v1/searches" \
    -H "Authorization: Bearer ${CANARY_API_KEY}" -H 'Content-Type: application/json' -H 'Accept: application/json' \
    -H "Idempotency-Key: deploy-canary-${IMAGE_TAG}-$(date +%s)" \
    -w '\n%{http_code}' --data "${body}")"
  [[ "${response##*$'\n'}" == "202" ]] || die "canary: POST /searches -> ${response##*$'\n'}: ${response%$'\n'*}"
  id="$(printf '%s' "${response%$'\n'*}" | json_get '.data.id')"
  [[ -n "${id}" && "${id}" != "null" ]] || die "canary: no search id in response"
  log "canary: search ${id} created for ${CANARY_REFERENCE_URL}"

  deadline=$((SECONDS + CANARY_TIMEOUT))
  status="queued"
  while ((SECONDS < deadline)); do
    status="$(curl_api -H "Authorization: Bearer ${CANARY_API_KEY}" -H 'Accept: application/json' \
      "${SMOKE_BASE_URL}/api/v1/searches/${id}" | json_get '.data.status' || echo unknown)"
    case "${status}" in
      completed) break ;;
      partially_completed | failed | cancelled) die "canary: search ${id} ended as ${status}" ;;
    esac
    sleep 2
  done
  [[ "${status}" == "completed" ]] || die "canary: search ${id} still ${status} after ${CANARY_TIMEOUT}s"

  offers="$(curl_api -H "Authorization: Bearer ${CANARY_API_KEY}" -H 'Accept: application/json' \
    "${SMOKE_BASE_URL}/api/v1/searches/${id}/offers")"
  count="$(printf '%s' "${offers}" | json_get '.data | length')"
  if ! [[ "${count}" =~ ^[0-9]+$ ]] || ((count < 1)); then
    die "canary: search ${id} completed with ${count:-0} offers"
  fi
  log "canary: search ${id} completed with ${count} offer(s)"
}

persist_tag() {
  [[ "${PERSIST_IMAGE_TAG}" == "1" && "${DRY_RUN}" != "1" ]] || return 0
  if [[ -f .env ]] && grep -q '^IMAGE_TAG=' .env; then
    sed -i.bak "s/^IMAGE_TAG=.*/IMAGE_TAG=${IMAGE_TAG}/" .env && rm -f .env.bak
  else
    printf '\nIMAGE_TAG=%s\n' "${IMAGE_TAG}" >>.env
  fi
  log "IMAGE_TAG=${IMAGE_TAG} written to .env"
}

main() {
  preflight
  pull_images
  migrate_and_optimize
  local svc
  for svc in "${ROLL_SERVICES[@]}"; do
    roll_service "${svc}"
  done
  restart_workers
  smoke_ready
  canary_search
  persist_tag
  log "deploy of ${IMAGE_TAG} complete"
}

main "$@"
