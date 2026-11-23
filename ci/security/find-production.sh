#!/usr/bin/env bash
#
# Find what production runs and group it by tag.
#
# Usage: find-production.sh
#
# Runs from the site repository's root. Prints a JSON array with one target
# per distinct production tag:
#   [{"tag": "2026-10-30_7.0.3", "git_urls": ["…:app.git"], "labels": ["app (prod)"]}]
# Each target becomes one hotfix, pushed to the git URLs of every
# application that runs that tag.
#
# Environment:
#   PROD_REF            A tag to use instead of asking an API. Pushed to every
#                       git-url in drush/drush.yml.
#   REF_SOURCE          cloud (default) or acsf.
#   ACQUIA_APPS         cloud: application UUIDs, one per line. Defaults to
#                       command.sws.options.app-id in drush/drush.yml.
#   ACQUIA_ENV          cloud: environment name (default prod).
#   ACQUIA_API_KEY, ACQUIA_API_SECRET
#                       cloud: Cloud Platform API credentials.
#   ACSF_URL            acsf: Site Factory URL, e.g. https://www.example.acsitefactory.com
#   ACSF_STACK_ID       acsf: stack (default 1).
#   ACSF_USER, ACSF_KEY acsf: Site Factory API credentials.

set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

strip_ref() {
  local ref="$1"
  ref="${ref#refs/}"
  ref="${ref#tags/}"
  echo "$ref"
}

repo_git_urls() {
  drush_yml command.sws.options.git-url | jq -R . | jq -sc .
}

if [[ -n "${PROD_REF:-}" ]]; then
  jq -nc --arg tag "$(strip_ref "$PROD_REF")" --argjson urls "$(repo_git_urls)" \
    '[{tag: $tag, git_urls: $urls, labels: ["given by hand"]}]'
  exit 0
fi

case "${REF_SOURCE:-cloud}" in
  acsf)
    : "${ACSF_URL:?ACSF_URL is required for ref-source acsf}"
    # Returns {"available": [...], "current": "tags/2026-10-30_7.0.3"}.
    # Credentials go to curl as a config on stdin (-K -), not as arguments,
    # so they don't show in the process list.
    current=$(printf 'user = "%s:%s"\n' "${ACSF_USER:?}" "${ACSF_KEY:?}" \
      | curl -fsS -K - "$ACSF_URL/api/v1/vcs?type=sites&stack_id=${ACSF_STACK_ID:-1}" | jq -r '.current // empty')
    [[ -n "$current" ]] || { echo "::error::Site Factory didn't report a current ref." >&2; exit 1; }
    jq -nc --arg tag "$(strip_ref "$current")" --argjson urls "$(repo_git_urls)" --arg label "Site Factory ($ACSF_URL)" \
      '[{tag: $tag, git_urls: $urls, labels: [$label]}]'
    ;;

  cloud)
    apps="${ACQUIA_APPS:-}"
    [[ -n "${apps//[[:space:]]/}" ]] || apps=$(drush_yml command.sws.options.app-id)
    [[ -n "${apps//[[:space:]]/}" ]] || { echo "::error::No Acquia application. Set acquia-apps or command.sws.options.app-id in drush/drush.yml." >&2; exit 1; }
    env_name="${ACQUIA_ENV:-prod}"

    # Key, secret and token go to curl as a config on stdin (-K -), not as
    # arguments, so they don't show in the process list.
    token=$(printf 'data-urlencode = "client_id=%s"\ndata-urlencode = "client_secret=%s"\n' "${ACQUIA_API_KEY:?}" "${ACQUIA_API_SECRET:?}" \
      | curl -fsS -K - -X POST --data-urlencode grant_type=client_credentials https://accounts.acquia.com/api/auth/oauth/token \
      | jq -r '.access_token // empty')
    [[ -n "$token" ]] || { echo "::error::Couldn't get a Cloud Platform API token." >&2; exit 1; }
    api() { printf 'header = "Authorization: Bearer %s"\n' "$token" | curl -fsS -K - -H 'Accept: application/json' "https://cloud.acquia.com/api/$1"; }

    rows=()
    while read -r app; do
      [[ -n "$app" ]] || continue
      name=$(api "applications/$app" | jq -r '.name // empty')
      env_json=$(api "applications/$app/environments" | jq -c --arg env "$env_name" '._embedded.items[] | select(.name == $env)')
      if [[ -z "$env_json" ]]; then
        echo "::error::Application ${name:-$app} has no '$env_name' environment." >&2
        exit 1
      fi
      path=$(jq -r '.vcs.path // empty' <<< "$env_json")
      url=$(jq -r '.vcs.url // empty' <<< "$env_json")
      [[ -n "$path" && -n "$url" ]] || { echo "::error::No VCS path or URL for ${name:-$app} $env_name." >&2; exit 1; }
      echo "${name:-$app} $env_name runs $path" >&2
      rows+=("$(jq -nc --arg tag "$(strip_ref "$path")" --arg url "$url" --arg label "${name:-$app} ($env_name)" '{tag: $tag, url: $url, label: $label}')")
    done <<< "$apps"

    printf '%s\n' "${rows[@]}" | jq -sc '
      group_by(.tag)
      | map({tag: .[0].tag, git_urls: (map(.url) | unique), labels: map(.label)})'
    ;;

  *)
    echo "::error::Unknown ref-source '${REF_SOURCE}'. Use cloud or acsf." >&2
    exit 1
    ;;
esac
