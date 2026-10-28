<?php
/**
 * Delivers plugin updates from GitHub Releases into the normal WordPress
 * update screens.
 *
 * The plugin header's "Update URI: https://github.com/..." makes WordPress
 * call the `update_plugins_github.com` filter (WP 5.8+), and also keeps
 * WordPress.org from ever offering an unrelated plugin with the same slug.
 *
 * Release convention: tag "v1.2.3" with an attached asset
 * "wordpress-multisite-exporter.zip" (built by .github/workflows/release.yml).
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Updater {

	const REPO      = 'didoivanov/wordpress-multisite-exporter';
	const SLUG      = 'wordpress-multisite-exporter';
	const TRANSIENT = 'mse_github_release';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( MSE_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * Latest release from the GitHub API (cached for 6 hours).
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|null
	 */
	public static function release( $force = false ) {
		$cached = $force ? false : get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 10,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . self::SLUG,
				),
			)
		);
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( is_wp_error( $response ) || 200 !== $code || empty( $data['tag_name'] ) ) {
			// Cache failures briefly so a GitHub outage doesn't slow every admin page.
			set_site_transient( self::TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		$package = '';
		foreach ( (array) $data['assets'] as $asset ) {
			if ( self::SLUG . '.zip' === $asset['name'] ) {
				$package = $asset['browser_download_url'];
				break;
			}
		}

		$release = array(
			'version'   => ltrim( $data['tag_name'], 'vV' ),
			'package'   => $package ? $package : $data['zipball_url'],
			'url'       => $data['html_url'],
			'notes'     => (string) $data['body'],
			'published' => $data['published_at'],
		);
		set_site_transient( self::TRANSIENT, $release, 6 * HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * Supplies update data for the Update URI hostname github.com.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( MSE_FILE ) !== $plugin_file ) {
			return $update;
		}
		$release = self::release();
		if ( ! $release ) {
			return $update;
		}
		return array(
			'slug'         => self::SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * "View details" popup.
	 */
	public static function info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || self::SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Multisite Exporter',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://github.com/' . self::REPO . '">didoivanov</a>',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '5.8',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Export single sites from a WordPress multisite network and import them into another network.',
				'changelog'   => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * GitHub's source zipballs unpack to "owner-repo-hash/"; rename to the
	 * plugin's folder so the update replaces the installed copy.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		if ( empty( $hook_extra['plugin'] ) || plugin_basename( MSE_FILE ) !== $hook_extra['plugin'] ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . self::SLUG . '/';
		if ( trailingslashit( $source ) === $wanted ) {
			return $source;
		}
		if ( $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'mse_rename_failed', 'Could not rename the update folder.' );
	}

	public static function action_links( $links ) {
		$url     = wp_nonce_url( add_query_arg( 'mse-check-update', '1', network_admin_url( 'plugins.php' ) ), 'mse-check-update' );
		$links[] = '<a href="' . esc_url( network_admin_url( 'admin.php?page=mse' ) ) . '">' . esc_html__( 'Open', 'wordpress-multisite-exporter' ) . '</a>';
		$links[] = '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Check for updates', 'wordpress-multisite-exporter' ) . '</a>';
		return $links;
	}

	/**
	 * Handles the "Check for updates" link.
	 */
	public static function maybe_force_check() {
		if ( empty( $_GET['mse-check-update'] ) || ! current_user_can( 'update_plugins' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		check_admin_referer( 'mse-check-update' );
		self::release( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( network_admin_url( 'plugins.php?plugin_status=upgrade' ) );
		exit;
	}
}
