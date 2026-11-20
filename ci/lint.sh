#!/usr/bin/env bash
# Check an extension's PHP syntax and field storage name lengths.
#
# Usage: lint.sh <extension dir>
set -euo pipefail

EXT_DIR="$1"
status=0

echo "Checking PHP syntax"
errors=$(find "$EXT_DIR" -type f \( -name '*.php' -o -name '*.module' -o -name '*.theme' -o -name '*.install' \) \
    -not -path '*/vendor/*' -not -path '*/node_modules/*' -print0 \
  | xargs -0 -n 1 -P 4 php -l 2>&1 | grep -v '^No syntax errors detected' || true)
if [ -n "$errors" ]; then
  echo "$errors"
  status=1
fi

# Drupal names revision tables <entity type>_revision__<field>, and MySQL
# table names are limited, so keep those under 48 characters.
echo "Checking field storage names"
while IFS= read -r -d '' file; do
  IFS=. read -r _ _ entity_type field_name _ <<< "$(basename "$file")"
  table="${entity_type}_revision__${field_name}"
  if [ "${#table}" -ge 48 ]; then
    max=$((48 - ${#entity_type} - 11))
    echo "::error file=$file::$(basename "$file") field name is too long. Keep field names under $max characters on '$entity_type' entities."
    status=1
  fi
done < <(find "$EXT_DIR" -type f -name 'field.storage.*' -print0)

exit "$status"
