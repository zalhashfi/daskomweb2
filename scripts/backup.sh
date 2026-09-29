#!/usr/bin/env bash
#
# scripts/backup.sh - MySQL logical backup for the LMS2 stack.
#
# WHAT IT DOES
#   1. Dumps the database from the running `lms2_mysql` container to a
#      timestamped file:  backup/lms2-YYYYmmdd-HHMMSS.sql
#   2. Fails loudly (non-zero exit) if mysqldump fails or the dump is empty.
#   3. Enforces 7-day local retention on backup/lms2-*.sql.
#   4. Optionally copies the fresh dump offsite via rclone. If rclone is not
#      installed OR BACKUP_RCLONE_REMOTE is unset it prints SKIPPED and
#      continues successfully - never aborts.
#   5. --dry-run  : prints what it WOULD do; writes/deletes/uploads nothing.
#   6. --restore-test <dumpfile> : restores a dump into a THROWAWAY scratch
#      database (default `lms2_restore_check`), verifies it, then drops it.
#      This is the real gate - `bash -n` alone proves nothing.
#
# USAGE
#   bash scripts/backup.sh                     # take a backup
#   bash scripts/backup.sh --dry-run           # preview, change nothing
#   bash scripts/backup.sh --restore-test backup/lms2-20260929-184500.sql
#   bash scripts/backup.sh --help
#
# ENVIRONMENT (all optional; sensible defaults)
#   COMPOSE_FILE            compose file path      (default: docker-compose.yml)
#   COMPOSE_SERVICE_MYSQL   mysql service name     (default: lms2_mysql)
#   DOCKER_BIN              docker CLI binary      (default: docker)
#   DB_DATABASE             database to dump       (default: dasdaskom)
#   DB_PASSWORD             mysql root password    (default: password)
#   BACKUP_DIR              output directory        (default: backup)
#   BACKUP_RETENTION_DAYS   local retention         (default: 7)
#   BACKUP_RCLONE_REMOTE    rclone target, e.g.  remote:lms2-backups
#   RESTORE_TEST_DATABASE   scratch db name         (default: lms2_restore_check)
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration
# ---------------------------------------------------------------------------
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"

COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.yml}"
COMPOSE_SERVICE_MYSQL="${COMPOSE_SERVICE_MYSQL:-lms2_mysql}"
DB_DATABASE="${DB_DATABASE:-dasdaskom}"
DB_PASSWORD="${DB_PASSWORD:-password}"
BACKUP_DIR="${BACKUP_DIR:-${REPO_ROOT}/backup}"
BACKUP_RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-7}"
BACKUP_RCLONE_REMOTE="${BACKUP_RCLONE_REMOTE:-}"
RESTORE_TEST_DATABASE="${RESTORE_TEST_DATABASE:-lms2_restore_check}"

# Docker CLI to use. DOCKER_BIN wins if set; otherwise auto-detect the first
# binary that can actually reach the daemon. This matters under WSL, where the
# Linux `docker` shim can fail (unix:///var/run/docker.sock) while the Windows
# `docker.exe` on PATH works.
resolve_docker_bin() {
  if [[ -n "${DOCKER_BIN:-}" ]]; then
    printf '%s\n' "${DOCKER_BIN}"
    return 0
  fi
  local candidate
  for candidate in docker docker.exe \
      "/mnt/c/Program Files/Docker/Docker/resources/bin/docker.exe"; do
    if command -v "${candidate}" >/dev/null 2>&1 \
       && "${candidate}" ps >/dev/null 2>&1; then
      printf '%s\n' "${candidate}"
      return 0
    fi
  done
  # Nothing reachable; fall back to `docker` so the error message is sensible.
  printf '%s\n' "docker"
}
DOCKER_BIN="$(resolve_docker_bin)"

DRY_RUN="false"
MODE="backup"
RESTORE_TEST_FILE=""

# ---------------------------------------------------------------------------
# Logging
# ---------------------------------------------------------------------------
log()  { printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
warn() { printf '[%s] WARN: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
die()  { printf '[%s] ERROR: %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; exit 1; }

usage() {
  sed -n '2,40p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dry-run)
      DRY_RUN="true"
      shift
      ;;
    --restore-test)
      [[ $# -ge 2 ]] || die "--restore-test requires a <dumpfile> argument"
      MODE="restore-test"
      RESTORE_TEST_FILE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      die "unknown argument: $1 (try --help)"
      ;;
  esac
done

# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------
compose_exec() {
  "${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" exec -T "${COMPOSE_SERVICE_MYSQL}" "$@"
}

# Fail early with a clear message if the mysql service is not reachable.
require_compose_service() {
  if [[ "${DRY_RUN}" == "true" ]]; then
    log "dry-run: skipping container reachability check for '${COMPOSE_SERVICE_MYSQL}'"
    return 0
  fi
  # Capture first, then match. Chaining `compose ps | grep -qx` under
  # `set -o pipefail` is brittle across environments (WSL interop, slow CLI
  # startup), so avoid the pipeline entirely.
  local running_services
  running_services="$("${DOCKER_BIN}" compose -f "${COMPOSE_FILE}" ps --status running --services 2>/dev/null || true)"
  if ! grep -qx "${COMPOSE_SERVICE_MYSQL}" <<< "${running_services}"; then
    die "service '${COMPOSE_SERVICE_MYSQL}' is not running (compose file: ${COMPOSE_FILE}). Start it first."
  fi
}

# ---------------------------------------------------------------------------
# Backup
# ---------------------------------------------------------------------------
do_backup() {
  log "==> Backup starting"
  log "    compose file : ${COMPOSE_FILE}"
  log "    service      : ${COMPOSE_SERVICE_MYSQL}"
  log "    database     : ${DB_DATABASE}"
  log "    backup dir   : ${BACKUP_DIR}"
  log "    retention    : ${BACKUP_RETENTION_DAYS} day(s)"
  log "    dry-run      : ${DRY_RUN}"

  require_compose_service

  local timestamp dump_file
  timestamp="$(date '+%Y%m%d-%H%M%S')"
  dump_file="${BACKUP_DIR}/lms2-${timestamp}.sql"

  if [[ "${DRY_RUN}" == "true" ]]; then
    printf '   [dry-run] would create directory: %s\n' "${BACKUP_DIR}"
    printf '   [dry-run] would dump %s -> %s\n' "${DB_DATABASE}" "${dump_file}"
  else
    mkdir -p "${BACKUP_DIR}"
  fi

  log "==> Dumping '${DB_DATABASE}' -> ${dump_file}"

  # Dump to a temp file first so a partial/failed dump never looks like success.
  local tmp_file="${dump_file}.partial"
  local dump_status=0

  if [[ "${DRY_RUN}" == "true" ]]; then
    printf '   [dry-run] would run: docker compose -f %s exec -T %s mysqldump --single-transaction --quick --lock-tables=false -uroot -p"***" %s > %s\n' \
      "${COMPOSE_FILE}" "${COMPOSE_SERVICE_MYSQL}" "${DB_DATABASE}" "${dump_file}"
  else
    # Note: the container does NOT export DB_PASSWORD/DB_DATABASE, so we bake
    # the resolved host-side values into the command instead of relying on the
    # container environment.
    set +e
    compose_exec sh -c "exec mysqldump --single-transaction --quick --lock-tables=false -uroot -p'${DB_PASSWORD}' '${DB_DATABASE}'" \
      > "${tmp_file}" 2> >(sed 's/^/    mysqldump: /' >&2)
    dump_status=$?
    set -e

    if [[ ${dump_status} -ne 0 ]]; then
      rm -f "${tmp_file}"
      die "mysqldump failed (exit ${dump_status}); no backup written"
    fi

    if [[ ! -s "${tmp_file}" ]]; then
      rm -f "${tmp_file}"
      die "dump file is empty (${tmp_file}); refusing to report success"
    fi

    mv -f "${tmp_file}" "${dump_file}"

    # Belt-and-braces: the final file must exist and be non-empty.
    if [[ ! -s "${dump_file}" ]]; then
      die "post-write verification failed: ${dump_file} is missing or empty"
    fi

    local size
    size="$(wc -c < "${dump_file}" | tr -d ' ')"
    log "==> Backup OK: ${dump_file} (${size} bytes)"
  fi

  # -------------------------------------------------------------------------
  # Retention: delete dumps older than N days
  # -------------------------------------------------------------------------
  log "==> Applying ${BACKUP_RETENTION_DAYS}-day retention to ${BACKUP_DIR}/lms2-*.sql"
  local old_files
  old_files="$(find "${BACKUP_DIR}" -maxdepth 1 -type f -name 'lms2-*.sql' \
                -mtime "+${BACKUP_RETENTION_DAYS}" -print 2>/dev/null || true)"

  if [[ -z "${old_files}" ]]; then
    log "    no dumps older than ${BACKUP_RETENTION_DAYS} days"
  else
    while IFS= read -r stale; do
      [[ -n "${stale}" ]] || continue
      if [[ "${DRY_RUN}" == "true" ]]; then
        printf '   [dry-run] would delete: %s\n' "${stale}"
      else
        log "    deleting stale: ${stale}"
        rm -f -- "${stale}"
      fi
    done <<< "${old_files}"
  fi

  # -------------------------------------------------------------------------
  # Optional offsite copy via rclone
  # -------------------------------------------------------------------------
  log "==> Offsite copy"
  if [[ "${DRY_RUN}" == "true" ]]; then
    if command -v rclone >/dev/null 2>&1 && [[ -n "${BACKUP_RCLONE_REMOTE}" ]]; then
      printf '   [dry-run] would run: rclone copy %s %s\n' "${dump_file}" "${BACKUP_RCLONE_REMOTE}"
    else
      printf '   [dry-run] would SKIP offsite (rclone or BACKUP_RCLONE_REMOTE missing)\n'
    fi
  elif ! command -v rclone >/dev/null 2>&1; then
    warn "OFFSITE SKIPPED: rclone is not installed"
  elif [[ -z "${BACKUP_RCLONE_REMOTE}" ]]; then
    warn "OFFSITE SKIPPED: BACKUP_RCLONE_REMOTE is not set"
  else
    log "    copying to ${BACKUP_RCLONE_REMOTE} ..."
    rclone copy "${dump_file}" "${BACKUP_RCLONE_REMOTE}" \
      || warn "offsite copy failed (local backup is still intact at ${dump_file})"
    log "    offsite copy done"
  fi

  if [[ "${DRY_RUN}" == "true" ]]; then
    log "==> DRY RUN complete - nothing was written, deleted or uploaded."
  else
    log "==> Backup complete: ${dump_file}"
  fi
}

# ---------------------------------------------------------------------------
# Restore test (the real gate)
# ---------------------------------------------------------------------------
do_restore_test() {
  local dump_file="${RESTORE_TEST_FILE}"
  local scratch_db="${RESTORE_TEST_DATABASE}"

  log "==> Restore test starting"
  log "    dump file    : ${dump_file}"
  log "    scratch db   : ${scratch_db}"
  log "    dry-run      : ${DRY_RUN}"

  if [[ ! -f "${dump_file}" ]]; then
    die "dump file not found: ${dump_file}"
  fi
  if [[ ! -s "${dump_file}" ]]; then
    die "dump file is empty: ${dump_file}"
  fi

  require_compose_service

  log "==> Recreating throwaway database '${scratch_db}'"
  if [[ "${DRY_RUN}" == "true" ]]; then
    printf '   [dry-run] would run: DROP DATABASE IF EXISTS `%s`; CREATE DATABASE `%s`;\n' "${scratch_db}" "${scratch_db}"
    printf '   [dry-run] would pipe %s into mysql %s\n' "${dump_file}" "${scratch_db}"
    printf '   [dry-run] would DROP DATABASE `%s` afterwards\n' "${scratch_db}"
    log "==> DRY RUN complete - scratch database untouched."
    return 0
  fi

  compose_exec mysql -uroot -p"${DB_PASSWORD}" \
    -e "DROP DATABASE IF EXISTS \`${scratch_db}\`; CREATE DATABASE \`${scratch_db}\`;" \
    || die "could not create scratch database '${scratch_db}'"

  log "==> Restoring ${dump_file} into '${scratch_db}'"
  local restore_status=0
  set +e
  compose_exec sh -c "exec mysql -uroot -p'${DB_PASSWORD}' '${scratch_db}'" \
    < "${dump_file}" 2> >(sed 's/^/    mysql: /' >&2)
  restore_status=$?
  set -e

  if [[ ${restore_status} -ne 0 ]]; then
    compose_exec mysql -uroot -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS \`${scratch_db}\`;" || true
    die "restore into '${scratch_db}' failed (exit ${restore_status})"
  fi

  local table_count
  table_count="$(compose_exec mysql -uroot -p"${DB_PASSWORD}" -N -B \
    -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${scratch_db}';" 2>/dev/null \
    | tr -d '[:space:]')"

  if [[ -z "${table_count}" || "${table_count}" -lt 1 ]]; then
    compose_exec mysql -uroot -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS \`${scratch_db}\`;" || true
    die "restore verification failed: scratch db '${scratch_db}' has no tables"
  fi
  log "==> Restored ${table_count} table(s) into '${scratch_db}'"

  log "==> Dropping throwaway database '${scratch_db}'"
  compose_exec mysql -uroot -p"${DB_PASSWORD}" -e "DROP DATABASE IF EXISTS \`${scratch_db}\`;" \
    || die "could not drop scratch database '${scratch_db}'"

  log "==> RESTORE TEST PASSED: ${dump_file} is restorable (${table_count} tables)."
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
case "${MODE}" in
  backup)       do_backup ;;
  restore-test) do_restore_test ;;
  *)            die "unknown mode: ${MODE}" ;;
esac
