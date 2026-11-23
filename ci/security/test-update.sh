#!/usr/bin/env bash
#
# Install sites on the production code, switch to the updated code, run the
# database updates and export the configuration, to see what deploying the
# update will do.
#
# Usage: test-update.sh <report-dir> <version>
#
# Runs from the site repository's root, with the updated composer.lock in
# place and the production lock at <report-dir>/composer.lock.orig (both
# written by security-update.sh). Needs a database the CI settings can reach.
# Leaves the updated code installed.
#
# Sites: SITES (one per line), else command.sws.options.multisites in
# drush/drush.yml, else default. Sites that share an install profile and a
# config sync directory get the same updates, so only the first of each group
# is tested. All tested sites share one database: each is installed and
# dumped on the production code, then restored and updated on the new code.
#
# Writes to <report-dir>:
#   tested-sites.txt   "<site> <profile> <config-sync>" for each tested site.
#   db-updates.md      Pending update, post-update and deploy hooks (empty
#                      when none), with the site when more than one is tested.
#   config-changes.md  Config the updates changed, per sync directory.
#   config/<n>/        One directory per changed sync directory:
#     meta.json          {site, sync_dir, mode, package, package_dir, rel_dir,
#                        patch_file}
#                        mode "repo": sync_dir is committed in this
#                        repository; config.patch applies at the repo root.
#                        mode "package": sync_dir belongs to a composer
#                        package; config.patch applies at the package root
#                        and the hotfix keeps it at patch_file.
#     config.patch       The change, against the files as they are now.
#     changes.md         The changed files.
#   sites/<site>/      Each site's exports, drush output and database dump.

set -euo pipefail
# shellcheck source=lib.sh
source "$(dirname "$0")/lib.sh"

OUT="${1:?Usage: $0 <report-dir> <version>}"
VERSION="${2:?Usage: $0 <report-dir> <version>}"
DRUSH=(vendor/bin/drush --yes)
ROOT=$(pwd)
# drupal:install runs child processes as a bare `drush`.
export PATH="$ROOT/vendor/bin:$PATH"

cp composer.lock "$OUT/composer.lock.new"

echo "::group::Install the production code"
cp "$OUT/composer.lock.orig" composer.lock
composer install --no-progress
echo "::endgroup::"

# Pick one site per install profile and config sync directory.
candidates="${SITES:-}"
[[ -n "${candidates//[[:space:]]/}" ]] || candidates=$(drush_yml command.sws.options.multisites)
[[ -n "${candidates//[[:space:]]/}" ]] || candidates=default
: > "$OUT/tested-sites.txt"
declare -A seen=()
while read -r site <&3; do
  [[ -n "$site" ]] || continue
  profile=$(site_profile "$site")
  sync=$("${DRUSH[@]}" --uri="$site" status --fields=config-sync --format=string)
  key="$profile|$sync"
  if [[ -n "${seen[$key]:-}" ]]; then
    echo "Skipping $site: same profile and config as ${seen[$key]}."
    continue
  fi
  seen[$key]="$site"
  echo "$site $profile $sync" >> "$OUT/tested-sites.txt"
done 3<<< "$candidates"
mapfile -t SITES_TESTED < <(cut -d' ' -f1 "$OUT/tested-sites.txt")
echo "Testing: ${SITES_TESTED[*]}"

: > "$OUT/tested-sites.installed"
while read -r site profile _sync <&3; do
  dir="$OUT/sites/$site"
  mkdir -p "$dir"
  echo "::group::Install $site on the production code"
  # drupal:install reads the profile from the site's sws.yml, then the
  # project.profile drush config. Defining project.profile makes it install
  # the profile site_profile found, which may come from
  # command.site.install.profile instead.
  "${DRUSH[@]}" -Dproject.profile="$profile" drupal:install --site="$site"
  "${DRUSH[@]}" --uri="$site" config:import
  "${DRUSH[@]}" --uri="$site" config:export --destination="$dir/config-before"
  "${DRUSH[@]}" --uri="$site" sql:dump --result-file="$dir/production.sql"
  # Some settings choose the sync directory from the installed profile, which
  # they read from the database, so read it again now that there is one.
  sync=$("${DRUSH[@]}" --uri="$site" status --fields=config-sync --format=string)
  echo "$site $profile $sync" >> "$OUT/tested-sites.installed"
  echo "::endgroup::"
done 3< "$OUT/tested-sites.txt"
mv "$OUT/tested-sites.installed" "$OUT/tested-sites.txt"

echo "::group::Install the updated code"
cp "$OUT/composer.lock.new" composer.lock
composer install --no-progress
echo "::endgroup::"

: > "$OUT/db-updates.md"
for site in "${SITES_TESTED[@]}"; do
  dir="$OUT/sites/$site"
  echo "::group::Update $site"
  "${DRUSH[@]}" --uri="$site" sql:drop
  "${DRUSH[@]}" --uri="$site" sql:query --file="$dir/production.sql"
  "${DRUSH[@]}" --uri="$site" cache:rebuild

  # Record what is pending before running it. drush prints nothing on stdout
  # when there is nothing to report.
  "${DRUSH[@]}" --uri="$site" updatedb:status --format=json > "$dir/db-updates.json"
  "${DRUSH[@]}" --uri="$site" deploy:hook-status --format=json > "$dir/deploy-hooks.json"
  for f in db-updates deploy-hooks; do
    [[ -s "$dir/$f.json" ]] || echo '{}' > "$dir/$f.json"
  done
  suffix=""
  (( ${#SITES_TESTED[@]} > 1 )) && suffix=" ($site)"
  jq -rs --arg suffix "$suffix" '
    .[][]
    | "- `\(.module)` \(.update_id // .hook // ""): \((.description // "") | gsub("\\s+"; " ") | ltrimstr(" "))\($suffix)"
  ' "$dir/db-updates.json" "$dir/deploy-hooks.json" >> "$OUT/db-updates.md"

  # Production runs `drush deploy`: updatedb, config:import, cache:rebuild
  # and deploy:hook. Export before the import would hide what changed.
  "${DRUSH[@]}" --uri="$site" updatedb
  "${DRUSH[@]}" --uri="$site" deploy:hook
  "${DRUSH[@]}" --uri="$site" config:export --destination="$dir/config-after"
  echo "::endgroup::"
done

if [[ -s "$OUT/db-updates.md" ]]; then
  echo "Database updates:"
  cat "$OUT/db-updates.md"
else
  echo "No database updates."
fi

# Composer packages and their install paths, to find who owns a sync dir.
composer show --path --format=json | jq -r '.installed[] | select(.path) | "\(.path)\t\(.name)"' > "$OUT/package-paths.tsv"

: > "$OUT/config-changes.md"
n=0
while read -r site _profile sync <&3; do
  dir="$OUT/sites/$site"
  # "<status> <path>" per changed file, relative to the export root.
  changed=$(
    cd "$dir"
    comm -3 <(cd config-before && find . -type f | sort) <(cd config-after && find . -type f | sort) \
      | awk -F'\t' '$1 != "" { print "removed " substr($1, 3) } $2 != "" { print "added " substr($2, 3) }'
    comm -12 <(cd config-before && find . -type f | sort) <(cd config-after && find . -type f | sort) \
      | while read -r f; do cmp -s "config-before/$f" "config-after/$f" || echo "changed ${f#./}"; done
  )
  [[ -n "$changed" ]] || continue

  n=$((n + 1))
  cdir="$OUT/config/$n"
  mkdir -p "$cdir"
  awk 'NF { printf "- `%s` %s\n", $2, $1 }' <<< "$changed" | sort > "$cdir/changes.md"
  sync_rel="${sync#"$ROOT"/}"

  # Committed in this repository, or part of a composer package?
  if [[ "$sync" == "$ROOT"/* ]] && ! git check-ignore -q "$sync_rel/" && [[ -n "$(git ls-files "$sync_rel" | head -n1)" ]]; then
    mode=repo package="" package_dir="" rel_dir="$sync_rel"
  else
    mode=package
    read -r package_path package < <(
      awk -F'\t' -v s="$sync" 'index(s "/", $1 "/") == 1 && length($1) > len { len = length($1); best = $1 "\t" $2 } END { print best }' "$OUT/package-paths.tsv"
    ) || true
    if [[ -z "${package:-}" ]]; then
      echo "::error::$sync_rel ($site) is neither committed here nor part of a composer package."
      exit 1
    fi
    package_dir="${package_path#"$ROOT"/}" rel_dir="${sync#"$package_path"/}"
  fi
  # Where the hotfix keeps a package patch; unique if a package has several
  # changed sync directories.
  patch_file=""
  if [[ "$mode" == package ]]; then
    patch_file="patches/security/${package//\//-}-config-$VERSION.patch"
    grep -qxF "$patch_file" "$OUT/patch-files.txt" 2>/dev/null && patch_file="${patch_file%.patch}-$n.patch"
    echo "$patch_file" >> "$OUT/patch-files.txt"
  fi
  jq -n --arg site "$site" --arg sync_dir "$sync_rel" --arg mode "$mode" --arg package "$package" \
    --arg package_dir "$package_dir" --arg rel_dir "$rel_dir" --arg patch_file "$patch_file" \
    '{site: $site, sync_dir: $sync_dir, mode: $mode, package: $package, package_dir: $package_dir, rel_dir: $rel_dir, patch_file: $patch_file}' > "$cdir/meta.json"

  # A patch that turns the sync directory's files into the exported ones,
  # for the changed files only.
  work=$(mktemp -d)
  mkdir -p "$work/old/$rel_dir" "$work/new/$rel_dir"
  while read -r status f; do
    [[ -n "$f" ]] || continue
    if [[ -f "$sync/$f" ]]; then
      mkdir -p "$(dirname "$work/old/$rel_dir/$f")"
      cp "$sync/$f" "$work/old/$rel_dir/$f"
    fi
    if [[ "$status" != removed ]]; then
      mkdir -p "$(dirname "$work/new/$rel_dir/$f")"
      cp "$dir/config-after/$f" "$work/new/$rel_dir/$f"
    fi
  done <<< "$changed"
  ( cd "$work" || exit; git diff --no-index --no-color --full-index old new || true ) \
    | sed -E '/^(diff --git|---|\+\+\+) /s#([ab])/(old|new)/#\1/#g' > "$cdir/config.patch"
  rm -rf "$work"

  {
    echo "#### \`$sync_rel\` (tested on $site)"
    if [[ ! -s "$cdir/config.patch" ]]; then
      echo "Already matches the exported config; nothing to change."
    elif [[ "$mode" == repo ]]; then
      echo "Committed with the hotfix."
    else
      echo "Part of \`$package\`: shipped as \`$patch_file\`, with a pull request to the package."
    fi
    echo
    cat "$cdir/changes.md"
    echo
  } >> "$OUT/config-changes.md"

  if [[ -s "$cdir/config.patch" ]]; then
    # Fail now rather than at commit or build time if it won't apply.
    if [[ "$mode" == repo ]]; then
      git apply --check -p1 "$cdir/config.patch"
    else
      git apply --check -p1 --directory="$package_dir" "$cdir/config.patch"
    fi
  else
    rm -rf "$cdir"
  fi
done 3< "$OUT/tested-sites.txt"

if [[ -s "$OUT/config-changes.md" ]]; then
  echo "::warning::The database updates changed configuration; see config-changes.md."
  cat "$OUT/config-changes.md"
else
  echo "The database updates didn't change any configuration."
fi
