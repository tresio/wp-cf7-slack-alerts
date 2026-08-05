#!/usr/bin/env bash
#
# Builds the distributable plugin zip into dist/.
#
# The archive contains a single top-level folder named after the plugin slug,
# which is what WordPress expects when installing or updating from a zip.
#
# Usage: bin/build.sh [version]

set -euo pipefail

SLUG="cf7-slack-error-alerts"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VERSION="${1:-$("$ROOT/bin/version.sh" header)}"

cd "$ROOT"

rm -rf build dist
mkdir -p "build/$SLUG" dist

rsync -a --exclude-from="$ROOT/.distignore" ./ "build/$SLUG/"

( cd build && zip -rq "../dist/$SLUG.zip" "$SLUG" )

rm -rf build

echo "dist/$SLUG.zip ($VERSION)"
