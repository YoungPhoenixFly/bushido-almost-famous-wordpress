#!/usr/bin/env bash

set -euo pipefail

expected_url="${1:-https://bushido.is/almost-famous/wp-connect}"
if ! result="$(
  curl --silent --show-error --location \
    --max-redirs 3 \
    --connect-timeout 10 \
    --max-time 30 \
    --output /dev/null \
    --write-out '%{http_code} %{url_effective}' \
    "$expected_url"
)"; then
  echo "Production consent route is not ready: request failed for $expected_url" >&2
  exit 69
fi
status="${result%% *}"
effective="${result#* }"
if [[ "$status" -lt 200 || "$status" -ge 400 || "$effective" != "$expected_url" ]]; then
  echo "Production consent route is not ready: HTTP $status, final URL $effective" >&2
  exit 69
fi
echo "Production consent route ready: HTTP $status $effective"
