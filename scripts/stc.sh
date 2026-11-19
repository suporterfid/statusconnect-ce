#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"

COMPOSE=(docker compose)
COMPOSE_FILES=(-f compose.yaml)

if [[ "${STC_CI:-}" == "1" || "${CI:-}" == "true" || "${GITHUB_ACTIONS:-}" == "true" ]]; then
  COMPOSE_FILES+=(-f compose.ci.yaml)
fi

compose() {
  "${COMPOSE[@]}" "${COMPOSE_FILES[@]}" "$@"
}

warn_packagist_mirror() {
  if [[ -n "${COMPOSER_PACKAGIST_URL:-}" ]]; then
    echo "WARNING: COMPOSER_PACKAGIST_URL is set (${COMPOSER_PACKAGIST_URL}). Custom Packagist mirrors can cause stale or incomplete installs." >&2
  fi
}

composer_env_args() {
  env_args=()
  if [[ -n "${COMPOSER_PACKAGIST_URL:-}" ]]; then
    env_args+=(-e "COMPOSER_PACKAGIST_URL=${COMPOSER_PACKAGIST_URL}")
  fi
}

composer_install_with_retry() {
  local max_attempts=3
  local attempt=1
  local delay=5

  warn_packagist_mirror

  local env_args=()
  composer_env_args

  while (( attempt <= max_attempts )); do
    if compose run --rm "${env_args[@]}" app composer "$@"; then
      return 0
    fi
    if (( attempt == max_attempts )); then
      echo "Composer failed after ${max_attempts} attempts." >&2
      return 1
    fi
    echo "Composer attempt ${attempt} failed; retrying in ${delay}s..." >&2
    sleep "$delay"
    delay=$((delay * 2))
    attempt=$((attempt + 1))
  done
}

usage() {
  cat <<'EOF'
StatusConnect Docker toolchain

Usage:
  ./scripts/stc.sh <verb> [args...]

Verbs:
  up           Start core services (app, mysql, mailpit, target)
  down         Stop and remove containers
  bootstrap    Install dependencies, prepare env, migrate database
  composer     Run composer via app container
  artisan      Run artisan via app container
  npm          Run npm via node container (dev profile)
  test         Run PHPUnit test suite
  e2e          Run the Playwright browser tests on an isolated stack (e2e/)
  ce-check     Community Edition check: without the enterprise parts, install, migrate, test, build
  deploy       Build production release and publish to Hostinger over SSH (scripts/deploy-hostinger.sh)
  shell        Open shell in app container
  help         Show this help
EOF
}

cmd_up() {
  compose up -d --build mysql mailpit target app
}

cmd_down() {
  compose down "$@"
}

cmd_bootstrap() {
  if [[ ! -f .env ]]; then
    cp .env.example .env
    echo "Created .env from .env.example"
  fi

  compose up -d --build mysql mailpit target
  compose up -d --wait mysql

  composer_install_with_retry install

  if compose run --rm app test -f artisan; then
    compose run --rm app php artisan key:generate --force
    compose run --rm app php artisan migrate --force
  else
    echo "Laravel not scaffolded yet; skipping artisan bootstrap steps."
  fi

  if [[ -f package.json ]]; then
    compose --profile dev run --rm node npm ci || compose --profile dev run --rm node npm install
  fi

  compose up -d --build app
  echo "Bootstrap complete."
}

cmd_composer() {
  if [[ "${1:-}" == "install" ]]; then
    composer_install_with_retry install "${@:2}"
    return
  fi

  warn_packagist_mirror
  local env_args=()
  composer_env_args
  compose run --rm "${env_args[@]}" app composer "$@"
}

cmd_artisan() {
  compose run --rm app php artisan "$@"
}

cmd_npm() {
  compose --profile dev run --rm --service-ports node npm "$@"
}

cmd_test() {
  if compose run --rm app test -f artisan; then
    compose run --rm -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app php artisan test "$@"
  elif compose run --rm app test -f vendor/bin/pest; then
    compose run --rm -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app vendor/bin/pest "$@"
  elif compose run --rm app test -f vendor/bin/phpunit; then
    compose run --rm -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: app vendor/bin/phpunit "$@"
  else
    echo "No test runner found. Scaffold Laravel or install dev dependencies first." >&2
    return 1
  fi
}

# Browser tests (e2e/, #173): an isolated stack under its own compose project,
# a fresh database, a platform admin, then the Playwright suite. E2E_KEEP=1
# leaves the stack running for debugging. Needs vendor/ (run bootstrap once).
E2E_PROJECT=statusconnect-e2e
E2E_ADMIN_EMAIL=admin@e2e.test

e2e_compose() {
  "${COMPOSE[@]}" -p "$E2E_PROJECT" -f compose.yaml -f compose.e2e.yaml --profile e2e "$@"
}

e2e_prepare() {
  e2e_compose up -d --build --wait mysql mailpit target
  e2e_compose up -d --build app
  e2e_compose exec -T app php artisan migrate:fresh --force >/dev/null

  local output
  output="$(e2e_compose exec -T app php artisan statusconnect:provision-account \
    --email="$E2E_ADMIN_EMAIL" --org="E2E Platform" --slug=e2e-platform --platform-admin)"
  E2E_ADMIN_PASSWORD="$(sed -n 's/.*Temporary password[^:]*: *//p' <<<"$output" | tr -d '[:space:]')"
  if [[ -z "$E2E_ADMIN_PASSWORD" ]]; then
    echo "$output" >&2
    echo "Could not read the platform admin's temporary password." >&2
    return 1
  fi
}

cmd_e2e() {
  local rc=0
  E2E_ADMIN_PASSWORD=""

  if e2e_prepare; then
    E2E_ADMIN_EMAIL="$E2E_ADMIN_EMAIL" E2E_ADMIN_PASSWORD="$E2E_ADMIN_PASSWORD" \
      e2e_compose run --rm playwright sh -c 'npm ci --no-audit --no-fund --loglevel=error && npx playwright test "$@"' -- "$@" || rc=$?
    # The Playwright image runs as root; hand the report back to the caller.
    e2e_compose run --rm --no-deps playwright chown -R "$(id -u):$(id -g)" /work/e2e/test-results /work/e2e/playwright-report >/dev/null 2>&1 || true
  else
    rc=1
  fi

  if [[ "${E2E_KEEP:-0}" == "1" ]]; then
    echo "E2E_KEEP=1: stack left running. Remove it with: docker compose -p $E2E_PROJECT -f compose.yaml -f compose.e2e.yaml --profile e2e down -v"
  else
    e2e_compose down -v --remove-orphans >/dev/null 2>&1 || true
  fi

  [[ $rc -eq 0 ]] && echo "E2E passed. Report: e2e/playwright-report/index.html" || echo "E2E failed (exit $rc). Report and traces: e2e/playwright-report/, e2e/test-results/" >&2
  return $rc
}

# Runs a helper script that this edition may not ship (the Community Edition has no
# deploy-hostinger.sh or ce-check.sh, #251).
run_optional_script() {
  local script="$ROOT_DIR/scripts/$1"
  shift
  if [[ ! -f "$script" ]]; then
    echo "scripts/$(basename "$script") is not part of this edition." >&2
    return 1
  fi
  bash "$script" "$@"
}

cmd_deploy() {
  run_optional_script deploy-hostinger.sh "$@"
}

cmd_shell() {
  compose run --rm app bash
}

main() {
  local verb="${1:-help}"
  shift || true

  case "$verb" in
    up) cmd_up "$@" ;;
    down) cmd_down "$@" ;;
    bootstrap) cmd_bootstrap "$@" ;;
    composer) cmd_composer "$@" ;;
    artisan) cmd_artisan "$@" ;;
    npm) cmd_npm "$@" ;;
    test) cmd_test "$@" ;;
    e2e) cmd_e2e "$@" ;;
    ce-check) run_optional_script ce-check.sh "$@" ;;
    deploy) cmd_deploy "$@" ;;
    shell) cmd_shell "$@" ;;
    help|-h|--help) usage ;;
    *)
      echo "Unknown verb: $verb" >&2
      usage >&2
      exit 1
      ;;
  esac
}

main "$@"
