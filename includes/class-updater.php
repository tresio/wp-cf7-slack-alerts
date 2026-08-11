<?php
/**
 * Self-update against GitHub Releases.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts;

use stdClass;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Teaches WordPress to find, describe and install updates for this plugin from
 * the GitHub Releases API, with no third-party update library involved.
 */
class Updater {

	const TRANSIENT   = 'cf7sa_github_release';
	const CACHE_HOURS = 12;

	/**
	 * Settings instance.
	 *
	 * @var Settings
	 */
	private $settings;

	/**
	 * Plugin basename, e.g. cf7-slack-error-alerts/cf7-slack-error-alerts.php.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Plugin directory name, used as the update slug.
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * Constructor.
	 *
	 * @param Settings $settings Settings instance.
	 */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
		$this->basename = plugin_basename( PLUGIN_FILE );
		$this->slug     = dirname( $this->basename );
	}

	/**
	 * Attach hooks.
	 *
	 * @return void
	 */
	public function boot() {
		// Priority 1 so this beats core's own wp_update_plugins() callback on
		// the same hook, which core registered first and which would otherwise
		// read our cache before we had a chance to drop it.
		add_action( 'load-update-core.php', array( $this, 'flush_on_force_check' ), 1 );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_directory' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 2 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
	}

	/**
	 * Advertise an available update on the plugins screen.
	 *
	 * @param mixed $transient The update_plugins site transient.
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		if ( ! $this->settings->get( 'auto_update_check' ) ) {
			return $transient;
		}

		$release = $this->latest_release();

		if ( is_wp_error( $release ) ) {
			return $transient;
		}

		if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
			$transient->response = array();
		}
		if ( ! isset( $transient->no_update ) || ! is_array( $transient->no_update ) ) {
			$transient->no_update = array();
		}

		$item = $this->build_update_object( $release );

		if ( version_compare( $release['version'], VERSION, '>' ) && '' !== $release['package'] ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * Populate the "View details" modal.
	 *
	 * @param mixed  $result Existing result.
	 * @param string $action Requested plugins_api action.
	 * @param object $args   Request arguments.
	 * @return mixed
	 */
	public function plugin_details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$release = $this->latest_release();

		if ( is_wp_error( $release ) ) {
			return $result;
		}

		$data                 = new stdClass();
		$data->name           = 'CF7 Slack Error Alerts';
		$data->slug           = $this->slug;
		$data->version        = $release['version'];
		$data->author         = '<a href="https://studio3enterprise.com/">Studio 3 Enterprise</a>';
		$data->homepage       = 'https://github.com/' . GITHUB_REPO;
		$data->download_link  = $release['package'];
		$data->trunk          = $release['package'];
		$data->requires       = '5.3';
		$data->requires_php   = '7.2';
		$data->last_updated   = $release['published_at'];
		$data->sections       = array(
			'description' => wpautop( esc_html__( 'Sends a Slack notification when a Contact Form 7 form errors, and catches wp_mail() failures as a safety net.', 'cf7-slack-error-alerts' ) ),
			'changelog'   => $this->render_notes( $release['notes'] ),
		);

		return $data;
	}

	/**
	 * Rename the extracted archive directory to match the installed plugin.
	 *
	 * GitHub's source archives unpack to owner-repo-sha/, and even a release
	 * asset may not match the folder this site installed the plugin into.
	 * Without this, WordPress installs a second copy under the wrong name.
	 *
	 * @param string|WP_Error $source        Path to the unpacked source.
	 * @param string          $remote_source Path to the download's temp dir.
	 * @param object          $upgrader      Upgrader instance.
	 * @param array           $hook_extra    Extra arguments, including the plugin basename.
	 * @return string|WP_Error
	 */
	public function fix_source_directory( $source, $remote_source, $upgrader = null, $hook_extra = array() ) {
		if ( is_wp_error( $source ) ) {
			return $source;
		}

		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;

		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->exists( $desired ) ) {
			$wp_filesystem->delete( $desired, true );
		}

		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return new WP_Error(
				'cf7sa_rename_failed',
				__( 'Could not rename the downloaded plugin folder.', 'cf7-slack-error-alerts' )
			);
		}

		return trailingslashit( $desired );
	}

	/**
	 * Honour the "Check Again" button on Dashboard -> Updates.
	 *
	 * That button clears core's update transient, but our own release cache
	 * would still be served for up to 12 hours — so a release published
	 * moments ago would not show up, which is precisely when someone clicks it.
	 *
	 * @return void
	 */
	public function flush_on_force_check() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flag set by core's own link.
		if ( empty( $_GET['force-check'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		delete_transient( self::TRANSIENT );
		delete_transient( self::TRANSIENT . '_backoff' );
	}

	/**
	 * Drop the cached release after any plugin update runs.
	 *
	 * @param object $upgrader Upgrader instance.
	 * @param array  $extra    Update context.
	 * @return void
	 */
	public function flush_cache( $upgrader, $extra ) {
		if ( isset( $extra['type'] ) && 'plugin' === $extra['type'] ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/**
	 * Add a "Check for updates" link to the plugin row.
	 *
	 * @param array  $meta     Existing row meta.
	 * @param string $basename Plugin basename for the row.
	 * @return array
	 */
	public function row_meta( $meta, $basename ) {
		if ( $basename !== $this->basename || ! current_user_can( 'update_plugins' ) ) {
			return $meta;
		}

		$meta[] = '<a href="' . esc_url( 'https://github.com/' . GITHUB_REPO . '/releases' ) . '" target="_blank" rel="noreferrer noopener">' . esc_html__( 'Releases', 'cf7-slack-error-alerts' ) . '</a>';

		return $meta;
	}

	/**
	 * Shape a release into the object WordPress expects in update_plugins.
	 *
	 * @param array $release Normalized release data.
	 * @return stdClass
	 */
	private function build_update_object( array $release ) {
		$item                 = new stdClass();
		$item->id             = 'github.com/' . GITHUB_REPO;
		$item->slug           = $this->slug;
		$item->plugin         = $this->basename;
		$item->new_version    = $release['version'];
		$item->url            = 'https://github.com/' . GITHUB_REPO;
		$item->package        = $release['package'];
		$item->icons          = array();
		$item->banners        = array();
		$item->banners_rtl    = array();
		$item->requires       = '5.3';
		$item->requires_php   = '7.2';
		$item->compatibility  = new stdClass();

		return $item;
	}

	/**
	 * Fetch and cache the latest published release.
	 *
	 * @return array|WP_Error Normalized release data, or an error.
	 */
	private function latest_release() {
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		if ( get_transient( self::TRANSIENT . '_backoff' ) ) {
			return new WP_Error(
				'cf7sa_release_lookup_backoff',
				__( 'Skipping the GitHub release check after a recent failure.', 'cf7-slack-error-alerts' )
			);
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . GITHUB_REPO . '/releases/latest',
			array(
				'timeout'    => 10,
				'headers'    => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
				),
				'user-agent' => 'cf7-slack-error-alerts/' . VERSION . '; ' . home_url( '/' ),
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->cache_failure();

			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code || ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			$this->cache_failure();

			return new WP_Error(
				'cf7sa_release_lookup_failed',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Could not read the latest release from GitHub (HTTP %d).', 'cf7-slack-error-alerts' ),
					$code
				)
			);
		}

		$release = array(
			'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
			'package'      => $this->package_url( $body ),
			'notes'        => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published_at' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
		);

		set_transient( self::TRANSIENT, $release, self::CACHE_HOURS * HOUR_IN_SECONDS );

		return $release;
	}

	/**
	 * Pick the downloadable archive for a release.
	 *
	 * A built .zip asset is preferred because it contains only shipped files
	 * under a correctly named folder; the source zipball is the fallback.
	 *
	 * @param array $body Decoded GitHub release payload.
	 * @return string
	 */
	private function package_url( array $body ) {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( empty( $asset['browser_download_url'] ) || empty( $asset['name'] ) ) {
					continue;
				}

				if ( '.zip' === strtolower( substr( $asset['name'], -4 ) ) ) {
					return (string) $asset['browser_download_url'];
				}
			}
		}

		return isset( $body['zipball_url'] ) ? (string) $body['zipball_url'] : '';
	}

	/**
	 * Back off for an hour after a failed lookup so every admin page load does
	 * not re-hit a rate-limited or unreachable API.
	 *
	 * @return void
	 */
	private function cache_failure() {
		set_transient( self::TRANSIENT . '_backoff', 1, HOUR_IN_SECONDS );
	}

	/**
	 * Convert a release body into safe HTML for the changelog tab.
	 *
	 * @param string $notes Raw release notes, usually Markdown.
	 * @return string
	 */
	private function render_notes( $notes ) {
		$notes = trim( (string) $notes );

		if ( '' === $notes ) {
			return wpautop( esc_html__( 'No release notes were published.', 'cf7-slack-error-alerts' ) );
		}

		$html = esc_html( $notes );
		$html = preg_replace( '/^### (.+)$/m', '<h4>$1</h4>', $html );
		$html = preg_replace( '/^## (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^# (.+)$/m', '<h3>$1</h3>', $html );
		$html = preg_replace( '/^[\*\-] (.+)$/m', '<li>$1</li>', $html );
		$html = preg_replace( '/(<li>.*<\/li>\n?)+/s', '<ul>$0</ul>', $html );
		$html = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $html );

		return wpautop( $html );
	}
}
