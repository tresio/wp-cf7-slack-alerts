#!/usr/bin/env bash
#
# Shared helpers for the WordPress-dependent test tiers.

set -euo pipefail

CF7SA_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CF7SA_DOCKER="$CF7SA_ROOT/tests/docker"
CF7SA_PORT="${CF7SA_WP_PORT:-8765}"

# Compose invocation for the base stack, plus any extra files passed in.
compose() {
	local files=( -f "$CF7SA_DOCKER/docker-compose.yml" )

	if [[ "${CF7SA_WITH_PLUGIN_MOUNT:-0}" == "1" ]]; then
		files+=( -f "$CF7SA_DOCKER/docker-compose.integration.yml" )
	fi

	docker compose "${files[@]}" --project-directory "$CF7SA_DOCKER" "$@"
}

say() {
	printf '\n\033[1m==> %s\033[0m\n' "$*"
}

fail() {
	printf '\033[31mFAIL\033[0m %s\n' "$*" >&2
	CF7SA_FAILURES=$(( ${CF7SA_FAILURES:-0} + 1 ))
}

pass() {
	printf '\033[32m ok \033[0m %s\n' "$*"
	CF7SA_PASSES=$(( ${CF7SA_PASSES:-0} + 1 ))
}

# assert_equals <label> <actual> <expected>
assert_equals() {
	if [[ "$2" == "$3" ]]; then
		pass "$1"
	else
		fail "$1 (got '$2', want '$3')"
	fi
}

# assert_contains <label> <haystack> <needle>
assert_contains() {
	if [[ "$2" == *"$3"* ]]; then
		pass "$1"
	else
		fail "$1 ('$3' not found in '$2')"
	fi
}

wp() {
	compose exec -T cli wp --allow-root --path=/var/www/html "$@"
}

# retry <attempts> <command...>
#
# Network steps against GitHub fail intermittently on shared CI runners, and a
# required check that flakes blocks merges at random.
retry() {
	local attempts="$1"
	shift

	local delay=3
	local n=1

	until "$@"; do
		if (( n >= attempts )); then
			echo "command failed after ${n} attempts: $*" >&2
			return 1
		fi
		echo "attempt ${n} failed, retrying in ${delay}s" >&2
		sleep "$delay"
		n=$(( n + 1 ))
		delay=$(( delay * 2 ))
	done
}

# Wait for Apache to answer, so tests never race the container.
wait_for_http() {
	local tries=60

	while (( tries-- > 0 )); do
		if curl -fsS -o /dev/null "http://localhost:$CF7SA_PORT/" 2>/dev/null; then
			return 0
		fi
		sleep 2
	done

	echo "WordPress did not come up on port $CF7SA_PORT" >&2
	compose logs --tail=40 wordpress >&2
	return 1
}

start_stack() {
	say "Starting containers"
	compose up -d --wait 2>&1 | tail -5
	wait_for_http
}

stop_stack() {
	say "Stopping containers"
	compose down -v --remove-orphans 2>&1 | tail -3
}

summarise() {
	printf '\n%d passed, %d failed\n' "${CF7SA_PASSES:-0}" "${CF7SA_FAILURES:-0}"
	[[ "${CF7SA_FAILURES:-0}" -eq 0 ]]
}
