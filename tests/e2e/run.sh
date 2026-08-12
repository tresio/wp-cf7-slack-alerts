#!/usr/bin/env bash
#
# Proves an unattended update cannot break a live site.
#
# Installs the previous published release into a real WordPress, then runs
# WordPress's own updater against the real GitHub release. Nothing here is
# mocked: the plugin's updater does the release lookup, WordPress downloads and
# unpacks the asset, and the assertions check the site afterwards.
#
# Usage: tests/e2e/run.sh [--keep]

set -euo pipefail

source "$(cd "$(dirname "${BASH_SOURCE[0]}")/../docker" && pwd)/lib.sh"

# These three are read and updated by tests/docker/lib.sh, sourced above.
# shellcheck disable=SC2034
CF7SA_WITH_PLUGIN_MOUNT=0
# shellcheck disable=SC2034
CF7SA_PASSES=0
# shellcheck disable=SC2034
CF7SA_FAILURES=0
KEEP=0
[[ "${1:-}" == "--keep" ]] && KEEP=1

REPO="tresio/wp-cf7-slack-alerts"
SLUG="cf7-slack-error-alerts"
PLUGINS_DIR=/var/www/html/wp-content/plugins
DEBUG_LOG=/var/www/html/wp-content/debug.log

# Settings seeded before the update, checked again afterwards. Losing these
# would silence alerts on every site that auto-updates.
SEEDED_SETTINGS='{"transport":"webhook","webhook_url":"https://hooks.slack.com/services/T0E2E/B0E2E/canary","channel":"#e2e-canary","throttle_seconds":123,"events":{"mail_failed":1,"spam":1,"validation_failed":0,"aborted":1,"wp_mail_failed":1}}'

cleanup() {
	[[ "$KEEP" == "1" ]] || stop_stack
}
trap cleanup EXIT

say "Resolving releases from GitHub"
RELEASES_URL="https://api.github.com/repos/$REPO/releases?per_page=20"
RESOLVER="$(dirname "${BASH_SOURCE[0]}")/resolve-releases.py"

# Unauthenticated GitHub API calls from shared CI runners hit rate limits, so
# use a token when one is available. Written as two branches rather than an
# argument array because expanding an empty array under `set -u` is an error in
# the bash 3.2 that ships with macOS.
if [[ -n "${GITHUB_TOKEN:-}" ]]; then
	RELEASES_JSON="$( curl -fsS -H "Authorization: Bearer $GITHUB_TOKEN" "$RELEASES_URL" )"
else
	RELEASES_JSON="$( curl -fsS "$RELEASES_URL" )"
fi

RESOLVED="$( printf '%s' "$RELEASES_JSON" | python3 "$RESOLVER" )"

read -r LATEST_TAG PREVIOUS_TAG PREVIOUS_URL <<<"$RESOLVED"

LATEST_VERSION="${LATEST_TAG#v}"
PREVIOUS_VERSION="${PREVIOUS_TAG#v}"

# Without this, unresolved versions make every assertion below compare an empty
# string to an empty string and report success.
if [[ -z "$LATEST_VERSION" || -z "$PREVIOUS_VERSION" || -z "$PREVIOUS_URL" ]]; then
	echo "could not resolve releases to test between" >&2
	exit 1
fi

echo "upgrading $PREVIOUS_TAG -> $LATEST_TAG"

start_stack

say "Installing WordPress"
wp core install \
	--url="http://localhost:$CF7SA_PORT" \
	--title="E2E" \
	--admin_user=admin \
	--admin_password=password \
	--admin_email=admin@example.test \
	--skip-email >/dev/null

# ---------------------------------------------------------------------------
# Scenario 1: the ordinary case, installed under the folder the zip carries.
# ---------------------------------------------------------------------------

say "Scenario 1: update in the folder the release zip creates"

wp plugin install "$PREVIOUS_URL" --activate >/dev/null
assert_equals "starts on $PREVIOUS_VERSION" "$(wp plugin get "$SLUG" --field=version | tr -d '\r')" "$PREVIOUS_VERSION"

wp option update cf7_slack_alerts_settings --format=json "$SEEDED_SETTINGS" >/dev/null

wp eval 'delete_site_transient( "update_plugins" ); delete_transient( "cf7sa_github_release" );' >/dev/null

say "Running WordPress's updater"
UPDATE_OUTPUT="$(wp plugin update "$SLUG" 2>&1 || true)"
echo "$UPDATE_OUTPUT" | tail -5

assert_equals "updated to $LATEST_VERSION" "$(wp plugin get "$SLUG" --field=version | tr -d '\r')" "$LATEST_VERSION"
assert_equals "plugin still active" "$(wp plugin get "$SLUG" --field=status | tr -d '\r')" "active"

# A second copy under a different folder name is the classic failure of a
# GitHub-based updater: the site keeps running the old code silently.
assert_equals "exactly one copy on disk" \
	"$(compose exec -T cli sh -c "ls -d $PLUGINS_DIR/*/ 2>/dev/null | grep -ci 'slack'" | tr -d '\r')" "1"

SETTINGS_AFTER="$(wp option get cf7_slack_alerts_settings --format=json | tr -d '\r')"
assert_contains "webhook survived the update" "$SETTINGS_AFTER" "T0E2E"
assert_contains "channel survived the update" "$SETTINGS_AFTER" "#e2e-canary"
assert_contains "throttle survived the update" "$SETTINGS_AFTER" "123"

assert_equals "site still returns 200" \
	"$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:$CF7SA_PORT/")" "200"

assert_equals "admin login page still returns 200" \
	"$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:$CF7SA_PORT/wp-login.php")" "200"

# Checked before anything below can deliberately trigger an error, so a fatal
# here can only have come from the update itself.
FATALS="$(compose exec -T cli sh -c "grep -c 'Fatal error' $DEBUG_LOG 2>/dev/null || true" | tr -d '\r')"
assert_equals "no fatals in debug.log" "${FATALS:-0}" "0"

# Loading WP through the CLI executes the plugin, so a fatal shows up here.
assert_equals "plugin code loads without fatals" "$(wp eval 'echo "loaded";' | tr -d '\r')" "loaded"

assert_equals "plugin reports its own version correctly" \
	"$(wp eval 'echo \CF7_Slack_Alerts\VERSION;' | tr -d '\r')" "$LATEST_VERSION"

# Offering an update the site already has causes an endless update loop.
wp eval 'delete_site_transient( "update_plugins" ); delete_transient( "cf7sa_github_release" );' >/dev/null
assert_equals "no further update offered" \
	"$(wp plugin list --name="$SLUG" --field=update | tr -d '\r')" "none"

# ---------------------------------------------------------------------------
# Scenario 2: installed from a git clone, so the folder is named after the
# repository and does not match the folder inside the release zip.
# ---------------------------------------------------------------------------

say "Scenario 2: update when the install folder does not match the zip"

wp plugin deactivate "$SLUG" >/dev/null 2>&1 || true
wp plugin delete "$SLUG" >/dev/null 2>&1 || true

wp plugin install "$PREVIOUS_URL" >/dev/null
compose exec -T cli mv "$PLUGINS_DIR/$SLUG" "$PLUGINS_DIR/wp-cf7-slack-alerts"
wp plugin activate wp-cf7-slack-alerts >/dev/null

assert_equals "installed under the repo-style folder" \
	"$(wp plugin get wp-cf7-slack-alerts --field=version | tr -d '\r')" "$PREVIOUS_VERSION"

wp eval 'delete_site_transient( "update_plugins" ); delete_transient( "cf7sa_github_release" );' >/dev/null
wp plugin update wp-cf7-slack-alerts 2>&1 | tail -3

assert_equals "updated in place" \
	"$(wp plugin get wp-cf7-slack-alerts --field=version | tr -d '\r')" "$LATEST_VERSION"
assert_equals "still active after the rename case" \
	"$(wp plugin get wp-cf7-slack-alerts --field=status | tr -d '\r')" "active"
assert_equals "did not leave a second copy behind" \
	"$(compose exec -T cli sh -c "ls -d $PLUGINS_DIR/*/ 2>/dev/null | grep -ci 'slack'" | tr -d '\r')" "1"
assert_equals "site still returns 200" \
	"$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:$CF7SA_PORT/")" "200"

# ---------------------------------------------------------------------------
# Scenario 3: the branch under review.
#
# The two scenarios above upgrade between published releases, so on a pull
# request they prove nothing about the code being proposed. This one upgrades a
# site onto a build of the working tree, through the same WordPress upgrader
# and the same source-selection filter, which is what will happen to every site
# once this branch is released.
# ---------------------------------------------------------------------------

say "Scenario 3: updating a live site onto this branch's code"

BRANCH_VERSION="$( bash "$CF7SA_ROOT/bin/version.sh" header )"
bash "$CF7SA_ROOT/bin/build.sh" >/dev/null
compose cp "$CF7SA_ROOT/dist/cf7-slack-error-alerts.zip" cli:/tmp/branch-build.zip

wp plugin deactivate wp-cf7-slack-alerts >/dev/null 2>&1 || true
wp plugin delete wp-cf7-slack-alerts >/dev/null 2>&1 || true

wp plugin install "$PREVIOUS_URL" --activate >/dev/null
wp option update cf7_slack_alerts_settings --format=json "$SEEDED_SETTINGS" >/dev/null
assert_equals "back on $PREVIOUS_VERSION before the upgrade" \
	"$(wp plugin get "$SLUG" --field=version | tr -d '\r')" "$PREVIOUS_VERSION"

wp plugin install /tmp/branch-build.zip --force >/dev/null

assert_equals "upgraded onto the branch build" \
	"$(wp plugin get "$SLUG" --field=version | tr -d '\r')" "$BRANCH_VERSION"
assert_equals "still active on the branch build" \
	"$(wp plugin get "$SLUG" --field=status | tr -d '\r')" "active"
assert_equals "no second copy from the branch build" \
	"$(compose exec -T cli sh -c "ls -d $PLUGINS_DIR/*/ 2>/dev/null | grep -ci 'slack'" | tr -d '\r')" "1"

BRANCH_SETTINGS="$(wp option get cf7_slack_alerts_settings --format=json | tr -d '\r')"
assert_contains "settings survived the branch build" "$BRANCH_SETTINGS" "#e2e-canary"

assert_equals "site still returns 200 on the branch build" \
	"$(curl -sL -o /dev/null -w '%{http_code}' "http://localhost:$CF7SA_PORT/")" "200"
assert_equals "branch code loads without fatals" \
	"$(wp eval 'echo "loaded";' | tr -d '\r')" "loaded"

BRANCH_FATALS="$(compose exec -T cli sh -c "grep -c 'Fatal error' $DEBUG_LOG 2>/dev/null || true" | tr -d '\r')"
assert_equals "no fatals after the branch build" "${BRANCH_FATALS:-0}" "0"

summarise
