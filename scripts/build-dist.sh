#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 3 ]]; then
  echo "Usage: $0 DESTINATION [EXPECTED_VERSION] [production|staging]" >&2
  exit 64
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
destination_input="$1"
destination_parent="$(dirname "$destination_input")"
destination_name="$(basename "$destination_input")"
channel="${3:-production}"

if [[ ! -d "$destination_parent" ]]; then
  echo "Destination parent must already exist: $destination_parent" >&2
  exit 64
fi
destination_parent="$(cd "$destination_parent" && pwd -P)"
destination="$destination_parent/$destination_name"

paths_overlap() {
  local first="$1"
  local second="$2"
  [[ "$first" == "$second" || "$first/" == "$second/"* || "$second/" == "$first/"* ]]
}

if [[ "$destination_name" != "bushido-almost-famous" ]]; then
  echo "Distribution destination must be named bushido-almost-famous." >&2
  exit 64
fi
if [[ "$channel" != "production" && "$channel" != "staging" ]]; then
  echo "Release channel must be production or staging." >&2
  exit 64
fi
if [[ "$destination" == "/" || -e "$destination" ]]; then
  echo "Distribution destination must be a fresh path: $destination" >&2
  exit 64
fi
if paths_overlap "$destination" "$repo_root"; then
  echo "Refusing distribution destination in repository ancestry: $destination" >&2
  exit 64
fi

header_version="$(
  sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' \
    "$repo_root/bushido-almost-famous.php" | head -n 1
)"
constant_version="$(
  sed -n "s/.*define( 'ALMOST_FAMOUS_VERSION', '\\([^']*\\)' ).*/\\1/p" \
    "$repo_root/bushido-almost-famous.php" | head -n 1
)"
stable_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$repo_root/readme.txt" | head -n 1)"
package_version="$(node -p "require('$repo_root/package.json').version")"
expected_version="${2:-$header_version}"

for version_pair in \
  "plugin header:$header_version" \
  "runtime constant:$constant_version" \
  "readme stable tag:$stable_version" \
  "package.json:$package_version"; do
  label="${version_pair%%:*}"
  value="${version_pair#*:}"
  if [[ -z "$value" || "$value" != "$expected_version" ]]; then
    echo "Version mismatch: $label is '$value', expected '$expected_version'." >&2
    exit 65
  fi
done

for required in \
  "$repo_root/vendor/autoload.php" \
  "$repo_root/assets/js/admin.js" \
  "$repo_root/assets/js/public-portal.js"; do
  if [[ ! -f "$required" ]]; then
    echo "Missing built runtime file: $required" >&2
    exit 66
  fi
done

exclude_args=()
while IFS= read -r line || [[ -n "$line" ]]; do
  case "$line" in
    ""|\#*) continue ;;
    !*)
      echo "Unsupported .distignore negation: $line" >&2
      exit 65
      ;;
    *) exclude_args+=("--exclude=$line") ;;
  esac
done < "$repo_root/.distignore"

created_destination=""
cleanup_failed_build() {
  if [[ -n "$created_destination" && -d "$created_destination" ]]; then
    rm -rf -- "$created_destination"
  fi
}
trap cleanup_failed_build ERR

mkdir "$destination"
created_destination="$destination"
rsync -a "${exclude_args[@]}" "$repo_root/" "$destination/"

if [[ "$channel" == "staging" ]]; then
  perl -0pi -e \
    's/( \* Plugin URI:[^\n]*\n)/$1 * Update URI: https:\/\/github.com\/YoungPhoenixFly\/bushido-almost-famous-wordpress\n/' \
    "$destination/bushido-almost-famous.php"
  perl -0pi -e \
    "s/(if \\( ! defined\\( 'ABSPATH' \\) \\) \\{\\n\\texit;\\n\\}\\n)/\\1\\ndefine( 'BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL', 'staging' );\\n/" \
    "$destination/bushido-almost-famous.php"
fi

"$repo_root/scripts/verify-dist.sh" "$destination" "$expected_version" "$channel"

trap - ERR
echo "Built Bushido Almost Famous $expected_version ($channel) at $destination"
