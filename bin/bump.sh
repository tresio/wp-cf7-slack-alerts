#!/usr/bin/env bash
#
# Bumps the plugin version everywhere it appears and opens a CHANGELOG section.
#
# The version lives in three places that must agree: the plugin file header, the
# VERSION constant, and a CHANGELOG heading the release workflow reads its notes
# from. CI fails the release if any of them drift, so they are bumped together.
#
# Usage:
#   bin/bump.sh patch|minor|major|<x.y.z> [options]
#
# Options:
#   --commit      Commit the result as "chore(release): vX.Y.Z"
#   --tag         Create an annotated vX.Y.Z tag (implies --commit)
#   --dry-run     Print what would change and touch nothing
#   --no-changelog  Skip the CHANGELOG edit
#
# Nothing is pushed. Pushing the tag is what triggers a release.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MAIN="$ROOT/cf7-slack-error-alerts.php"
CHANGELOG="$ROOT/CHANGELOG.md"

LEVEL=""
DO_COMMIT=0
DO_TAG=0
DRY_RUN=0
DO_CHANGELOG=1

die() {
	echo "error: $*" >&2
	exit 1
}

usage() {
	awk 'NR > 1 && /^#/ { sub(/^# ?/, ""); print; next } NR > 1 { exit }' "${BASH_SOURCE[0]}"
	exit "${1:-0}"
}

while [[ $# -gt 0 ]]; do
	case "$1" in
		patch|minor|major)
			LEVEL="$1"
			;;
		--commit)
			DO_COMMIT=1
			;;
		--tag)
			DO_TAG=1
			DO_COMMIT=1
			;;
		--dry-run)
			DRY_RUN=1
			;;
		--no-changelog)
			DO_CHANGELOG=0
			;;
		-h|--help)
			usage 0
			;;
		-*)
			die "unknown option: $1"
			;;
		*)
			[[ -n "$LEVEL" ]] && die "unexpected argument: $1"
			LEVEL="$1"
			;;
	esac
	shift
done

[[ -n "$LEVEL" ]] || usage 64

# Reading through version.sh means a pre-existing mismatch is caught here,
# rather than producing a bump layered on top of an already-broken state.
CURRENT="$( "$ROOT/bin/version.sh" check )"

SEMVER_RE='^([0-9]+)\.([0-9]+)\.([0-9]+)(-([0-9A-Za-z.-]+))?$'

[[ "$CURRENT" =~ $SEMVER_RE ]] || die "current version '$CURRENT' is not semver"

MAJOR="${BASH_REMATCH[1]}"
MINOR="${BASH_REMATCH[2]}"
PATCH="${BASH_REMATCH[3]}"
PRE="${BASH_REMATCH[5]:-}"

case "$LEVEL" in
	# These follow node-semver's inc() rules: a bump that a prerelease is
	# already heading towards releases that version rather than skipping past
	# it, so 1.2.0-beta.1 + minor is 1.2.0, not 1.3.0.
	major)
		if [[ "$MINOR" != 0 || "$PATCH" != 0 || -z "$PRE" ]]; then
			MAJOR=$(( MAJOR + 1 ))
		fi
		MINOR=0
		PATCH=0
		NEW="$MAJOR.$MINOR.$PATCH"
		;;
	minor)
		if [[ "$PATCH" != 0 || -z "$PRE" ]]; then
			MINOR=$(( MINOR + 1 ))
		fi
		PATCH=0
		NEW="$MAJOR.$MINOR.$PATCH"
		;;
	patch)
		if [[ -z "$PRE" ]]; then
			PATCH=$(( PATCH + 1 ))
		fi
		NEW="$MAJOR.$MINOR.$PATCH"
		;;
	*)
		NEW="${LEVEL#v}"
		[[ "$NEW" =~ $SEMVER_RE ]] || die "'$LEVEL' is not a bump level or a semver version"
		;;
esac

[[ "$NEW" != "$CURRENT" ]] || die "version is already $NEW"

if git -C "$ROOT" rev-parse -q --verify "refs/tags/v$NEW" >/dev/null; then
	die "tag v$NEW already exists"
fi

if [[ $DO_COMMIT -eq 1 && $DRY_RUN -eq 0 ]] && [[ -n "$( git -C "$ROOT" status --porcelain )" ]]; then
	die "working tree is dirty; commit or stash first"
fi

echo "$CURRENT -> $NEW"

if [[ $DRY_RUN -eq 1 ]]; then
	echo
	echo "would rewrite:"
	grep -nE '^[[:space:]]*\*[[:space:]]*Version:|^const VERSION' "$MAIN" | sed 's/^/  cf7-slack-error-alerts.php:/'
	[[ $DO_CHANGELOG -eq 1 ]] && echo "  CHANGELOG.md: new '## [$NEW]' section"
	[[ $DO_COMMIT -eq 1 ]] && echo "  git commit -m 'chore(release): v$NEW'"
	[[ $DO_TAG -eq 1 ]] && echo "  git tag -a v$NEW"
	exit 0
fi

NEW_VERSION="$NEW" perl -0777 -pi -e '
	my $new = $ENV{NEW_VERSION};
	s{^(\s*\*\s*Version:\s+)\S+$}{$1 . $new}me;
	s{^(const VERSION\s*=\s*)\x27[^\x27]*\x27}{$1 . qq{\x27$new\x27}}me;
' "$MAIN"

# Re-read rather than trusting the substitutions: a silently missed regex would
# otherwise surface as a failed release instead of a failed bump.
"$ROOT/bin/version.sh" check "$NEW" >/dev/null || die "version rewrite did not take"

if [[ $DO_CHANGELOG -eq 1 && -f "$CHANGELOG" ]]; then
	NEW_VERSION="$NEW" perl -0777 -pi -e '
		my $new = $ENV{NEW_VERSION};

		# An Unreleased section becomes the release; otherwise scaffold one
		# above the newest existing entry.
		if ( s{^## \[?Unreleased\]?.*$}{## [$new]}mi ) {
			s{^(## \[\Q$new\E\])}{## [Unreleased]\n\n$1}m;
		} else {
			s{^(## \[)}{## [Unreleased]\n\n## [$new]\n\n### Changed\n\n- TODO: describe this release.\n\n$1}m;
		}
	' "$CHANGELOG"

	NOTES="$(
		awk -v v="$NEW" '
			$0 ~ "^## \\[?" v "\\]?" { found = 1; next }
			found && /^## / { exit }
			found { print }
		' "$CHANGELOG" | grep -v '^[[:space:]]*$' || true
	)"

	if [[ -z "$NOTES" || "$NOTES" == *TODO* ]]; then
		echo
		echo "note: CHANGELOG.md section for $NEW needs writing."
		echo "      Release notes fall back to auto-generated commit lists otherwise."
	fi
fi

echo "updated: cf7-slack-error-alerts.php$( [[ $DO_CHANGELOG -eq 1 ]] && echo ', CHANGELOG.md' )"

if [[ $DO_COMMIT -eq 1 ]]; then
	git -C "$ROOT" add cf7-slack-error-alerts.php
	[[ $DO_CHANGELOG -eq 1 && -f "$CHANGELOG" ]] && git -C "$ROOT" add CHANGELOG.md
	git -C "$ROOT" commit -q -m "chore(release): v$NEW"
	echo "committed: chore(release): v$NEW"
fi

if [[ $DO_TAG -eq 1 ]]; then
	git -C "$ROOT" tag -a "v$NEW" -m "v$NEW"
	echo "tagged: v$NEW"
fi

echo
echo "next:"
if [[ $DO_TAG -eq 1 ]]; then
	echo "  git push origin main --follow-tags"
else
	if [[ $DO_COMMIT -eq 0 ]]; then
		echo "  # edit the CHANGELOG section, then:"
		echo "  git commit -am 'chore(release): v$NEW'"
	fi
	echo "  git tag -a v$NEW -m v$NEW"
	echo "  git push origin main --follow-tags"
fi
