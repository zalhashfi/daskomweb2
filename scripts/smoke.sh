#!/usr/bin/env bash
#
# scripts/smoke.sh - post-deploy smoke test for the LMS2 HTTP surface.
#
# WHAT IT DOES
#   Hits a small set of endpoints that prove the app booted correctly, and
#   asserts the EXACT status code expected for each one. The point is to catch
#   the nasty failure mode where a route exists but 500s: /api-v1/praktikum
#   must answer 401 (unauthenticated) - a 500 means the app is broken.
#
#   Each check is retried 3 times with a short backoff before it is declared a
#   failure, so a container that is still warming up does not produce a false
#   red. Every attempt is printed, so a retry is visible in the deploy logs.
#
#   Exits NON-ZERO if ANY check ultimately fails. Prints a summary table.
#
# USAGE
#   bash scripts/smoke.sh                          # against http://localhost:8000
#   bash scripts/smoke.sh https://lms.example.com  # explicit base URL
#   bash scripts/smoke.sh --timeout 20             # 20s per request
#   SMOKE_BASE_URL=http://127.0.0.1:8080 bash scripts/smoke.sh
#   bash scripts/smoke.sh --headers 'Cookie: auth=...'
#
# BASE URL
#   $1 (first positional arg, a URL)  >  $SMOKE_BASE_URL  >  http://localhost:8000
#
# ENVIRONMENT (all optional; sensible defaults)
#   SMOKE_BASE_URL    base URL if no positional URL is given (default http://localhost:8000)
#   SMOKE_TIMEOUT     seconds per request (default: 10); --timeout overrides
#   SMOKE_RETRIES     attempts per endpoint  (default: 3);  --retries overrides
#   SMOKE_BACKOFF     seconds between attempts (default: 2)
#   SMOKE_CURL_OPTS   extra curl options (e.g. "--resolve host:443:1.2.3.4")
#
# DEPENDENCIES
#   curl, standard text tools, bash. No jq.
#
set -euo pipefail

# ---------------------------------------------------------------------------
# Configuration & defaults
# ---------------------------------------------------------------------------
SMOKE_BASE_URL="${SMOKE_BASE_URL:-}"
SMOKE_TIMEOUT="${SMOKE_TIMEOUT:-10}"
SMOKE_RETRIES="${SMOKE_RETRIES:-3}"
SMOKE_BACKOFF="${SMOKE_BACKOFF:-2}"
SMOKE_CURL_OPTS="${SMOKE_CURL_OPTS:-}"
EXTRA_HEADERS=()
BASE_URL=""

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
    --timeout)  SMOKE_TIMEOUT="${2:?--timeout needs a value}"; shift 2 ;;
    --retries)  SMOKE_RETRIES="${2:?--retries needs a value}"; shift 2 ;;
    --backoff)  SMOKE_BACKOFF="${2:?--backoff needs a value}"; shift 2 ;;
    --headers)  EXTRA_HEADERS+=(-H "${2:?--headers needs a value}"); shift 2 ;;
    -h|--help)  usage; exit 0 ;;
    --*)        die "unknown option: $1 (try --help)" ;;
    *)          BASE_URL="$1"; shift ;;
  esac
done

command -v curl >/dev/null 2>&1 || die "curl is required but was not found on PATH"

# Response sink for curl -o. We deliberately do NOT use /dev/null (or NUL):
# in Git-Bash/MSYS on Windows the -o argument is sometimes path-translated,
# which makes curl error with exit 23 and lose the status code, and Windows
# curl cannot open a literal /dev/null either. A real temp file is created
# identically under Linux and Windows and adds one extra redirect that curl
# simply overwrites; %{http_code} is written to stdout as required.
NULL_SINK="$(mktemp "${TMPDIR:-/tmp}/smoke-sink.XXXXXX" 2>/dev/null || true)"
if [[ -z "${NULL_SINK}" ]]; then
  NULL_SINK="${TMPDIR:-/tmp}/smoke-sink.$$"
  : >"${NULL_SINK}" 2>/dev/null || NULL_SINK="${TMPDIR:-/tmp}/smoke-sink.$$.out"
fi
trap 'rm -f "${NULL_SINK}"' EXIT

# Base URL precedence: positional arg > SMOKE_BASE_URL > localhost default.
BASE_URL="${BASE_URL:-${SMOKE_BASE_URL:-http://localhost:8000}}"
BASE_URL="${BASE_URL%/}"   # strip a trailing slash so "${BASE}${path}" is clean

[[ "${SMOKE_TIMEOUT}" =~ ^[0-9]+$ ]] || die "--timeout must be a positive integer"
[[ "${SMOKE_RETRIES}" =~ ^[1-9][0-9]*$ ]] || die "--retries must be an integer >= 1"
[[ "${SMOKE_BACKOFF}" =~ ^[0-9]+$ ]] || die "--backoff must be an integer"

# ---------------------------------------------------------------------------
# Checks
#
# Each entry is: METHOD|PATH|EXPECTED_STATUS
# The 401 for the API path is the important one: 401 = route exists and the app
# correctly demands auth; 500 would mean the app is broken. 403 (CSRF/session
# rejection of an unauthenticated POST) is accepted as an equivalent
# "auth-denied" outcome only when SMOKE_ALLOW_403 is set to 1; by default the
# assertion stays strict on 401 exactly as the spec requires.
# ---------------------------------------------------------------------------
CHECKS=(
  "GET|/health|200"
  "GET|/up|200"
  "GET|/login|200"
  "POST|/api-v1/praktikum|401"
)

# ---------------------------------------------------------------------------
# Core: one HTTP request -> status code (no jq, no body, parse curl's %{http_code})
# ---------------------------------------------------------------------------
http_status() {
  local method="$1" path="$2" url status
  url="${BASE_URL}${path}"
  status="$(
    curl -sS -o "${NULL_SINK}" -w '%{http_code}' \
      --max-time "${SMOKE_TIMEOUT}" \
      -X "${method}" \
      -H 'Accept: application/json' \
      "${EXTRA_HEADERS[@]+"${EXTRA_HEADERS[@]}"}" \
      ${SMOKE_CURL_OPTS} \
      "${url}" 2>/dev/null
  )" || true
  # A transport failure (connection refused, DNS, timeout) leaves $status empty.
  [[ -n "${status}" ]] || status="000"
  printf '%s' "${status}"
}

# Returns 0 if the observed status satisfies the expectation, 1 otherwise.
status_matches() {
  local expected="$1" actual="$2"
  if [[ "${actual}" == "${expected}" ]]; then
    return 0
  fi
  # Optional escape hatch: treat 403 as auth-denied for the protected API path.
  if [[ "${expected}" == "401" && "${actual}" == "403" && "${SMOKE_ALLOW_403:-0}" == "1" ]]; then
    return 0
  fi
  return 1
}

# ---------------------------------------------------------------------------
# Run one check with retries. Populates RESULT_STATUS / RESULT_OK.
# ---------------------------------------------------------------------------
run_check() {
  local method="$1" path="$2" expected="$3"
  local attempt status ok=1

  log "CHECK ${method} ${path} (expect ${expected})"
  for (( attempt = 1; attempt <= SMOKE_RETRIES; attempt++ )); do
    status="$(http_status "${method}" "${path}")"
    if status_matches "${expected}" "${status}"; then
      log "  attempt ${attempt}/${SMOKE_RETRIES}: HTTP ${status} - OK"
      ok=0
      break
    fi
    log "  attempt ${attempt}/${SMOKE_RETRIES}: HTTP ${status} - expected ${expected}, retrying"
    if (( attempt < SMOKE_RETRIES )); then
      sleep "${SMOKE_BACKOFF}"
    fi
  done

  RESULT_STATUS="${status}"
  if (( ok == 0 )); then
    RESULT_OK=1
  else
    RESULT_OK=0
    if [[ "${expected}" == "401" && "${status}" == "500" ]]; then
      warn "  ${method} ${path} returned 500 - the endpoint is broken, not merely unauthenticated"
    fi
  fi
}

# ---------------------------------------------------------------------------
# Main
# ---------------------------------------------------------------------------
log "Smoke test against ${BASE_URL} (timeout=${SMOKE_TIMEOUT}s, retries=${SMOKE_RETRIES}, backoff=${SMOKE_BACKOFF}s)"

RESULTS=()       # "OK|METHOD|PATH|expected|actual" per check
FAILED=0

for entry in "${CHECKS[@]}"; do
  IFS='|' read -r method path expected <<<"${entry}"
  RESULT_OK=0
  RESULT_STATUS=""
  run_check "${method}" "${path}" "${expected}"
  if (( RESULT_OK == 1 )); then
    RESULTS+=("PASS|${method}|${path}|${expected}|${RESULT_STATUS}")
  else
    RESULTS+=("FAIL|${method}|${path}|${expected}|${RESULT_STATUS}")
    FAILED=$(( FAILED + 1 ))
  fi
done

# ---------------------------------------------------------------------------
# Summary table
# ---------------------------------------------------------------------------
printf '\n%-6s %-6s %-24s %-9s %-8s\n' "RESULT" "METHOD" "PATH" "EXPECTED" "ACTUAL"
printf '%-6s %-6s %-24s %-9s %-8s\n' "------" "------" "------------------------" "--------" "------"
for row in "${RESULTS[@]}"; do
  IFS='|' read -r result method path expected actual <<<"${row}"
  printf '%-6s %-6s %-24s %-9s %-8s\n' "${result}" "${method}" "${path}" "${expected}" "${actual}"
done

total="${#RESULTS[@]}"
passed=$(( total - FAILED ))
printf '\n%s/%s checks passed.\n' "${passed}" "${total}"

if (( FAILED > 0 )); then
  log "SMOKE FAILED: ${FAILED} of ${total} checks failed."
  exit 1
fi

log "SMOKE PASSED: all ${total} checks passed."
exit 0
