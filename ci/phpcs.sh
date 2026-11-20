#!/usr/bin/env bash
# Check an extension against the Drupal coding standards with phpcs.
#
# Usage: phpcs.sh <extension dir> [<report dir>]
#
# Environment:
#   MODE         fail (errors fail the step) or warn (report only). Default fail.
#   CODER_DIR    Where to install drupal/coder. Default ~/.cache/caravan-coder.
#   CODER_VERSION  Composer constraint for drupal/coder. Default ^8.3.
#
# Uses the extension's own phpcs.xml, phpcs.xml.dist, .phpcs.xml or
# .phpcs.xml.dist if it has one, and config/phpcs.xml otherwise. Writes
# phpcs.xml (checkstyle) to the report dir, which defaults to the current one.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
REPORT_DIR="$(realpath "${2:-.}")"
mode="${MODE:-fail}"
coder="${CODER_DIR:-$HOME/.cache/caravan-coder}"

# A separate project, so coder's dependencies can't clash with the extension's.
if [ ! -x "$coder/vendor/bin/phpcs" ]; then
  mkdir -p "$coder"
  composer --working-dir="$coder" init --no-interaction --name=caravan/coder --quiet
  composer --working-dir="$coder" config allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
  composer --working-dir="$coder" require --no-interaction --no-progress \
    "drupal/coder:${CODER_VERSION:-^8.3}" dealerdirect/phpcodesniffer-composer-installer
fi

standard="$CONFIG_DIR/phpcs.xml"
for name in phpcs.xml phpcs.xml.dist .phpcs.xml .phpcs.xml.dist; do
  if [ -f "$EXT_DIR/$name" ]; then
    standard="$EXT_DIR/$name"
    break
  fi
done
echo "Standard: ${standard#"$CARAVAN_DIR/"}"

mkdir -p "$REPORT_DIR"
report="$REPORT_DIR/phpcs.xml"
# phpcs exits non-zero when it finds anything; report.php decides the result.
"$coder/vendor/bin/phpcs" --standard="$standard" --report-full --report-checkstyle="$report" \
  -s -p --colors "$EXT_DIR" || true

php "$CI_DIR/report.php" PHPCS "$mode" "$EXT_DIR" "$report"
