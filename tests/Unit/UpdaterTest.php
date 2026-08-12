<?php
/**
 * Self-update against GitHub Releases.
 *
 * @package CF7_Slack_Alerts
 */

namespace CF7_Slack_Alerts\Tests\Unit;

use CF7_Slack_Alerts\Tests\Doubles\Filesystem_Double;

use const CF7_Slack_Alerts\VERSION;

/**
 * @covers \CF7_Slack_Alerts\Updater
 */
class UpdaterTest extends TestCase {

	/**
	 * Plugin basename as the bootstrap's PLUGIN_FILE resolves it.
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * Temporary directory for filesystem tests.
	 *
	 * @var string
	 */
	private $tmp = '';

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function set_up() {
		parent::set_up();
		$this->basename = basename( dirname( \CF7_Slack_Alerts\PLUGIN_FILE ) ) . '/cf7-slack-error-alerts.php';
	}

	/**
	 * Tear down.
	 *
	 * @return void
	 */
	protected function tear_down() {
		if ( $this->tmp && is_dir( $this->tmp ) ) {
			( new Filesystem_Double() )->delete( $this->tmp, true );
		}
		parent::tear_down();
	}

	/**
	 * A GitHub release payload.
	 *
	 * @param string $tag    Tag name.
	 * @param array  $assets Release assets.
	 * @return array
	 */
	private function release( $tag, array $assets = array() ) {
		return array(
			'tag_name'     => $tag,
			'body'         => "## Notes\n- A `thing`",
			'published_at' => '2026-01-01T00:00:00Z',
			'zipball_url'  => 'https://api.github.com/zipball',
			'assets'       => $assets,
		);
	}

	/**
	 * A built plugin zip asset.
	 *
	 * @return array
	 */
	private function zip_asset() {
		return array(
			array(
				'name'                 => 'cf7-slack-error-alerts.zip',
				'browser_download_url' => 'https://github.com/x/releases/download/v9/plugin.zip',
			),
		);
	}

	/**
	 * An empty update transient.
	 *
	 * @return object
	 */
	private function transient() {
		return (object) array(
			'response'  => array(),
			'no_update' => array(),
		);
	}

	public function test_offers_a_newer_release() {
		$this->queue_response( $this->release( 'v9.9.9', $this->zip_asset() ) );

		$result = $this->updater( $this->with_settings() )->inject_update( $this->transient() );

		$this->assertArrayHasKey( $this->basename, $result->response );
		$this->assertSame( '9.9.9', $result->response[ $this->basename ]->new_version );
		$this->assertSame( 'https://github.com/x/releases/download/v9/plugin.zip', $result->response[ $this->basename ]->package );
	}

	/**
	 * Core reads either bucket to decide a plugin supports updates at all,
	 * which is what makes the auto-update toggle appear on the Plugins screen.
	 */
	public function test_current_version_lands_in_no_update() {
		$this->queue_response( $this->release( 'v' . VERSION, $this->zip_asset() ) );

		$result = $this->updater( $this->with_settings() )->inject_update( $this->transient() );

		$this->assertArrayNotHasKey( $this->basename, $result->response );
		$this->assertArrayHasKey( $this->basename, $result->no_update );
	}

	public function test_older_release_is_not_offered() {
		$this->queue_response( $this->release( 'v0.0.1', $this->zip_asset() ) );

		$result = $this->updater( $this->with_settings() )->inject_update( $this->transient() );

		$this->assertArrayNotHasKey( $this->basename, $result->response );
	}

	/**
	 * The source zipball unpacks with a commit hash in the folder name and
	 * carries dev files, so a built asset is always preferred.
	 */
	public function test_prefers_a_zip_asset_over_the_zipball() {
		$this->queue_response(
			$this->release(
				'v9.9.9',
				array(
					array(
						'name'                 => 'notes.txt',
						'browser_download_url' => 'https://x/notes.txt',
					),
					array(
						'name'                 => 'cf7-slack-error-alerts.zip',
						'browser_download_url' => 'https://x/plugin.zip',
					),
				)
			)
		);

		$result = $this->updater( $this->with_settings() )->inject_update( $this->transient() );

		$this->assertSame( 'https://x/plugin.zip', $result->response[ $this->basename ]->package );
	}

	public function test_falls_back_to_the_zipball() {
		$this->queue_response( $this->release( 'v9.9.9' ) );

		$result = $this->updater( $this->with_settings() )->inject_update( $this->transient() );

		$this->assertSame( 'https://api.github.com/zipball', $result->response[ $this->basename ]->package );
	}

	public function test_caches_the_release_lookup() {
		$this->queue_response( $this->release( 'v9.9.9', $this->zip_asset() ) );
		$updater = $this->updater( $this->with_settings() );

		$updater->inject_update( $this->transient() );
		$updater->inject_update( $this->transient() );

		$this->assertCount( 1, $this->requests );
	}

	public function test_disabled_check_makes_no_request() {
		$this->updater( $this->with_settings( array( 'auto_update_check' => 0 ) ) )->inject_update( $this->transient() );

		$this->assertSame( array(), $this->requests );
	}

	/**
	 * Without a backoff a rate-limited API would be re-hit on every admin page
	 * load, making the admin slow for everyone on that server.
	 */
	public function test_backs_off_after_a_failure() {
		$this->queue_response( '{"message":"rate limited"}', 403 );
		$updater = $this->updater( $this->with_settings() );

		$updater->inject_update( $this->transient() );
		$updater->inject_update( $this->transient() );

		$this->assertCount( 1, $this->requests );
	}

	public function test_failure_leaves_other_plugins_alone() {
		$this->queue_response( 'nope', 404 );
		$transient                            = $this->transient();
		$transient->response['other/other.php'] = 'untouched';

		$result = $this->updater( $this->with_settings() )->inject_update( $transient );

		$this->assertSame( 'untouched', $result->response['other/other.php'] );
		$this->assertArrayNotHasKey( $this->basename, $result->response );
	}

	public function test_force_check_clears_caches() {
		$this->transients['cf7sa_github_release']         = array( array( 'version' => '1.0.0' ), 0 );
		$this->transients['cf7sa_github_release_backoff'] = array( 1, 0 );
		$_GET['force-check']                              = '1';

		$this->updater( $this->with_settings() )->flush_on_force_check();

		$this->assertArrayNotHasKey( 'cf7sa_github_release', $this->transients );
		$this->assertArrayNotHasKey( 'cf7sa_github_release_backoff', $this->transients );
		unset( $_GET['force-check'] );
	}

	public function test_ordinary_page_load_keeps_the_cache() {
		$this->transients['cf7sa_github_release'] = array( array( 'version' => '1.0.0' ), 0 );
		unset( $_GET['force-check'] );

		$this->updater( $this->with_settings() )->flush_on_force_check();

		$this->assertArrayHasKey( 'cf7sa_github_release', $this->transients );
	}

	public function test_force_check_requires_the_capability() {
		$this->transients['cf7sa_github_release'] = array( array( 'version' => '1.0.0' ), 0 );
		$_GET['force-check']                      = '1';
		$this->can                                = false;

		$this->updater( $this->with_settings() )->flush_on_force_check();

		$this->assertArrayHasKey( 'cf7sa_github_release', $this->transients );
		unset( $_GET['force-check'] );
	}

	/**
	 * @dataProvider auto_update_provider
	 *
	 * @param int        $setting  Stored auto_update value.
	 * @param bool|null  $incoming What WordPress proposed.
	 * @param bool|null  $expected What the filter should return.
	 */
	public function test_auto_update_filter( $setting, $incoming, $expected ) {
		$updater = $this->updater( $this->with_settings( array( 'auto_update' => $setting ) ) );
		$item    = (object) array( 'plugin' => $this->basename );

		$this->assertSame( $expected, $updater->maybe_auto_update( $incoming, $item ) );
	}

	/**
	 * @return array
	 */
	public function auto_update_provider() {
		return array(
			'off keeps false'  => array( 0, false, false ),
			'off keeps true'   => array( 0, true, true ),
			'off keeps null'   => array( 0, null, null ),
			'on forces false'  => array( 1, false, true ),
			'on forces null'   => array( 1, null, true ),
			'on keeps true'    => array( 1, true, true ),
		);
	}

	/**
	 * @dataProvider foreign_item_provider
	 *
	 * @param object $item An update offer for something else.
	 */
	public function test_auto_update_never_touches_other_plugins( $item ) {
		$updater = $this->updater( $this->with_settings( array( 'auto_update' => 1 ) ) );

		$this->assertFalse( $updater->maybe_auto_update( false, $item ) );
	}

	/**
	 * @return array
	 */
	public function foreign_item_provider() {
		return array(
			'another plugin' => array( (object) array( 'plugin' => 'akismet/akismet.php' ) ),
			'no plugin key'  => array( (object) array() ),
		);
	}

	/**
	 * GitHub archives unpack to a folder named after the repo and tag, not the
	 * folder the site installed into. Without renaming, WordPress installs a
	 * second copy alongside the original and the site keeps running old code.
	 */
	public function test_renames_the_extracted_folder_to_match_the_install() {
		$GLOBALS['wp_filesystem'] = new Filesystem_Double();
		$this->tmp                = sys_get_temp_dir() . '/cf7sa-' . uniqid();
		$extracted                = $this->tmp . '/cf7-slack-error-alerts-1.2.3';

		mkdir( $extracted, 0777, true );
		file_put_contents( $extracted . '/cf7-slack-error-alerts.php', '<?php // plugin' );

		$result = $this->updater( $this->with_settings() )->fix_source_directory(
			$extracted . '/',
			$this->tmp . '/',
			null,
			array( 'plugin' => $this->basename )
		);

		$expected = $this->tmp . '/' . dirname( $this->basename );
		$this->assertSame( $expected . '/', $result );
		$this->assertDirectoryExists( $expected );
		$this->assertDirectoryDoesNotExist( $extracted );
		$this->assertFileExists( $expected . '/cf7-slack-error-alerts.php' );
	}

	public function test_leaves_other_plugins_source_alone() {
		$result = $this->updater( $this->with_settings() )->fix_source_directory(
			'/tmp/src/',
			'/tmp/',
			null,
			array( 'plugin' => 'other/other.php' )
		);

		$this->assertSame( '/tmp/src/', $result );
	}

	public function test_provides_plugin_information() {
		$this->queue_response( $this->release( 'v9.9.9', $this->zip_asset() ) );

		$info = $this->updater( $this->with_settings() )->plugin_details(
			false,
			'plugin_information',
			(object) array( 'slug' => dirname( $this->basename ) )
		);

		$this->assertIsObject( $info );
		$this->assertSame( '9.9.9', $info->version );
		$this->assertArrayHasKey( 'changelog', $info->sections );
	}

	public function test_ignores_other_plugins_information_requests() {
		$info = $this->updater( $this->with_settings() )->plugin_details(
			false,
			'plugin_information',
			(object) array( 'slug' => 'some-other-plugin' )
		);

		$this->assertFalse( $info );
		$this->assertSame( array(), $this->requests );
	}
}
