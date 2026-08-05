#!/usr/bin/env bash
#
# Reads the plugin version from its two sources of truth, and checks they agree.
#
# Usage:
#   bin/version.sh header   # version from the plugin file header
#   bin/version.sh const    # version from the VERSION constant
#   bin/version.sh check    # exit non-zero unless both match (and match $1, if given)

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/cf7-slack-error-alerts.php"

header_version() {
	grep -m1 -E '^[[:space:]]*\*[[:space:]]*Version:' "$MAIN" \
		| sed -E 's/.*Version:[[:space:]]*//' \
		| tr -d '[:space:]'
}

const_version() {
	grep -m1 -E "^const VERSION" "$MAIN" \
		| sed -E "s/.*=[[:space:]]*'([^']+)'.*/\1/" \
		| tr -d '[:space:]'
}

case "${1:-check}" in
	header)
		header_version
		;;
	const)
		const_version
		;;
	check)
		HEADER="$(header_version)"
		CONST="$(const_version)"

		if [[ -z "$HEADER" || -z "$CONST" ]]; then
			echo "::error::Could not parse the plugin version (header='$HEADER' const='$CONST')" >&2
			exit 1
		fi

		if [[ "$HEADER" != "$CONST" ]]; then
			echo "::error::Version mismatch: header says '$HEADER' but the VERSION constant says '$CONST'" >&2
			exit 1
		fi

		EXPECTED="${2:-}"
		if [[ -n "$EXPECTED" && "$HEADER" != "$EXPECTED" ]]; then
			echo "::error::Version mismatch: plugin says '$HEADER' but the tag says '$EXPECTED'" >&2
			exit 1
		fi

		echo "$HEADER"
		;;
	*)
		echo "usage: $0 {header|const|check [expected]}" >&2
		exit 64
		;;
esac
