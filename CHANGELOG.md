# Changelog

All notable changes to this plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[semantic versioning](https://semver.org/).

## [Unreleased]

## [1.2.0]

## [1.1.0]

### Security

- **The 1.0.x releases hardcoded a live Slack webhook URL in the plugin source
  and it is present in this repository's git history. Rotate that webhook.**
  Credentials now live in the database or in `wp-config.php` constants.

### Added

- Settings screen under **Settings → CF7 Slack Alerts**.
- Bot token transport using `chat.postMessage`, which makes the target channel
  configurable. Incoming webhooks remain supported but always post to the
  channel they were created against.
- Per-event toggles. Validation failures and spam alerts are now off by default
  because they fire on ordinary visitor mistakes.
- Throttling, so a broken SMTP account cannot flood the channel with identical
  alerts. Defaults to one identical alert per form per 60 seconds.
- Option to omit the submitter's email address from alerts.
- "Send test message" button that reports what Slack actually said back, with
  plain-English explanations for the common Slack error codes.
- Self-updating from GitHub Releases, surfaced on the normal Plugins screen.
- `cf7_slack_alerts_should_send` and `cf7_slack_alerts_message` filters.

### Changed

- Rewritten as namespaced classes under `includes/`.
- A CF7 mail failure now produces one alert instead of two. The `wp_mail_failed`
  handler stays quiet during a CF7 submission and folds its error message into
  the richer `wpcf7_mail_failed` alert.
- Long field values are truncated multibyte-safely rather than being sent whole.

### Fixed

- `strlen()` on multibyte values could mislabel a field as short.

## [1.0.2]

### Fixed

- Defensive handling of the object passed to the spam handler.

## [1.0.1]

### Fixed

- Fatal error in the `wpcf7_spam` handler, which called `title()` on a
  `WPCF7_Submission` instead of a `WPCF7_ContactForm`.
- `wpcf7_spam` is a filter, not an action: only alert when spam is actually
  detected, and return the value unchanged.

## [1.0.0]

- Initial release.
