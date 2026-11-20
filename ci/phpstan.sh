#!/usr/bin/env bash
# Analyse an extension with PHPStan, in a project built by build.sh with
# WITH_PHPSTAN=1.
#
# Usage: phpstan.sh <extension dir> <site dir> [<report dir>]
#
# Environment:
#   MODE           fail (errors fail the step) or warn (report only). Default fail.
#   PHPSTAN_LEVEL  Rule level, 0 to 10. Default 1, the level Drupal core uses.
#
# Uses the extension's own phpstan.neon or phpstan.neon.dist if it has one.
# Otherwise it analyses the whole extension except Codeception tests, with
# phpstan-drupal and the deprecation rules. Writes phpstan.xml (checkstyle)
# to the report dir, which defaults to the current one.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
SITE_DIR="$(realpath "$2")"
REPORT_DIR="$(realpath "${3:-.}")"
mode="${MODE:-fail}"
ext="$SITE_DIR/$(ext_path "$EXT_DIR")"

config=""
for name in phpstan.neon phpstan.neon.dist; do
  if [ -f "$ext/$name" ]; then
    config="$ext/$name"
    break
  fi
done
if [ -z "$config" ]; then
  config="$SITE_DIR/phpstan.neon"
  cat > "$config" <<EOF
parameters:
  level: ${PHPSTAN_LEVEL:-1}
  paths:
    - $ext
  excludePaths:
    analyseAndScan:
      - $ext/tests/codeception (?)
      - */node_modules/*
      - */vendor/*
EOF
fi
echo "Config: ${config#"$SITE_DIR/"}"

mkdir -p "$REPORT_DIR"
report="$REPORT_DIR/phpstan.xml"
cd "$SITE_DIR"
# Show the readable table in the log, then write the report without the cost
# of a second analysis: PHPStan's result cache makes the second run instant.
vendor/bin/phpstan analyse -c "$config" --no-progress --memory-limit=-1 || true
vendor/bin/phpstan analyse -c "$config" --no-progress --memory-limit=-1 \
  --error-format=checkstyle > "$report" || true

# PHPStan writes paths relative to the site directory.
php "$CI_DIR/report.php" PHPStan "$mode" "$(ext_path "$EXT_DIR")" "$report"
