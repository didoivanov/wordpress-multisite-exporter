<?php
/**
 * Compares a package's manifest with this network before importing:
 * theme, plugins, versions, domain conflicts and disk space.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Preflight {

	/**
	 * @param array  $manifest Package manifest.
	 * @param array  $params   target_domain, mode.
	 * @param string $package  Package path (for disk space checks).
	 * @return array[] Checks: [ 'level' => ok|info|warning|error, 'label', 'detail' ].
	 */
	public static function run( array $manifest, array $params, $package = '' ) {
		global $wp_version, $wpdb;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$checks = array();
		$add    = function ( $level, $label, $detail = '' ) use ( &$checks ) {
			$checks[] = array(
				'level'  => $level,
				'label'  => $label,
				'detail' => $detail,
			);
		};

		// Target domain.
		$domain   = $params['target_domain'];
		$existing = self::find_site( $domain );
		if ( $existing && is_main_site( $existing->blog_id ) ) {
			$add( 'error', sprintf( 'The domain %s belongs to the network\'s main site', $domain ), 'Importing over the main site is not supported. Choose another domain.' );
		} elseif ( $existing && 'overwrite' !== $params['mode'] ) {
			$add( 'error', sprintf( 'A site with the domain %s already exists (ID %d)', $domain, $existing->blog_id ), 'Choose "Overwrite existing site" to replace its content, or use another domain.' );
		} elseif ( $existing ) {
			$add( 'warning', sprintf( 'Site %s (ID %d) will be overwritten', $domain, $existing->blog_id ), 'Its tables are replaced by the package content. This cannot be undone; take a backup first.' );
		} elseif ( 'overwrite' === $params['mode'] ) {
			$add( 'info', sprintf( 'No site with the domain %s exists; a new site will be created', $domain ) );
		} else {
			$add( 'ok', sprintf( 'A new site will be created at %s', $domain ) );
		}

		// Theme.
		$missing     = self::missing_themes( $manifest );
		$replacement = ! empty( $params['theme'] ) ? wp_get_theme( $params['theme'] ) : null;
		if ( $missing && $replacement && $replacement->exists() ) {
			$add( 'warning', sprintf( 'Theme "%1$s" is not installed; "%2$s" will be used instead', implode( '", "', $missing ), $replacement->get( 'Name' ) ), 'Theme-specific settings (Customizer options, widget areas, menu locations) may need to be set up again.' );
		} elseif ( $missing ) {
			$add( 'error', sprintf( 'Theme "%s" is not installed', implode( '", "', $missing ) ), 'Choose a replacement theme below, or install the same theme on this network first.' );
		} else {
			foreach ( array_unique( array( $manifest['theme']['stylesheet'], $manifest['theme']['template'] ) ) as $slug ) {
				$is_parent = $slug !== $manifest['theme']['stylesheet'];
				$theme     = wp_get_theme( $slug );
				$want      = $is_parent ? $manifest['theme']['parent_version'] : $manifest['theme']['version'];
				$label     = $is_parent ? sprintf( 'Parent theme "%s"', $slug ) : sprintf( 'Theme "%s"', $slug );
				if ( $want && version_compare( $theme->get( 'Version' ), $want, '!=' ) ) {
					$add( 'warning', sprintf( '%s version differs', $label ), sprintf( 'Source %s, this network %s.', $want, $theme->get( 'Version' ) ) );
				}
			}
			$add( 'ok', sprintf( 'Theme "%s" is installed', $manifest['theme']['name'] ) );
		}

		// Plugins.
		$installed = get_plugins();
		$network   = (array) get_site_option( 'active_sitewide_plugins', array() );
		$missing   = array();
		$versions  = array();
		$inactive  = array();
		foreach ( $manifest['plugins'] as $plugin ) {
			$file = $plugin['file'];
			if ( ! isset( $installed[ $file ] ) ) {
				$missing[] = sprintf( '%s %s (%s)', $plugin['name'], $plugin['version'], $file );
				continue;
			}
			if ( $plugin['version'] && version_compare( $installed[ $file ]['Version'], $plugin['version'], '!=' ) ) {
				$versions[] = sprintf( '%s: source %s, this network %s', $plugin['name'], $plugin['version'], $installed[ $file ]['Version'] );
			}
			if ( $plugin['network'] && ! isset( $network[ $file ] ) ) {
				$inactive[] = $plugin['name'];
			}
		}
		if ( $missing ) {
			$add( 'error', sprintf( _n( '%d plugin is not installed', '%d plugins are not installed', count( $missing ), 'wordpress-multisite-exporter' ), count( $missing ) ), implode( "\n", $missing ) );
		}
		if ( $versions ) {
			$add( 'warning', sprintf( _n( '%d plugin has a different version', '%d plugins have different versions', count( $versions ), 'wordpress-multisite-exporter' ), count( $versions ) ), implode( "\n", $versions ) );
		}
		if ( $inactive ) {
			$add( 'warning', 'Network-activated on the source but not here', implode( "\n", $inactive ) );
		}
		if ( ! $missing && ! $versions && ! $inactive ) {
			$add( 'ok', sprintf( 'All %d active plugins are installed with the same versions', count( $manifest['plugins'] ) ) );
		}

		// Environment.
		$src_wp = $manifest['environment']['wp'];
		if ( version_compare( $wp_version, $src_wp, '<' ) ) {
			$add( 'warning', 'This network runs an older WordPress version', sprintf( 'Source %s, this network %s. Update WordPress first.', $src_wp, $wp_version ) );
		} elseif ( version_compare( $wp_version, $src_wp, '>' ) ) {
			$add( 'info', 'This network runs a newer WordPress version', sprintf( 'Source %s, this network %s. The database is upgraded on next admin visit.', $src_wp, $wp_version ) );
		}
		if ( preg_match( '/utf8mb4_0900/', implode( '', wp_list_pluck( $manifest['tables'], 'create' ) ) ) && false !== stripos( $wpdb->db_server_info(), 'mariadb' ) ) {
			$add( 'info', 'MySQL 8 collations will be converted for MariaDB' );
		}

		// Package contents.
		if ( isset( $manifest['files']['included'] ) && ! $manifest['files']['included'] ) {
			$add( 'warning', 'The package does not include media files', 'Copy the uploads folder manually.' );
		}

		// Disk space: the media is copied out of the package, so it needs its size again.
		$needed = isset( $manifest['files']['bytes'] ) ? (int) $manifest['files']['bytes'] : 0;
		$free   = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $needed && $free < $needed * 1.1 ) {
			$add( 'error', 'Not enough disk space', sprintf( 'Media needs %s, %s is free.', size_format( $needed ), size_format( $free ) ) );
		}

		return $checks;
	}

	/**
	 * Themes (child and/or parent) of the package that are not installed here.
	 *
	 * @param array $manifest Package manifest.
	 * @return string[] Theme slugs.
	 */
	public static function missing_themes( array $manifest ) {
		$missing = array();
		foreach ( array_unique( array( $manifest['theme']['stylesheet'], $manifest['theme']['template'] ) ) as $slug ) {
			if ( ! wp_get_theme( $slug )->exists() ) {
				$missing[] = $slug;
			}
		}
		return $missing;
	}

	/**
	 * @param array[] $checks Checks.
	 * @return bool
	 */
	public static function has_errors( array $checks ) {
		return in_array( 'error', wp_list_pluck( $checks, 'level' ), true );
	}

	/**
	 * Finds a site on this network by domain (path "/").
	 *
	 * @param string $domain Domain.
	 * @return WP_Site|null
	 */
	public static function find_site( $domain ) {
		$sites = get_sites(
			array(
				'domain'     => $domain,
				'path'       => '/',
				'network_id' => get_current_network_id(),
				'number'     => 1,
			)
		);
		return $sites ? $sites[0] : null;
	}
}
