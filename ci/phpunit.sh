#!/usr/bin/env bash
# Run an extension's PHPUnit tests in a project made by build.sh.
#
# Usage: phpunit.sh <extension dir> <site dir>
#
# Environment:
#   COVERAGE            "true" to collect coverage (runs Unit and Kernel tests only).
#   COVERAGE_REQUIRED   Minimum line coverage percent. Default 90.
#   SIMPLETEST_DB       Test database URL. Default: built from the DRUPAL_DATABASE_*
#                       variables install.sh uses (NAME, USERNAME, PASSWORD, HOST,
#                       PORT), each defaulting to drupal, drupal, drupal, 127.0.0.1, 3306.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
SITE_DIR="$(realpath "$2")"

if [ -z "$(find "$EXT_DIR" -name '*Test.php' -print -quit)" ]; then
  echo "No PHPUnit tests in $(ext_name "$EXT_DIR")."
  exit 0
fi

ext="$(ext_path "$EXT_DIR")"
reports="$SITE_DIR/artifacts/phpunit"
mkdir -p "$SITE_DIR/web/sites/simpletest" "$reports"

# Measure coverage of this extension only. PHPUnit lets environment variables
# that are already set win over the <env> values in this file.
sed "s|modules/custom/\*|$(ext_type "$EXT_DIR")s/custom/$(ext_name "$EXT_DIR")|g" \
  "$CONFIG_DIR/phpunit.xml" > "$SITE_DIR/web/core/phpunit.xml"
if [ -z "${SIMPLETEST_DB:-}" ]; then
  db_login="${DRUPAL_DATABASE_USERNAME:-drupal}:${DRUPAL_DATABASE_PASSWORD:-drupal}"
  db_server="${DRUPAL_DATABASE_HOST:-127.0.0.1}:${DRUPAL_DATABASE_PORT:-3306}"
  export SIMPLETEST_DB="mysql://$db_login@$db_server/${DRUPAL_DATABASE_NAME:-drupal}"
fi

args=(--config=core --log-junit "$reports/results.xml")
if [ "${COVERAGE:-false}" = "true" ]; then
  args+=(--filter='/(Unit|Kernel)/'
    --coverage-html="$reports/coverage/html"
    --coverage-xml="$reports/coverage/xml"
    --coverage-clover "$reports/coverage/clover.xml")
fi

cd "$SITE_DIR/web"
status=0
../vendor/bin/phpunit "${args[@]}" "$SITE_DIR/$ext" || status=$?

if [ "${COVERAGE:-false}" = "true" ]; then
  php "$CI_DIR/check-coverage.php" "$reports/coverage/xml/index.xml" "${COVERAGE_REQUIRED:-90}" || status=1
fi
exit "$status"
