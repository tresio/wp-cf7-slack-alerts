<?php
/**
 * Plugin Name:       CF7 Slack Error Alerts
 * Plugin URI:        https://github.com/tresio/wp-cf7-slack-alerts
 * Description:       Sends a Slack notification when a Contact Form 7 form errors — mail failure (red), captcha/spam rejection (orange), or validation (orange). Also catches any wp_mail() failure as a safety net for WP Mail SMTP issues.
 * Version:           1.2.1
 * Requires at least: 5.3
 * Requires PHP:      7.2
 * Author:            Studio 3 Enterprise
 * Author URI:        https://studio3enterprise.com/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cf7-slack-error-alerts
 * Update URI:        https://github.com/tresio/wp-cf7-slack-alerts
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const VERSION     = '1.2.1';
const OPTION_KEY  = 'cf7_slack_alerts_settings';
const GITHUB_REPO = 'tresio/wp-cf7-slack-alerts';

define( __NAMESPACE__ . '\PLUGIN_FILE', __FILE__ );
define( __NAMESPACE__ . '\PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once PLUGIN_DIR . 'includes/class-settings.php';
require_once PLUGIN_DIR . 'includes/class-slack-client.php';
require_once PLUGIN_DIR . 'includes/class-notifier.php';
require_once PLUGIN_DIR . 'includes/class-events.php';
require_once PLUGIN_DIR . 'includes/class-updater.php';
require_once PLUGIN_DIR . 'includes/class-plugin.php';
require_once PLUGIN_DIR . 'includes/back-compat.php';

Plugin::instance()->boot();
