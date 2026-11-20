#!/usr/bin/env bash
# Install Drupal in a project made by build.sh, ready for Codeception.
#
# Usage: install.sh <extension dir> <site dir>
#
# Environment:
#   PROFILE   Install profile when the extension is a module or theme. Default standard.
#   MODULES   Comma-separated extra modules to enable.
#   DRUPAL_DATABASE_{NAME,USERNAME,PASSWORD,HOST,PORT}  Read by the settings file.
set -euo pipefail
source "$(dirname "$0")/lib.sh"

EXT_DIR="$(realpath "$1")"
SITE_DIR="$(realpath "$2")"
name="$(ext_name "$EXT_DIR")"
type="$(ext_type "$EXT_DIR")"
profile="${PROFILE:-standard}"
[ "$type" = "profile" ] && profile="$name"

cp "$CONFIG_DIR/circleci.settings.php" "$SITE_DIR/web/sites/default/settings.php"

cd "$SITE_DIR/web"
drush() { ../vendor/bin/drush -y "$@"; }

drush site:install "$profile" -v
# A custom profile's config/sync holds the configuration it ships with.
if [ "$profile" != "minimal" ] && [ "$profile" != "standard" ]; then
  drush config:import
fi
drush cache:rebuild

modules=()
IFS=, read -r -a modules <<< "${MODULES:-}"
[ "$type" = "module" ] && modules+=("$name")
for module in "${modules[@]}"; do
  [ -n "$module" ] && drush pm:install "$module"
done
if [ "$type" = "theme" ]; then
  drush theme:install "$name"
fi
