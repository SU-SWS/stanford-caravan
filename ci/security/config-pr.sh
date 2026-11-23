#!/usr/bin/env bash
#
# Open pull requests that carry config changed by a security update's
# database updates back to where the config lives, so the next regular
# release has it and its `drush deploy` doesn't revert it.
#
# Usage: config-pr.sh <report-dir> <version> <acquia-tag>
#
# Runs from the site repository's root with the hotfix's composer.lock in
# place. Reads <report-dir>/config/*/ (from test-update.sh) and the other
# report files, and appends "<label> <url>" lines to
# <report-dir>/config-prs.txt.
#
# - Sync directories inside a composer package (mode "package"): one pull
#   request per package, in the package's GitHub repository, against the
#   branch the lock follows. Needs PACKAGE_GH_TOKEN with contents and pull
#   request write access there; without it they're skipped with a warning.
#   The hotfix ships the same change as a patch until the package has it.
# - Sync directories committed in the site repository (mode "repo"): one pull
#   request for all of them, in the site repository, against its default
#   branch. Needs SITE_GH_TOKEN (the workflow's GITHUB_TOKEN) with contents
#   and pull request write access. The hotfix commit already has the change,
#   but only on security/<version>.
#
# Each branch is named after the change itself (security-config/<hash>), so
# runs that need the same change share one pull request; later ones add a
# comment to it.
#
# Optional environment: PROD_TAG, PROD_SHA (the site commit production runs,
# to branch from if the change conflicts with the default branch), RUN_URL,
# PR_ASSIGNEE (GitHub login, or several separated by commas, to assign each
# pull request to), CLONE_URL_TEMPLATE (printf template for clone URLs,
# default https://github.com/%s.git; for testing).

set -euo pipefail

OUT="${1:?Usage: $0 <report-dir> <version> <acquia-tag>}"
VERSION="${2:?}"
TAG="${3:?}"
SITE_REPO="${GITHUB_REPOSITORY:-this repository}"

# Assign a pull request to PR_ASSIGNEE, if set. Only a warning on failure
# (for example, the person can't be assigned in that repository), so the
# pull request still counts as opened.
assign() {
  [[ -n "${PR_ASSIGNEE:-}" ]] || return 0
  if gh pr edit "$1" --add-assignee "$PR_ASSIGNEE" > /dev/null; then
    echo "Assigned $1 to $PR_ASSIGNEE"
  else
    echo "::warning::Couldn't assign $1 to $PR_ASSIGNEE."
  fi
}

# Shared by both kinds of pull request.
pr_details() {
  echo "# Associated Issues and/or People"
  echo "- Production tag: \`${PROD_TAG:-unknown}\`; hotfix tag: \`$TAG\`"
  [[ -n "${RUN_URL:-}" ]] && echo "- Workflow run: $RUN_URL"
  echo
  echo "Advisories:"
  echo
  cat "$OUT/advisories.md"
  echo
  echo "Updated packages:"
  echo
  cat "$OUT/changes.md"
  if [[ -s "$OUT/db-updates.md" ]]; then
    echo
    echo "Database updates:"
    echo
    cat "$OUT/db-updates.md"
  fi
}

# open_pr <label> <repo> <base> <fallback-sha> <patch> <body-writer>
#
# Opens (or comments on) the pull request for one patch. The patch applies
# at -p1 to the root of <repo>. <fallback-sha> is the commit the patch was
# made against, used when it conflicts with <base>. <body-writer> is a
# function that prints the pull request body; it can use $based_on.
# Uses GH_TOKEN for gh and for the clone.
open_pr() {
  local label="$1" repo="$2" base="$3" fallback_sha="$4" patch="$5" body_writer="$6"
  local fingerprint branch existing dir url body

  # Name the branch after the resulting files, not the patch, so the same
  # change made against different versions still matches.
  fingerprint=$(grep -E '^(\+\+\+ |\+[^+]|-[^-])' "$patch" | sha256sum | cut -c1-10)
  branch="security-config/$fingerprint"
  echo "$label ($repo): pull request against $base from $branch."

  existing=$(gh pr list -R "$repo" --head "$branch" --state open --json url --jq '.[0].url // empty')
  if [[ -n "$existing" ]]; then
    gh pr comment "$existing" --body "Also needed by \`$SITE_REPO\` for its \`$VERSION\` security update (\`$TAG\`)${RUN_URL:+: $RUN_URL}." > /dev/null
    echo "Commented on $existing"
    assign "$existing"
    echo "$label $existing" >> "$OUT/config-prs.txt"
    return 0
  fi

  dir=$(mktemp -d)
  # Authenticate this clone only, through gh and GH_TOKEN.
  # shellcheck disable=SC2059 # CLONE_URL_TEMPLATE is a printf template.
  git -c credential.helper= -c 'credential.helper=!gh auth git-credential' \
    clone -q --filter=blob:none --no-tags --branch "$base" "$(printf "${CLONE_URL_TEMPLATE:-https://github.com/%s.git}" "$repo")" "$dir"
  git -C "$dir" config credential.helper ''
  git -C "$dir" config --add credential.helper '!gh auth git-credential'
  # The workflow sets the commit identity globally.

  if git -C "$dir" apply --check -R -p1 "$patch" 2> /dev/null; then
    echo "$repo $base already has these config changes; no pull request needed."
    rm -rf "$dir"
    return 0
  fi

  # Apply to the tip of the branch if possible. Otherwise branch from the
  # commit the patch was made against, and leave the conflicts to the pull
  # request.
  git -C "$dir" switch -q -c "$branch"
  based_on="the tip of \`$base\`"
  if ! git -C "$dir" apply --3way -p1 "$patch"; then
    if [[ -z "$fallback_sha" ]]; then
      echo "::warning::The $label config changes don't apply cleanly to $base; open its pull request by hand from the report."
      rm -rf "$dir"
      return 0
    fi
    echo "::warning::The $label config changes don't apply cleanly to $base; branching from the production commit instead."
    git -C "$dir" reset -q --hard
    git -C "$dir" cat-file -e "$fallback_sha^{commit}" 2> /dev/null || git -C "$dir" fetch -q origin "$fallback_sha"
    git -C "$dir" switch -q -C "$branch" "$fallback_sha"
    git -C "$dir" apply -p1 "$patch"
    based_on="\`${fallback_sha:0:10}\`, the commit production runs, because it conflicts with \`$base\`"
  fi
  git -C "$dir" add -A
  git -C "$dir" commit -q -m "Config changes from database updates in a security update"
  git -C "$dir" push -q --force origin "HEAD:refs/heads/$branch"

  body=$(mktemp)
  "$body_writer" > "$body"
  url=$(gh pr create -R "$repo" --base "$base" --head "$branch" \
    --title "Config changes from database updates in a security update" \
    --body-file "$body")
  echo "Opened $url"
  assign "$url"
  echo "$label $url" >> "$OUT/config-prs.txt"
  rm -rf "$dir" "$body"
}

# --- Composer packages: one pull request each. ------------------------------

package_body() {
  echo "# READY FOR REVIEW"
  echo
  echo "# Summary"
  echo "- The database updates in \`$SITE_REPO\`'s \`$VERSION\` security update change configuration. This exports those changes to \`$(jq -r .rel_dir "$meta")\`, so \`drush deploy\` doesn't revert them."
  echo "- Until this is released, that hotfix ships the same change as \`$patch_file\`. Remove the patch from its \`composer.json\` once a release includes this."
  echo "- Built on $based_on."
  echo
  echo "# Review By (Date)"
  echo "- Before the next release from \`$base\`. A release without these changes reverts them on deploy."
  echo
  echo "# Urgency"
  echo "- High: security update."
  echo
  echo "# Review Tasks"
  echo
  echo "## Setup tasks and/or behavior to test"
  echo
  echo "1. Check that each change below comes from the module updates, not from the CI site's install."
  echo "2. Check out this branch, run the updates, then \`drush cr ; drush ci\` and confirm nothing is reverted."
  echo
  echo "### Site Configuration Sync"
  echo
  echo "Yes. Changed config (tested on the \`$site\` site of \`$SITE_REPO\`):"
  echo
  cat "$cdir/changes.md"
  echo
  pr_details
}

for meta in "$OUT"/config/*/meta.json; do
  [[ -f "$meta" ]] || continue
  [[ "$(jq -r .mode "$meta")" == package ]] || continue
  if [[ -z "${PACKAGE_GH_TOKEN:-}" ]]; then
    echo "::warning::CONFIG_PR_TOKEN isn't set; open the package pull requests by hand from the config patches in the report."
    break
  fi
  export GH_TOKEN="$PACKAGE_GH_TOKEN"
  cdir=$(dirname "$meta")
  package=$(jq -r .package "$meta")
  patch_file=$(jq -r .patch_file "$meta")
  site=$(jq -r .site "$meta")

  read -r locked_version locked_sha source_url < <(
    jq -r --arg p "$package" '(.packages + (.["packages-dev"] // []))[] | select(.name == $p) | "\(.version) \(.source.reference // "") \(.source.url // "")"' composer.lock
  )
  repo=$(sed -nE 's#^(https://github\.com/|git@github\.com:)([^/]+/[^/]+)$#\2#p' <<< "$source_url" | sed 's/\.git$//')
  if [[ -z "$repo" ]]; then
    echo "::warning::$package isn't hosted on GitHub ($source_url); open its pull request by hand from $(basename "$cdir")/config.patch in the report."
    continue
  fi
  # Locked to a branch (13.x-dev) or a release (12.0.3): target that branch,
  # or the release's major branch.
  case "$locked_version" in
    dev-*) base="${locked_version#dev-}" ;;
    *-dev) base="${locked_version%-dev}" ;;
    *) base="${locked_version%%.*}.x" ;;
  esac
  echo "$package is locked to $locked_version."
  open_pr "$package" "$repo" "$base" "$locked_sha" "$cdir/config.patch" package_body
done

# --- This repository: one pull request for every committed sync dir. ------

repo_metas=()
for meta in "$OUT"/config/*/meta.json; do
  [[ -f "$meta" && "$(jq -r .mode "$meta")" == repo ]] && repo_metas+=("$meta")
done

repo_body() {
  echo "# READY FOR REVIEW"
  echo
  echo "# Summary"
  echo "- The database updates in the \`$VERSION\` security update change configuration committed in this repository. This brings those changes to \`$base\`, so the next release's \`drush deploy\` doesn't revert them."
  echo "- The hotfix commit already has them, but only on \`security/$VERSION\`, which isn't merged back. Only the config changes are here; \`$base\` gets the package updates from its own dependency updates."
  echo "- Built on $based_on."
  echo
  echo "# Review By (Date)"
  echo "- Before the next release from \`$base\`. A release without these changes reverts them on deploy."
  echo
  echo "# Urgency"
  echo "- High: security update."
  echo
  echo "# Review Tasks"
  echo
  echo "## Setup tasks and/or behavior to test"
  echo
  echo "1. Check that each change below comes from the module updates, not from the CI site's install."
  echo "2. Once \`$base\` has the updated packages, run the updates, then \`drush cr ; drush ci\` and confirm nothing is reverted."
  echo
  echo "### Site Configuration Sync"
  echo
  echo "Yes. Changed config:"
  echo
  for meta in "${repo_metas[@]}"; do
    echo "#### \`$(jq -r .sync_dir "$meta")\` (tested on the \`$(jq -r .site "$meta")\` site)"
    echo
    cat "$(dirname "$meta")/changes.md"
    echo
  done
  pr_details
}

if (( ${#repo_metas[@]} > 0 )); then
  if [[ -z "${SITE_GH_TOKEN:-}" || -z "${GITHUB_REPOSITORY:-}" ]]; then
    echo "::warning::No token for this repository; merge the config changes on security/$VERSION into the default branch by hand."
  else
    export GH_TOKEN="$SITE_GH_TOKEN"
    combined="$OUT/repo-config.patch"
    for meta in "${repo_metas[@]}"; do
      cat "$(dirname "$meta")/config.patch"
    done > "$combined"
    base="${SITE_BASE:-$(gh api "repos/$GITHUB_REPOSITORY" --jq .default_branch)}"
    open_pr "${GITHUB_REPOSITORY#*/}" "$GITHUB_REPOSITORY" "$base" "${PROD_SHA:-}" "$combined" repo_body
  fi
fi
