#!/usr/bin/env bash
#
# Audit composer.lock and update the fewest packages needed to clear the
# security advisories.
#
# Usage: security-update.sh <out-dir> [drupal|all]
#
# Runs in the current directory, which must contain composer.json and
# composer.lock. Only the lock file is resolved (no install), so it is fast and
# leaves vendor/ alone. Writes these files to <out-dir>:
#   audit-before.json  Full `composer audit` result for the original lock.
#   audit-after.json   Full `composer audit` result for the updated lock.
#   targets.txt        Vulnerable packages that were in scope.
#   advisories.md      Markdown list of the advisories that were in scope.
#   changes.md         Markdown table of the packages that changed.
#   strategy.txt       The update strategy that was used.
#   unresolved.md      Advisories that no update could clear (may be empty).
#   fingerprint.txt    Hash of the in-scope advisory IDs, to detect repeat runs.
#
# Exit codes: 0 at least one advisory fixed, 3 nothing to fix, 1 error or
# nothing could be fixed.

set -euo pipefail

OUT="${1:?Usage: $0 <out-dir> [drupal|all]}"
SCOPE="${2:-drupal}"
mkdir -p "$OUT"

export COMPOSER_NO_INTERACTION=1
# Policy blocking would stop composer from resolving to the very versions we
# are trying to get away from while it searches. The final audit is the check.
export COMPOSER_NO_BLOCKING=1

audit() {
  # `composer audit` exits non-zero when it finds advisories; that's expected.
  composer audit --locked --format=json --abandoned=ignore --no-plugins 2>/dev/null || true
}

# Advisories in scope, one "package<TAB>advisoryId<TAB>title<TAB>link" per line.
# Advisories come back either as a list or as an object keyed by index.
in_scope() {
  jq -r --arg scope "$SCOPE" '
    (.advisories // {}) | to_entries[]
    | select($scope == "all" or (.key | startswith("drupal/")))
    | .value[]
    | [.packageName, .advisoryId, .title, (.link // "")] | @tsv' "$1"
}

locked_versions() {
  jq -r '(.packages + (.["packages-dev"] // []))[] | "\(.name)\t\(.version)"' "$1" | sort
}

audit > "$OUT/audit-before.json"
in_scope "$OUT/audit-before.json" > "$OUT/advisories.tsv"

if [[ ! -s "$OUT/advisories.tsv" ]]; then
  echo "No security advisories in scope ($SCOPE)."
  exit 3
fi

cut -f1 "$OUT/advisories.tsv" | sort -u > "$OUT/targets.txt"
cut -f2 "$OUT/advisories.tsv" | sort -u | sha256sum | cut -c1-16 > "$OUT/fingerprint.txt"
awk -F'\t' '{ printf "- **%s**: [%s](%s) %s\n", $1, $2, $4, $3 }' "$OUT/advisories.tsv" > "$OUT/advisories.md"

echo "Vulnerable packages in scope:"
sed 's/^/  /' "$OUT/targets.txt"

mapfile -t TARGETS < "$OUT/targets.txt"

# drupal/core is pinned to an exact version by drupal/core-recommended, so the
# packages that are released alongside it have to move with it.
if grep -qx 'drupal/core' "$OUT/targets.txt"; then
  for pkg in drupal/core-recommended drupal/core-composer-scaffold drupal/core-project-message drupal/core-dev-pinned drupal/core-dev; do
    if jq -e --arg p "$pkg" '(.packages + (.["packages-dev"] // []))[] | select(.name == $p)' composer.lock > /dev/null; then
      TARGETS+=("$pkg")
    fi
  done
fi

cp composer.lock "$OUT/composer.lock.orig"
locked_versions composer.lock > "$OUT/versions-before.tsv"

# Try the least disruptive strategy first and stop at the first one that
# clears every in-scope advisory. If none clears them all, use the first one
# that clears the most:
#   1. patch releases of the vulnerable packages only
#   2. any allowed release of the vulnerable packages only
#   3. also update their dependencies, but only where strictly required
STRATEGIES=(
  "--patch-only"
  ""
  "--with-all-dependencies --minimal-changes"
)

total=$(wc -l < "$OUT/advisories.tsv")
best_remaining=$total
for strategy in "${STRATEGIES[@]}"; do
  cp "$OUT/composer.lock.orig" composer.lock
  echo "::group::composer update ${TARGETS[*]} $strategy"
  # shellcheck disable=SC2086
  if ! composer update "${TARGETS[@]}" $strategy --no-install --no-scripts --no-plugins --no-audit --no-progress; then
    echo "::endgroup::"
    continue
  fi
  echo "::endgroup::"

  audit > "$OUT/audit-attempt.json"
  remaining=$(in_scope "$OUT/audit-attempt.json" | wc -l)
  echo "$remaining of $total advisories remain after 'composer update $strategy'."
  # Keep the least disruptive strategy that fixes the most advisories.
  if (( remaining < best_remaining )); then
    best_remaining=$remaining
    cp composer.lock "$OUT/composer.lock.best"
    cp "$OUT/audit-attempt.json" "$OUT/audit-after.json"
    echo "${strategy:-default}" > "$OUT/strategy.txt"
  fi
  (( remaining == 0 )) && break
done
rm -f "$OUT/audit-attempt.json"

if [[ ! -f "$OUT/strategy.txt" ]]; then
  cp "$OUT/composer.lock.orig" composer.lock
  echo "::error::No update cleared any advisory for ${TARGETS[*]}. A composer.json constraint, a fork or a missing release is probably holding them back."
  exit 1
fi
cp "$OUT/composer.lock.best" composer.lock

# Advisories that no update could clear, e.g. unsupported modules or packages
# held back by a constraint. They are reported but don't block the others.
in_scope "$OUT/audit-after.json" \
  | awk -F'\t' '{ printf "- **%s**: [%s](%s) %s\n", $1, $2, $4, $3 }' > "$OUT/unresolved.md"
if [[ -s "$OUT/unresolved.md" ]]; then
  echo "::warning::$best_remaining of $total advisories could not be fixed by a composer update. See the job summary."
fi

locked_versions composer.lock > "$OUT/versions-after.tsv"
{
  echo "| Package | Production | Updated |"
  echo "|---|---|---|"
  join -t $'\t' -a1 -a2 -e '(none)' -o 0,1.2,2.2 "$OUT/versions-before.tsv" "$OUT/versions-after.tsv" \
    | awk -F'\t' '$2 != $3 { printf "| %s | %s | %s |\n", $1, $2, $3 }'
} > "$OUT/changes.md"

echo "Strategy: $(cat "$OUT/strategy.txt")"
cat "$OUT/changes.md"
