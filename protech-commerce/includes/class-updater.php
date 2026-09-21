<?php
/**
 * Updates from GitHub releases.
 *
 * The plugin header's `Update URI: https://github.com/DakotaGillette/
 * WholesalePro` makes WordPress core ask THIS code — via the
 * `update_plugins_github.com` filter — instead of wordpress.org whenever
 * it checks for plugin updates (twice a day, on the Plugins and Updates
 * screens, and on demand). The answer comes from the repository's latest
 * GitHub release: its tag is the version, and its `protech-commerce.zip`
 * asset (built by the release job in .github/workflows/ci.yml on every
 * push to main, with the plugin folder at the zip's root) is the package.
 * From there core does everything else: the "update available" row in the
 * Plugins list, "View details", one-click update, auto-updates.
 *
 * The repository is public, so no token is involved; the release lookup is
 * an anonymous GitHub API call, cached for twelve hours (a failed one for
 * one hour, so a GitHub outage never means a request per page load).
 *
 * @package ProtechWholesale
 */

declare( strict_types = 1 );

namespace ProtechWholesale;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Updater
 */
class Updater {

	public const REPO       = 'DakotaGillette/WholesalePro';
	public const ASSET_NAME = 'protech-commerce.zip';
	public const SLUG       = 'protech-commerce';

	public const TRANSIENT    = 'protech_wholesale_latest_release';
	public const CHECK_ACTION = 'protech_check_updates';

	private const TTL_OK     = 12 * HOUR_IN_SECONDS;
	private const TTL_FAILED = HOUR_IN_SECONDS;

	public function register_hooks(): void {
		add_filter( 'update_plugins_github.com', array( $this, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( $this, 'details' ), 10, 3 );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( $this, 'handle_check_now' ) );
		add_action( 'admin_notices', array( $this, 'checked_notice' ) );
	}

	/**
	 * The latest release, from cache or GitHub.
	 *
	 * @return array{version: string, package: string, url: string, notes: string, published: string, checked_at: int, error: string}
	 */
	public static function get_latest( bool $force = false ): array {
		$cached = $force ? false : get_transient( self::TRANSIENT );

		if ( is_array( $cached ) && isset( $cached['checked_at'] ) ) {
			return $cached;
		}

		$latest = self::fetch_latest();

		set_transient( self::TRANSIENT, $latest, '' === $latest['error'] ? self::TTL_OK : self::TTL_FAILED );

		return $latest;
	}

	/**
	 * @return array{version: string, package: string, url: string, notes: string, published: string, checked_at: int, error: string}
	 */
	private static function fetch_latest(): array {
		$empty = array(
			'version'    => '',
			'package'    => '',
			'url'        => 'https://github.com/' . self::REPO . '/releases',
			'notes'      => '',
			'published'  => '',
			'checked_at' => time(),
			'error'      => '',
		);

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'               => 'application/vnd.github+json',
					'X-GitHub-Api-Version' => '2022-11-28',
					'User-Agent'           => 'protech-commerce/' . PROTECH_WHOLESALE_VERSION . '; ' . home_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			$empty['error'] = $response->get_error_message();
			return $empty;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 404 === $code ) {
			// No release published yet: not an error, just nothing to offer.
			return $empty;
		}

		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			$empty['error'] = sprintf( __( 'GitHub answered with HTTP %d.', 'protech-wholesale' ), $code );
			return $empty;
		}

		$release = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $release ) || empty( $release['tag_name'] ) ) {
			$empty['error'] = __( 'GitHub returned an unexpected response.', 'protech-wholesale' );
			return $empty;
		}

		$package = '';

		foreach ( (array) ( $release['assets'] ?? array() ) as $asset ) {
			if ( self::ASSET_NAME === ( $asset['name'] ?? '' ) && ! empty( $asset['browser_download_url'] ) ) {
				$package = (string) $asset['browser_download_url'];
				break;
			}
		}

		if ( '' === $package ) {
			/* translators: %s: the expected zip file name. */
			$empty['error'] = sprintf( __( 'The latest release has no %s attached.', 'protech-wholesale' ), self::ASSET_NAME );
			return $empty;
		}

		return array(
			'version'    => ltrim( (string) $release['tag_name'], 'vV' ),
			'package'    => $package,
			'url'        => (string) ( $release['html_url'] ?? $empty['url'] ),
			'notes'      => (string) ( $release['body'] ?? '' ),
			'published'  => (string) ( $release['published_at'] ?? '' ),
			'checked_at' => time(),
			'error'      => '',
		);
	}

	/** True when the release found is newer than what is installed. */
	public static function is_update_available( array $latest ): bool {
		return '' !== $latest['version'] && version_compare( $latest['version'], PROTECH_WHOLESALE_VERSION, '>' );
	}

	/**
	 * What core's update check calls for every plugin whose Update URI is on
	 * github.com. Only ours is answered; anything else on that host is left
	 * to whoever owns it.
	 *
	 * @param array|false $update
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file
	 * @return array|false
	 */
	public function check( $update, $plugin_data, $plugin_file ) {
		if ( PROTECH_WHOLESALE_BASENAME !== $plugin_file ) {
			return $update;
		}

		$latest = self::get_latest();

		if ( '' === $latest['version'] ) {
			return $update;
		}

		// Core compares `version` to the installed one and files this under
		// response or no_update accordingly (wp-includes/update.php).
		return array(
			'slug'         => self::SLUG,
			'version'      => $latest['version'],
			'url'          => $latest['url'],
			'package'      => $latest['package'],
			'requires'     => (string) ( $plugin_data['RequiresWP'] ?? '6.0' ),
			'requires_php' => (string) ( $plugin_data['RequiresPHP'] ?? '8.1' ),
			'icons'        => array(),
			'banners'      => array(),
		);
	}

	/**
	 * The "View details" modal in the Plugins list, which otherwise tries
	 * wordpress.org and finds nothing.
	 *
	 * @param false|object|array $result
	 * @param string             $action
	 * @param object             $args
	 * @return false|object|array
	 */
	public function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}

		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = get_plugin_data( PROTECH_WHOLESALE_FILE, false, false );
		$latest = self::get_latest();

		$changelog = '' !== $latest['notes']
			? wpautop( wp_kses_post( self::markdown_lite( $latest['notes'] ) ) )
			: '<p>' . esc_html__( 'No release notes.', 'protech-wholesale' ) . '</p>';

		return (object) array(
			'name'          => $plugin['Name'],
			'slug'          => self::SLUG,
			'version'       => '' !== $latest['version'] ? $latest['version'] : $plugin['Version'],
			'author'        => $plugin['Author'],
			'homepage'      => 'https://github.com/' . self::REPO,
			'download_link' => $latest['package'],
			'requires'      => $plugin['RequiresWP'],
			'requires_php'  => $plugin['RequiresPHP'],
			'last_updated'  => $latest['published'],
			'sections'      => array(
				'description' => '<p>' . esc_html( $plugin['Description'] ) . '</p>',
				'changelog'   => $changelog,
			),
		);
	}

	/**
	 * Just enough Markdown for CHANGELOG-style release notes: headings and
	 * bullets. Anything else stays as text.
	 */
	private static function markdown_lite( string $markdown ): string {
		$html  = '';
		$lines = preg_split( '/\r\n|\r|\n/', $markdown ) ?: array();
		$open  = false;

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^#{1,6}\s+(.*)$/', $line, $m ) ) {
				$html .= ( $open ? '</ul>' : '' ) . '<h4>' . esc_html( $m[1] ) . '</h4>';
				$open  = false;
			} elseif ( preg_match( '/^[-*]\s+(.*)$/', $line, $m ) ) {
				$html .= ( $open ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$open  = true;
			} elseif ( '' !== $line ) {
				$html .= ( $open ? '</ul>' : '' ) . '<p>' . esc_html( $line ) . '</p>';
				$open  = false;
			}
		}

		return $html . ( $open ? '</ul>' : '' );
	}

	public static function check_now_url( string $return_to = 'plugins' ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'    => self::CHECK_ACTION,
					'return_to' => $return_to,
				),
				admin_url( 'admin-post.php' )
			),
			self::CHECK_ACTION
		);
	}

	/**
	 * "Changelog" and "Check for updates" under the plugin's row.
	 *
	 * @param string[] $links
	 * @param string   $file
	 * @return string[]
	 */
	public function row_meta( array $links, string $file ): array {
		if ( PROTECH_WHOLESALE_BASENAME !== $file ) {
			return $links;
		}

		$links[] = '<a href="' . esc_url( admin_url( 'plugin-install.php?tab=plugin-information&plugin=' . self::SLUG . '&section=changelog&TB_iframe=true&width=600&height=550' ) ) . '" class="thickbox open-plugin-details-modal">' . esc_html__( 'Changelog', 'protech-wholesale' ) . '</a>';
		$links[] = '<a href="' . esc_url( self::check_now_url( 'plugins' ) ) . '">' . esc_html__( 'Check for updates', 'protech-wholesale' ) . '</a>';

		return $links;
	}

	/** Drops the cached answer, asks GitHub again, and comes back with a notice. */
	public function handle_check_now(): void {
		check_admin_referer( self::CHECK_ACTION );

		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'protech-wholesale' ) );
		}

		delete_transient( self::TRANSIENT );
		self::get_latest( true );

		// Make core re-run its own check now rather than in up to 12 hours.
		if ( function_exists( 'wp_clean_plugins_cache' ) ) {
			wp_clean_plugins_cache( true );
		}

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- checked above.
		$return_to = sanitize_key( $_GET['return_to'] ?? 'plugins' );
		$target    = 'settings' === $return_to
			? Approval::tab_url( 'settings' )
			: admin_url( 'plugins.php' );

		wp_safe_redirect( add_query_arg( 'protech-checked', '1', $target ) );
		exit;
	}

	public function checked_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( empty( $_GET['protech-checked'] ) || ! current_user_can( 'update_plugins' ) ) {
			return;
		}

		$latest = self::get_latest();

		if ( '' !== $latest['error'] ) {
			$class   = 'notice-error';
			/* translators: %s: error text. */
			$message = sprintf( __( 'Could not check GitHub for a new Protech Commerce release: %s', 'protech-wholesale' ), $latest['error'] );
		} elseif ( self::is_update_available( $latest ) ) {
			$class   = 'notice-info';
			/* translators: 1: new version, 2: installed version. */
			$message = sprintf( __( 'Protech Commerce %1$s is available (you have %2$s). Update it from the Plugins list.', 'protech-wholesale' ), $latest['version'], PROTECH_WHOLESALE_VERSION );
		} elseif ( '' === $latest['version'] ) {
			$class   = 'notice-warning';
			$message = __( 'No Protech Commerce release has been published on GitHub yet.', 'protech-wholesale' );
		} else {
			$class   = 'notice-success';
			/* translators: %s: installed version. */
			$message = sprintf( __( 'Protech Wholesale %s is the latest release.', 'protech-wholesale' ), PROTECH_WHOLESALE_VERSION );
		}

		echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** The "Updates" block at the bottom of the Settings tab. */
	public static function render_settings_section(): void {
		$latest = self::get_latest();

		echo '<h2>' . esc_html__( 'Updates', 'protech-wholesale' ) . '</h2>';
		echo '<p>' . wp_kses_post(
			sprintf(
				/* translators: %s: link to the GitHub releases page. */
				__( 'New versions are published as releases on <a href="%s" target="_blank" rel="noopener">GitHub</a>. WordPress checks twice a day and shows them in the Plugins list like any other update.', 'protech-wholesale' ),
				esc_url( 'https://github.com/' . self::REPO . '/releases' )
			)
		) . '</p>';

		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th>' . esc_html__( 'Installed', 'protech-wholesale' ) . '</th><td>' . esc_html( PROTECH_WHOLESALE_VERSION ) . '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Latest release', 'protech-wholesale' ) . '</th><td>';
		if ( '' !== $latest['error'] ) {
			echo '<span style="color:#b32d2e;">' . esc_html( $latest['error'] ) . '</span>';
		} elseif ( '' === $latest['version'] ) {
			esc_html_e( 'None published yet.', 'protech-wholesale' );
		} else {
			echo '<a href="' . esc_url( $latest['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $latest['version'] ) . '</a>';

			if ( '' !== $latest['published'] ) {
				echo ' <span class="description">' . esc_html( sprintf( /* translators: %s: date. */ __( 'published %s', 'protech-wholesale' ), date_i18n( get_option( 'date_format' ), strtotime( $latest['published'] ) ?: 0 ) ) ) . '</span>';
			}

			if ( self::is_update_available( $latest ) ) {
				echo ' <strong>' . esc_html__( 'Update available.', 'protech-wholesale' ) . '</strong> <a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Go to Plugins', 'protech-wholesale' ) . '</a>';
			} else {
				echo ' <span class="description">' . esc_html__( 'You are up to date.', 'protech-wholesale' ) . '</span>';
			}
		}
		echo '</td></tr>';

		echo '<tr><th>' . esc_html__( 'Last checked', 'protech-wholesale' ) . '</th><td>' . esc_html( human_time_diff( (int) $latest['checked_at'] ) ) . ' ' . esc_html__( 'ago', 'protech-wholesale' ) . ' &middot; <a class="button" href="' . esc_url( self::check_now_url( 'settings' ) ) . '">' . esc_html__( 'Check now', 'protech-wholesale' ) . '</a></td></tr>';
		echo '</tbody></table>';
	}
}
