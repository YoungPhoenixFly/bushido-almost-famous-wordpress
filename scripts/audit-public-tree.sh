#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
cd "$repo_root"

if [[ ! -f LICENSE ]]; then
  echo "LICENSE is required." >&2
  exit 66
fi
if git rev-parse --verify HEAD >/dev/null 2>&1; then
  if git log --all --format='%s%n%b' | rg -i 'bushido monorepo|PR #1466|bb8f7521'; then
    echo "Private monorepo provenance leaked into public history." >&2
    exit 67
  fi
fi
if rg -n -I \
  --glob '!.git/**' --glob '!node_modules/**' --glob '!vendor/**' \
  --glob '!scripts/audit-public-tree.sh' \
  --glob '!package-lock.json' --glob '!composer.lock' \
  '(BEGIN (RSA|OPENSSH|EC|DSA) PRIVATE KEY|AKIA[0-9A-Z]{16}|gh[pousr]_[A-Za-z0-9_]{30,}|xox[baprs]-[A-Za-z0-9-]{20,})' \
  .; then
  echo "Potential credential material found." >&2
  exit 67
fi
if rg -n -I \
  --glob '!.git/**' --glob '!node_modules/**' --glob '!vendor/**' \
  --glob '!scripts/audit-public-tree.sh' \
  --glob '!docs/extraction-record.md' \
  'apps/(af-server|web)|packages/(af-[^/]+|database|use-cases|service|bushido-core)|plugins/almost-famous' \
  .; then
  echo "Private monorepo paths found in standalone source." >&2
  exit 67
fi
if rg -n -I \
  --glob '!.git/**' --glob '!node_modules/**' --glob '!vendor/**' \
  --glob '!scripts/audit-public-tree.sh' \
  'Tier_Gate|Fraud_Report|Start Pro Trial|Upgrade to Pro' .; then
  echo "Removed local commercial/placeholder behavior remains." >&2
  exit 67
fi

echo "Public-tree secret, provenance, and licensing audit passed."
