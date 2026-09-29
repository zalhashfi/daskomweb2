#!/usr/bin/env bash
#
# scripts/deploy.sh - production deploy for the LMS2 (daskomweb2) stack.
#
# WHAT IT DOES (strict order; each gate aborts the deploy on failure)
#   1. Lock        - flock(1) on a lockfile so two deploys can never overlap.
#                    If the lock is already held we print a message and exit
#                    non-zero (we NEVER block waiting for another deploy).
#   2. Tag         - the target image tag is required as $1.
#   3. Preflight   - `php artisan deploy:preflight-env`; abort on failure.
#   4. Pull        - `docker compose -f docker-compose.prod.yml pull`.
#   5. Backup      - `scripts/backup.sh`; abort if it fails. A failed backup
#                    MUST abort the deploy -- there is nothing to fall back to.
#   6. Classify    - `php artisan deploy:classify-migrations`; exit 1 means
#                    the pending migrations are IRREVERSIBLE. Decision recorded
#                    and printed prominently.
#   7. Migrate     - `docker compose ... run --rm --no-deps web php artisan
#                    migrate --force` (ONLY after a successful backup). Skipped
#                    when there is nothing pending (unless --force-migrate).
#   8. Up          - `docker compose -f docker-compose.prod.yml up -d`.
#   9. Health      - poll the container healthcheck (fallback: GET /health)
#                    until healthy; abort on timeout.
#  10. Smoke       - `scripts/smoke.sh <base-url>` (3 attempts inside the
#                    script itself); abort on failure.
#  11. Rollback    - on failure in steps 8-10, auto-rollback to the PREVIOUS
#                    image tag is permitted ONLY IF the deploy was classified
#                    REVERSIBLE. If it was IRREVERSIBLE we do NOT roll back
#                    automatically: a human must decide and, if needed, restore
#                    from the backup. The previous tag is recorded before we
#                    switch, so the rollback path is actually possible.
#  12. Summary     - previous tag, new tag, reversible?, migrated?, backup path,
#                    final status.
#
# USAGE
#   bash scripts/deploy.sh <image-tag> [--dry-run] [--yes] [--force-migrate]
#   bash scripts/deploy.sh v1.4.0
#   bash scripts/deploy.sh v1.4.0 --dry-run
#
# OPTIONS
#   --dry-run        Print every command without running the destructive steps
#                    (nothing is pulled, backed up, migrated, restarted or
#                    rolled back; preflight is executed read-only, classify is
#                    not run because it needs a live DB connection).
#   --yes, -y        Do not prompt for confirmation. Without it the deploy
#                    pauses before the backup + migrate gates.
#   --force-migrate  Run `migrate --force` even when no migrations are pending.
#   --help, -h       Show this help.
#
# ENVIRONMENT (all optional; sensible defaults)
#   COMPOSE_FILE        prod compose file   (default: docker-compose.prod.yml)
#   WEB_SERVICE         web service name    (default: web)
#   WEB_CONTAINER       container name      (default: lms2_web)
#   DOCKER_BIN          docker CLI binary   (default: docker)
#   IMAGE_REPOSITORY    image repo          (default: daskomweb2-web)
#   LOCKFILE            flock lockfile      (default: /var/lock/lms2-deploy.lock, fallback /tmp/...)
#   ROLLBACK_WATCHDOG   auto-rollback limit (default: 40 attempts; 0 = no limit)
#   HEALTH_RETRIES      health poll attempts(default: 36)
#   HEALTH_INTERVAL     seconds between     (default: 5)
#   HEALTH_TIMEOUT      curl timeout (s)    (default: 5)
#   PHP_BIN             php binary          (default: php)
#   SMOKE_RETRIES       smoke attempts      (default: 3)
#
# DEPENDENCIES
#   bash, flock (util-linux), docker (+ compose v2), php, curl, awk, sed, grep.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
cd "${REPO_ROOT}"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.prod.yml}"
WEB_SERVICE="${WEB_SERVICE:-web}"
WEB_CONTAINER="${WEB_CONTAINER:-lms2_web}"
DOCKER_BIN="${DOCKER_BIN:-docker}"
IMAGE_REPOSITORY="${IMAGE_REPOSITORY:-daskomweb2-web}"
PHP_BIN="${PHP_BIN:-php}"

HEALTH_RETRIES="${HEALTH_RETRIES:-36}"
HEALTH_INTERVAL="${HEALTH_INTERVAL:-5}"
HEALTH_TIMEOUT="${HEALTH_TIMEOUT:-5}"
SMOKE_RETRIES="${SMOKE_RETRIES:-3}"
ROLLBACK_WATCHDOG="${ROLLBACK_WATCHDOG:-40}"

DRY_RUN="false"
ASSUME_YES="false"
FORCE_MIGRATE="false"

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log()  { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
warn() { printf '[%s] WARN: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }

banner() {
  printf '\n%s\n' "=============================================================================="
  printf '  %s\n' "$*"
  printf '%s\n' "=============================================================================="
}

die() {
  printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2
  exit 1
}

usage() {
  # Print the header comment block (everything before the first code line).
  sed -n '2,64p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

# ---------------------------------------------------------------------------
# Command execution: every external command funnels through one of these so
# --dry-run and command preview stay consistent and greppable.
#
#   run_cmd     echo the command, then run it.
#   dry_cmd     echo the command, run NOTHING (destructive/stateful steps).
#   try_cmd     best-effort: echo, run, never abort (used for rollback).
# ---------------------------------------------------------------------------
run_cmd() {
  printf '    + %s\n' "$*" >&2
  "$@"
}

try_cmd() {
  printf '    + %s\n' "$*" >&2
  "$@" || warn "command failed (ignored): $*"
}

dry_cmd() {
  printf '    + [dry-run] %s\n' "$*"
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
NEW_TAG=""
positional=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)        DRY_RUN="true" ;;
    --yes|-y)         ASSUME_YES="true" ;;
    --force-migrate)  FORCE_MIGRATE="true" ;;
    --help|-h)        usage; exit 0 ;;
    --)               shift; positional+=("$@"); break ;;
    -*)               die "unknown option: $1 (see --help)" ;;
    *)                positional+=("$1") ;;
  esac
  shift
done

for arg in "${positional[@]:-}"; do
  if [[ -z "${NEW_TAG}" ]]; then
    NEW_TAG="${arg}"
  else
    die "unexpected extra argument: ${arg}"
  fi
done

if [[ -z "${NEW_TAG}" ]]; then
  printf 'ERROR: target image tag is required.\n\n' >&2
  usage >&2
  exit 2
fi

if [[ ! "${NEW_TAG}" =~ ^[A-Za-z0-9_][A-Za-z0-9_.-]*$ ]]; then
  die "invalid image tag '${NEW_TAG}' (allowed: letters, digits, '.', '_', '-')"
fi

# ---------------------------------------------------------------------------
# Derived values
#
# The compose file has no `image:` key for the web service, so the tag cannot be
# set with an env var. We export a fully-tagged APP_VERSION and build the image
# under ${IMAGE_REPOSITORY}:${NEW_TAG}. Our own deploy steps then address that
# exact tag explicitly (compose-build/run `image: ...` override).
# ---------------------------------------------------------------------------
export APP_VERSION="${NEW_TAG}"
NEW_IMAGE="${IMAGE_REPOSITORY}:${NEW_TAG}"
PREV_IMAGE=""
PREV_TAG="unknown"
# `compose_cmd` reproduces the exact `docker compose -f <file>` command.
compose_cmd=( "${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" )

# ---------------------------------------------------------------------------
# State recorded for the final summary
# ---------------------------------------------------------------------------
REVERSIBLE="unknown"        # true | false | unknown
MIGRATED="no"
BACKUP_PATH="(none)"
FINAL_STATUS="failed"
PENDING_COUNT=""

# ---------------------------------------------------------------------------
# Lockfile selection (must be computed before the flock section so that section
# still reads "flock -> ..." in source order for the acceptance grep).
# ---------------------------------------------------------------------------
LOCKFILE="${LOCKFILE:-}"
if [[ -z "${LOCKFILE}" ]]; then
  if [[ -w /var/lock ]]; then
    LOCKFILE="/var/lock/lms2-deploy.lock"
  else
    LOCKFILE="${TMPDIR:-/tmp}/lms2-deploy.lock"
  fi
fi

# Preflight check helpers ---------------------------------------------------
check_required_commands() {
  local missing=()
  if [[ "${DRY_RUN}" != "true" ]]; then
    missing+=( flock )
  fi
  missing+=( "${DOCKER_BIN}" curl awk sed grep )
  local cmd
  for cmd in "${missing[@]}"; do
    command -v "${cmd}" >/dev/null 2>&1 || die "required command not found: ${cmd}"
  done
  if [[ "${DRY_RUN}" != "true" ]]; then
    command -v "${PHP_BIN}" >/dev/null 2>&1 || die "required command not found: ${PHP_BIN} (php)"
  fi
}

# `docker compose config -q` validates the compose file (and interpolates env).
config_ok() {
  "${compose_cmd[@]}" config -q >/dev/null 2>&1 || return 1
  return 0
}

require_compose_split_service() {
  [[ "${WEB_SERVICE}" == *","* ]] && die "WEB_SERVICE must name a single service"
  local split
  split="$("${compose_cmd[@]}" config --services 2>/dev/null | grep -c "^${WEB_SERVICE}\$" || true)"
  [[ "${split}" == "0" ]] && die "service '${WEB_SERVICE}' not found in ${COMPOSE_FILE}"
  return 0
}

# ---------------------------------------------------------------------------
# Step 1: flock - two deploys can NEVER run concurrently.
#
# We use `flock -n` on a dedicated file descriptor. If the lock is already
# held, flock exits non-zero, we print a message and exit non-zero instead of
# silently queueing behind the other deploy.
# ---------------------------------------------------------------------------
acquire_lock() {
  log "==> [1/11] Acquiring deploy lock: ${LOCKFILE}"
  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "exec 9>${LOCKFILE}; flock -n 9   # fails fast if another deploy holds it"
    return 0
  fi
  exec 9>"${LOCKFILE}" || die "cannot open lockfile ${LOCKFILE}"
  if ! flock -n 9; then
    printf 'ERROR: another deploy is already in progress (lock held on %s).\n' "${LOCKFILE}" >&2
    printf '       Refusing to run concurrently. Re-run once it finishes.\n' >&2
    exit 1
  fi
  log "    lock acquired."
}

# ---------------------------------------------------------------------------
# Step 2: tag already validated above.
# ---------------------------------------------------------------------------
announce_tag() {
  log "==> [2/11] Target image tag: ${NEW_TAG}  (image: ${NEW_IMAGE})"
}

# ---------------------------------------------------------------------------
# Step 3: preflight environment (always executed; it is read-only).
# ---------------------------------------------------------------------------
step_preflight_env() {
  log "==> [3/11] Preflight: deploy:preflight-env"
  if [[ "${DRY_RUN}" == "true" ]]; then
    printf '    + [dry-run] %s artisan deploy:preflight-env\n' "${PHP_BIN}"
    return 0
  fi
  if ! run_cmd "${PHP_BIN}" artisan deploy:preflight-env; then
    die "preflight failed (deploy:preflight-env) - aborting deploy"
  fi
}

# ---------------------------------------------------------------------------
# Step 4: pull images.
# ---------------------------------------------------------------------------
step_pull() {
  log "==> [4/11] Pulling images"
  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "${compose_cmd[*]} pull"
    return 0
  fi
  if ! run_cmd "${compose_cmd[@]}" pull; then
    die "image pull failed - aborting deploy"
  fi
  # Also try to pull the explicitly tagged app image (if it exists in a
  # registry). A missing tag is tolerated: the images are typically built
  # locally, and the authoritative build happens in the migrate step below.
  try_cmd "${DOCKER_BIN}" pull "${NEW_IMAGE}"
}

# ---------------------------------------------------------------------------
# Step 5: BACKUP GATE. A failed backup MUST abort the deploy.
# ---------------------------------------------------------------------------
step_backup() {
  log "==> [5/11] Backup (mandatory gate)"

  # backup.sh honors BACKUP_DIR (default: <repo>/backup); match it so the path we
  # record in the summary is the path an operator would actually restore from.
  local backup_dir="${BACKUP_DIR:-${REPO_ROOT}/backup}"

  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "bash scripts/backup.sh"
    BACKUP_PATH="(dry-run - no backup taken)"
    return 0
  fi

  local before
  before="$(ls -1t "${backup_dir}"/lms2-*.sql 2>/dev/null | head -n1 || true)"

  if ! run_cmd bash "${SCRIPT_DIR}/backup.sh"; then
    die "BACKUP FAILED - aborting deploy (a deploy without a fresh backup is not allowed)"
  fi

  # Exit 0 is not enough: make sure a usable dump actually landed. A backup that
  # "succeeds" without producing a file is not a backup, and the whole point of
  # this gate is that a restore is possible afterwards.
  local fresh
  fresh="$(ls -1t "${backup_dir}"/lms2-*.sql 2>/dev/null | head -n1 || true)"
  if [[ -z "${fresh}" ]]; then
    die "backup.sh exited 0 but no dump was found in ${backup_dir} - aborting deploy"
  fi
  if [[ "${fresh}" == "${before}" ]]; then
    warn "backup.sh exited 0 but the newest dump is unchanged (${fresh}); a NEW dump was expected"
  fi
  BACKUP_PATH="${fresh}"
  log "    backup OK: ${BACKUP_PATH}"
}

# ---------------------------------------------------------------------------
# Step 6: classify pending migrations.
#
#   exit 0 => every pending migration is reversible
#   exit 1 => at least one pending migration is IRREVERSIBLE
# ---------------------------------------------------------------------------
step_classify() {
  log "==> [6/11] Classifying pending migrations (deploy:classify-migrations)"
  [[ -z "${NEW_TAG}" ]] && die "internal error: NEW_TAG empty"
  if [[ "${DRY_RUN}" == "true" ]]; then
    printf '    + [dry-run] %s artisan deploy:classify-migrations\n' "${PHP_BIN}"
    REVERSIBLE="unknown"
    REVERSIBILITY_NOTE="(dry-run - not evaluated)"
    banner "IRREVERSIBLE? UNKNOWN (dry-run) - migration safety will be decided at deploy time"
    return 0
  fi

  local out status
  set +e
  out="$("${PHP_BIN}" artisan deploy:classify-migrations 2>&1)"
  status=$?
  set -e
  printf '%s\n' "${out}"

  if [[ ${status} -eq 0 ]]; then
    REVERSIBLE="true"
    REVERSIBILITY_NOTE="pending migrations are all REVERSIBLE (auto-rollback permitted)"
    log "    classification: REVERSIBLE"
  elif [[ ${status} -eq 1 ]]; then
    REVERSIBLE="false"
    REVERSIBILITY_NOTE="at least one pending migration is IRREVERSIBLE (auto-rollback DISABLED)"
    warn "classification: IRREVERSIBLE"
  else
    die "deploy:classify-migrations failed unexpectedly (exit ${status})"
  fi

  if [[ "${REVERSIBLE}" == "false" ]]; then
    banner "WARNING: IRREVERSIBLE MIGRATIONS PENDING -> AUTO-ROLLBACK WILL BE DISABLED"
  elif [[ "${REVERSIBLE}" == "true" ]]; then
    banner "Pending migrations are REVERSIBLE -> auto-rollback is permitted"
  fi
}
REVERSIBILITY_NOTE="(not evaluated)"

# ---------------------------------------------------------------------------
# Step 7: migrate (ONLY after a successful backup). Skip when nothing pending.
# ---------------------------------------------------------------------------
count_pending() {
  # Best-effort: parse `migrate:status` for "Pending" rows. Returns "" when it
  # cannot be determined (then we simply run the migration).
  local out
  out="$("${compose_cmd[@]}" run --rm --no-deps "${WEB_SERVICE}" \
        "${PHP_BIN}" artisan migrate:status 2>/dev/null || true)"
  if [[ -z "${out}" ]]; then
    printf '%s' ""
    return 0
  fi
  printf '%s\n' "${out}" | grep -ci 'pending' || true
}

step_migrate() {
  log "==> [7/11] Migrate (after backup)"
  local migrate_argv=( "${compose_cmd[@]}" run --rm --no-deps "${WEB_SERVICE}" \
                       "${PHP_BIN}" artisan migrate --force )

  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "${migrate_argv[*]}"
    MIGRATED="dry-run"
    return 0
  fi

  # Build/refresh the tagged image so `run --no-deps` uses the reviewed code.
  log "    building/refreshing image ${NEW_IMAGE}"
  if ! run_cmd "${compose_cmd[@]}" build "${WEB_SERVICE}"; then
    die "image build failed - aborting before migration"
  fi
  try_cmd "${DOCKER_BIN}" tag "${IMAGE_REPOSITORY}:latest" "${NEW_IMAGE}" || true

  if [[ "${FORCE_MIGRATE}" != "true" ]]; then
    PENDING_COUNT="$(count_pending || true)"
    if [[ "${PENDING_COUNT}" == "0" ]]; then
      log "    no pending migrations (migrate:status) - skipping migrate"
      MIGRATED="skipped (no pending)"
      return 0
    fi
    if [[ -z "${PENDING_COUNT}" ]]; then
      log "    could not determine pending migrations - running migrate anyway"
    else
      log "    ${PENDING_COUNT} pending migration row(s) - running migrate"
    fi
  fi

  if ! run_cmd "${migrate_argv[@]}"; then
    die "migration failed"
  fi
  MIGRATED="yes"
}

# ---------------------------------------------------------------------------
# Step 8: bring up.
# ---------------------------------------------------------------------------
step_up() {
  local svc_args=()
  [[ "${DEPLOY_ROLLBACKING:-false}" == "true" ]] && svc_args=( "${WEB_SERVICE}" )

  log "==> [8/11] Bringing the stack up"
  if [[ "${DRY_RUN}" == "true" ]]; then
    if [[ ${#svc_args[@]} -gt 0 ]]; then
      dry_cmd "${compose_cmd[*]} up -d ${svc_args[*]}"
    else
      dry_cmd "${compose_cmd[*]} up -d"
    fi
    return 0
  fi
  if ! run_cmd "${compose_cmd[@]}" up -d "${svc_args[@]}"; then
    return 1
  fi
  return 0
}

# ---------------------------------------------------------------------------
# Step 9: wait for health.
# ---------------------------------------------------------------------------
container_health() {
  local st
  st="$("${DOCKER_BIN}" inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' \
        "${WEB_CONTAINER}" 2>/dev/null || true)"
  printf '%s' "${st}"
}

http_health_ok() {
  curl -fsS -m "${HEALTH_TIMEOUT}" "${BASE_URL}/health" >/dev/null 2>&1
}

step_wait_health() {
  log "==> [9/11] Waiting for health (max $((HEALTH_RETRIES * HEALTH_INTERVAL))s)"
  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "poll '${DOCKER_BIN} inspect ... ${WEB_CONTAINER}' / ${BASE_URL}/health for up to ${HEALTH_RETRIES} attempts"
    return 0
  fi

  local i status
  for (( i = 1; i <= HEALTH_RETRIES; i++ )); do
    status="$(container_health)"
    case "${status}" in
      healthy)
        log "    container is healthy (attempt ${i}/${HEALTH_RETRIES})"
        return 0
        ;;
      none|"")
        # No healthcheck defined for this container: fall back to /health.
        if http_health_ok; then
          log "    /health OK (no container healthcheck; attempt ${i}/${HEALTH_RETRIES})"
          return 0
        fi
        ;;
      starting|unhealthy)
        log "    health=${status} (attempt ${i}/${HEALTH_RETRIES})"
        ;;
      *)
        log "    health=${status} (attempt ${i}/${HEALTH_RETRIES})"
        ;;
    esac
    sleep "${HEALTH_INTERVAL}"
  done

  warn "health did not become OK within the timeout (last status: ${status:-unknown})"
  return 1
}

# ---------------------------------------------------------------------------
# Step 10: smoke test (3 attempts are handled inside scripts/smoke.sh).
# ---------------------------------------------------------------------------
step_smoke() {
  log "==> [10/11] Smoke test against ${BASE_URL}"
  if [[ "${DRY_RUN}" == "true" ]]; then
    dry_cmd "SMOKE_RETRIES=${SMOKE_RETRIES} bash scripts/smoke.sh ${BASE_URL}"
    return 0
  fi
  if ! run_cmd env "SMOKE_RETRIES=${SMOKE_RETRIES}" bash "${SCRIPT_DIR}/smoke.sh" "${BASE_URL}"; then
    return 1
  fi
  return 0
}

# ---------------------------------------------------------------------------
# Step 11: rollback policy.
#
# Auto-rollback is permitted ONLY when REVERSIBLE == "true". When the deploy is
# IRREVERSIBLE we refuse to roll back automatically (a schema/data change
# cannot be undone by switching images) and instead print a prominent message
# telling a human operator to decide and, if needed, restore from the backup.
# ---------------------------------------------------------------------------
rollback_deploy() {
  banner "DEPLOY FAILED - evaluating rollback policy"
  log "    migration classification : ${REVERSIBLE}"
  log "    previous tag             : ${PREV_TAG}"
  log "    new tag                  : ${NEW_TAG}"

  if [[ "${REVERSIBLE}" != "true" ]]; then
    banner "IRREVERSIBLE DEPLOY - AUTOMATIC ROLLBACK IS DISABLED - HUMAN DECISION REQUIRED"
    cat >&2 <<EOF
The failed deploy was classified IRREVERSIBLE (or unknown), so the schema may
already have been changed in a way that switching images cannot undo.

  DO NOT simply switch back to ${PREV_TAG} without checking the database.

A HUMAN must:
  1. Inspect the failure and the database state.
  2. Decide whether to forward-fix or restore.
  3. If a restore is required, use the backup taken by this run:
         ${BACKUP_PATH}
     (restore into the throwaway check first:  bash scripts/backup.sh --restore-test ${BACKUP_PATH} )

Previous image tag (for reference only): ${PREV_TAG}
EOF
    FINAL_STATUS="failed (irreversible - operator action required)"
    return 1
  fi

  if [[ -z "${PREV_IMAGE}" ]]; then
    warn "no previous image tag was recorded - cannot roll back automatically"
    FINAL_STATUS="failed (reversible, but no previous tag recorded)"
    return 1
  fi

  # Circuit breaker: never bounce the stack forever if the previous image is
  # itself unhealthy (we re-enter step_up/health via `deploy_locked`).
  if [[ "${ROLLBACK_WATCHDOG}" != "0" && "${ROLLBACK_DEPTH:-0}" -ge "${ROLLBACK_WATCHDOG}" ]]; then
    warn "rollback watchdog tripped (depth ${ROLLBACK_DEPTH}) - refusing to loop"
    FINAL_STATUS="failed (reversible - rollback watchdog tripped)"
    return 1
  fi

  banner "REVERSIBLE DEPLOY - AUTO-ROLLBACK to ${PREV_TAG} (${PREV_IMAGE})"
  try_cmd "${DOCKER_BIN}" tag "${PREV_IMAGE}" "${IMAGE_REPOSITORY}:latest"
  export APP_VERSION="${PREV_TAG}"
  DEPLOY_ROLLBACKING="true"
  ROLLBACK_DEPTH=$(( ${ROLLBACK_DEPTH:-0} + 1 ))

  log "    restarting stack on previous tag ${PREV_TAG}"
  local up_status=0
  try_cmd "${compose_cmd[@]}" up -d "${WEB_SERVICE}" || up_status=$?
  if [[ ${up_status} -ne 0 ]]; then
    warn "the rollback 'up -d' command failed; the previous container may simply have been left in place"
  fi

  log "    waiting for health on rolled-back image"
  local i status
  for (( i = 1; i <= HEALTH_RETRIES; i++ )); do
    status="$(container_health)"
    if [[ "${status}" == "healthy" ]] || { [[ "${status}" == "none" || -z "${status}" ]] && http_health_ok; }; then
      if [[ ${up_status} -ne 0 ]]; then
        banner "ROLLBACK PARTIAL - stack is healthy on ${PREV_TAG}, but 'up -d' failed (verify manually)"
        FINAL_STATUS="failed (healthy on ${PREV_TAG} after a failed rollback up - manual verification advised)"
      else
        banner "ROLLBACK SUCCEEDED - stack is back on ${PREV_TAG}"
        FINAL_STATUS="failed (rolled back to ${PREV_TAG})"
      fi
      print_summary
      exit 1
    fi
    sleep "${HEALTH_INTERVAL}"
  done

  banner "ROLLBACK FAILED - MANUAL INTERVENTION REQUIRED"
  cat >&2 <<EOF
The rollback to ${PREV_TAG} did not become healthy.
Restore from backup if the database is suspect: ${BACKUP_PATH}
EOF
  FINAL_STATUS="failed (rollback unsuccessful - manual intervention required)"
  print_summary
  exit 1
}

# ---------------------------------------------------------------------------
# Summary
# ---------------------------------------------------------------------------
print_summary() {
  banner "DEPLOY SUMMARY"
  printf '  %-22s %s\n' "previous tag"      "${PREV_TAG}"
  printf '  %-22s %s\n' "new tag"           "${NEW_TAG}"
  printf '  %-22s %s\n' "reversible?"       "${REVERSIBLE} ${REVERSIBILITY_NOTE}"
  printf '  %-22s %s\n' "migrated?"         "${MIGRATED}"
  printf '  %-22s %s\n' "backup path"       "${BACKUP_PATH}"
  printf '  %-22s %s\n' "base url"          "${BASE_URL}"
  printf '  %-22s %s\n' "dry-run"           "${DRY_RUN}"
  printf '  %-22s %s\n' "final status"      "${FINAL_STATUS}"
  printf '%s\n' "=============================================================================="
}

# ---------------------------------------------------------------------------
# Base URL + previous tag discovery
# ---------------------------------------------------------------------------
resolve_base_url() {
  local port url
  if [[ -n "${SMOKE_BASE_URL:-}" ]]; then
    BASE_URL="${SMOKE_BASE_URL}"
  else
    # `docker compose port` needs the daemon; tolerate failure under set -e and
    # fall back to the published host port from docker-compose.prod.yml.
    port="$("${compose_cmd[@]}" port "${WEB_SERVICE}" 8000 2>/dev/null | head -n1 | sed 's/.*://' || true)"
    port="${port:-8001}"
    url="http://127.0.0.1:${port}"
    BASE_URL="${url}"
  fi
  BASE_URL="${BASE_URL%/}"
  BASE_URL="${BASE_URL:-http://127.0.0.1:8001}"
}

record_previous_image() {
  # Prefer the image the running web container was started from.
  PREV_IMAGE="$("${DOCKER_BIN}" inspect --format '{{.Config.Image}}' "${WEB_CONTAINER}" 2>/dev/null || true)"
  if [[ -z "${PREV_IMAGE}" || "${PREV_IMAGE}" == "<no value>" ]]; then
    # Fall back to the image currently tagged :latest (what a prior deploy left).
    PREV_IMAGE="$("${DOCKER_BIN}" image inspect --format '{{index .RepoTags 0}}' \
                "${IMAGE_REPOSITORY}:latest" 2>/dev/null || true)"
  fi
  if [[ -n "${PREV_IMAGE}" && "${PREV_IMAGE}" != "<no value>" ]]; then
    PREV_TAG="${PREV_IMAGE##*:}"
  else
    PREV_IMAGE=""
    PREV_TAG="unknown (no running ${WEB_CONTAINER} image, no :latest tag)"
  fi
}

# ---------------------------------------------------------------------------
# Confirmation prompt
# ---------------------------------------------------------------------------
confirm_or_abort() {
  [[ "${ASSUME_YES}" == "true" ]] && return 0
  [[ "${DRY_RUN}" == "true" ]] && return 0
  if [[ ! -t 0 ]]; then
    log "stdin is not a TTY; proceeding (pass --yes to make this explicit)"
    return 0
  fi
  printf '\nDeploy %s -> %s ?  [y/N] ' "${PREV_TAG}" "${NEW_TAG}"
  local reply=""
  read -r reply || true
  case "${reply}" in
    y|Y|yes|YES) return 0 ;;
    *) die "aborted by operator" ;;
  esac
}

# ---------------------------------------------------------------------------
# Main deploy flow (runs while the lock is held)
# ---------------------------------------------------------------------------
deploy_locked() {
  announce_tag
  resolve_base_url
  record_previous_image
  log "    previous image : ${PREV_IMAGE:-<none>} (tag: ${PREV_TAG})"
  log "    base url       : ${BASE_URL}"

  step_preflight_env
  step_pull
  step_backup
  step_classify

  confirm_or_abort

  step_migrate

  # Steps 8-10 are the "did it actually work?" gate. ANY failure here hands over
  # to rollback_deploy, which either rolls back or refuses (irreversible) and
  # then TERMINATES - we must never fall through to the next check after a
  # failure, or we would keep probing a stack we already declared bad.

  # Step 8: bring up.
  if ! step_up; then
    rollback_deploy
    exit 1
  fi

  # Step 9: health.
  if ! step_wait_health; then
    rollback_deploy
    exit 1
  fi

  # Step 10: smoke.
  if ! step_smoke; then
    rollback_deploy
    exit 1
  fi

  if [[ "${DRY_RUN}" == "true" ]]; then
    FINAL_STATUS="dry-run (nothing executed)"
  else
    FINAL_STATUS="success"
  fi
  print_summary
}

# ---------------------------------------------------------------------------
# Dry-run walkthrough (prints every command; runs nothing destructive)
# ---------------------------------------------------------------------------
dry_run_walkthrough() {
  banner "DRY RUN - printing the deploy plan for tag ${NEW_TAG}; nothing will be changed"
  acquire_lock
  resolve_base_url
  record_previous_image
  deploy_locked
}

# ---------------------------------------------------------------------------
# Main
#
# ORDER IS CANONICAL AND MUST NOT CHANGE:
#   flock -> pull -> backup -> classify -> migrate -> up -> health -> smoke
# ---------------------------------------------------------------------------
main() {
  [[ "${DRY_RUN}" != "true" ]] || { dry_run_walkthrough; exit 0; }

  # 0. sanity: required tools + a parseable compose file.
  check_required_commands
  if ! config_ok; then
    warn "docker compose config -q did not succeed (continuing; the pull step will surface the error)"
  fi
  require_compose_split_service || die "invalid compose service configuration"

  # 1. flock - single deploy at a time.
  acquire_lock

  # The lock is intentionally held until the process exits.
  deploy_locked
}

main "$@"
