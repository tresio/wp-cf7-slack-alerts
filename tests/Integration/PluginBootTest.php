<?php
/**
 * The plugin's behaviour once loaded by a real WordPress.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Integration;

use CF7_Slack_Alerts\Plugin;
use WP_UnitTestCase;

use const CF7_Slack_Alerts\OPTION_KEY;

/**
 * Covers hook registration and first-run installation against real WordPress.
 */
class PluginBootTest extends WP_UnitTestCase {

	/**
	 * Every hook the plugin relies on must actually be registered, otherwise
	 * alerts silently never fire.
	 *
	 * @dataProvider hook_provider
	 *
	 * @param string $hook   Hook name.
	 * @param string $method Method expected to be attached.
	 */
	public function test_registers_its_hooks( $hook, $method ) {
		$this->assertNotFalse(
			has_filter( $hook ),
			"nothing is attached to {$hook}"
		);

		$found = false;

		foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				if ( is_array( $callback['function'] ) && is_object( $callback['function'][0] )
					&& false !== strpos( get_class( $callback['function'][0] ), 'CF7_Slack_Alerts' )
					&& $callback['function'][1] === $method ) {
					$found = true;
				}
			}
		}

		$this->assertTrue( $found, "{$method} is not attached to {$hook}" );
	}

	/**
	 * @return array
	 */
	public function hook_provider() {
		return array(
			array( 'wpcf7_mail_failed', 'on_cf7_mail_failed' ),
			array( 'wpcf7_spam', 'on_cf7_spam' ),
			array( 'wpcf7_submit', 'on_cf7_submit' ),
			array( 'wp_mail_failed', 'on_wp_mail_failed' ),
			array( 'pre_set_site_transient_update_plugins', 'inject_update' ),
			array( 'plugins_api', 'plugin_details' ),
			array( 'upgrader_source_selection', 'fix_source_directory' ),
			array( 'auto_update_plugin', 'maybe_auto_update' ),
			array( 'load-update-core.php', 'flush_on_force_check' ),
		);
	}

	/**
	 * The force-check flush has to beat core's own callback on that hook,
	 * which core registered before plugins loaded.
	 */
	public function test_force_check_flush_outranks_core() {
		$priority = has_action( 'load-update-core.php', array( Plugin::instance()->updater(), 'flush_on_force_check' ) );

		$this->assertIsInt( $priority );
		$this->assertLessThan( 10, $priority );
	}

	public function test_seeds_its_option_on_first_run() {
		$stored = get_option( OPTION_KEY );

		$this->assertIsArray( $stored );
		$this->assertArrayHasKey( 'transport', $stored );
	}

	/**
	 * Defaults must not send anything until someone configures a destination.
	 */
	public function test_starts_unconfigured() {
		delete_option( OPTION_KEY );
		update_option( OPTION_KEY, ( new \CF7_Slack_Alerts\Settings() )->defaults() );

		$this->assertFalse( ( new \CF7_Slack_Alerts\Settings() )->is_configured() );
	}

	public function test_exposes_the_legacy_global_function() {
		$this->assertTrue( function_exists( 'cf7_slack_notify' ) );
	}

	/**
	 * Text domain loading moved to init in WP 6.7; loading earlier triggers a
	 * _doing_it_wrong notice, which WP_UnitTestCase turns into a failure.
	 */
	public function test_loads_translations_without_a_notice() {
		$this->assertNotFalse( has_action( 'init', array( Plugin::instance(), 'load_textdomain' ) ) );
	}
}
