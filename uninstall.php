<?php
/**
 * Removes everything the plugin stored.
 *
 * @package CF7_Slack_Alerts
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$cf7sa_options = array(
	'cf7_slack_alerts_settings',
	'cf7_slack_alerts_settings_last_error',
);

foreach ( $cf7sa_options as $cf7sa_option ) {
	delete_option( $cf7sa_option );

	if ( is_multisite() ) {
		delete_site_option( $cf7sa_option );
	}
}

delete_transient( 'cf7sa_github_release' );
delete_transient( 'cf7sa_github_release_backoff' );

global $wpdb;

$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_cf7sa_throttle_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_cf7sa_throttle_' ) . '%'
	)
);
