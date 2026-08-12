<?php
/**
 * Slack transport behaviour.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use CF7_Slack_Alerts\Slack_Client;
use WP_Error;

/**
 * @covers \CF7_Slack_Alerts\Slack_Client
 */
class SlackClientTest extends TestCase {

	/**
	 * Build a client for the given settings overrides.
	 *
	 * @param array $overrides Settings overrides.
	 * @return Slack_Client
	 */
	private function client( array $overrides ) {
		return new Slack_Client( $this->with_settings( $overrides ) );
	}

	public function test_bot_transport_posts_to_chat_postmessage() {
		$client = $this->client(
			array(
				'bot_token' => 'xoxb-test',
				'channel'   => '#alerts',
			)
		);
		$this->queue_response( array( 'ok' => true ) );

		$result = $client->send( array( 'text' => 'hi' ), true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://slack.com/api/chat.postMessage', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer xoxb-test', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( '#alerts', $this->sent_body()['channel'] );
	}

	public function test_bot_transport_requires_credentials() {
		$noToken = $this->client( array( 'channel' => '#a' ) )->send( array(), true );
		$this->assertFalse( $noToken['ok'] );
		$this->assertSame( array(), $this->requests, 'must not call Slack without a token' );

		$noChannel = $this->client( array( 'bot_token' => 'xoxb-x' ) )->send( array(), true );
		$this->assertFalse( $noChannel['ok'] );
		$this->assertSame( array(), $this->requests );
	}

	/**
	 * @dataProvider slack_error_provider
	 *
	 * @param string $code Slack error code.
	 * @param string $hint Text an admin should see.
	 */
	public function test_translates_slack_errors( $code, $hint ) {
		$client = $this->client(
			array(
				'bot_token' => 'xoxb-test',
				'channel'   => '#a',
			)
		);
		$this->queue_response(
			array(
				'ok'    => false,
				'error' => $code,
			)
		);

		$result = $client->send( array(), true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( $hint, $result['error'] );
	}

	/**
	 * @return array
	 */
	public function slack_error_provider() {
		return array(
			array( 'invalid_auth', 'revoked' ),
			array( 'not_in_channel', '/invite' ),
			array( 'channel_not_found', 'channel ID' ),
			array( 'missing_scope', 'chat:write' ),
			array( 'ratelimited', 'throttle' ),
			array( 'some_unmapped_code', 'some_unmapped_code' ),
		);
	}

	public function test_webhook_transport_posts_to_url() {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
			)
		);
		$this->queue_response( 'ok' );

		$result = $client->send( array( 'text' => 'hi' ), true );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'https://hooks.slack.com/services/A/B/C', $this->requests[0]['url'] );
	}

	public function test_webhook_reports_dead_endpoint() {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
			)
		);
		$this->queue_response( 'no_service', 404 );

		$result = $client->send( array(), true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'no longer exists', $result['error'] );
	}

	/**
	 * Fire-and-forget is the default so a form submission is never slowed by
	 * Slack; nothing can be inspected in that mode.
	 */
	public function test_non_blocking_send_does_not_inspect_response() {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
			)
		);
		$this->queue_response( 'invalid_payload', 400 );

		$result = $client->send( array(), false );

		$this->assertTrue( $result['ok'] );
		$this->assertFalse( $this->requests[0]['args']['blocking'] );
	}

	public function test_transport_error_is_reported() {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/A/B/C',
			)
		);
		$this->queue_response( new WP_Error( 'http_request_failed', 'Connection refused' ) );

		$result = $client->send( array(), true );

		$this->assertFalse( $result['ok'] );
		$this->assertStringContainsString( 'Connection refused', $result['error'] );
	}

	/**
	 * A webhook URL is itself the credential, and error strings are persisted
	 * and rendered in the admin, so they must never carry one.
	 *
	 * @dataProvider secret_provider
	 *
	 * @param string $message Error text containing a secret.
	 * @param string $secret  Substring that must not survive.
	 */
	public function test_redacts_credentials_from_errors( $message, $secret ) {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/T0SEC/B0SEC/zzSECRETzz',
				'bot_token'   => 'xoxb-1111-SECRETTOKEN',
			)
		);
		$this->queue_response( new WP_Error( 'http_request_failed', $message ) );

		$result = $client->send( array(), true );

		$this->assertStringNotContainsString( $secret, $result['error'] );
	}

	/**
	 * @return array
	 */
	public function secret_provider() {
		return array(
			'stored webhook' => array( 'timed out for https://hooks.slack.com/services/T0SEC/B0SEC/zzSECRETzz', 'zzSECRETzz' ),
			'stored token'   => array( 'auth failed with xoxb-1111-SECRETTOKEN', 'SECRETTOKEN' ),
			'other webhook'  => array( 'posting to https://hooks.slack.com/services/TAAA/BBBB/ccDDee failed', 'ccDDee' ),
			'other token'    => array( 'bad token xoxp-2222-OTHERSECRET here', 'OTHERSECRET' ),
		);
	}

	public function test_redaction_keeps_the_diagnostic_text() {
		$client = $this->client(
			array(
				'transport'   => 'webhook',
				'webhook_url' => 'https://hooks.slack.com/services/T0SEC/B0SEC/zzSECRETzz',
			)
		);
		$this->queue_response( new WP_Error( 'http_request_failed', 'cURL error 28: timed out for https://hooks.slack.com/services/T0SEC/B0SEC/zzSECRETzz' ) );

		$result = $client->send( array(), true );

		$this->assertStringContainsString( 'cURL error 28', $result['error'] );
		$this->assertStringContainsString( '[redacted]', $result['error'] );
	}
}
