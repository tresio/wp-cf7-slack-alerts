<?php
/**
 * Settings storage, sanitization and the options screen.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the plugin option, and renders the admin screen.
 *
 * Any value may be locked from wp-config.php with a constant, in which case the
 * stored option is ignored and the corresponding field renders read-only.
 */
class Settings {

	const PAGE_SLUG   = 'cf7-slack-error-alerts';
	const GROUP       = 'cf7_slack_alerts';
	const TEST_ACTION = 'cf7sa_send_test';
	const NOTICE_KEY  = 'cf7sa_notice';

	/**
	 * Cached merged settings for this request.
	 *
	 * @var array|null
	 */
	private $cache = null;

	/**
	 * Constants that override stored values, keyed by setting name.
	 *
	 * @return array
	 */
	private function constant_map() {
		return array(
			'bot_token'   => 'CF7_SLACK_BOT_TOKEN',
			'channel'     => 'CF7_SLACK_CHANNEL',
			'webhook_url' => 'CF7_SLACK_WEBHOOK_URL',
		);
	}

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public function defaults() {
		return array(
			'transport'         => 'bot',
			'bot_token'         => '',
			'channel'           => '',
			'webhook_url'       => '',
			'events'            => array(
				'mail_failed'       => 1,
				'spam'              => 0,
				'validation_failed' => 0,
				'aborted'           => 1,
				'wp_mail_failed'    => 1,
			),
			'include_submitter' => 1,
			'throttle_seconds'  => 60,
			'blocking'          => 0,
			'auto_update_check' => 1,
			'auto_update'       => 0,
		);
	}

	/**
	 * All settings, with stored values and constant overrides applied.
	 *
	 * @return array
	 */
	public function all() {
		if ( null !== $this->cache ) {
			return $this->cache;
		}

		$stored = get_option( OPTION_KEY, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( $this->defaults(), $stored );

		$merged['events'] = array_merge( $this->defaults()['events'], isset( $stored['events'] ) && is_array( $stored['events'] ) ? $stored['events'] : array() );

		foreach ( $this->constant_map() as $key => $constant ) {
			if ( defined( $constant ) && '' !== constant( $constant ) ) {
				$merged[ $key ] = constant( $constant );
			}
		}

		$this->cache = $merged;

		return $this->cache;
	}

	/**
	 * Fetch a single setting.
	 *
	 * @param string $key     Setting name.
	 * @param mixed  $default Fallback when unset.
	 * @return mixed
	 */
	public function get( $key, $default = null ) {
		$all = $this->all();

		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
	}

	/**
	 * Whether a setting is pinned by a constant and therefore not editable.
	 *
	 * @param string $key Setting name.
	 * @return bool
	 */
	public function is_locked( $key ) {
		$map = $this->constant_map();

		return isset( $map[ $key ] ) && defined( $map[ $key ] ) && '' !== constant( $map[ $key ] );
	}

	/**
	 * Whether alerts for a given event are enabled.
	 *
	 * @param string $event Event key.
	 * @return bool
	 */
	public function event_enabled( $event ) {
		$events = $this->get( 'events', array() );

		return ! empty( $events[ $event ] );
	}

	/**
	 * True when enough credentials are present to attempt a delivery.
	 *
	 * @return bool
	 */
	public function is_configured() {
		if ( 'webhook' === $this->get( 'transport' ) ) {
			return false !== strpos( (string) $this->get( 'webhook_url' ), 'hooks.slack.com' );
		}

		return '' !== trim( (string) $this->get( 'bot_token' ) ) && '' !== trim( (string) $this->get( 'channel' ) );
	}

	/**
	 * Register hooks for the admin screen.
	 *
	 * @return void
	 */
	public function boot() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_post_' . self::TEST_ACTION, array( $this, 'handle_test' ) );
		add_action( 'admin_notices', array( $this, 'render_notice' ) );
		add_action( 'admin_notices', array( $this, 'render_setup_notice' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( PLUGIN_FILE ), array( $this, 'action_links' ) );
	}

	/**
	 * Add a Settings link on the plugins list row.
	 *
	 * @param array $links Existing action links.
	 * @return array
	 */
	public function action_links( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE_SLUG );

		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'cf7-slack-error-alerts' ) . '</a>' );

		return $links;
	}

	/**
	 * Register the options page.
	 *
	 * @return void
	 */
	public function add_menu() {
		add_options_page(
			__( 'CF7 Slack Error Alerts', 'cf7-slack-error-alerts' ),
			__( 'CF7 Slack Alerts', 'cf7-slack-error-alerts' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the setting with the Settings API.
	 *
	 * @return void
	 */
	public function register() {
		register_setting(
			self::GROUP,
			OPTION_KEY,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize' ),
				'default'           => $this->defaults(),
			)
		);
	}

	/**
	 * Validate and normalize submitted settings.
	 *
	 * Secret fields render blank, so an empty submission preserves the stored
	 * value; clearing one requires ticking its explicit "clear" box.
	 *
	 * @param mixed $input Raw submitted values.
	 * @return array
	 */
	public function sanitize( $input ) {
		$input    = is_array( $input ) ? $input : array();
		$stored   = get_option( OPTION_KEY, array() );
		$stored   = is_array( $stored ) ? array_merge( $this->defaults(), $stored ) : $this->defaults();
		$defaults = $this->defaults();
		$out      = array();

		$out['transport'] = in_array( isset( $input['transport'] ) ? $input['transport'] : '', array( 'bot', 'webhook' ), true )
			? $input['transport']
			: $defaults['transport'];

		$out['bot_token'] = $this->sanitize_secret(
			isset( $input['bot_token'] ) ? $input['bot_token'] : '',
			$stored['bot_token'],
			! empty( $input['bot_token_clear'] )
		);

		if ( '' !== $out['bot_token'] && 0 !== strpos( $out['bot_token'], 'xoxb-' ) && 0 !== strpos( $out['bot_token'], 'xoxp-' ) ) {
			add_settings_error(
				OPTION_KEY,
				'bot_token',
				__( 'That does not look like a Slack bot token. Bot tokens start with "xoxb-".', 'cf7-slack-error-alerts' ),
				'warning'
			);
		}

		$webhook = $this->sanitize_secret(
			isset( $input['webhook_url'] ) ? $input['webhook_url'] : '',
			$stored['webhook_url'],
			! empty( $input['webhook_url_clear'] )
		);

		if ( '' !== $webhook && false === strpos( $webhook, 'hooks.slack.com' ) ) {
			add_settings_error(
				OPTION_KEY,
				'webhook_url',
				__( 'The webhook URL must be a hooks.slack.com address. The previous value was kept.', 'cf7-slack-error-alerts' ),
				'error'
			);
			$webhook = $stored['webhook_url'];
		}

		$out['webhook_url'] = $webhook ? esc_url_raw( $webhook, array( 'https' ) ) : '';

		$out['channel'] = $this->sanitize_channel( isset( $input['channel'] ) ? $input['channel'] : '' );

		$out['events'] = array();
		foreach ( array_keys( $defaults['events'] ) as $event ) {
			$out['events'][ $event ] = empty( $input['events'][ $event ] ) ? 0 : 1;
		}

		$out['include_submitter'] = empty( $input['include_submitter'] ) ? 0 : 1;
		$out['blocking']          = empty( $input['blocking'] ) ? 0 : 1;
		$out['auto_update_check'] = empty( $input['auto_update_check'] ) ? 0 : 1;
		$out['auto_update']       = empty( $input['auto_update'] ) ? 0 : 1;

		$throttle                = isset( $input['throttle_seconds'] ) ? absint( $input['throttle_seconds'] ) : $defaults['throttle_seconds'];
		$out['throttle_seconds'] = min( 3600, $throttle );

		return $out;
	}

	/**
	 * Keep, replace or clear a write-only secret field.
	 *
	 * @param string $submitted Newly submitted value (blank means "unchanged").
	 * @param string $existing  Currently stored value.
	 * @param bool   $clear     Whether the clear box was ticked.
	 * @return string
	 */
	private function sanitize_secret( $submitted, $existing, $clear ) {
		if ( $clear ) {
			return '';
		}

		$submitted = trim( (string) $submitted );

		return '' === $submitted ? (string) $existing : sanitize_text_field( $submitted );
	}

	/**
	 * Normalize a channel reference.
	 *
	 * Accepts a channel ID (C0123ABCD), a #name, or a bare name.
	 *
	 * @param string $value Raw channel input.
	 * @return string
	 */
	private function sanitize_channel( $value ) {
		$value = trim( sanitize_text_field( (string) $value ) );

		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/^[CGD][A-Z0-9]{6,}$/', $value ) ) {
			return $value;
		}

		return '#' . ltrim( $value, '#' );
	}

	/**
	 * Handle the "send test message" button.
	 *
	 * @return void
	 */
	public function handle_test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'cf7-slack-error-alerts' ), 403 );
		}

		check_admin_referer( self::TEST_ACTION );

		$this->cache = null;
		$result      = Plugin::instance()->notifier()->send_test();

		set_transient(
			self::NOTICE_KEY . '_' . get_current_user_id(),
			array(
				'type'    => $result['ok'] ? 'success' : 'error',
				'message' => $result['ok']
					? __( 'Test message delivered to Slack.', 'cf7-slack-error-alerts' )
					: sprintf( /* translators: %s: error string returned by Slack. */ __( 'Slack rejected the test message: %s', 'cf7-slack-error-alerts' ), $result['error'] ),
			),
			60
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Print the queued admin notice, if any.
	 *
	 * @return void
	 */
	public function render_notice() {
		$key    = self::NOTICE_KEY . '_' . get_current_user_id();
		$notice = get_transient( $key );

		if ( ! $notice || empty( $notice['message'] ) ) {
			return;
		}

		delete_transient( $key );

		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'success' === $notice['type'] ? 'success' : 'error' ),
			esc_html( $notice['message'] )
		);
	}

	/**
	 * Warn when the plugin is installed but has nowhere to send alerts.
	 *
	 * This matters most on upgrade from 1.0.x, which shipped a webhook URL
	 * baked into the source. That credential is gone, so those sites are silent
	 * until someone enters one — exactly the failure the plugin exists to catch.
	 *
	 * @return void
	 */
	public function render_setup_notice() {
		if ( ! current_user_can( 'manage_options' ) || $this->is_configured() ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && ! in_array( $screen->id, array( 'dashboard', 'plugins' ), true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'CF7 Slack Error Alerts is not sending anything yet.', 'cf7-slack-error-alerts' ),
			esc_html__( 'Add a Slack bot token and channel, or an incoming webhook URL, to start receiving alerts.', 'cf7-slack-error-alerts' ),
			esc_url( admin_url( 'options-general.php?page=' . self::PAGE_SLUG ) ),
			esc_html__( 'Open settings', 'cf7-slack-error-alerts' )
		);
	}

	/**
	 * Render the options screen.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s          = $this->all();
		$is_webhook = 'webhook' === $s['transport'];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CF7 Slack Error Alerts', 'cf7-slack-error-alerts' ); ?></h1>

			<?php settings_errors( OPTION_KEY ); ?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2 class="title"><?php esc_html_e( 'Slack connection', 'cf7-slack-error-alerts' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Method', 'cf7-slack-error-alerts' ); ?></th>
						<td>
							<fieldset>
								<label>
									<input type="radio" name="<?php echo esc_attr( OPTION_KEY ); ?>[transport]" value="bot" <?php checked( ! $is_webhook ); ?>>
									<?php esc_html_e( 'Bot token (recommended — lets you choose the channel)', 'cf7-slack-error-alerts' ); ?>
								</label><br>
								<label>
									<input type="radio" name="<?php echo esc_attr( OPTION_KEY ); ?>[transport]" value="webhook" <?php checked( $is_webhook ); ?>>
									<?php esc_html_e( 'Incoming webhook (posts only to the channel chosen when the webhook was created)', 'cf7-slack-error-alerts' ); ?>
								</label>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cf7sa-bot-token"><?php esc_html_e( 'Bot user OAuth token', 'cf7-slack-error-alerts' ); ?></label>
						</th>
						<td>
							<?php $this->render_secret_field( 'bot_token', 'cf7sa-bot-token', $s['bot_token'] ); ?>
							<p class="description">
								<?php esc_html_e( 'Create a Slack app, add the chat:write scope, install it to your workspace, then paste the xoxb- token here. Invite the bot to the target channel.', 'cf7-slack-error-alerts' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cf7sa-channel"><?php esc_html_e( 'Channel', 'cf7-slack-error-alerts' ); ?></label>
						</th>
						<td>
							<input
								type="text"
								id="cf7sa-channel"
								class="regular-text"
								name="<?php echo esc_attr( OPTION_KEY ); ?>[channel]"
								value="<?php echo esc_attr( $s['channel'] ); ?>"
								placeholder="#site-alerts"
								<?php disabled( $this->is_locked( 'channel' ) ); ?>
							>
							<p class="description">
								<?php esc_html_e( 'Channel ID (C01ABCDEF) or #name. A channel ID is more reliable because it survives renames.', 'cf7-slack-error-alerts' ); ?>
								<?php if ( $is_webhook ) : ?>
									<br><strong><?php esc_html_e( 'Ignored while using a webhook: modern Slack webhooks always post to their own fixed channel.', 'cf7-slack-error-alerts' ); ?></strong>
								<?php endif; ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cf7sa-webhook"><?php esc_html_e( 'Incoming webhook URL', 'cf7-slack-error-alerts' ); ?></label>
						</th>
						<td>
							<?php $this->render_secret_field( 'webhook_url', 'cf7sa-webhook', $s['webhook_url'] ); ?>
							<p class="description"><?php esc_html_e( 'Only used when the webhook method is selected.', 'cf7-slack-error-alerts' ); ?></p>
						</td>
					</tr>
				</table>

				<h2 class="title"><?php esc_html_e( 'Alerts', 'cf7-slack-error-alerts' ); ?></h2>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Notify me when', 'cf7-slack-error-alerts' ); ?></th>
						<td>
							<fieldset>
								<?php
								foreach ( $this->event_labels() as $event => $label ) {
									printf(
										'<label><input type="checkbox" name="%1$s[events][%2$s]" value="1" %3$s> %4$s</label><br>',
										esc_attr( OPTION_KEY ),
										esc_attr( $event ),
										checked( ! empty( $s['events'][ $event ] ), true, false ),
										esc_html( $label )
									);
								}
								?>
							</fieldset>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Submitter email', 'cf7-slack-error-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION_KEY ); ?>[include_submitter]" value="1" <?php checked( ! empty( $s['include_submitter'] ) ); ?>>
								<?php esc_html_e( 'Include the submitter\'s email address in alerts', 'cf7-slack-error-alerts' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Turn this off if your Slack workspace should not receive personal data from form submissions.', 'cf7-slack-error-alerts' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="cf7sa-throttle"><?php esc_html_e( 'Throttle', 'cf7-slack-error-alerts' ); ?></label>
						</th>
						<td>
							<input type="number" id="cf7sa-throttle" name="<?php echo esc_attr( OPTION_KEY ); ?>[throttle_seconds]" value="<?php echo esc_attr( $s['throttle_seconds'] ); ?>" min="0" max="3600" step="10" class="small-text">
							<?php esc_html_e( 'seconds', 'cf7-slack-error-alerts' ); ?>
							<p class="description"><?php esc_html_e( 'Suppresses repeats of an identical alert within this window, so a broken SMTP account cannot flood the channel. Set to 0 to disable.', 'cf7-slack-error-alerts' ); ?></p>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Delivery', 'cf7-slack-error-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION_KEY ); ?>[blocking]" value="1" <?php checked( ! empty( $s['blocking'] ) ); ?>>
								<?php esc_html_e( 'Wait for Slack to confirm each alert', 'cf7-slack-error-alerts' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Off by default so form submissions are never slowed down. Turning it on records the last delivery error below, at the cost of up to 5 seconds per alert.', 'cf7-slack-error-alerts' ); ?></p>
							<?php $this->render_last_error(); ?>
						</td>
					</tr>

					<tr>
						<th scope="row"><?php esc_html_e( 'Updates', 'cf7-slack-error-alerts' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION_KEY ); ?>[auto_update_check]" value="1" <?php checked( ! empty( $s['auto_update_check'] ) ); ?>>
								<?php esc_html_e( 'Check GitHub for new releases of this plugin', 'cf7-slack-error-alerts' ); ?>
							</label><br>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( OPTION_KEY ); ?>[auto_update]" value="1" <?php checked( ! empty( $s['auto_update'] ) ); ?>>
								<?php esc_html_e( 'Install those updates automatically, without asking', 'cf7-slack-error-alerts' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Leave this off to review each update yourself on the Plugins screen. WordPress runs unattended updates on its twice-daily cron, so an automatic update lands within about 12 hours of release.', 'cf7-slack-error-alerts' ); ?>
							</p>
							<p class="description">
								<?php
								printf(
									/* translators: %s: repository link. */
									esc_html__( 'Updates are served from %s and appear on the normal Plugins screen.', 'cf7-slack-error-alerts' ),
									'<a href="' . esc_url( 'https://github.com/' . GITHUB_REPO ) . '" target="_blank" rel="noreferrer noopener">' . esc_html( GITHUB_REPO ) . '</a>'
								);
								?>
							</p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Test', 'cf7-slack-error-alerts' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::TEST_ACTION ); ?>">
				<?php wp_nonce_field( self::TEST_ACTION ); ?>
				<p><?php esc_html_e( 'Sends a green test alert and reports exactly what Slack said back.', 'cf7-slack-error-alerts' ); ?></p>
				<?php submit_button( __( 'Send test message', 'cf7-slack-error-alerts' ), 'secondary', 'submit', false, $this->is_configured() ? array() : array( 'disabled' => 'disabled' ) ); ?>
				<?php if ( ! $this->is_configured() ) : ?>
					<p class="description"><?php esc_html_e( 'Save a token and channel (or a webhook URL) first.', 'cf7-slack-error-alerts' ); ?></p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Render a write-only secret input plus its "clear" toggle.
	 *
	 * @param string $key   Setting name.
	 * @param string $id    Input element id.
	 * @param string $value Currently stored value.
	 * @return void
	 */
	private function render_secret_field( $key, $id, $value ) {
		$locked = $this->is_locked( $key );
		$has    = '' !== trim( (string) $value );

		if ( $locked ) {
			$map = $this->constant_map();
			printf(
				'<input type="text" id="%1$s" class="regular-text" value="%2$s" disabled> <p class="description">%3$s</p>',
				esc_attr( $id ),
				esc_attr__( 'Set in wp-config.php', 'cf7-slack-error-alerts' ),
				sprintf(
					/* translators: %s: PHP constant name. */
					esc_html__( 'Locked by the %s constant.', 'cf7-slack-error-alerts' ),
					'<code>' . esc_html( $map[ $key ] ) . '</code>'
				)
			);

			return;
		}

		printf(
			'<input type="password" id="%1$s" class="regular-text" name="%2$s[%3$s]" value="" autocomplete="new-password" placeholder="%4$s">',
			esc_attr( $id ),
			esc_attr( OPTION_KEY ),
			esc_attr( $key ),
			esc_attr(
				$has
					? __( 'Saved — leave blank to keep', 'cf7-slack-error-alerts' )
					: __( 'Not set', 'cf7-slack-error-alerts' )
			)
		);

		if ( $has ) {
			printf(
				' <label><input type="checkbox" name="%1$s[%2$s_clear]" value="1"> %3$s</label>',
				esc_attr( OPTION_KEY ),
				esc_attr( $key ),
				esc_html__( 'Clear', 'cf7-slack-error-alerts' )
			);
		}
	}

	/**
	 * Show the most recent delivery failure, if one was recorded.
	 *
	 * @return void
	 */
	private function render_last_error() {
		$last = get_option( OPTION_KEY . '_last_error', array() );

		if ( empty( $last['error'] ) ) {
			return;
		}

		printf(
			'<p class="description" style="color:#b32d2e;"><strong>%1$s</strong> %2$s <em>(%3$s)</em></p>',
			esc_html__( 'Last delivery error:', 'cf7-slack-error-alerts' ),
			esc_html( $last['error'] ),
			esc_html( human_time_diff( (int) $last['time'] ) . ' ago' )
		);
	}

	/**
	 * Human labels for each alertable event.
	 *
	 * @return array
	 */
	private function event_labels() {
		return array(
			'mail_failed'       => __( 'A Contact Form 7 form fails to send its email (red)', 'cf7-slack-error-alerts' ),
			'aborted'           => __( 'A submission is aborted by another plugin (red)', 'cf7-slack-error-alerts' ),
			'spam'              => __( 'A submission is flagged as spam by captcha or Akismet (orange)', 'cf7-slack-error-alerts' ),
			'validation_failed' => __( 'A submission fails field validation (orange — noisy, this fires on ordinary user typos)', 'cf7-slack-error-alerts' ),
			'wp_mail_failed'    => __( 'Any other wp_mail() call on the site fails (red)', 'cf7-slack-error-alerts' ),
		);
	}
}
