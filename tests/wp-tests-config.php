<?php
/**
 * Config for the WordPress test library.
 *
 * Values default to what wp-env's tests container provides, and can be
 * overridden by environment variables so the same file works elsewhere.
 *
 * @package CF7_Slack_Alerts
 */

/**
 * Read a setting from the environment with a fallback.
 *
 * @param string $name    Environment variable name.
 * @param string $default Fallback value.
 * @return string
 */
function cf7sa_test_env( $name, $default ) {
	$value = getenv( $name );

	return ( false === $value || '' === $value ) ? $default : $value;
}

define( 'DB_NAME', cf7sa_test_env( 'WORDPRESS_DB_NAME', 'tests-wordpress' ) );
define( 'DB_USER', cf7sa_test_env( 'WORDPRESS_DB_USER', 'root' ) );
define( 'DB_PASSWORD', cf7sa_test_env( 'WORDPRESS_DB_PASSWORD', 'password' ) );
define( 'DB_HOST', cf7sa_test_env( 'WORDPRESS_DB_HOST', 'mysql' ) );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );

$table_prefix = 'wptests_';

define( 'WP_TESTS_DOMAIN', 'example.org' );
define( 'WP_TESTS_EMAIL', 'admin@example.org' );
define( 'WP_TESTS_TITLE', 'CF7 Slack Error Alerts Tests' );
define( 'WP_PHP_BINARY', 'php' );

define( 'WP_DEBUG', true );

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', cf7sa_test_env( 'WP_TESTS_ABSPATH', '/var/www/html/' ) );
}
