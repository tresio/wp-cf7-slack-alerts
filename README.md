# CF7 Slack Error Alerts

Posts a Slack alert when a Contact Form 7 submission fails, so a broken SMTP
account or a misfiring captcha doesn't quietly swallow leads for a week.

- **Red** — the form's email failed to send, or the submission was aborted.
- **Orange** — the submission was flagged as spam, or failed validation.
- Also watches every other `wp_mail()` call on the site as a safety net.

## Install

Download `cf7-slack-error-alerts.zip` from the
[latest release](https://github.com/tresio/wp-cf7-slack-alerts/releases/latest)
and upload it under **Plugins → Add New → Upload Plugin**.

After that the plugin updates itself: it checks GitHub Releases and offers new
versions on the normal Plugins screen, like any other plugin.

## Configure

**Settings → CF7 Slack Alerts.**

### Bot token (recommended)

Choosing which channel to post to requires a bot token — modern Slack incoming
webhooks are permanently bound to the channel picked when they were created, and
ignore the `channel` field in the payload.

1. Create an app at [api.slack.com/apps](https://api.slack.com/apps) → *From scratch*.
2. **OAuth & Permissions** → add the **`chat:write`** bot token scope.
3. **Install to Workspace**, then copy the **Bot User OAuth Token** (`xoxb-…`).
4. Paste it into the settings screen along with the target channel.
5. Invite the bot to that channel: `/invite @your-app-name`.

Use the channel's ID (`C01ABCDEF`, from *Channel details → About*) rather than
its name — the ID survives a rename.

### Incoming webhook

Paste a `hooks.slack.com` URL instead. Simpler to set up, but the channel is
fixed at creation time and the channel field is ignored.

### Setting credentials in `wp-config.php`

Any of these constants override the stored setting and lock the matching field
in the UI, which keeps secrets out of the database:

```php
define( 'CF7_SLACK_BOT_TOKEN', 'xoxb-…' );
define( 'CF7_SLACK_CHANNEL', 'C01ABCDEF' );
define( 'CF7_SLACK_WEBHOOK_URL', 'https://hooks.slack.com/services/…' );
```

### Other settings

| Setting | Default | Notes |
| --- | --- | --- |
| Mail failure alerts | on | The reason to install this plugin. |
| Aborted submission alerts | on | Another plugin stopped the submission. |
| Spam alerts | off | Fires on every captcha rejection. |
| Validation failure alerts | off | Fires on ordinary visitor typos. Noisy. |
| Other `wp_mail()` failures | on | Order emails, password resets, and so on. |
| Include submitter email | on | Turn off to keep personal data out of Slack. |
| Throttle | 60s | Suppresses repeats of an identical alert. `0` disables. |
| Wait for confirmation | off | On: alerts are verified but add up to 5s to a submission. Off: fire-and-forget. |

With confirmation off, delivery problems are invisible on the front end — use
**Send test message**, which always waits and reports the real Slack error.

## Extending

```php
// Skip alerts for a particular form.
add_filter( 'cf7_slack_alerts_should_send', function ( $send, $severity, $title, $details ) {
	return ( isset( $details['Form'] ) && 'Newsletter' === $details['Form'] ) ? false : $send;
}, 10, 4 );

// Reshape the Slack payload before it goes out.
add_filter( 'cf7_slack_alerts_message', function ( $message, $severity, $details ) {
	$message['attachments'][0]['footer'] = 'my-site.com';
	return $message;
}, 10, 3 );
```

## Releasing

The version lives in three places that must agree: the `Version:` header and the
`VERSION` constant in `cf7-slack-error-alerts.php`, and a `## [x.y.z]` heading in
`CHANGELOG.md`. CI fails if the first two disagree, or if either disagrees with
the tag. `bin/bump.sh` moves all three together:

```bash
bin/bump.sh patch          # 1.1.0 -> 1.1.1
bin/bump.sh minor          # 1.1.0 -> 1.2.0
bin/bump.sh major          # 1.1.0 -> 2.0.0
bin/bump.sh 2.0.0-beta.1   # or set one explicitly
```

Options: `--dry-run` to preview, `--commit` to commit as
`chore(release): vX.Y.Z`, `--tag` to also create the annotated tag. Nothing is
ever pushed — pushing the tag is what triggers a release.

The usual flow is to write the release notes under `## [Unreleased]` as you go,
then:

```bash
bin/bump.sh minor --tag                  # Unreleased becomes ## [1.2.0]
git push origin main --follow-tags
```

Bump levels follow semver, including prereleases: `1.2.0-beta.1` plus a `patch`
or `minor` bump releases `1.2.0` rather than skipping past it.

The `Release` workflow then verifies the versions, lints, builds
`dist/cf7-slack-error-alerts.zip`, and publishes a GitHub Release with that zip
attached.

### How often sites check

WordPress decides when to ask for plugin updates: on a twice-daily cron, on any
admin page at most every 12 hours, and on the Plugins or Updates screens at most
hourly. This plugin then caches the GitHub release lookup for 12 hours on top of
that, so GitHub sees at most about two requests per day per site — well inside
the unauthenticated rate limit. A failed lookup backs off for an hour.

In practice a new release lands on a site within 12 hours. To get it
immediately, use **Dashboard → Updates → Check Again**, which clears both
caches and re-queries GitHub straight away.

### Installing updates automatically

Finding an update and installing it are separate things. By default the plugin
only *offers* the update, and you click **update now** on the Plugins screen.

For unattended updates, either:

- tick **Install those updates automatically** in the plugin's settings, or
- use WordPress's own **Enable auto-updates** link in the right-hand column of
  the Plugins screen.

The plugin's setting only ever turns auto-updates on. Left off, WordPress's own
per-plugin toggle governs, so the two never fight. Unattended updates run on
WordPress's twice-daily cron, so they land within roughly 12 hours of a release
rather than instantly.

Tags containing a hyphen (`v1.2.0-beta.1`) are published as prereleases, which
GitHub excludes from `releases/latest` — so sites are never offered them.

To build locally:

```bash
bash bin/build.sh
```

## Testing

Sites can update this plugin unattended, so a bad release breaks somebody else's
site without anyone watching. The suite is built around that risk in three
tiers.

```bash
composer install

composer test                  # unit — no WordPress, no database, ~0.3s
bash tests/run-integration.sh  # real WordPress in Docker
bash tests/run-e2e.sh          # a real unattended update, end to end
```

Docker is needed for the last two. Pass `--keep` to either to leave the
containers up for poking at.

| Tier | What it runs against | Catches |
| --- | --- | --- |
| Unit | Plugin classes, WordPress faked | Sanitization, payload building, throttling, redaction, updater logic |
| Integration | Real WordPress + MySQL | Hooks not registered, settings not persisting, the update transient not populating |
| End to end | A real site, a real release | An update that white-screens a site, loses settings, or installs a second copy |

### The end-to-end tier

This is the one that answers "will an auto-update break a live site". It
installs the *previous* published release into a real WordPress, seeds settings,
then runs WordPress's own updater against the real GitHub release. Nothing is
mocked. Afterwards it checks the site still returns 200, the plugin is still
active on the new version, the settings survived, no fatals were logged, and
exactly one copy exists on disk.

It runs twice: once installed under the folder name the release zip carries,
and once under the repository name, which is what a `git clone` install looks
like. The second case is where a GitHub-based updater usually fails, by
installing a second copy and silently leaving the site on old code.

Because it upgrades between the two most recent releases, it needs at least two
published releases to run.

### On pull requests

Every tier runs on pull requests against `main`, and `main` is protected so a
pull request cannot merge until they pass.

Branch protection requires a single check named **CI**, which is an aggregating
job that depends on all the others. Requiring the individual jobs instead means
a renamed job quietly stops being required, and a job added later is not gated
until someone remembers to update the branch rule.

The end-to-end tier has a third scenario specifically for this: the first two
upgrade between published releases, which on a pull request proves nothing about
the code being proposed, so the third builds the branch and upgrades a live site
onto *that*.

`enforce_admins` is deliberately off. Requiring checks on `main` otherwise
blocks direct pushes to it, which would break `bin/bump.sh --tag` followed by
`git push origin main --follow-tags`. Pull requests are still gated for
everyone; an admin can push a release commit straight to `main`.

### Cross-version

Unit tests run on PHP 7.2 through 8.4 in CI, not just the newest. 7.2 is the
floor the plugin header claims, and an unattended update pushes code to
whatever PHP a site happens to run. Composer resolves PHPUnit per version (8.5
on 7.2, 9.6 above), so no lock file is committed.

### Docker note

`/var/www/html` is a named volume rather than a host bind. Bind-mounting the
plugin *inside* another bind mount silently yields an empty directory on Docker
Desktop, which is why `wp-env` cannot be used here.

## Requirements

WordPress 5.3+, PHP 7.2+, Contact Form 7.

## License

GPL v2 or later.
