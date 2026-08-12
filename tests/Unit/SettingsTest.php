<?php
/**
 * Settings storage and sanitization.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use CF7_Slack_Alerts\Settings;

use const CF7_Slack_Alerts\OPTION_KEY;

/**
 * @covers \CF7_Slack_Alerts\Settings
 */
class SettingsTest extends TestCase {

	/**
	 * Noisy alerts must stay off unless deliberately enabled, because they fire
	 * on ordinary visitor mistakes rather than on faults.
	 */
	public function test_defaults_are_conservative() {
		$defaults = ( new Settings() )->defaults();

		$this->assertSame( 1, $defaults['events']['mail_failed'] );
		$this->assertSame( 1, $defaults['events']['aborted'] );
		$this->assertSame( 0, $defaults['events']['spam'] );
		$this->assertSame( 0, $defaults['events']['validation_failed'] );
		$this->assertSame( 0, $defaults['auto_update'], 'unattended updates must be opt-in' );
		$this->assertSame( 0, $defaults['blocking'] );
		$this->assertSame( 60, $defaults['throttle_seconds'] );
	}

	public function test_reports_unconfigured_when_empty() {
		$this->assertFalse( $this->with_settings()->is_configured() );
	}

	public function test_bot_transport_needs_token_and_channel() {
		$this->assertFalse( $this->with_settings( array( 'bot_token' => 'xoxb-x' ) )->is_configured() );
		$this->assertFalse( $this->with_settings( array( 'channel' => '#a' ) )->is_configured() );
		$this->assertTrue(
			$this->with_settings(
				array(
					'bot_token' => 'xoxb-x',
					'channel'   => '#a',
				)
			)->is_configured()
		);
	}

	public function test_webhook_transport_needs_a_slack_url() {
		$this->assertFalse(
			$this->with_settings(
				array(
					'transport'   => 'webhook',
					'webhook_url' => 'https://example.com/hook',
				)
			)->is_configured()
		);
		$this->assertTrue(
			$this->with_settings(
				array(
					'transport'   => 'webhook',
					'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
				)
			)->is_configured()
		);
	}

	/**
	 * @dataProvider channel_provider
	 *
	 * @param string $input    Submitted channel.
	 * @param string $expected Stored channel.
	 */
	public function test_sanitize_normalizes_channel( $input, $expected ) {
		$out = ( new Settings() )->sanitize( array( 'channel' => $input ) );

		$this->assertSame( $expected, $out['channel'] );
	}

	/**
	 * @return array
	 */
	public function channel_provider() {
		return array(
			'bare name'        => array( 'site-alerts', '#site-alerts' ),
			'already prefixed' => array( '#site-alerts', '#site-alerts' ),
			'double prefix'    => array( '##site-alerts', '#site-alerts' ),
			'channel id'       => array( 'C01ABCDEF', 'C01ABCDEF' ),
			'group id'         => array( 'G01ABCDEF', 'G01ABCDEF' ),
			'whitespace'       => array( '  #ops  ', '#ops' ),
			'empty'            => array( '', '' ),
		);
	}

	/**
	 * Secret fields render blank, so a blank submission means "unchanged"
	 * rather than "clear this".
	 */
	public function test_blank_secret_submission_keeps_stored_value() {
		$this->options[ OPTION_KEY ] = array( 'bot_token' => 'xoxb-existing' );

		$out = ( new Settings() )->sanitize( array( 'bot_token' => '' ) );

		$this->assertSame( 'xoxb-existing', $out['bot_token'] );
	}

	public function test_clear_checkbox_wipes_secret() {
		$this->options[ OPTION_KEY ] = array( 'bot_token' => 'xoxb-existing' );

		$out = ( new Settings() )->sanitize(
			array(
				'bot_token'       => '',
				'bot_token_clear' => '1',
			)
		);

		$this->assertSame( '', $out['bot_token'] );
	}

	public function test_new_secret_replaces_stored_value() {
		$this->options[ OPTION_KEY ] = array( 'bot_token' => 'xoxb-old' );

		$out = ( new Settings() )->sanitize( array( 'bot_token' => 'xoxb-new' ) );

		$this->assertSame( 'xoxb-new', $out['bot_token'] );
	}

	/**
	 * A non-Slack webhook would silently post form data to a third party, so it
	 * is rejected rather than stored.
	 */
	public function test_rejects_non_slack_webhook_and_keeps_previous() {
		$this->options[ OPTION_KEY ] = array( 'webhook_url' => 'https://hooks.slack.com/services/A/B/C' );

		$out = ( new Settings() )->sanitize( array( 'webhook_url' => 'https://evil.example/collect' ) );

		$this->assertSame( 'https://hooks.slack.com/services/A/B/C', $out['webhook_url'] );
	}

	public function test_clamps_throttle_to_an_hour() {
		$out = ( new Settings() )->sanitize( array( 'throttle_seconds' => '99999' ) );

		$this->assertSame( 3600, $out['throttle_seconds'] );
	}

	public function test_throttle_accepts_zero() {
		$out = ( new Settings() )->sanitize( array( 'throttle_seconds' => '0' ) );

		$this->assertSame( 0, $out['throttle_seconds'] );
	}

	public function test_unknown_transport_falls_back_to_default() {
		$out = ( new Settings() )->sanitize( array( 'transport' => 'carrier-pigeon' ) );

		$this->assertSame( 'bot', $out['transport'] );
	}

	public function test_events_normalize_to_flags() {
		$out = ( new Settings() )->sanitize( array( 'events' => array( 'spam' => 'on' ) ) );

		$this->assertSame( 1, $out['events']['spam'] );
		$this->assertSame( 0, $out['events']['mail_failed'], 'absent checkbox means off' );
	}

	public function test_event_enabled_reads_stored_flags() {
		$settings = $this->with_settings( array( 'events' => array( 'spam' => 1 ) ) );

		$this->assertTrue( $settings->event_enabled( 'spam' ) );
		$this->assertFalse( $settings->event_enabled( 'validation_failed' ) );
		$this->assertFalse( $settings->event_enabled( 'no_such_event' ) );
	}

	/**
	 * Constants in wp-config.php win over stored values, so credentials can be
	 * kept out of the database.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_constant_overrides_and_locks_setting() {
		define( 'CF7_SLACK_CHANNEL', 'C0FROMCONFIG' );

		$settings = $this->with_settings( array( 'channel' => '#from-database' ) );

		$this->assertSame( 'C0FROMCONFIG', $settings->get( 'channel' ) );
		$this->assertTrue( $settings->is_locked( 'channel' ) );
		$this->assertFalse( $settings->is_locked( 'bot_token' ) );
	}
}
