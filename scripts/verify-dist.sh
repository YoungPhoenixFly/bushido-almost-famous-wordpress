#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 1 || $# -gt 3 ]]; then
  echo "Usage: $0 DISTRIBUTION [EXPECTED_VERSION] [production|staging]" >&2
  exit 64
fi

distribution="$1"
channel="${3:-production}"
if [[ ! -d "$distribution" ]]; then
  echo "Distribution directory does not exist: $distribution" >&2
  exit 64
fi
distribution="$(cd "$distribution" && pwd -P)"
if [[ "$(basename "$distribution")" != "bushido-almost-famous" ]]; then
  echo "Distribution root must be bushido-almost-famous." >&2
  exit 65
fi
if [[ "$channel" != "production" && "$channel" != "staging" ]]; then
  echo "Release channel must be production or staging." >&2
  exit 64
fi

main="$distribution/bushido-almost-famous.php"
header_version="$(sed -n 's/^[[:space:]]*\* Version:[[:space:]]*//p' "$main" | head -n 1)"
constant_version="$(
  sed -n "s/.*define( 'ALMOST_FAMOUS_VERSION', '\\([^']*\\)' ).*/\\1/p" "$main" | head -n 1
)"
stable_version="$(sed -n 's/^Stable tag:[[:space:]]*//p' "$distribution/readme.txt" | head -n 1)"
expected_version="${2:-$header_version}"

if [[ ! "$expected_version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]; then
  echo "Invalid expected version: $expected_version" >&2
  exit 65
fi
for version_pair in \
  "plugin header:$header_version" \
  "runtime constant:$constant_version" \
  "readme stable tag:$stable_version"; do
  label="${version_pair%%:*}"
  value="${version_pair#*:}"
  if [[ -z "$value" || "$value" != "$expected_version" ]]; then
    echo "Version mismatch: $label is '$value', expected '$expected_version'." >&2
    exit 65
  fi
done

for required in \
  bushido-almost-famous.php readme.txt LICENSE \
  vendor/autoload.php assets/js/admin.js assets/js/public-portal.js; do
  if [[ ! -f "$distribution/$required" ]]; then
    echo "Distribution is missing required file: $required" >&2
    exit 66
  fi
done

unexpected_vendor="$(
  find "$distribution/vendor" -mindepth 1 -maxdepth 1 \
    ! -name autoload.php ! -name composer -print
)"
if [[ -n "$unexpected_vendor" ]]; then
  echo "Distribution vendor tree contains non-runtime packages:" >&2
  echo "$unexpected_vendor" >&2
  exit 67
fi

forbidden="$(
  find "$distribution" \
    \( -type d \( \
      -name .git -o -name .github -o -name node_modules -o -name tests \
      -o -name src -o -name docs -o -name scripts -o -name artifacts \
    \) -o -type f \( \
      -name '*.map' -o -name '.env*' -o -name '.distignore' \
      -o -name '.wp-env.json' -o -name '.phpcs.xml.dist' \
      -o -name 'phpunit.xml*' -o -name 'playwright.config.js' \
      -o -name 'webpack.config.js' -o -name 'package.json' \
      -o -name 'package-lock.json' -o -name 'composer.lock' \
    \) -o -type l \) -print
)"
if [[ -n "$forbidden" ]]; then
  echo "Forbidden development files or links reached the distribution:" >&2
  echo "$forbidden" >&2
  exit 67
fi

if [[ "$channel" == "production" ]]; then
  if rg -n '^[[:space:]]*\*[[:space:]]+Update URI:|RELEASE_CHANNEL.*staging' "$main"; then
    echo "Production distribution contains staging/update-channel markers." >&2
    exit 67
  fi
else
  rg -q 'Update URI: https://github.com/YoungPhoenixFly/bushido-almost-famous-wordpress' "$main"
  rg -q "BUSHIDO_ALMOST_FAMOUS_RELEASE_CHANNEL', 'staging'" "$main"
fi

while IFS= read -r php_file; do
  php -l "$php_file" >/dev/null
done < <(find "$distribution" -type f -name '*.php' -print)

echo "Verified Bushido Almost Famous $expected_version ($channel) at $distribution"
