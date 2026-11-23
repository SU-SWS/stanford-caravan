#!/usr/bin/env bash
#
# Open a pull request from the hotfix's security/<version> branch to the site
# repository's default branch: a place to review the composer changes and to
# run the site's automated tests on them. Production gets the hotfix from its
# Acquia tag, not from merging this.
#
# Usage: hotfix-pr.sh <report-dir> <version> <acquia-tag>
#
# Runs after security/<version> is pushed. Needs GH_TOKEN with pull request
# write access to GITHUB_REPOSITORY. Writes the pull request's URL to
# <report-dir>/hotfix-pr.txt.
#
# Optional environment: PROD_TAG, RUN_URL, PR_ASSIGNEE (GitHub login, or
# several separated by commas), SITE_BASE (the base branch; default: the
# repository's default branch).

set -euo pipefail

OUT="${1:?Usage: $0 <report-dir> <version> <acquia-tag>}"
VERSION="${2:?}"
TAG="${3:?}"
: "${GH_TOKEN:?GH_TOKEN must be set}"
: "${GITHUB_REPOSITORY:?GITHUB_REPOSITORY must be set}"
branch="security/$VERSION"
base="${SITE_BASE:-$(gh api "repos/$GITHUB_REPOSITORY" --jq .default_branch)}"

# A rebuild of the same version reuses the pull request.
url=$(gh pr list -R "$GITHUB_REPOSITORY" --head "$branch" --state open --json url --jq '.[0].url // empty')
if [[ -n "$url" ]]; then
  echo "Pull request already open: $url"
else
  body="$OUT/hotfix-pr-body.md"
  {
    echo "# READY FOR REVIEW"
    echo
    echo "# Summary"
    echo "- Security hotfix \`$VERSION\` of production's \`${PROD_TAG:-unknown}\`, built by the production security update workflow and pushed to Acquia as \`$TAG\`."
    echo "- Production gets it by deploying that tag, not by merging this. This pull request runs the automated tests on it and brings its updates to \`$base\`. If \`$base\` already has these versions or newer, close it."
    echo
    echo "# Urgency"
    echo "- High: security update."
    echo
    echo "# Review Tasks"
    echo
    echo "- Check the automated tests."
    echo "- Deploy \`$TAG\` once they pass."
    echo
    echo "### Advisories"
    echo
    cat "$OUT/advisories.md"
    echo
    echo "### Updated packages"
    echo
    cat "$OUT/changes.md"
    echo
    echo "### Database updates"
    echo
    if [[ -s "$OUT/db-updates.md" ]]; then
      cat "$OUT/db-updates.md"
    else
      echo "None."
    fi
    if [[ -s "$OUT/config-notes.txt" ]]; then
      echo
      echo "### Site Configuration Sync"
      echo
      echo "Yes. Config changed by the updates:"
      echo
      cat "$OUT/config-notes.txt"
    fi
    if [[ -s "$OUT/config-prs.txt" ]]; then
      echo
      echo "Config pull requests to merge before the next release:"
      echo
      sort -u "$OUT/config-prs.txt" | awk '{ printf "- %s: %s\n", $1, $2 }'
    fi
    if [[ -n "${RUN_URL:-}" ]]; then
      echo
      echo "# Associated Issues and/or People"
      echo "- Workflow run: $RUN_URL"
    fi
  } > "$body"
  url=$(gh pr create -R "$GITHUB_REPOSITORY" --base "$base" --head "$branch" \
    --title "Security update $VERSION" --body-file "$body")
  echo "Opened $url"
fi

if [[ -n "${PR_ASSIGNEE:-}" ]]; then
  if gh pr edit "$url" --add-assignee "$PR_ASSIGNEE" > /dev/null; then
    echo "Assigned $url to $PR_ASSIGNEE"
  else
    echo "::warning::Couldn't assign $url to $PR_ASSIGNEE."
  fi
fi
echo "$url" > "$OUT/hotfix-pr.txt"
