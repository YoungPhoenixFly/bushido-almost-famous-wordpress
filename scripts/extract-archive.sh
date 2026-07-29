#!/usr/bin/env bash

set -euo pipefail

if [[ $# -lt 3 || $# -gt 4 ]]; then
  echo "Usage: $0 ARCHIVE DESTINATION_PARENT EXPECTED_VERSION [CHANNEL]" >&2
  exit 64
fi

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
archive="$(cd "$(dirname "$1")" && pwd -P)/$(basename "$1")"
destination_parent="$2"
expected_version="$3"
channel="${4:-production}"
if [[ ! -f "$archive" || ! -d "$destination_parent" ]]; then
  echo "Archive and destination parent must already exist." >&2
  exit 64
fi
destination_parent="$(cd "$destination_parent" && pwd -P)"
destination="$destination_parent/bushido-almost-famous"
if [[ -e "$destination" ]]; then
  echo "Archive destination must be fresh: $destination" >&2
  exit 64
fi

entries="$(unzip -Z1 "$archive")"
unsafe_entry="$(
  awk '
    /^\// || /^[A-Za-z]:[\\\/]/ || /\\/ || /(^|\/)\.\.(\/|$)/ {
      print
      exit
    }
    $0 == "bushido-almost-famous/" { next }
    !/^bushido-almost-famous\// {
      print
      exit
    }
  ' <<<"$entries"
)"
if [[ -n "$unsafe_entry" ]]; then
  echo "Archive contains an unsafe entry: $unsafe_entry" >&2
  exit 67
fi
if ! grep -Fx 'bushido-almost-famous/bushido-almost-famous.php' <<<"$entries" >/dev/null; then
  echo "Archive does not contain the expected plugin root." >&2
  exit 66
fi
if zipinfo -l "$archive" | awk '$1 ~ /^[lbcsph]/ { found=1 } END { exit !found }'; then
  echo "Archive contains a link or special-file entry." >&2
  exit 67
fi

unzip -q "$archive" -d "$destination_parent"
"$repo_root/scripts/verify-dist.sh" "$destination" "$expected_version" "$channel"
echo "$destination"
