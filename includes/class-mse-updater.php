<?php
/**
 * Delivers plugin updates from GitHub into the normal WordPress update
 * screens: Network Admin → Plugins / Updates.
 *
 * The version is read from the plugin header on the repository's main branch,
 * so every push that raises the version is offered as an update; no GitHub
 * release is needed. The package is GitHub's zip of that branch (its folder
 * is renamed to the installed folder by fix_folder()).
 *
 * The plugin header's "Update URI: https://github.com/..." makes WordPress
 * call the `update_plugins_github.com` filter (WP 5.8+), and also keeps
 * WordPress.org from ever offering an unrelated plugin with the same slug.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Updater {

	const REPO      = 'didoivanov/wordpress-multisite-exporter';
	const BRANCH    = 'main';
	const SLUG      = 'wordpress-multisite-exporter';
	const TRANSIENT = 'mse_github_update';

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'info' ), 20, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'network_admin_plugin_action_links_' . plugin_basename( MSE_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_force_check' ) );
	}

	/**
	 * Latest version on GitHub's main branch (cached for 6 hours).
	 *
	 * @param bool $force Bypass the cache.
	 * @return array|null
	 */
	public static function release( $force = false ) {
		$cached = $force ? false : get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached ? $cached : null;
		}

		$raw     = 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/';
		$args    = array(
			'timeout' => 10,
			'headers' => array( 'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . self::SLUG ),
		);
		$main    = wp_remote_get( $raw . self::SLUG . '.php', $args );
		$version = '';
		if ( 200 === wp_remote_retrieve_response_code( $main ) && preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', wp_remote_retrieve_body( $main ), $m ) ) {
			$version = $m[1];
		}
		if ( ! $version ) {
			// Cache failures briefly so a GitHub outage doesn't slow every admin page.
			set_site_transient( self::TRANSIENT, array(), 30 * MINUTE_IN_SECONDS );
			return null;
		}

		// Release notes: the CHANGELOG.md section for this version.
		$notes     = '';
		$changelog = wp_remote_get( $raw . 'CHANGELOG.md', $args );
		if ( 200 === wp_remote_retrieve_response_code( $changelog ) && preg_match( '/^## ' . preg_quote( $version, '/' ) . '\s*$(.*?)(?=^## |\z)/ms', wp_remote_retrieve_body( $changelog ), $m ) ) {
			$notes = trim( $m[1] );
		}

		$release = array(
			'version' => $version,
			'package' => 'https://github.com/' . self::REPO . '/archive/refs/heads/' . self::BRANCH . '.zip',
			'url'     => 'https://github.com/' . self::REPO . '/blob/' . self::BRANCH . '/CHANGELOG.md',
			'notes'   => $notes,
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
			'download_link' => $release['package'],
			'sections'      => array(
				'description' => 'Export single sites from a WordPress multisite network and import them into another network.',
				'changelog'   => wpautop( esc_html( $release['notes'] ) ),
			),
		);
	}

	/**
	 * GitHub's source zipballs unpack to "owner-repo-hash/"; rename to the
	 * folder the plugin is installed in, so the update replaces it in place
	 * and the plugin stays active.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		$main = basename( MSE_FILE );
		if ( empty( $hook_extra['plugin'] ) || basename( $hook_extra['plugin'] ) !== $main || ! $wp_filesystem->exists( trailingslashit( $source ) . $main ) ) {
			return $source;
		}
		$folder = dirname( $hook_extra['plugin'] );
		$wanted = trailingslashit( $remote_source ) . ( '.' === $folder ? self::SLUG : $folder ) . '/';
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
