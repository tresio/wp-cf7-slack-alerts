<?php
/**
 * Global-namespace doubles for WordPress classes the plugin type-hints or
 * instantiates. Functions are stubbed per test by Brain Monkey; only things
 * that must exist as real classes live here.
 *
 * @package CF7_Slack_Alerts
 */

if ( ! class_exists( 'WP_Error' ) ) {
	/**
	 * Stand-in for WordPress's error object.
	 */
	class WP_Error {

		/**
		 * Error code.
		 *
		 * @var string
		 */
		private $code;

		/**
		 * Error message.
		 *
		 * @var string
		 */
		private $message;

		/**
		 * Constructor.
		 *
		 * @param string $code    Error code.
		 * @param string $message Error message.
		 */
		public function __construct( $code = '', $message = '' ) {
			$this->code    = $code;
			$this->message = $message;
		}

		/**
		 * Error code accessor.
		 *
		 * @return string
		 */
		public function get_error_code() {
			return $this->code;
		}

		/**
		 * Error message accessor.
		 *
		 * @return string
		 */
		public function get_error_message() {
			return $this->message;
		}
	}
}

if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
	/**
	 * Stand-in for a Contact Form 7 form.
	 */
	class WPCF7_ContactForm {

		/**
		 * Form title.
		 *
		 * @var string
		 */
		private $title;

		/**
		 * Constructor.
		 *
		 * @param string $title Form title.
		 */
		public function __construct( $title = 'Test Form' ) {
			$this->title = $title;
		}

		/**
		 * Title accessor.
		 *
		 * @return string
		 */
		public function title() {
			return $this->title;
		}
	}
}

if ( ! class_exists( 'WPCF7_Submission' ) ) {
	/**
	 * Stand-in for a Contact Form 7 submission.
	 */
	class WPCF7_Submission {

		/**
		 * Current instance, or null when no submission is in flight.
		 *
		 * @var WPCF7_Submission|null
		 */
		public static $instance = null;

		/**
		 * Associated contact form.
		 *
		 * @var WPCF7_ContactForm|null
		 */
		public $form = null;

		/**
		 * Posted form data.
		 *
		 * @var array
		 */
		public $posted = array();

		/**
		 * Submission meta.
		 *
		 * @var array
		 */
		public $meta = array();

		/**
		 * Current instance accessor.
		 *
		 * @return WPCF7_Submission|null
		 */
		public static function get_instance() {
			return self::$instance;
		}

		/**
		 * Contact form accessor.
		 *
		 * @return WPCF7_ContactForm|null
		 */
		public function get_contact_form() {
			return $this->form;
		}

		/**
		 * Posted data accessor.
		 *
		 * @return array
		 */
		public function get_posted_data() {
			return $this->posted;
		}

		/**
		 * Meta accessor.
		 *
		 * @param string $key Meta key.
		 * @return string
		 */
		public function get_meta( $key ) {
			return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : '';
		}
	}
}
