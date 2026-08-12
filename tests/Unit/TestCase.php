<?php
/**
 * Base class for unit tests.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use CF7_Slack_Alerts\Events;
use CF7_Slack_Alerts\Notifier;
use CF7_Slack_Alerts\Settings;
use CF7_Slack_Alerts\Slack_Client;
use CF7_Slack_Alerts\Updater;
use Yoast\PHPUnitPolyfills\TestCases\TestCase as PolyfillTestCase;

use const CF7_Slack_Alerts\OPTION_KEY;

/**
 * Provides an in-memory WordPress: options, transients and a queued HTTP
 * transport, so tests can assert on what the plugin stored and sent.
 */
abstract class TestCase extends PolyfillTestCase {

	/**
	 * In-memory options table.
	 *
	 * @var array
	 */
	protected $options = array();

	/**
	 * In-memory transients, as key => array( value, expiry ).
	 *
	 * @var array
	 */
	protected $transients = array();

	/**
	 * Outgoing HTTP requests captured during the test.
	 *
	 * @var array
	 */
	protected $requests = array();

	/**
	 * Responses to hand back, in order.
	 *
	 * @var array
	 */
	protected $responses = array();

	/**
	 * What current_user_can() should return.
	 *
	 * @var bool
	 */
	protected $can = true;

	/**
	 * Set up the fake WordPress.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();
		Monkey\setUp();

		$this->options    = array();
		$this->transients = array();
		$this->requests   = array();
		$this->responses  = array();
		$this->can        = true;

		\WPCF7_Submission::$instance = null;

		$this->stub_wordpress();
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tear_down() {
		\WPCF7_Submission::$instance = null;
		Monkey\tearDown();
		parent::tear_down();
	}

	/**
	 * Register function stubs backed by the in-memory state.
	 *
	 * @return void
	 */
	private function stub_wordpress() {
		$self = $this;

		Monkey\Functions\stubTranslationFunctions();
		Monkey\Functions\stubEscapeFunctions();

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) use ( $self ) {
				return array_key_exists( $key, $self->options ) ? $self->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) use ( $self ) {
				$self->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'add_option' )->alias(
			function ( $key, $value ) use ( $self ) {
				if ( array_key_exists( $key, $self->options ) ) {
					return false;
				}
				$self->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) use ( $self ) {
				unset( $self->options[ $key ] );
				return true;
			}
		);

		Functions\when( 'get_transient' )->alias(
			function ( $key ) use ( $self ) {
				if ( ! isset( $self->transients[ $key ] ) ) {
					return false;
				}
				list( $value, $expires ) = $self->transients[ $key ];
				if ( $expires && $expires < time() ) {
					unset( $self->transients[ $key ] );
					return false;
				}
				return $value;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) use ( $self ) {
				$self->transients[ $key ] = array( $value, $ttl ? time() + $ttl : 0 );
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) use ( $self ) {
				unset( $self->transients[ $key ] );
				return true;
			}
		);

		$http = function ( $url, $args = array() ) use ( $self ) {
			$self->requests[] = array(
				'url'  => $url,
				'args' => $args,
			);
			if ( ! $self->responses ) {
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => 'ok',
				);
			}
			return array_shift( $self->responses );
		};
		Functions\when( 'wp_remote_post' )->alias( $http );
		Functions\when( 'wp_remote_get' )->alias( $http );
		Functions\when( 'wp_remote_retrieve_body' )->alias(
			function ( $r ) {
				return is_array( $r ) && isset( $r['body'] ) ? $r['body'] : '';
			}
		);
		Functions\when( 'wp_remote_retrieve_response_code' )->alias(
			function ( $r ) {
				return is_array( $r ) && isset( $r['response']['code'] ) ? $r['response']['code'] : 0;
			}
		);

		Functions\when( 'current_user_can' )->alias(
			function () use ( $self ) {
				return $self->can;
			}
		);

		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'sanitize_text_field' )->alias(
			function ( $s ) {
				return trim( strip_tags( (string) $s ) );
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'absint' )->alias(
			function ( $n ) {
				return abs( (int) $n );
			}
		);
		Functions\when( 'is_email' )->alias(
			function ( $e ) {
				return (bool) filter_var( $e, FILTER_VALIDATE_EMAIL );
			}
		);
		Functions\when( 'home_url' )->alias(
			function ( $p = '' ) {
				return 'https://example.test' . $p;
			}
		);
		Functions\when( 'admin_url' )->alias(
			function ( $p = '' ) {
				return 'https://example.test/wp-admin/' . $p;
			}
		);
		Functions\when( 'current_time' )->justReturn( '2026-01-01 00:00:00' );
		Functions\when( 'get_bloginfo' )->justReturn( '6.9' );
		Functions\when( 'human_time_diff' )->justReturn( '1 min' );
		Functions\when( 'wpautop' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'get_current_screen' )->justReturn( null );
		Functions\when( 'wp_get_current_user' )->justReturn( (object) array( 'user_login' => 'tester' ) );
		Functions\when( 'plugin_basename' )->alias(
			function ( $f ) {
				return basename( dirname( $f ) ) . '/' . basename( $f );
			}
		);
		Functions\when( 'trailingslashit' )->alias(
			function ( $s ) {
				return rtrim( $s, '/\\' ) . '/';
			}
		);
		Functions\when( 'untrailingslashit' )->alias(
			function ( $s ) {
				return rtrim( $s, '/\\' );
			}
		);
		Functions\when( 'add_settings_error' )->justReturn( null );
		Functions\when( 'register_setting' )->justReturn( null );
		Functions\when( 'settings_fields' )->justReturn( null );
		Functions\when( 'settings_errors' )->justReturn( null );
		Functions\when( 'wp_nonce_field' )->justReturn( null );
		Functions\when( 'submit_button' )->justReturn( null );
		Functions\when( 'checked' )->alias( array( $this, 'render_attr' ) );
		Functions\when( 'disabled' )->alias( array( $this, 'render_attr' ) );
	}

	/**
	 * Emulate checked()/disabled(), which echo by default.
	 *
	 * @param mixed $a       Value to compare.
	 * @param mixed $b       Value to compare against.
	 * @param bool  $display Whether to echo.
	 * @return string
	 */
	public function render_attr( $a, $b = true, $display = true ) {
		// phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual -- mirrors core.
		$out = ( $a == $b ) ? ' checked' : '';
		if ( $display ) {
			echo $out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		return $out;
	}

	/**
	 * Persist a settings array, merged over the defaults.
	 *
	 * @param array $overrides Settings to override.
	 * @return Settings A settings instance reading the new values.
	 */
	protected function with_settings( array $overrides = array() ) {
		$settings                 = new Settings();
		$this->options[ OPTION_KEY ] = array_merge( $settings->defaults(), $overrides );

		// Settings memoizes per request, so a fresh instance is required after
		// writing. In production WordPress redirects after saving options.
		return new Settings();
	}

	/**
	 * Build a notifier wired to the given settings.
	 *
	 * @param Settings $settings Settings instance.
	 * @return Notifier
	 */
	protected function notifier( Settings $settings ) {
		return new Notifier( $settings, new Slack_Client( $settings ) );
	}

	/**
	 * Build an events handler wired to the given settings.
	 *
	 * @param Settings $settings Settings instance.
	 * @return Events
	 */
	protected function events( Settings $settings ) {
		return new Events( $settings, $this->notifier( $settings ) );
	}

	/**
	 * Build an updater wired to the given settings.
	 *
	 * @param Settings $settings Settings instance.
	 * @return Updater
	 */
	protected function updater( Settings $settings ) {
		return new Updater( $settings );
	}

	/**
	 * Queue an HTTP response.
	 *
	 * @param mixed $body Response body, or a WP_Error.
	 * @param int   $code HTTP status code.
	 * @return void
	 */
	protected function queue_response( $body, $code = 200 ) {
		if ( $body instanceof \WP_Error ) {
			$this->responses[] = $body;
			return;
		}

		$this->responses[] = array(
			'response' => array( 'code' => $code ),
			'body'     => is_array( $body ) ? wp_json_encode( $body ) : $body,
		);
	}

	/**
	 * The decoded JSON body of a captured request.
	 *
	 * @param int $index Request index.
	 * @return array
	 */
	protected function sent_body( $index = 0 ) {
		return json_decode( $this->requests[ $index ]['args']['body'], true );
	}

	/**
	 * Slack attachment fields from a captured request, as title => value.
	 *
	 * @param int $index Request index.
	 * @return array
	 */
	protected function sent_fields( $index = 0 ) {
		$body = $this->sent_body( $index );
		$out  = array();

		foreach ( $body['attachments'][0]['fields'] as $field ) {
			$out[ $field['title'] ] = $field['value'];
		}

		return $out;
	}

	/**
	 * A submission double with sensible defaults, registered as current.
	 *
	 * @param string $title Form title.
	 * @return \WPCF7_Submission
	 */
	protected function submission( $title = 'Contact Page' ) {
		$submission         = new \WPCF7_Submission();
		$submission->form   = new \WPCF7_ContactForm( $title );
		$submission->posted = array(
			'your-name'  => 'Jo',
			'your-email' => 'jo@example.com',
		);
		$submission->meta   = array(
			'url'       => 'https://example.test/contact',
			'remote_ip' => '203.0.113.9',
		);

		\WPCF7_Submission::$instance = $submission;

		return $submission;
	}
}
