# shellcheck shell=bash
# Shared helpers for the ci/ scripts. Source it; don't run it.

CI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CARAVAN_DIR="$(dirname "$CI_DIR")"
# shellcheck disable=SC2034 # Used by the scripts that source this file.
CONFIG_DIR="$CARAVAN_DIR/config"

# Machine name of the extension in directory $1, from its *.info.yml file.
ext_name() {
  local info
  info=$(find "$1" -maxdepth 1 -name '*.info.yml' | sort | head -n 1)
  [ -n "$info" ] || { echo "No *.info.yml file in $1" >&2; return 1; }
  basename "$info" .info.yml
}

# Extension type in directory $1: module, theme or profile.
ext_type() {
  sed -n 's/^type:[[:space:]]*//p' "$1/$(ext_name "$1").info.yml" | head -n 1 | tr -d "\"'\r "
}

# Path of the extension inside the built project, relative to the project root.
ext_path() {
  echo "web/$(ext_type "$1")s/custom/$(ext_name "$1")"
}

# Drupal core constraint from CORE_VERSION: "^11" and "~11.2" pass through,
# "11" becomes "~11.0" and "11.3" becomes "~11.3.0".
core_constraint() {
  local version="${CORE_VERSION:-^11}"
  if [[ $version =~ ^[~^][0-9] ]]; then
    echo "$version"
  else
    echo "~$version.0"
  fi
}

# Fill the site path and domain into one of caravan's Codeception configs.
codeception_config() {
  sed -e "s|/var/www/html|$SITE_DIR|g" -e "s|localhost|${DOMAIN:-localhost}|g" "$1"
}
