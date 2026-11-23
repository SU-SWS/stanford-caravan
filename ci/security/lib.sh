#!/usr/bin/env bash
#
# Helpers shared by the scripts. Source it; don't run it.

# Print a value from the site's drush/drush.yml, one line per list item.
# Prints nothing when the key is missing.
#
#   drush_yml command.sws.options.git-url
drush_yml() {
  local file="${DRUSH_YML:-drush/drush.yml}"
  [[ -f "$file" ]] || return 0
  if python3 -c 'import yaml' 2> /dev/null; then
    python3 -I -c '
import sys, yaml
value = yaml.safe_load(open(sys.argv[1])) or {}
for key in sys.argv[2].split("."):
    value = value.get(key) if isinstance(value, dict) else None
for item in value if isinstance(value, list) else ([] if value is None else [value]):
    print(item)
' "$file" "$1"
  else
    # mikefarah yq, preinstalled on GitHub's Ubuntu runners. Quote each key
    # so names like git-url work.
    local path
    path=$(awk -F. '{ for (i = 1; i <= NF; i++) printf "[\"%s\"]", $i }' <<< "$1")
    yq -r ".${path} | select(. != null) | ((select(tag == \"!!seq\") | .[]), select(tag != \"!!seq\"))" "$file"
  fi
}

# The install profile drupal:install uses for a site: the site's sws.yml,
# then project.profile in drush.yml, then stanford_profile.
site_profile() {
  local site="$1" profile=""
  if [[ -f "docroot/sites/$site/sws.yml" ]]; then
    profile=$(DRUSH_YML="docroot/sites/$site/sws.yml" drush_yml site.profile)
  fi
  [[ -n "$profile" && "$profile" != null ]] || profile=$(drush_yml project.profile)
  [[ -n "$profile" && "$profile" != null ]] || profile=$(drush_yml command.site.install.profile)
  [[ -n "$profile" && "$profile" != null ]] || profile=stanford_profile
  echo "$profile"
}

# Escape text for Slack mrkdwn.
slack_escape() {
  sed 's/&/\&amp;/g; s/</\&lt;/g; s/>/\&gt;/g'
}
