#!/usr/bin/env bash
# Build a Drupal project with the extension in it.
#
# Usage: build.sh <extension dir> <site dir>
#
# Environment:
#   CORE_VERSION       Drupal core version (e.g. 11, 11.3, ^11). Default ^11.
#   WITH_CODECEPTION   1 to add the Codeception packages.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
SITE_DIR="$2"
core="$(core_constraint)"
ext="$(ext_path "$EXT_DIR")"

rm -rf "$SITE_DIR"
composer create-project "drupal/recommended-project:$core" "$SITE_DIR" --no-install --no-interaction
SITE_DIR="$(realpath "$SITE_DIR")"
cd "$SITE_DIR"

composer require --no-update 'drupal/core-composer-scaffold:>=11.2' 'drupal/core-recommended:>=11.2'
# Not every core version's template has these.
composer remove --no-update drupal/core-recipe-unpack drupal/core-project-message || true
composer config minimum-stability dev

# Tests and settings expect a docroot directory.
ln -s web docroot

mkdir -p "$(dirname "$ext")"
rsync -a --exclude html --exclude .git "$EXT_DIR/" "$ext/"

if compgen -G "$CONFIG_DIR/patches/*.patch" > /dev/null; then
  rsync -a "$CONFIG_DIR/patches/" patches/
fi

php "$CI_DIR/merge-composer.php" --caravan="$CARAVAN_DIR" composer.json \
  "$CONFIG_DIR/composer.json" "$ext/composer.json"

composer require --no-update "drupal/core:$core"
if [ "${WITH_CODECEPTION:-0}" = "1" ]; then
  composer require --no-update \
    'codeception/codeception:^5' \
    'codeception/module-asserts:^3' \
    'codeception/module-phpbrowser:^3' \
    'codeception/module-webdriver:^4'
fi
composer update --no-interaction --no-progress

# Standard's optional media config conflicts with stanford_media's tests.
rm -f web/core/profiles/standard/config/optional/*media*

mkdir -p artifacts
