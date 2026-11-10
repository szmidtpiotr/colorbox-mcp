#!/usr/bin/env bash
# Builds colorbox-wp-mcp-<version>.zip for Plugins → Add New → Upload Plugin (no development files).
# Usage: bin/build-zip.sh [output directory, default dist/]
set -euo pipefail
OUT=$(realpath -m "${1:-$(dirname "$0")/../dist}")
cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/^const VERSION *= *'\(.*\)';/\1/p" colorbox-wp-mcp.php)
mkdir -p "$OUT"
TMP=$(mktemp -d)
trap 'rm -rf "$TMP"' EXIT
mkdir "$TMP/colorbox-wp-mcp"
cp -r colorbox-wp-mcp.php includes languages vendor composer.json composer.lock README.md README.pl.md LICENSE "$TMP/colorbox-wp-mcp/"
(cd "$TMP" && python3 -m zipfile -c "colorbox-wp-mcp-$VERSION.zip" colorbox-wp-mcp)
mv "$TMP/colorbox-wp-mcp-$VERSION.zip" "$OUT/"
echo "$OUT/colorbox-wp-mcp-$VERSION.zip"
