<?php
/**
 * Message construction, throttling and dispatch.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds Slack payloads from a severity, a headline and a set of detail fields,
 * then hands them to the transport.
 */
class Notifier {

	const LAST_ERROR_OPTION = OPTION_KEY . '_last_error';
	const MAX_FIELD_LENGTH  = 900;
	const MAX_FIELDS        = 20;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Transport.
	 *
	 * @var Slack_Client
	 */
	private $client;

	/**
	 * Constructor.
	 *
	 * @param Settings     $settings Settings instance.
	 * @param Slack_Client $client   Transport.
	 */
	public function __construct( Settings $settings, Slack_Client $client ) {
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Severity name to Slack attachment color.
	 *
	 * @param string $severity 'red' | 'orange' | 'green'.
	 * @return string
	 */
	private function color( $severity ) {
		$map = array(
			'red'    => '#d32f2f',
			'orange' => '#f57c00',
			'green'  => '#388e3c',
		);

		return isset( $map[ $severity ] ) ? $map[ $severity ] : '#888888';
	}

	/**
	 * Compose and send an alert.
	 *
	 * @param string $severity 'red' | 'orange' | 'green'.
	 * @param string $title    Headline shown in Slack.
	 * @param array  $details  Key => value pairs rendered as fields.
	 * @return array{ok:bool,error:string}
	 */
	public function notify( $severity, $title, $details = array() ) {
		if ( ! $this->settings->is_configured() ) {
			return array(
				'ok'    => false,
				'error' => 'not_configured',
			);
		}

		/**
		 * Filter whether a given alert should be sent.
		 *
		 * @param bool   $send     Whether to send.
		 * @param string $severity Severity name.
		 * @param string $title    Alert headline.
		 * @param array  $details  Detail fields.
		 */
		if ( ! apply_filters( 'cf7_slack_alerts_should_send', true, $severity, $title, $details ) ) {
			return array(
				'ok'    => false,
				'error' => 'filtered',
			);
		}

		if ( $this->is_throttled( $title, $details ) ) {
			return array(
				'ok'    => false,
				'error' => 'throttled',
			);
		}

		return $this->dispatch( $this->build_message( $severity, $title, $details ), (bool) $this->settings->get( 'blocking' ) );
	}

	/**
	 * Send a deliberately blocking test message and report the raw outcome.
	 *
	 * @return array{ok:bool,error:string}
	 */
	public function send_test() {
		$message = $this->build_message(
			'green',
			':white_check_mark: CF7 Slack Error Alerts test',
			array(
				'Site'      => home_url(),
				'Method'    => 'webhook' === $this->settings->get( 'transport' ) ? 'Incoming webhook' : 'Bot token',
				'Plugin'    => 'v' . VERSION,
				'Triggered' => wp_get_current_user()->user_login,
				'Time'      => current_time( 'mysql' ),
			)
		);

		return $this->dispatch( $message, true );
	}

	/**
	 * Hand a built message to the transport and record the outcome.
	 *
	 * @param array $message  Slack message body.
	 * @param bool  $blocking Whether to wait for the response.
	 * @return array{ok:bool,error:string}
	 */
	private function dispatch( array $message, $blocking ) {
		$result = $this->client->send( $message, $blocking );

		if ( $blocking ) {
			if ( $result['ok'] ) {
				delete_option( self::LAST_ERROR_OPTION );
			} else {
				update_option(
					self::LAST_ERROR_OPTION,
					array(
						'error' => $result['error'],
						'time'  => time(),
					),
					false
				);
			}
		}

		return $result;
	}

	/**
	 * Build the Slack message body.
	 *
	 * @param string $severity 'red' | 'orange' | 'green'.
	 * @param string $title    Headline shown in Slack.
	 * @param array  $details  Key => value pairs rendered as fields.
	 * @return array
	 */
	private function build_message( $severity, $title, array $details ) {
		$fields = array();

		foreach ( $details as $label => $value ) {
			if ( '' === $value || null === $value ) {
				continue;
			}

			if ( count( $fields ) >= self::MAX_FIELDS ) {
				break;
			}

			$text = is_scalar( $value ) ? (string) $value : wp_json_encode( $value );
			$text = $this->truncate( wp_strip_all_tags( $text ) );

			$fields[] = array(
				'title' => (string) $label,
				'value' => $text,
				'short' => $this->length( $text ) < 40,
			);
		}

		$message = array(
			'text'        => $title,
			'attachments' => array(
				array(
					'color'    => $this->color( $severity ),
					'fallback' => $title,
					'title'    => $title,
					'fields'   => $fields,
					'footer'   => wp_parse_url( home_url(), PHP_URL_HOST ),
					'ts'       => time(),
				),
			),
		);

		/**
		 * Filter the Slack message body immediately before it is sent.
		 *
		 * @param array  $message  Slack message body.
		 * @param string $severity Severity name.
		 * @param array  $details  Detail fields.
		 */
		return apply_filters( 'cf7_slack_alerts_message', $message, $severity, $details );
	}

	/**
	 * Whether an identical alert was already sent inside the throttle window.
	 *
	 * Recording happens here too, so callers get a single check-and-set.
	 *
	 * @param string $title   Alert headline.
	 * @param array  $details Detail fields.
	 * @return bool
	 */
	private function is_throttled( $title, array $details ) {
		$seconds = (int) $this->settings->get( 'throttle_seconds' );

		if ( $seconds < 1 ) {
			return false;
		}

		$form = isset( $details['Form'] ) ? $details['Form'] : '';
		$key  = 'cf7sa_throttle_' . md5( $title . '|' . $form );

		if ( get_transient( $key ) ) {
			return true;
		}

		set_transient( $key, 1, $seconds );

		return false;
	}

	/**
	 * Shorten a field value to Slack-friendly length.
	 *
	 * @param string $text Field value.
	 * @return string
	 */
	private function truncate( $text ) {
		if ( $this->length( $text ) <= self::MAX_FIELD_LENGTH ) {
			return $text;
		}

		$cut = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_FIELD_LENGTH ) : substr( $text, 0, self::MAX_FIELD_LENGTH );

		return $cut . '…';
	}

	/**
	 * Multibyte-safe string length.
	 *
	 * @param string $text Subject string.
	 * @return int
	 */
	private function length( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text ) : strlen( $text );
	}
}
