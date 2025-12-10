#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"

php -d phar.readonly=0 "${SCRIPT_DIR}/build-phar.php" "$@"
