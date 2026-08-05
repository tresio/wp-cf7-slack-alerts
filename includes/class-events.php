<?php
/**
 * Contact Form 7 and wp_mail hook handlers.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

use WPCF7_ContactForm;
use WPCF7_Submission;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Translates WordPress and CF7 events into Slack alerts.
 */
class Events {

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Notifier instance.
	 *
	 * @var Notifier
	 */
	private $notifier;

	/**
	 * Mailer error captured from wp_mail_failed during a CF7 submission.
	 *
	 * @var string
	 */
	private $pending_mail_error = '';

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 * @param Notifier $notifier Notifier instance.
	 */
	public function __construct( Settings $settings, Notifier $notifier ) {
		$this->settings = $settings;
		$this->notifier = $notifier;
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'wpcf7_mail_failed', array( $this, 'on_cf7_mail_failed' ) );
		add_filter( 'wpcf7_spam', array( $this, 'on_cf7_spam' ), 99, 2 );
		add_action( 'wpcf7_submit', array( $this, 'on_cf7_submit' ), 10, 2 );
		add_action( 'wp_mail_failed', array( $this, 'on_wp_mail_failed' ) );
	}

	/**
	 * A CF7 form failed to send its mail.
	 *
	 * @param mixed $contact_form Contact form, per the CF7 action signature.
	 * @return void
	 */
	public function on_cf7_mail_failed( $contact_form ) {
		if ( ! $this->settings->event_enabled( 'mail_failed' ) ) {
			return;
		}

		$extra = array();

		if ( '' !== $this->pending_mail_error ) {
			$extra['Mailer Error'] = $this->pending_mail_error;
		}

		global $phpmailer;
		if ( isset( $phpmailer ) && ! empty( $phpmailer->ErrorInfo ) ) {
			$extra['SMTP / Mailer Error'] = $phpmailer->ErrorInfo;
		}

		$this->notifier->notify(
			'red',
			':rotating_light: CF7 mail FAILED to send',
			$this->context( $contact_form, $extra )
		);
	}

	/**
	 * A submission was voted on by the spam filters.
	 *
	 * This is a filter, not an action: it runs on every submission, so it only
	 * alerts when spam was actually detected, and always returns $spam intact.
	 *
	 * @param bool  $spam       Whether the submission is spam.
	 * @param mixed $submission Submission object, on CF7 5.6+.
	 * @return bool
	 */
	public function on_cf7_spam( $spam, $submission = null ) {
		if ( true !== $spam || ! $this->settings->event_enabled( 'spam' ) ) {
			return $spam;
		}

		$contact_form = null;
		if ( is_object( $submission ) && method_exists( $submission, 'get_contact_form' ) ) {
			$contact_form = $submission->get_contact_form();
		}

		$this->notifier->notify(
			'orange',
			':warning: CF7 submission flagged as spam (captcha / akismet)',
			$this->context( $contact_form )
		);

		return $spam;
	}

	/**
	 * A submission finished, successfully or otherwise.
	 *
	 * @param mixed $contact_form Contact form.
	 * @param array $result       Submission result.
	 * @return void
	 */
	public function on_cf7_submit( $contact_form, $result ) {
		if ( empty( $result['status'] ) ) {
			return;
		}

		$status = $result['status'];

		if ( ! in_array( $status, array( 'validation_failed', 'aborted' ), true ) ) {
			return;
		}

		if ( ! $this->settings->event_enabled( $status ) ) {
			return;
		}

		$this->notifier->notify(
			'aborted' === $status ? 'red' : 'orange',
			':warning: CF7 submission ' . str_replace( '_', ' ', $status ),
			$this->context(
				$contact_form,
				array(
					'Status'  => $status,
					'Message' => isset( $result['message'] ) ? wp_strip_all_tags( $result['message'] ) : '',
				)
			)
		);
	}

	/**
	 * Any wp_mail() call failed.
	 *
	 * During a CF7 submission this stays quiet and stashes the error instead:
	 * wpcf7_mail_failed fires moments later with far better context, and two
	 * alerts for one failure is noise.
	 *
	 * @param mixed $wp_error Error from wp_mail().
	 * @return void
	 */
	public function on_wp_mail_failed( $wp_error ) {
		$message = $wp_error instanceof WP_Error ? $wp_error->get_error_message() : '';
		$code    = $wp_error instanceof WP_Error ? $wp_error->get_error_code() : '';

		if ( $this->current_submission() ) {
			$this->pending_mail_error = $message;

			return;
		}

		if ( ! $this->settings->event_enabled( 'wp_mail_failed' ) ) {
			return;
		}

		$this->notifier->notify(
			'red',
			':rotating_light: wp_mail() FAILED (likely SMTP / API issue)',
			array(
				'Site'  => home_url(),
				'Error' => $message,
				'Code'  => is_scalar( $code ) ? (string) $code : '',
				'Time'  => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * The active CF7 submission, if one is in flight.
	 *
	 * @return WPCF7_Submission|null
	 */
	private function current_submission() {
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return null;
		}

		$submission = WPCF7_Submission::get_instance();

		return $submission instanceof WPCF7_Submission ? $submission : null;
	}

	/**
	 * Build the common context block for a CF7 alert.
	 *
	 * Accepts a WPCF7_ContactForm, a WPCF7_Submission, or nothing, and works
	 * out the actual contact form defensively.
	 *
	 * @param mixed $contact_form Whatever the hook handed us.
	 * @param array $extra        Additional fields appended after the context.
	 * @return array
	 */
	private function context( $contact_form = null, $extra = array() ) {
		$submission = $this->current_submission();

		if ( ! ( $contact_form instanceof WPCF7_ContactForm ) ) {
			$contact_form = $submission ? $submission->get_contact_form() : null;
		}

		$context = array(
			'Site'     => home_url(),
			'Form'     => $contact_form instanceof WPCF7_ContactForm ? $contact_form->title() : __( 'Unknown', 'cf7-slack-error-alerts' ),
			'Page URL' => $submission ? $submission->get_meta( 'url' ) : '',
			'IP'       => $submission ? $submission->get_meta( 'remote_ip' ) : '',
			'Time'     => current_time( 'mysql' ),
		);

		if ( $this->settings->get( 'include_submitter' ) ) {
			$context['Submitter'] = $this->submitter_email( $submission );
		}

		return array_merge( $context, $extra );
	}

	/**
	 * Best guess at the submitter's email address.
	 *
	 * @param WPCF7_Submission|null $submission Active submission.
	 * @return string
	 */
	private function submitter_email( $submission ) {
		if ( ! $submission ) {
			return '';
		}

		$posted = $submission->get_posted_data();

		if ( ! is_array( $posted ) ) {
			return '';
		}

		foreach ( array( 'your-email', 'email', 'Email', 'user-email' ) as $key ) {
			if ( ! empty( $posted[ $key ] ) && is_scalar( $posted[ $key ] ) ) {
				return (string) $posted[ $key ];
			}
		}

		foreach ( $posted as $value ) {
			if ( is_scalar( $value ) && is_email( (string) $value ) ) {
				return (string) $value;
			}
		}

		return '';
	}
}
