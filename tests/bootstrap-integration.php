<?php
/**
 * Bootstrap for the integration suite.
 *
 * Boots a real WordPress via the core test library and loads the plugin the
 * same way WordPress would, so hook registration and the plugin file itself
 * are genuinely exercised.
 *
 * @package CF7_Slack_Alerts
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$cf7sa_tests_dir = getenv( 'WP_PHPUNIT__DIR' );

if ( ! $cf7sa_tests_dir ) {
	$cf7sa_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $cf7sa_tests_dir . '/includes/functions.php' ) ) {
	echo "Could not find the WordPress test library at {$cf7sa_tests_dir}.\n";
	echo "Run `composer install`, then `npm run test:integration`.\n";
	exit( 1 );
}

putenv( 'WP_PHPUNIT__TESTS_CONFIG=' . __DIR__ . '/wp-tests-config.php' );

require_once $cf7sa_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	function () {
		require dirname( __DIR__ ) . '/cf7-slack-error-alerts.php';
	}
);

require $cf7sa_tests_dir . '/includes/bootstrap.php';
