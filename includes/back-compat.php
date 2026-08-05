<?php
/**
 * Global-namespace shims kept for sites that called the 1.0.x API directly.
 *
 * @package CF7_Slack_Alerts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'cf7_slack_notify' ) ) {
	/**
	 * Post a colored message to Slack.
	 *
	 * @deprecated 1.1.0 Use CF7_Slack_Alerts\Plugin::instance()->notifier()->notify().
	 *
	 * @param string $severity 'red' | 'orange' | 'green'.
	 * @param string $title    Headline shown in Slack.
	 * @param array  $details  Key => value pairs rendered as fields.
	 * @return void
	 */
	function cf7_slack_notify( $severity, $title, $details = array() ) {
		\CF7_Slack_Alerts\Plugin::instance()->notifier()->notify( $severity, $title, $details );
	}
}
