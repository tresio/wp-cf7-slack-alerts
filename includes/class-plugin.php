<?php
/**
 * Plugin container and bootstrap.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the pieces together and exposes them to hook callbacks.
 */
class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

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
	 * Notifier instance.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Event handlers.
	 *
	 * @var Events
	 */
	private $events;

	/**
	 * Updater instance.
	 *
	 * @var Updater
	 */
	private $updater;

	/**
	 * Whether boot() already ran.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Build the object graph.
	 */
	private function __construct() {
		$this->settings = new Settings();
		$this->client   = new Slack_Client( $this->settings );
		$this->notifier = new Notifier( $this->settings, $this->client );
		$this->events   = new Events( $this->settings, $this->notifier );
		$this->updater  = new Updater( $this->settings );
	}

	/**
	 * Shared instance.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register every hook the plugin needs.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->maybe_install();
		$this->events->boot();
		$this->updater->boot();

		if ( is_admin() ) {
			$this->settings->boot();
		}

		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Seed the option on first run.
	 *
	 * Sites upgrading from 1.0.x had their webhook in a constant and no stored
	 * settings, so they start on the webhook transport with the mail failure
	 * alerts that version always sent. Without this they would go silent.
	 *
	 * @return void
	 */
	private function maybe_install() {
		if ( false !== get_option( OPTION_KEY, false ) ) {
			return;
		}

		$defaults = $this->settings->defaults();

		if ( defined( 'CF7_SLACK_WEBHOOK_URL' ) && false !== strpos( (string) CF7_SLACK_WEBHOOK_URL, 'hooks.slack.com' ) ) {
			$defaults['transport'] = 'webhook';
		}

		add_option( OPTION_KEY, $defaults, '', false );
	}

	/**
	 * Load translations.
	 *
	 * @return void
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'cf7-slack-error-alerts', false, dirname( plugin_basename( PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Settings accessor.
	 *
	 * @return Settings
	 */
	public function settings() {
		return $this->settings;
	}

	/**
	 * Notifier accessor.
	 *
	 * @return Notifier
	 */
	public function notifier() {
		return $this->notifier;
	}

	/**
	 * Updater accessor.
	 *
	 * @return Updater
	 */
	public function updater() {
		return $this->updater;
	}
}
