"""Pick the two most recent published releases to upgrade between.

Reads the GitHub releases JSON on stdin and prints:

    <latest tag> <previous tag> <previous zip asset url>

Kept in its own file rather than inlined in the shell script: quoting a JSON
parser through bash is how the first version of this silently produced empty
values, which made every downstream assertion pass without testing anything.
"""

import json
import sys


def main() -> int:
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        print(f"could not parse the releases response: {exc}", file=sys.stderr)
        return 1

    releases = [r for r in payload if not r.get("draft") and not r.get("prerelease")]

    if len(releases) < 2:
        print(
            f"need two published releases to test an upgrade, found {len(releases)}",
            file=sys.stderr,
        )
        return 1

    latest, previous = releases[0], releases[1]

    asset = next(
        (a for a in previous.get("assets", []) if a["name"].endswith(".zip")),
        None,
    )

    if asset is None:
        tag = previous.get("tag_name", "?")
        print(f"release {tag} has no zip asset to install", file=sys.stderr)
        return 1

    print(latest["tag_name"], previous["tag_name"], asset["browser_download_url"])
    return 0


if __name__ == "__main__":
    sys.exit(main())
