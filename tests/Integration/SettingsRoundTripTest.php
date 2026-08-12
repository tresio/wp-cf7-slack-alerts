<?php
/**
 * Settings persistence through the real Settings API.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Integration;

use CF7_Slack_Alerts\Settings;
use WP_UnitTestCase;

use const CF7_Slack_Alerts\OPTION_KEY;

/**
 * Saving options in WordPress runs the registered sanitize callback, so this
 * checks the real round trip rather than calling sanitize() directly.
 */
class SettingsRoundTripTest extends WP_UnitTestCase {

	/**
	 * Set up.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		// register_setting() runs on admin_init, which does not fire in tests.
		( new Settings() )->register();
	}

	public function test_sanitize_callback_runs_on_save() {
		update_option(
			OPTION_KEY,
			array(
				'transport'        => 'bot',
				'bot_token'        => 'xoxb-roundtrip',
				'channel'          => 'ops-alerts',
				'throttle_seconds' => '99999',
			)
		);

		$stored = get_option( OPTION_KEY );

		$this->assertSame( '#ops-alerts', $stored['channel'], 'channel should gain its prefix' );
		$this->assertSame( 3600, $stored['throttle_seconds'], 'throttle should be clamped' );
		$this->assertSame( 'xoxb-roundtrip', $stored['bot_token'] );
	}

	public function test_blank_secret_preserves_the_stored_one() {
		update_option(
			OPTION_KEY,
			array(
				'transport' => 'bot',
				'bot_token' => 'xoxb-keepme',
				'channel'   => '#a',
			)
		);

		update_option(
			OPTION_KEY,
			array(
				'transport' => 'bot',
				'bot_token' => '',
				'channel'   => '#b',
			)
		);

		$stored = get_option( OPTION_KEY );

		$this->assertSame( 'xoxb-keepme', $stored['bot_token'] );
		$this->assertSame( '#b', $stored['channel'] );
	}

	public function test_rejects_a_non_slack_webhook_on_save() {
		update_option(
			OPTION_KEY,
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
			)
		);

		update_option(
			OPTION_KEY,
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://evil.example/collect',
			)
		);

		$this->assertSame( 'https://hooks.slack.com/services/A/B/C', get_option( OPTION_KEY )['webhook_url'] );
	}

	/**
	 * Settings must survive a plugin update; losing them would silence alerts
	 * on every site that auto-updates.
	 */
	public function test_settings_survive_a_simulated_upgrade() {
		update_option(
			OPTION_KEY,
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
				'events'      => array( 'spam' => 1 ),
			)
		);

		// What the plugin does on every load, including the first after an update.
		do_action( 'plugins_loaded' );

		$settings = new Settings();

		$this->assertTrue( $settings->is_configured() );
		$this->assertTrue( $settings->event_enabled( 'spam' ) );
	}
}
