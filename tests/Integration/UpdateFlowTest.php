<?php
/**
 * The update path, driven through real WordPress APIs.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Integration;

use CF7_Slack_Alerts\Plugin;
use WP_UnitTestCase;

/**
 * Exercises the update transient and details modal with real options,
 * transients and HTTP interception.
 */
class UpdateFlowTest extends WP_UnitTestCase {

	/**
	 * Release payload served to the plugin.
	 *
	 * @var array
	 */
	private $release = array();

	/**
	 * How many outbound HTTP requests were attempted.
	 *
	 * @var int
	 */
	private $calls = 0;

	/**
	 * Set up HTTP interception.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		delete_transient( 'cf7sa_github_release' );
		delete_transient( 'cf7sa_github_release_backoff' );

		$this->calls   = 0;
		$this->release = array(
			'tag_name'     => 'v99.0.0',
			'body'         => "## Notes\n- Something",
			'published_at' => '2026-01-01T00:00:00Z',
			'zipball_url'  => 'https://api.github.com/zipball',
			'assets'       => array(
				array(
					'name'                 => 'cf7-slack-error-alerts.zip',
					'browser_download_url' => 'https://example.test/plugin.zip',
				),
			),
		);

		add_filter( 'pre_http_request', array( $this, 'intercept' ), 10, 3 );
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'intercept' ), 10 );
		delete_transient( 'cf7sa_github_release' );
		delete_transient( 'cf7sa_github_release_backoff' );
		parent::tear_down();
	}

	/**
	 * Serve the canned release instead of hitting the network.
	 *
	 * @param mixed  $pre  Short-circuit value.
	 * @param array  $args Request arguments.
	 * @param string $url  Request URL.
	 * @return array
	 */
	public function intercept( $pre, $args, $url ) {
		++$this->calls;

		return array(
			'headers'  => array(),
			'body'     => wp_json_encode( $this->release ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	/**
	 * The plugin's basename as WordPress sees it here.
	 *
	 * @return string
	 */
	private function basename() {
		return plugin_basename( \CF7_Slack_Alerts\PLUGIN_FILE );
	}

	/**
	 * This is the path core actually takes: setting the transient runs the
	 * pre_set_site_transient filter the plugin hooks.
	 */
	public function test_update_appears_in_the_real_transient() {
		set_site_transient(
			'update_plugins',
			(object) array(
				'response'  => array(),
				'no_update' => array(),
			)
		);

		$transient = get_site_transient( 'update_plugins' );

		$this->assertArrayHasKey( $this->basename(), $transient->response );
		$this->assertSame( '99.0.0', $transient->response[ $this->basename() ]->new_version );
		$this->assertSame( 'https://example.test/plugin.zip', $transient->response[ $this->basename() ]->package );
	}

	public function test_details_modal_uses_plugins_api() {
		// plugins_api() lives in an admin include that the test bootstrap does
		// not load, but the details modal only ever runs in the admin.
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';

		$info = plugins_api(
			'plugin_information',
			array( 'slug' => dirname( $this->basename() ) )
		);

		$this->assertIsObject( $info );
		$this->assertSame( '99.0.0', $info->version );
		$this->assertArrayHasKey( 'changelog', (array) $info->sections );
	}

	public function test_release_lookup_is_cached() {
		Plugin::instance()->updater()->inject_update(
			(object) array(
				'response'  => array(),
				'no_update' => array(),
			)
		);
		$after_first = $this->calls;

		Plugin::instance()->updater()->inject_update(
			(object) array(
				'response'  => array(),
				'no_update' => array(),
			)
		);

		$this->assertSame( 1, $after_first );
		$this->assertSame( 1, $this->calls, 'second check must be served from the transient' );
	}

	public function test_force_check_clears_the_real_transient() {
		set_transient( 'cf7sa_github_release', array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );

		$_GET['force-check'] = '1';
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'administrator' ) ) );

		Plugin::instance()->updater()->flush_on_force_check();

		$this->assertFalse( get_transient( 'cf7sa_github_release' ) );
		unset( $_GET['force-check'] );
	}

	/**
	 * A subscriber must not be able to bust the cache.
	 */
	public function test_force_check_is_capability_gated() {
		set_transient( 'cf7sa_github_release', array( 'version' => '1.0.0' ), HOUR_IN_SECONDS );

		$_GET['force-check'] = '1';
		wp_set_current_user( $this->factory->user->create( array( 'role' => 'subscriber' ) ) );

		Plugin::instance()->updater()->flush_on_force_check();

		$this->assertNotFalse( get_transient( 'cf7sa_github_release' ) );
		unset( $_GET['force-check'] );
	}

	/**
	 * Core decides a plugin supports updates by finding it in either bucket,
	 * which is what surfaces the auto-update toggle on the Plugins screen.
	 */
	public function test_current_version_is_advertised_as_supported() {
		$this->release['tag_name'] = 'v' . \CF7_Slack_Alerts\VERSION;
		delete_transient( 'cf7sa_github_release' );

		$result = Plugin::instance()->updater()->inject_update(
			(object) array(
				'response'  => array(),
				'no_update' => array(),
			)
		);

		$this->assertArrayNotHasKey( $this->basename(), $result->response );
		$this->assertArrayHasKey( $this->basename(), $result->no_update );
	}
}
