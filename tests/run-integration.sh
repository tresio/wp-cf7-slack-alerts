#!/usr/bin/env bash
#
# Runs the PHPUnit integration suite inside a real WordPress.
#
# Usage: tests/run-integration.sh [--keep]
#   --keep  leave the containers running afterwards

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/docker" && pwd)/lib.sh"

# Consumed by tests/docker/lib.sh, which is sourced above.
# shellcheck disable=SC2034
CF7SA_WITH_PLUGIN_MOUNT=1
KEEP=0
[[ "${1:-}" == "--keep" ]] && KEEP=1

PLUGIN_DIR=/var/www/html/wp-content/plugins/cf7-slack-error-alerts

cleanup() {
	[[ "$KEEP" == "1" ]] || stop_stack
}
trap cleanup EXIT

start_stack

say "Verifying the working copy is visible inside the container"
if ! compose exec -T cli test -f "$PLUGIN_DIR/cf7-slack-error-alerts.php"; then
	echo "The plugin mount is empty. On Docker Desktop this happens when a bind" >&2
	echo "mount is nested inside another bind mount; /var/www/html must stay a" >&2
	echo "named volume." >&2
	exit 1
fi

if ! compose exec -T cli test -f "$PLUGIN_DIR/vendor/autoload.php"; then
	echo "vendor/ is missing. Run: composer install" >&2
	exit 1
fi

say "Running PHPUnit"
compose exec -T -w "$PLUGIN_DIR" cli \
	php -d memory_limit=512M vendor/bin/phpunit --configuration phpunit-integration.xml.dist
