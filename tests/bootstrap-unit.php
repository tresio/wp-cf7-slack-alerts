<?php
/**
 * Bootstrap for the unit suite.
 *
 * Loads the plugin's classes without executing the plugin file, so nothing
 * boots and no hooks are registered before a test has set up its own fakes.
 * The real plugin file is exercised by the integration suite instead, inside a
 * real WordPress.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/wordpress/' );
}

define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

// Pinned rather than read from the plugin header: unit tests assert on version
// comparisons, and those assertions should not change every time a release is
// cut. bin/version.sh already guards the real header against drift.
const VERSION     = '1.0.0';
const OPTION_KEY  = 'cf7_slack_alerts_settings';
const GITHUB_REPO = 'tresio/wp-cf7-slack-alerts';

define( __NAMESPACE__ . '\PLUGIN_FILE', dirname( __DIR__ ) . '/cf7-slack-error-alerts.php' );
define( __NAMESPACE__ . '\PLUGIN_DIR', dirname( __DIR__ ) . '/' );

require_once __DIR__ . '/Doubles/wordpress.php';

require_once PLUGIN_DIR . 'includes/class-settings.php';
require_once PLUGIN_DIR . 'includes/class-slack-client.php';
require_once PLUGIN_DIR . 'includes/class-notifier.php';
require_once PLUGIN_DIR . 'includes/class-events.php';
require_once PLUGIN_DIR . 'includes/class-updater.php';
require_once PLUGIN_DIR . 'includes/class-plugin.php';
