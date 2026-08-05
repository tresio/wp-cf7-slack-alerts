<?php
/**
 * Slack transport.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delivers a message payload to Slack over either chat.postMessage or an
 * incoming webhook, depending on how the plugin is configured.
 */
class Slack_Client {

	const API_URL = 'https://slack.com/api/chat.postMessage';

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Send a message.
	 *
	 * @param array $message  Slack message body (attachments, text, ...).
	 * @param bool  $blocking Whether to wait for and interpret the response.
	 * @return array{ok:bool,error:string}
	 */
	public function send( array $message, $blocking ) {
		if ( 'webhook' === $this->settings->get( 'transport' ) ) {
			return $this->send_webhook( $message, $blocking );
		}

		return $this->send_api( $message, $blocking );
	}

	/**
	 * Post via chat.postMessage using a bot token.
	 *
	 * @param array $message  Slack message body.
	 * @param bool  $blocking Whether to wait for the response.
	 * @return array{ok:bool,error:string}
	 */
	private function send_api( array $message, $blocking ) {
		$token   = trim( (string) $this->settings->get( 'bot_token' ) );
		$channel = trim( (string) $this->settings->get( 'channel' ) );

		if ( '' === $token ) {
			return $this->fail( __( 'No Slack bot token configured.', 'cf7-slack-error-alerts' ) );
		}

		if ( '' === $channel ) {
			return $this->fail( __( 'No Slack channel configured.', 'cf7-slack-error-alerts' ) );
		}

		$message['channel'] = $channel;

		$response = wp_remote_post(
			self::API_URL,
			$this->request_args(
				$message,
				$blocking,
				array(
					'Content-Type'  => 'application/json; charset=utf-8',
					'Authorization' => 'Bearer ' . $token,
				)
			)
		);

		if ( ! $blocking ) {
			return $this->ok();
		}

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return $this->fail(
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Unexpected response from Slack (HTTP %d).', 'cf7-slack-error-alerts' ),
					(int) wp_remote_retrieve_response_code( $response )
				)
			);
		}

		if ( empty( $body['ok'] ) ) {
			return $this->fail( $this->explain( isset( $body['error'] ) ? (string) $body['error'] : 'unknown_error' ) );
		}

		return $this->ok();
	}

	/**
	 * Post to an incoming webhook.
	 *
	 * @param array $message  Slack message body.
	 * @param bool  $blocking Whether to wait for the response.
	 * @return array{ok:bool,error:string}
	 */
	private function send_webhook( array $message, $blocking ) {
		$url = trim( (string) $this->settings->get( 'webhook_url' ) );

		if ( '' === $url || false === strpos( $url, 'hooks.slack.com' ) ) {
			return $this->fail( __( 'No valid Slack webhook URL configured.', 'cf7-slack-error-alerts' ) );
		}

		$channel = trim( (string) $this->settings->get( 'channel' ) );
		if ( '' !== $channel ) {
			$message['channel'] = $channel;
		}

		$response = wp_remote_post(
			$url,
			$this->request_args( $message, $blocking, array( 'Content-Type' => 'application/json' ) )
		);

		if ( ! $blocking ) {
			return $this->ok();
		}

		if ( is_wp_error( $response ) ) {
			return $this->fail( $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = trim( (string) wp_remote_retrieve_body( $response ) );

		if ( 200 !== $code || 'ok' !== strtolower( $body ) ) {
			return $this->fail( '' !== $body ? $this->explain( $body ) : sprintf( 'HTTP %d', $code ) );
		}

		return $this->ok();
	}

	/**
	 * Build wp_remote_post arguments.
	 *
	 * @param array $message  Slack message body.
	 * @param bool  $blocking Whether to wait for the response.
	 * @param array $headers  Request headers.
	 * @return array
	 */
	private function request_args( array $message, $blocking, array $headers ) {
		return array(
			'timeout'     => $blocking ? 5 : 1,
			'blocking'    => (bool) $blocking,
			'redirection' => 0,
			'headers'     => $headers,
			'body'        => wp_json_encode( $message ),
			'user-agent'  => 'cf7-slack-error-alerts/' . VERSION . '; ' . home_url( '/' ),
		);
	}

	/**
	 * Turn a Slack error code into something an admin can act on.
	 *
	 * @param string $code Raw Slack error string.
	 * @return string
	 */
	private function explain( $code ) {
		$hints = array(
			'invalid_auth'          => __( 'invalid_auth — the bot token is wrong or was revoked.', 'cf7-slack-error-alerts' ),
			'not_authed'            => __( 'not_authed — no token was sent.', 'cf7-slack-error-alerts' ),
			'account_inactive'      => __( 'account_inactive — the Slack app was disabled or uninstalled.', 'cf7-slack-error-alerts' ),
			'channel_not_found'     => __( 'channel_not_found — check the channel ID or name.', 'cf7-slack-error-alerts' ),
			'not_in_channel'        => __( 'not_in_channel — invite the bot to that channel with /invite @yourbot.', 'cf7-slack-error-alerts' ),
			'is_archived'           => __( 'is_archived — that channel is archived.', 'cf7-slack-error-alerts' ),
			'missing_scope'         => __( 'missing_scope — the app needs the chat:write scope, then reinstall it.', 'cf7-slack-error-alerts' ),
			'no_service'            => __( 'no_service — this webhook no longer exists.', 'cf7-slack-error-alerts' ),
			'no_team'               => __( 'no_team — the workspace for this webhook is gone.', 'cf7-slack-error-alerts' ),
			'invalid_payload'       => __( 'invalid_payload — Slack rejected the message body.', 'cf7-slack-error-alerts' ),
			'ratelimited'           => __( 'ratelimited — too many messages; raise the throttle setting.', 'cf7-slack-error-alerts' ),
			'rate_limited'          => __( 'rate_limited — too many messages; raise the throttle setting.', 'cf7-slack-error-alerts' ),
			'channel_is_archived'   => __( 'channel_is_archived — that channel is archived.', 'cf7-slack-error-alerts' ),
			'restricted_action'     => __( 'restricted_action — workspace policy blocked the post.', 'cf7-slack-error-alerts' ),
			'org_login_required'    => __( 'org_login_required — the token is not valid for this workspace.', 'cf7-slack-error-alerts' ),
			'team_access_not_granted' => __( 'team_access_not_granted — the app is not installed in this workspace.', 'cf7-slack-error-alerts' ),
		);

		$code = trim( $code );

		return isset( $hints[ $code ] ) ? $hints[ $code ] : $code;
	}

	/**
	 * Success result.
	 *
	 * @return array{ok:bool,error:string}
	 */
	private function ok() {
		return array(
			'ok'    => true,
			'error' => '',
		);
	}

	/**
	 * Failure result.
	 *
	 * @param string $error Error text.
	 * @return array{ok:bool,error:string}
	 */
	private function fail( $error ) {
		return array(
			'ok'    => false,
			'error' => (string) $error,
		);
	}
}
