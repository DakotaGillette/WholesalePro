<?php
/**
 * Updates from GitHub releases (Updater): the release lookup is parsed
 * into what core's `update_plugins_github.com` filter expects, only a
 * newer version counts as an update, failures are cached briefly rather
 * than retried per request, and other plugins on github.com are left
 * alone. GitHub itself is never contacted: every HTTP call is answered by
 * a canned response through `pre_http_request`.
 *
 * @package ProtechWholesale
 */

use ProtechWholesale\Updater;

/**
 * Class Test_Updater
 */
class Test_Updater extends WP_UnitTestCase {

	/** @var array|WP_Error|null What the next GitHub request gets back. */
	private $canned = null;

	/** @var string[] URLs requested. */
	private array $requested = array();

	public function set_up(): void {
		parent::set_up();
		delete_transient( Updater::TRANSIENT );
		$this->requested = array();
		add_filter( 'pre_http_request', array( $this, 'answer_http' ), 10, 3 );
	}

	public function tear_down(): void {
		remove_filter( 'pre_http_request', array( $this, 'answer_http' ), 10 );
		delete_transient( Updater::TRANSIENT );
		parent::tear_down();
	}

	/**
	 * @param false|array|WP_Error $preempt
	 * @param array                $args
	 * @param string               $url
	 * @return false|array|WP_Error
	 */
	public function answer_http( $preempt, $args, $url ) {
		if ( false === strpos( $url, 'api.github.com' ) ) {
			return $preempt;
		}

		$this->requested[] = $url;

		return $this->canned;
	}

	private function release( string $tag, bool $with_asset = true ): array {
		$assets = $with_asset
			? array(
				array(
					'name'                 => Updater::ASSET_NAME,
					'browser_download_url' => 'https://github.com/' . Updater::REPO . '/releases/download/' . $tag . '/' . Updater::ASSET_NAME,
				),
			)
			: array();

		return array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => wp_json_encode(
				array(
					'tag_name'     => $tag,
					'html_url'     => 'https://github.com/' . Updater::REPO . '/releases/tag/' . $tag,
					'body'         => "## Fixed\n- Something",
					'published_at' => '2026-09-18T01:02:03Z',
					'assets'       => $assets,
				)
			),
		);
	}

	public function test_a_newer_release_becomes_an_update_for_this_plugin_only(): void {
		$this->canned = $this->release( 'v99.0.0' );

		$updater = new Updater();
		$headers = array( 'Version' => PROTECH_WHOLESALE_VERSION, 'RequiresWP' => '6.0', 'RequiresPHP' => '8.1' );

		$update = $updater->check( false, $headers, PROTECH_WHOLESALE_BASENAME );

		$this->assertIsArray( $update );
		$this->assertSame( '99.0.0', $update['version'], 'The leading "v" of the tag is dropped.' );
		$this->assertSame( 'protech-wholesale', $update['slug'] );
		$this->assertStringEndsWith( '/' . Updater::ASSET_NAME, $update['package'] );
		$this->assertTrue( Updater::is_update_available( Updater::get_latest() ) );

		// Another plugin that also updates from github.com is not ours to answer.
		$this->assertFalse( $updater->check( false, $headers, 'someone-else/plugin.php' ) );
	}

	public function test_the_installed_version_is_not_offered_as_an_update(): void {
		$this->canned = $this->release( 'v' . PROTECH_WHOLESALE_VERSION );

		$latest = Updater::get_latest();

		$this->assertSame( PROTECH_WHOLESALE_VERSION, $latest['version'] );
		$this->assertFalse( Updater::is_update_available( $latest ) );
	}

	public function test_the_lookup_is_cached(): void {
		$this->canned = $this->release( 'v99.0.0' );

		Updater::get_latest();
		Updater::get_latest();
		( new Updater() )->check( false, array( 'Version' => '1.0.0' ), PROTECH_WHOLESALE_BASENAME );

		$this->assertCount( 1, $this->requested, 'One request serves every check until the cache expires.' );

		Updater::get_latest( true );
		$this->assertCount( 2, $this->requested, 'A forced check asks again.' );
	}

	public function test_a_release_without_the_zip_is_not_offered(): void {
		$this->canned = $this->release( 'v99.0.0', false );

		$latest = Updater::get_latest();

		$this->assertSame( '', $latest['version'] );
		$this->assertStringContainsString( Updater::ASSET_NAME, $latest['error'] );
		$this->assertFalse( ( new Updater() )->check( false, array( 'Version' => '1.0.0' ), PROTECH_WHOLESALE_BASENAME ) );
	}

	public function test_no_release_yet_is_not_an_error(): void {
		$this->canned = array(
			'response' => array( 'code' => 404 ),
			'headers'  => array(),
			'body'     => '{"message":"Not Found"}',
		);

		$latest = Updater::get_latest();

		$this->assertSame( '', $latest['version'] );
		$this->assertSame( '', $latest['error'] );
	}

	public function test_a_failed_request_is_remembered_and_reported(): void {
		$this->canned = new WP_Error( 'http_request_failed', 'cURL error 28: timed out' );

		$latest = Updater::get_latest();
		Updater::get_latest();

		$this->assertSame( '', $latest['version'] );
		$this->assertStringContainsString( 'timed out', $latest['error'] );
		$this->assertCount( 1, $this->requested, 'A failure is cached too, so an outage does not mean a request per page load.' );
	}

	public function test_view_details_uses_the_release_notes(): void {
		$this->canned = $this->release( 'v99.0.0' );

		$info = ( new Updater() )->details( false, 'plugin_information', (object) array( 'slug' => 'protech-wholesale' ) );

		$this->assertIsObject( $info );
		$this->assertSame( '99.0.0', $info->version );
		$this->assertStringContainsString( '<h4>Fixed</h4>', $info->sections['changelog'] );
		$this->assertStringContainsString( '<li>Something</li>', $info->sections['changelog'] );

		$this->assertFalse( ( new Updater() )->details( false, 'plugin_information', (object) array( 'slug' => 'other' ) ) );
	}
}
