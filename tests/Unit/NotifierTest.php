<?php
/**
 * Message construction, throttling and dispatch.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use Brain\Monkey\Filters;

use const CF7_Slack_Alerts\OPTION_KEY;

/**
 * @covers \CF7_Slack_Alerts\Notifier
 */
class NotifierTest extends TestCase {

	/**
	 * Settings for a configured, blocking, unthrottled notifier.
	 *
	 * @param array $extra Additional overrides.
	 * @return \CF7_Slack_Alerts\Settings
	 */
	private function configured( array $extra = array() ) {
		return $this->with_settings(
			array_merge(
				array(
					'transport'        => 'webhook',
					'webhook_url'      => 'https://hooks.slack.com/services/A/B/C',
					'blocking'         => 1,
					'throttle_seconds' => 0,
				),
				$extra
			)
		);
	}

	/**
	 * @dataProvider severity_provider
	 *
	 * @param string $severity Severity name.
	 * @param string $colour   Expected attachment colour.
	 */
	public function test_maps_severity_to_colour( $severity, $colour ) {
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify( $severity, 'Title', array( 'Form' => 'X' ) );

		$this->assertSame( $colour, $this->sent_body()['attachments'][0]['color'] );
	}

	/**
	 * @return array
	 */
	public function severity_provider() {
		return array(
			array( 'red', '#d32f2f' ),
			array( 'orange', '#f57c00' ),
			array( 'green', '#388e3c' ),
			array( 'chartreuse', '#888888' ),
		);
	}

	public function test_drops_empty_and_null_fields() {
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify(
			'red',
			'Title',
			array(
				'Form'  => 'X',
				'Empty' => '',
				'Null'  => null,
			)
		);

		$this->assertSame( array( 'Form' ), array_keys( $this->sent_fields() ) );
	}

	/**
	 * Slack rejects oversized payloads, and a stack trace in a form field
	 * should not be able to cause that.
	 */
	public function test_truncates_long_values_without_splitting_characters() {
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify(
			'red',
			'Title',
			array( 'Blob' => str_repeat( 'é', 2000 ) )
		);

		$value = $this->sent_fields()['Blob'];

		$this->assertSame( 901, mb_strlen( $value ), '900 characters plus an ellipsis' );
		$this->assertStringEndsWith( '…', $value );
		$this->assertSame( $value, mb_convert_encoding( $value, 'UTF-8', 'UTF-8' ), 'must remain valid UTF-8' );
	}

	public function test_marks_short_fields_short() {
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify(
			'red',
			'Title',
			array(
				'Short' => 'brief',
				'Long'  => str_repeat( 'x', 100 ),
			)
		);

		$fields = $this->sent_body()['attachments'][0]['fields'];

		$this->assertTrue( $fields[0]['short'] );
		$this->assertFalse( $fields[1]['short'] );
	}

	/**
	 * A broken SMTP account can fail on every submission; without throttling
	 * that becomes a flood of identical Slack messages.
	 */
	public function test_throttles_identical_alerts() {
		$notifier = $this->notifier( $this->configured( array( 'throttle_seconds' => 60 ) ) );
		$this->queue_response( 'ok' );
		$this->queue_response( 'ok' );

		$first  = $notifier->notify( 'red', 'Repeated', array( 'Form' => 'Contact' ) );
		$second = $notifier->notify( 'red', 'Repeated', array( 'Form' => 'Contact' ) );

		$this->assertTrue( $first['ok'] );
		$this->assertSame( 'throttled', $second['error'] );
		$this->assertCount( 1, $this->requests );
	}

	public function test_throttle_is_per_form() {
		$notifier = $this->notifier( $this->configured( array( 'throttle_seconds' => 60 ) ) );
		$this->queue_response( 'ok' );
		$this->queue_response( 'ok' );

		$notifier->notify( 'red', 'Repeated', array( 'Form' => 'Contact' ) );
		$other = $notifier->notify( 'red', 'Repeated', array( 'Form' => 'Newsletter' ) );

		$this->assertTrue( $other['ok'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_throttle_disabled_at_zero() {
		$notifier = $this->notifier( $this->configured( array( 'throttle_seconds' => 0 ) ) );
		$this->queue_response( 'ok' );
		$this->queue_response( 'ok' );

		$notifier->notify( 'red', 'Repeated', array( 'Form' => 'Contact' ) );
		$second = $notifier->notify( 'red', 'Repeated', array( 'Form' => 'Contact' ) );

		$this->assertTrue( $second['ok'] );
		$this->assertCount( 2, $this->requests );
	}

	public function test_records_last_error_when_blocking() {
		$this->queue_response( 'no_service', 404 );

		$this->notifier( $this->configured() )->notify( 'red', 'Boom', array( 'Form' => 'X' ) );

		$this->assertNotEmpty( $this->options[ OPTION_KEY . '_last_error' ]['error'] );
	}

	public function test_clears_last_error_after_a_success() {
		$this->options[ OPTION_KEY . '_last_error' ] = array(
			'error' => 'stale',
			'time'  => 1,
		);
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify( 'red', 'Fine', array( 'Form' => 'X' ) );

		$this->assertArrayNotHasKey( OPTION_KEY . '_last_error', $this->options );
	}

	/**
	 * Non-blocking sends cannot know the outcome, so they must not overwrite a
	 * previously recorded real error with a false success.
	 */
	public function test_non_blocking_does_not_touch_last_error() {
		$this->options[ OPTION_KEY . '_last_error' ] = array(
			'error' => 'real failure',
			'time'  => 1,
		);
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured( array( 'blocking' => 0 ) ) )->notify( 'red', 'X', array( 'Form' => 'X' ) );

		$this->assertSame( 'real failure', $this->options[ OPTION_KEY . '_last_error' ]['error'] );
	}

	public function test_sends_nothing_when_unconfigured() {
		$result = $this->notifier( $this->with_settings() )->notify( 'red', 'X', array() );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'not_configured', $result['error'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_should_send_filter_can_veto() {
		Filters\expectApplied( 'cf7_slack_alerts_should_send' )->once()->andReturn( false );
		$this->queue_response( 'ok' );

		$result = $this->notifier( $this->configured() )->notify( 'red', 'X', array( 'Form' => 'Y' ) );

		$this->assertSame( 'filtered', $result['error'] );
		$this->assertSame( array(), $this->requests );
	}

	public function test_message_filter_can_reshape_payload() {
		Filters\expectApplied( 'cf7_slack_alerts_message' )->once()->andReturnUsing(
			function ( $message ) {
				$message['attachments'][0]['footer'] = 'rewritten';
				return $message;
			}
		);
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured() )->notify( 'red', 'X', array( 'Form' => 'Y' ) );

		$this->assertSame( 'rewritten', $this->sent_body()['attachments'][0]['footer'] );
	}

	public function test_test_message_always_blocks() {
		$this->queue_response( 'ok' );

		$this->notifier( $this->configured( array( 'blocking' => 0 ) ) )->send_test();

		$this->assertTrue( $this->requests[0]['args']['blocking'], 'the test button must report real results' );
	}
}
