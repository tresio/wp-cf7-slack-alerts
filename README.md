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
attached. Sites pick it up within 12 hours, or immediately via
**Dashboard → Updates → Check again**.

Tags containing a hyphen (`v1.2.0-beta.1`) are published as prereleases, which
GitHub excludes from `releases/latest` — so sites are never offered them.

To build locally:

```bash
bash bin/build.sh
```

## Requirements

WordPress 5.3+, PHP 7.2+, Contact Form 7.

## License

GPL v2 or later.
