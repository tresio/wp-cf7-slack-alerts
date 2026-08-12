<?php
/**
 * Contact Form 7 and wp_mail hook handling.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use WP_Error;

/**
 * @covers \CF7_Slack_Alerts\Events
 */
class EventsTest extends TestCase {

	/**
	 * Settings with every event on and delivery observable.
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
					'events'           => array(
						'mail_failed'       => 1,
						'spam'              => 1,
						'validation_failed' => 1,
						'aborted'           => 1,
						'wp_mail_failed'    => 1,
					),
				),
				$extra
			)
		);
	}

	public function test_mail_failure_includes_submission_context() {
		$submission = $this->submission();
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_cf7_mail_failed( $submission->form );

		$fields = $this->sent_fields();
		$this->assertSame( 'Contact Page', $fields['Form'] );
		$this->assertSame( 'https://example.test/contact', $fields['Page URL'] );
		$this->assertSame( '203.0.113.9', $fields['IP'] );
		$this->assertSame( 'jo@example.com', $fields['Submitter'] );
	}

	/**
	 * The 1.0.1 fatal: CF7 hands a Submission where a ContactForm is expected,
	 * and calling title() on it was fatal.
	 */
	public function test_survives_a_submission_passed_as_the_form() {
		$submission = $this->submission();
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_cf7_mail_failed( $submission );

		$this->assertSame( 'Contact Page', $this->sent_fields()['Form'] );
	}

	public function test_handles_a_missing_submission() {
		\WPCF7_Submission::$instance = null;
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_cf7_mail_failed( null );

		$this->assertSame( 'Unknown', $this->sent_fields()['Form'] );
	}

	/**
	 * wpcf7_spam is a filter that runs on every submission; returning anything
	 * other than the input would break the spam chain for other plugins.
	 */
	public function test_spam_filter_returns_its_input_unchanged() {
		$submission = $this->submission();
		$events     = $this->events( $this->configured() );
		$this->queue_response( 'ok' );

		$this->assertTrue( $events->on_cf7_spam( true, $submission ) );
		$this->assertFalse( $events->on_cf7_spam( false, $submission ) );
	}

	public function test_spam_alerts_only_when_spam_detected() {
		$submission = $this->submission();
		$events     = $this->events( $this->configured() );

		$events->on_cf7_spam( false, $submission );

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @dataProvider status_provider
	 *
	 * @param string $status   Submission status.
	 * @param bool   $alerts   Whether an alert is expected.
	 * @param string $severity Expected colour, when alerting.
	 */
	public function test_submit_alerts_only_on_failure_statuses( $status, $alerts, $severity = '' ) {
		$submission = $this->submission();
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_cf7_submit(
			$submission->form,
			array(
				'status'  => $status,
				'message' => 'msg',
			)
		);

		if ( ! $alerts ) {
			$this->assertSame( array(), $this->requests );
			return;
		}

		$this->assertCount( 1, $this->requests );
		$this->assertSame( $severity, $this->sent_body()['attachments'][0]['color'] );
	}

	/**
	 * @return array
	 */
	public function status_provider() {
		return array(
			'mail sent'         => array( 'mail_sent', false ),
			'validation failed' => array( 'validation_failed', true, '#f57c00' ),
			'aborted'           => array( 'aborted', true, '#d32f2f' ),
			'spam'              => array( 'spam', false ),
			'empty'             => array( '', false ),
		);
	}

	/**
	 * A CF7 mail failure fires both wp_mail_failed and wpcf7_mail_failed. Two
	 * Slack messages for one incident is noise, so the generic handler stays
	 * quiet and hands its error to the specific one.
	 */
	public function test_one_alert_per_mail_failure_with_the_error_folded_in() {
		$submission = $this->submission();
		$events     = $this->events( $this->configured() );
		$this->queue_response( 'ok' );

		$events->on_wp_mail_failed( new WP_Error( 'wp_mail_failed', 'SMTP connect() failed' ) );
		$this->assertSame( array(), $this->requests, 'generic handler must stay quiet mid-submission' );

		$events->on_cf7_mail_failed( $submission->form );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'SMTP connect() failed', $this->sent_fields()['Mailer Error'] );
	}

	public function test_wp_mail_failure_outside_a_submission_still_alerts() {
		\WPCF7_Submission::$instance = null;
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_wp_mail_failed( new WP_Error( 'oops', 'Standalone failure' ) );

		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'Standalone failure', $this->sent_fields()['Error'] );
	}

	/**
	 * @dataProvider toggle_provider
	 *
	 * @param string $event  Event key to disable.
	 * @param string $method Handler to invoke.
	 * @param array  $args   Handler arguments.
	 */
	public function test_disabled_events_send_nothing( $event, $method, array $args ) {
		$submission = $this->submission();
		$events     = $this->events(
			$this->configured(
				array(
					'events' => array(
						'mail_failed'       => 1,
						'spam'              => 1,
						'validation_failed' => 1,
						'aborted'           => 1,
						'wp_mail_failed'    => 1,
						$event              => 0,
					),
				)
			)
		);

		$resolved = array_map(
			function ( $arg ) use ( $submission ) {
				return 'FORM' === $arg ? $submission->form : $arg;
			},
			$args
		);

		call_user_func_array( array( $events, $method ), $resolved );

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @return array
	 */
	public function toggle_provider() {
		return array(
			'mail failed'       => array( 'mail_failed', 'on_cf7_mail_failed', array( 'FORM' ) ),
			'spam'              => array( 'spam', 'on_cf7_spam', array( true, null ) ),
			'validation failed' => array( 'validation_failed', 'on_cf7_submit', array( 'FORM', array( 'status' => 'validation_failed' ) ) ),
			'aborted'           => array( 'aborted', 'on_cf7_submit', array( 'FORM', array( 'status' => 'aborted' ) ) ),
		);
	}

	public function test_submitter_can_be_withheld() {
		$submission = $this->submission();
		$this->queue_response( 'ok' );

		$this->events( $this->configured( array( 'include_submitter' => 0 ) ) )->on_cf7_mail_failed( $submission->form );

		$this->assertArrayNotHasKey( 'Submitter', $this->sent_fields() );
	}

	public function test_finds_submitter_email_in_an_unconventional_field() {
		$submission         = $this->submission();
		$submission->posted = array( 'contact-address' => 'someone@example.org' );
		$this->queue_response( 'ok' );

		$this->events( $this->configured() )->on_cf7_mail_failed( $submission->form );

		$this->assertSame( 'someone@example.org', $this->sent_fields()['Submitter'] );
	}
}
