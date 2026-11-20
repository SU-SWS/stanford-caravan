#!/usr/bin/env bash
# Run an extension's Codeception tests on a site made by build.sh and install.sh.
#
# Usage: codeception.sh <extension dir> <site dir>
#
# Environment:
#   SUITES     Comma-separated suites. Default acceptance,functional.
#   TEST_DIR   Tests, relative to the extension. Default tests/codeception.
#   SHARD      Run part of each suite, e.g. 2/4.
#   DOMAIN     Host name the site is served on. Default localhost.
#
# Failed tests run once more.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
SITE_DIR="$(realpath "$2")"
test_dir="$SITE_DIR/$(ext_path "$EXT_DIR")/${TEST_DIR:-tests/codeception}"

if [ ! -d "$test_dir" ]; then
  echo "No Codeception tests in $test_dir."
  exit 0
fi

cd "$SITE_DIR"
codecept() { vendor/bin/codecept "$@"; }

[ -f codeception.yml ] || codecept bootstrap
codeception_config "$CONFIG_DIR/codeception/codeception.yml" > codeception.yml
rsync -a "$test_dir/" tests/
mkdir -p _data _output _support

# Environments defined in codeception.yml. github_actions points WebDriver at
# the Selenium service.
envs=()
[ -n "${CI:-}" ] && envs+=(ci)
[ -n "${GITHUB_ACTIONS:-}" ] && envs+=(github_actions)

status=0
IFS=, read -r -a suites <<< "${SUITES:-acceptance,functional}"
for suite in "${suites[@]}"; do
  codeception_config "$CONFIG_DIR/codeception/$suite.suite.yml" > "tests/$suite.suite.yml"
  codecept build

  output="$SITE_DIR/artifacts/$suite"
  args=(--steps --override="paths: output: $output")
  [ "${#envs[@]}" -gt 0 ] && args+=(--env="$(IFS=,; echo "${envs[*]}")")

  report=results.html
  run_args=("${args[@]}")
  [ -n "${SHARD:-}" ] && run_args+=(--shard="$SHARD")
  if ! codecept run "$suite" --html results.html --xml results.xml "${run_args[@]}"; then
    echo "::group::Retrying the failed $suite tests"
    report=retry.html
    codecept run "$suite" --group failed --html retry.html --xml retry.xml "${args[@]}" || status=1
    echo "::endgroup::"
  fi

  if [ ! -f "$output/$report" ]; then
    echo "::error::The $suite suite wrote no $report. The run probably failed before any test started."
    status=1
    continue
  fi
  php "$CI_DIR/summary.php" "$SITE_DIR" "$output/$report" "$suite" || true
done
exit "$status"
