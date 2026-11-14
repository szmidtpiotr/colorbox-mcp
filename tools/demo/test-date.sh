#!/usr/bin/env bash
# Runs tools/demo/test-date.php on the demo site (see seed.sh: the site must be up and seeded with the current ZIP).
set -euo pipefail
cd "$(dirname "$0")"
docker compose run --rm -T cli wp eval-file - < test-date.php
