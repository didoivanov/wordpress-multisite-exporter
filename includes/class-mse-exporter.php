<?php
/**
 * Builds a single-site package (.tar) from a multisite network.
 *
 * Package layout:
 *   manifest.json                 Source site, environment, theme/plugins, table schemas.
 *   users.json                    Users of the site with their user meta.
 *   db/<table>/<n>.json.gz        Table rows in batches (table name without prefix).
 *   files/<path>                  Files from the site's uploads folder.
 *   end.json                      Completion marker with totals.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Exporter {

	const FORMAT     = 1;
	const FILE_CHUNK = 8388608; // 8 MB per read when streaming files.

	/**
	 * Network-wide tables that never belong to a single site.
	 *
	 * @return string[] Table names without prefix.
	 */
	public static function global_tables() {
		return array( 'users', 'usermeta', 'blogs', 'blogmeta', 'site', 'sitemeta', 'signups', 'registration_log', 'blog_versions', 'sitecategories' );
	}

	/**
	 * Queues an export job for a site.
	 *
	 * @param int  $blog_id       Site ID.
	 * @param bool $include_media Include the uploads folder.
	 * @return array Job.
	 */
	public static function create_job( $blog_id, $include_media = true ) {
		$site = get_site( $blog_id );
		if ( ! $site ) {
			throw new InvalidArgumentException( sprintf( 'Site %d does not exist.', $blog_id ) );
		}
		return MSE_Jobs::create(
			'export',
			$site->domain . untrailingslashit( $site->path ),
			array(
				'blog_id' => (int) $blog_id,
				'media'   => (bool) $include_media,
			),
			'queued'
		);
	}

	/**
	 * Performs one bounded unit of work.
	 *
	 * @param array $job      Job (by reference).
	 * @param float $deadline microtime() deadline.
	 */
	public static function step( array &$job, $deadline ) {
		$state = &$job['state'];
		if ( empty( $state['phase'] ) ) {
			self::start( $job );
			return;
		}

		self::rollback_partial_write( $state );

		switch ( $state['phase'] ) {
			case 'scan':
				self::scan( $job, $deadline );
				break;
			case 'manifest':
				self::write_manifest( $job );
				break;
			case 'users':
				self::write_users( $job );
				break;
			case 'tables':
				self::write_tables( $job, $deadline );
				break;
			case 'files':
				self::write_files( $job, $deadline );
				break;
			case 'finish':
				self::finish( $job );
				break;
		}
	}

	// ------------------------------------------------------------- Phases.

	private static function start( array &$job ) {
		MSE_Jobs::ensure_dirs();
		$blog_id = $job['params']['blog_id'];
		$site    = get_site( $blog_id );
		if ( ! $site ) {
			throw new RuntimeException( 'The site no longer exists.' );
		}

		$work = MSE_Jobs::work_dir( $job['id'] );
		wp_mkdir_p( $work );

		$slug    = sanitize_file_name( preg_replace( '/[^a-z0-9.\-]+/i', '-', $site->domain . $site->path ) );
		$name    = trim( $slug, '-' ) . '-' . gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 16, false ) ) . '.tar';
		$package = MSE_Jobs::exports_dir() . '/' . $name;
		if ( false === file_put_contents( $package, '' ) ) {
			throw new RuntimeException( 'Cannot create the package file in ' . MSE_Jobs::exports_dir() );
		}

		switch_to_blog( $blog_id );
		$upload = wp_upload_dir( null, false );
		restore_current_blog();

		$job['state'] = array(
			'phase'      => $job['params']['media'] ? 'scan' : 'manifest',
			'package'    => $package,
			'tar_size'   => 0,
			'upload_dir' => $upload['basedir'],
			'inventory'  => $work . '/inventory.txt',
			'scan_queue' => array( '' ),
			'file_count' => 0,
			'file_bytes' => 0,
			'inv_pos'    => 0,
			'files_done' => 0,
			'bytes_done' => 0,
			'current'    => null,
			'tables'     => array(),
			'table_idx'  => 0,
			'cursor'     => null,
			'chunk'      => 0,
			'batch'      => 1000,
			'rows_done'  => 0,
		);
		file_put_contents( $job['state']['inventory'], '' );

		MSE_Jobs::log( $job, sprintf( 'Export started for %s (site ID %d).', $job['title'], $blog_id ) );
		self::progress( $job, 1, __( 'Scanning media files…', 'wordpress-multisite-exporter' ) );
	}

	/**
	 * Walks the uploads folder (resumable: a queue of folders to list).
	 */
	private static function scan( array &$job, $deadline ) {
		$state = &$job['state'];
		$base  = $state['upload_dir'];

		if ( ! is_dir( $base ) ) {
			MSE_Jobs::log( $job, 'The site has no uploads folder; no media to export.' );
			$state['scan_queue'] = array();
		}

		$skip_top = array();
		if ( is_main_site( $job['params']['blog_id'] ) ) {
			// The main site's uploads folder contains every other site's media.
			$skip_top = array( 'sites', 'blogs.dir' );
		}
		$skip_top = apply_filters( 'mse_export_skip_upload_dirs', $skip_top, $job['params']['blog_id'] );

		$inv = fopen( $state['inventory'], 'ab' );
		while ( $state['scan_queue'] && microtime( true ) < $deadline ) {
			$rel   = array_shift( $state['scan_queue'] );
			$dir   = '' === $rel ? $base : $base . '/' . $rel;
			$items = @scandir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $items ) {
				MSE_Jobs::log( $job, 'Cannot read folder: ' . $dir, 'warning' );
				continue;
			}
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				$path = $dir . '/' . $item;
				$irel = '' === $rel ? $item : $rel . '/' . $item;
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( '' === $rel && in_array( $item, $skip_top, true ) ) {
						continue;
					}
					$state['scan_queue'][] = $irel;
				} elseif ( is_file( $path ) && is_readable( $path ) ) {
					$size = filesize( $path );
					fwrite( $inv, $irel . "\t" . $size . "\n" );
					$state['file_count']++;
					$state['file_bytes'] += $size;
				}
			}
		}
		fclose( $inv );

		if ( ! $state['scan_queue'] ) {
			MSE_Jobs::log( $job, sprintf( 'Found %s media files (%s).', number_format_i18n( $state['file_count'] ), size_format( $state['file_bytes'] ) ) );
			$state['phase'] = 'manifest';
		}
		self::progress( $job, 2, sprintf( __( 'Scanning media files… %s found', 'wordpress-multisite-exporter' ), number_format_i18n( $state['file_count'] ) ) );
	}

	private static function write_manifest( array &$job ) {
		$blog_id                 = $job['params']['blog_id'];
		$manifest                = self::build_manifest( $blog_id );
		$manifest['files']       = array(
			'included' => (bool) $job['params']['media'],
			'count'    => $job['state']['file_count'],
			'bytes'    => $job['state']['file_bytes'],
		);
		$job['state']['tables'] = array();
		foreach ( $manifest['tables'] as $table ) {
			$job['state']['tables'][] = array(
				'name'  => $table['name'],
				'table' => $manifest['source']['blog_prefix'] . $table['name'],
				'rows'  => $table['rows'],
				'pk'    => $table['pk'],
				'order' => $table['order'],
			);
		}

		self::append( $job, 'manifest.json', wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		MSE_Jobs::log( $job, sprintf( 'Manifest written: %d tables, theme "%s", %d active plugins.', count( $manifest['tables'] ), $manifest['theme']['stylesheet'], count( $manifest['plugins'] ) ) );
		$job['state']['phase'] = 'users';
		self::progress( $job, 4, __( 'Exporting users…', 'wordpress-multisite-exporter' ) );
	}

	private static function write_users( array &$job ) {
		$data = self::collect_users( $job['params']['blog_id'] );
		self::append( $job, 'users.json', wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
		MSE_Jobs::log( $job, sprintf( 'Exported %d users.', count( $data['users'] ) ) );
		$job['state']['phase'] = 'tables';
		self::progress( $job, 5, __( 'Exporting database…', 'wordpress-multisite-exporter' ) );
	}

	private static function write_tables( array &$job, $deadline ) {
		global $wpdb;
		$state = &$job['state'];

		while ( microtime( true ) < $deadline ) {
			if ( ! isset( $state['tables'][ $state['table_idx'] ] ) ) {
				MSE_Jobs::log( $job, sprintf( 'Database exported: %s rows.', number_format_i18n( $state['rows_done'] ) ) );
				$state['phase'] = $job['params']['media'] ? 'files' : 'finish';
				break;
			}
			$t     = $state['tables'][ $state['table_idx'] ];
			$table = '`' . str_replace( '`', '', $t['table'] ) . '`';
			$where = self::row_filter( $t['name'] );
			$limit = (int) $state['batch'];

			if ( $t['pk'] ) {
				$pk     = '`' . str_replace( '`', '', $t['pk'] ) . '`';
				$cursor = null === $state['cursor'] ? null : (string) $state['cursor'];
				$conds  = array_filter( array( $where, null === $cursor ? '' : $wpdb->prepare( "$pk > %s", $cursor ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql    = "SELECT * FROM $table" . ( $conds ? ' WHERE ' . implode( ' AND ', $conds ) : '' ) . " ORDER BY $pk ASC LIMIT $limit";
			} else {
				$offset = (int) $state['cursor'];
				$order  = $t['order'] ? ' ORDER BY ' . implode( ', ', array_map( function ( $c ) {
					return '`' . str_replace( '`', '', $c ) . '`';
				}, $t['order'] ) ) : '';
				$sql    = "SELECT * FROM $table" . ( $where ? " WHERE $where" : '' ) . "$order LIMIT $offset, $limit";
			}

			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $wpdb->last_error ) {
				throw new RuntimeException( sprintf( 'Database error reading %s: %s', $t['table'], $wpdb->last_error ) );
			}

			if ( ! $rows ) {
				$state['table_idx']++;
				$state['cursor'] = null;
				$state['chunk']  = 0;
				continue;
			}

			$payload = array(
				'cols' => array_keys( $rows[0] ),
				'rows' => array(),
			);
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					if ( null !== $value && ! mb_check_encoding( $value, 'UTF-8' ) ) {
						$value = array( 'b' => base64_encode( $value ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
					}
					$values[] = $value;
				}
				$payload['rows'][] = $values;
			}
			$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
			if ( false === $json ) {
				throw new RuntimeException( sprintf( 'Could not encode rows of %s: %s', $t['table'], json_last_error_msg() ) );
			}

			$state['chunk']++;
			self::append( $job, sprintf( 'db/%s/%06d.json.gz', $t['name'], $state['chunk'] ), gzencode( $json, 6 ) );

			$count               = count( $rows );
			$state['rows_done'] += $count;
			if ( $t['pk'] ) {
				$state['cursor'] = $rows[ $count - 1 ][ $t['pk'] ];
			} else {
				$state['cursor'] = (int) $state['cursor'] + $count;
			}

			// Keep each batch roughly between 1 and 8 MB.
			$size = strlen( $json );
			if ( $size > 8388608 && $limit > 50 ) {
				$state['batch'] = (int) max( 50, $limit / 2 );
			} elseif ( $size < 1048576 && $limit < 5000 ) {
				$state['batch'] = min( 5000, $limit * 2 );
			}

			if ( $count < $limit ) {
				$state['table_idx']++;
				$state['cursor'] = null;
				$state['chunk']  = 0;
			}
		}

		$total = max( 1, array_sum( wp_list_pluck( $state['tables'], 'rows' ) ) );
		$pct   = 5 + ( $job['params']['media'] ? 25 : 90 ) * min( 1, $state['rows_done'] / $total );
		$label = isset( $state['tables'][ $state['table_idx'] ] ) ? $state['tables'][ $state['table_idx'] ]['table'] : '';
		self::progress( $job, $pct, sprintf( __( 'Exporting database… %1$s rows (%2$s)', 'wordpress-multisite-exporter' ), number_format_i18n( $state['rows_done'] ), $label ) );
	}

	private static function write_files( array &$job, $deadline ) {
		$state = &$job['state'];
		$base  = $state['upload_dir'];
		$out   = fopen( $state['package'], 'ab' );
		$inv   = fopen( $state['inventory'], 'rb' );
		fseek( $inv, $state['inv_pos'] );

		while ( microtime( true ) < $deadline ) {
			if ( ! $state['current'] ) {
				$line = fgets( $inv );
				if ( false === $line ) {
					$state['phase'] = 'finish';
					break;
				}
				$state['inv_pos'] = ftell( $inv );
				list( $rel )      = explode( "\t", rtrim( $line, "\n" ) );
				$path             = $base . '/' . $rel;
				clearstatcache( true, $path );
				if ( ! is_file( $path ) || ! is_readable( $path ) ) {
					MSE_Jobs::log( $job, 'Skipped (file disappeared or unreadable): ' . $rel, 'warning' );
					continue;
				}
				$size = filesize( $path );
				if ( $size > 077777777777 ) {
					MSE_Jobs::log( $job, 'Skipped (larger than 8 GB): ' . $rel, 'warning' );
					continue;
				}
				fwrite( $out, MSE_Tar::header( 'files/' . $rel, $size, filemtime( $path ) ) );
				$state['current'] = array(
					'rel'     => $rel,
					'size'    => $size,
					'written' => 0,
				);
			}

			$cur = &$state['current'];
			$in  = fopen( $base . '/' . $cur['rel'], 'rb' );
			if ( $in ) {
				fseek( $in, $cur['written'] );
			}
			while ( $cur['written'] < $cur['size'] && microtime( true ) < $deadline ) {
				$want = min( self::FILE_CHUNK, $cur['size'] - $cur['written'] );
				$data = $in ? fread( $in, $want ) : '';
				if ( false === $data || '' === $data ) {
					// File shrank or vanished mid-export: keep the archive valid.
					$data = str_repeat( "\0", $want );
					MSE_Jobs::log( $job, 'File changed while exporting (padded): ' . $cur['rel'], 'warning' );
				}
				fwrite( $out, $data );
				$cur['written']      += strlen( $data );
				$state['bytes_done'] += strlen( $data );
			}
			if ( $in ) {
				fclose( $in );
			}

			if ( $cur['written'] >= $cur['size'] ) {
				fwrite( $out, MSE_Tar::padding( $cur['size'] ) );
				$state['files_done']++;
				$state['current'] = null;
			}
			unset( $cur );
			fflush( $out );
			$state['tar_size'] = fstat( $out )['size'];
		}

		fflush( $out );
		$state['tar_size'] = fstat( $out )['size'];
		fclose( $out );
		fclose( $inv );

		$pct = 30 + 68 * ( $state['file_bytes'] ? min( 1, $state['bytes_done'] / $state['file_bytes'] ) : 1 );
		self::progress( $job, $pct, sprintf( __( 'Packing media… %1$s / %2$s files', 'wordpress-multisite-exporter' ), number_format_i18n( $state['files_done'] ), number_format_i18n( $state['file_count'] ) ) );
	}

	private static function finish( array &$job ) {
		$state = &$job['state'];
		self::append(
			$job,
			'end.json',
			wp_json_encode(
				array(
					'rows'  => $state['rows_done'],
					'files' => $state['files_done'],
					'bytes' => $state['bytes_done'],
				)
			)
		);
		$fh = fopen( $state['package'], 'ab' );
		fwrite( $fh, MSE_Tar::footer() );
		fclose( $fh );
		clearstatcache( true, $state['package'] );

		MSE_Jobs::rrmdir( MSE_Jobs::work_dir( $job['id'] ) );

		$size          = filesize( $state['package'] );
		$job['result'] = array(
			'file' => basename( $state['package'] ),
			'url'  => MSE_Jobs::exports_url() . '/' . rawurlencode( basename( $state['package'] ) ),
			'size' => $size,
		);
		$job['status'] = 'done';
		MSE_Jobs::log( $job, sprintf( 'Export finished: %s (%s).', basename( $state['package'] ), size_format( $size ) ) );
		self::progress( $job, 100, __( 'Export complete', 'wordpress-multisite-exporter' ) );
	}

	// ------------------------------------------------------------ Helpers.

	/**
	 * Appends a complete small entry to the package.
	 */
	private static function append( array &$job, $name, $data ) {
		$fh = fopen( $job['state']['package'], 'ab' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Cannot write to the package file.' );
		}
		$len = strlen( $data );
		if ( false === fwrite( $fh, MSE_Tar::header( $name, $len, time() ) . $data . MSE_Tar::padding( $len ) ) ) {
			throw new RuntimeException( 'Writing the package failed (is the disk full?).' );
		}
		fflush( $fh );
		$job['state']['tar_size'] = fstat( $fh )['size'];
		fclose( $fh );
	}

	/**
	 * If a previous request died mid-write, cut the package back to the last
	 * known-good size so the archive stays consistent.
	 */
	private static function rollback_partial_write( array &$state ) {
		if ( empty( $state['package'] ) ) {
			return;
		}
		clearstatcache( true, $state['package'] );
		if ( ! is_file( $state['package'] ) ) {
			throw new RuntimeException( 'The package file was removed during export.' );
		}
		if ( filesize( $state['package'] ) > $state['tar_size'] ) {
			$fh = fopen( $state['package'], 'r+b' );
			ftruncate( $fh, $state['tar_size'] );
			fclose( $fh );
		}
	}

	private static function progress( array &$job, $percent, $label ) {
		$job['progress'] = array(
			'percent' => (int) round( $percent ),
			'label'   => $label,
		);
	}

	/**
	 * Rows that should not be exported (e.g. transients).
	 *
	 * @param string $name Table name without prefix.
	 * @return string SQL condition or ''.
	 */
	private static function row_filter( $name ) {
		$filter = '';
		if ( 'options' === $name ) {
			$filter = "option_name NOT LIKE '\\_transient\\_%' AND option_name NOT LIKE '\\_site\\_transient\\_%'";
		}
		return apply_filters( 'mse_export_row_filter', $filter, $name );
	}

	/**
	 * Lists a site's own tables.
	 *
	 * @param int $blog_id Site ID.
	 * @return string[] Full table names.
	 */
	public static function site_tables( $blog_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );
		$all    = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );
		$tables = array();
		foreach ( $all as $table ) {
			$rest = substr( $table, strlen( $prefix ) );
			if ( is_main_site( $blog_id ) || $prefix === $wpdb->base_prefix ) {
				if ( in_array( $rest, self::global_tables(), true ) || preg_match( '/^\d+_/', $rest ) ) {
					continue;
				}
			}
			$tables[] = $table;
		}
		return apply_filters( 'mse_export_tables', $tables, $blog_id );
	}

	/**
	 * Builds the package manifest describing the source site.
	 *
	 * @param int $blog_id Site ID.
	 * @return array
	 */
	public static function build_manifest( $blog_id ) {
		global $wpdb, $wp_version;

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$site   = get_site( $blog_id );
		$prefix = $wpdb->get_blog_prefix( $blog_id );

		$tables = array();
		foreach ( self::site_tables( $blog_id ) as $table ) {
			$name   = substr( $table, strlen( $prefix ) );
			$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_N ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( ! $create || empty( $create[1] ) ) {
				continue; // Views and broken tables are skipped.
			}
			$keys    = $wpdb->get_results( 'SHOW KEYS FROM `' . str_replace( '`', '', $table ) . "` WHERE Key_name = 'PRIMARY'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$pk_cols = wp_list_pluck( $keys, 'Column_name' );
			$pk      = '';
			if ( 1 === count( $pk_cols ) ) {
				$col = $wpdb->get_row( $wpdb->prepare( 'SHOW COLUMNS FROM `' . str_replace( '`', '', $table ) . '` WHERE Field = %s', $pk_cols[0] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				if ( $col && preg_match( '/int/i', $col['Type'] ) ) {
					$pk = $pk_cols[0];
				}
			}
			$where    = self::row_filter( $name );
			$tables[] = array(
				'name'   => $name,
				'rows'   => (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . str_replace( '`', '', $table ) . '`' . ( $where ? " WHERE $where" : '' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				'pk'     => $pk,
				'order'  => $pk ? array() : $pk_cols,
				'create' => $create[1],
			);
		}

		switch_to_blog( $blog_id );
		$theme      = wp_get_theme();
		$upload     = wp_upload_dir( null, false );
		$home       = get_option( 'home' );
		$siteurl    = get_option( 'siteurl' );
		$blogname   = get_option( 'blogname' );
		$site_plugs = (array) get_option( 'active_plugins', array() );
		restore_current_blog();

		$all_plugins = get_plugins();
		$network     = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
		$plugins     = array();
		foreach ( array_unique( array_merge( $site_plugs, $network ) ) as $file ) {
			if ( 'wordpress-multisite-exporter/wordpress-multisite-exporter.php' === $file ) {
				continue;
			}
			$plugins[] = array(
				'file'    => $file,
				'name'    => isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Name'] : $file,
				'version' => isset( $all_plugins[ $file ] ) ? $all_plugins[ $file ]['Version'] : '',
				'network' => in_array( $file, $network, true ),
			);
		}

		// Earlier addresses of the site (e.g. the install subdomain before a
		// domain switch). GUIDs keep the domain a post was created under.
		$current   = array_map( 'strtolower', array( $site->domain, (string) wp_parse_url( $home, PHP_URL_HOST ), (string) wp_parse_url( $siteurl, PHP_URL_HOST ) ) );
		$old_hosts = array();
		if ( in_array( $prefix . 'posts', self::site_tables( $blog_id ), true ) ) {
			$found = $wpdb->get_col( "SELECT DISTINCT LOWER(SUBSTRING_INDEX(SUBSTRING_INDEX(guid, '/', 3), '/', -1)) FROM `{$prefix}posts` WHERE guid LIKE 'http%' LIMIT 50" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( $found as $host ) {
				if ( preg_match( '/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/', $host ) && ! in_array( $host, $current, true ) && get_network()->domain !== $host ) {
					$old_hosts[] = $host;
				}
			}
		}

		$parent = $theme->parent();
		return array(
			'format'         => self::FORMAT,
			'plugin_version' => MSE_VERSION,
			'created'        => gmdate( 'c' ),
			'source'         => array(
				'blog_id'           => (int) $blog_id,
				'domain'            => $site->domain,
				'path'              => $site->path,
				'home'              => $home,
				'siteurl'           => $siteurl,
				'blogname'          => $blogname,
				'old_hosts'         => $old_hosts,
				'network_domain'    => get_network()->domain,
				'subdomain_install' => is_subdomain_install(),
				'is_main_site'      => is_main_site( $blog_id ),
				'base_prefix'       => $wpdb->base_prefix,
				'blog_prefix'       => $prefix,
				'upload_basedir'    => wp_normalize_path( $upload['basedir'] ),
				'upload_path'       => untrailingslashit( (string) wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) ),
				'content_dir'       => wp_normalize_path( WP_CONTENT_DIR ),
				'abspath'           => untrailingslashit( wp_normalize_path( ABSPATH ) ),
				'flags'             => array(
					'public'   => (int) $site->public,
					'archived' => (int) $site->archived,
					'mature'   => (int) $site->mature,
					'spam'     => (int) $site->spam,
					'deleted'  => (int) $site->deleted,
					'lang_id'  => (int) $site->lang_id,
				),
			),
			'environment'    => array(
				'wp'         => $wp_version,
				'php'        => PHP_VERSION,
				'db_server'  => $wpdb->db_server_info(),
				'db_charset' => $wpdb->charset,
			),
			'theme'          => array(
				'stylesheet'     => $theme->get_stylesheet(),
				'template'       => $theme->get_template(),
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'parent_version' => $parent ? $parent->get( 'Version' ) : '',
			),
			'plugins'        => $plugins,
			'tables'         => $tables,
		);
	}

	/**
	 * Collects the site's users (members plus content authors) with their
	 * global user meta and this site's prefixed meta (roles etc.).
	 *
	 * @param int $blog_id Site ID.
	 * @return array
	 */
	public static function collect_users( $blog_id ) {
		global $wpdb;
		$prefix = $wpdb->get_blog_prefix( $blog_id );

		$ids = get_users(
			array(
				'blog_id' => $blog_id,
				'fields'  => 'ID',
			)
		);
		$ids = array_merge(
			$ids,
			$wpdb->get_col( "SELECT DISTINCT post_author FROM `{$prefix}posts` WHERE post_author > 0" ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->get_col( "SELECT DISTINCT user_id FROM `{$prefix}comments` WHERE user_id > 0" ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );

		$skip  = apply_filters( 'mse_export_skip_user_meta', array( 'session_tokens', 'primary_blog', 'source_domain' ) );
		$users = array();
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			$in   = implode( ',', $chunk );
			$rows = $wpdb->get_results( "SELECT ID, user_login, user_pass, user_nicename, user_email, user_url, user_registered, user_status, display_name FROM {$wpdb->users} WHERE ID IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$meta = $wpdb->get_results( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id IN ($in)", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $rows as $row ) {
				$users[ $row['ID'] ] = array(
					'user'      => $row,
					'meta'      => array(),
					'site_meta' => array(),
				);
			}
			foreach ( $meta as $m ) {
				if ( ! isset( $users[ $m['user_id'] ] ) || in_array( $m['meta_key'], $skip, true ) ) {
					continue;
				}
				$key = $m['meta_key'];
				if ( 0 === strpos( $key, $prefix ) && ! ( $prefix === $wpdb->base_prefix && preg_match( '/^' . preg_quote( $wpdb->base_prefix, '/' ) . '\d+_/', $key ) ) ) {
					// This site's meta, e.g. wp_17_capabilities: store without prefix.
					$users[ $m['user_id'] ]['site_meta'][] = array( substr( $key, strlen( $prefix ) ), $m['meta_value'] );
				} elseif ( 0 === strpos( $key, $wpdb->base_prefix ) ) {
					continue; // Another site's meta.
				} else {
					$users[ $m['user_id'] ]['meta'][] = array( $key, $m['meta_value'] );
				}
			}
		}

		return array(
			'users'        => array_values( $users ),
			'super_admins' => get_super_admins(),
		);
	}
}
