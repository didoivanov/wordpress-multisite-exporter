<?php
/**
 * Imports a single-site package into this multisite network.
 *
 * Flow: fetch (URL jobs) → inspect (read manifest, preflight) → review (admin
 * confirms target domain and options) → site → tables → entries → finalize.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Importer {

	const DOWNLOAD_CHUNK = 20971520; // 20 MB per HTTP range request.
	const FILE_CHUNK     = 8388608;

	// -------------------------------------------------------- Job creation.

	/**
	 * @param string $url Package URL on the source network.
	 * @return array Job.
	 */
	public static function create_from_url( $url ) {
		$url = esc_url_raw( trim( $url ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid package URL: %s', $url ) );
		}
		MSE_Jobs::ensure_dirs();
		$job                     = MSE_Jobs::create( 'import', basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ), array( 'url' => $url ), 'preparing' );
		$job['state']            = array(
			'phase'   => 'fetch',
			'package' => MSE_Jobs::imports_dir() . '/' . $job['id'] . '.tar',
			'fetched' => 0,
			'total'   => 0,
		);
		MSE_Jobs::save( $job );
		return $job;
	}

	/**
	 * Starts a chunked browser upload.
	 *
	 * @param string $filename Original filename.
	 * @param int    $size     Size in bytes.
	 * @return array Job.
	 */
	public static function create_upload( $filename, $size ) {
		MSE_Jobs::ensure_dirs();
		$job          = MSE_Jobs::create( 'import', sanitize_file_name( $filename ), array( 'upload' => true ), 'uploading' );
		$job['state'] = array(
			'package' => MSE_Jobs::imports_dir() . '/' . $job['id'] . '.tar',
			'total'   => (int) $size,
		);
		file_put_contents( $job['state']['package'], '' );
		$job['progress'] = array( 'percent' => 0, 'label' => __( 'Uploading…', 'wordpress-multisite-exporter' ) );
		MSE_Jobs::save( $job );
		return $job;
	}

	/**
	 * Appends an uploaded chunk. Offsets make uploads resumable and reject
	 * out-of-order chunks.
	 *
	 * @param array  $job    Job.
	 * @param int    $offset Byte offset of the chunk.
	 * @param string $tmp    Uploaded temp file.
	 * @return array Job.
	 */
	public static function receive_chunk( array $job, $offset, $tmp ) {
		if ( 'uploading' !== $job['status'] ) {
			throw new RuntimeException( 'This job is not accepting uploads.' );
		}
		$package = $job['state']['package'];
		clearstatcache( true, $package );
		$current = filesize( $package );
		if ( (int) $offset !== $current ) {
			throw new RuntimeException( sprintf( 'Upload offset mismatch (expected %d, got %d).', $current, $offset ) );
		}
		$in  = fopen( $tmp, 'rb' );
		$out = fopen( $package, 'ab' );
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		clearstatcache( true, $package );
		$size = filesize( $package );

		$total           = max( 1, $job['state']['total'] );
		$job['progress'] = array(
			'percent' => (int) floor( 100 * $size / $total ),
			'label'   => sprintf( __( 'Uploading… %1$s of %2$s', 'wordpress-multisite-exporter' ), size_format( $size ), size_format( $total ) ),
		);
		if ( $size >= $job['state']['total'] ) {
			$job['status']         = 'preparing';
			$job['state']['phase'] = 'inspect';
			MSE_Jobs::log( $job, sprintf( 'Upload complete (%s).', size_format( $size ) ) );
		}
		MSE_Jobs::save( $job );
		return $job;
	}

	/**
	 * Uses a package that already sits in the imports folder.
	 *
	 * @param string $filename File name inside imports_dir().
	 * @return array Job.
	 */
	public static function create_from_local( $filename ) {
		$filename = basename( $filename );
		$path     = MSE_Jobs::imports_dir() . '/' . $filename;
		if ( ! preg_match( '/\.tar$/i', $filename ) || ! is_file( $path ) ) {
			throw new InvalidArgumentException( 'Package file not found.' );
		}
		foreach ( MSE_Jobs::all() as $other ) {
			if ( isset( $other['state']['package'] ) && $other['state']['package'] === $path ) {
				throw new InvalidArgumentException( 'This file is already used by another import job.' );
			}
		}
		$job          = MSE_Jobs::create( 'import', $filename, array( 'local' => true ), 'preparing' );
		$job['state'] = array(
			'phase'   => 'inspect',
			'package' => $path,
		);
		MSE_Jobs::save( $job );
		return $job;
	}

	/**
	 * Applies the admin's choices from the review screen and queues the job.
	 *
	 * @param array $job    Job in "review".
	 * @param array $config target_domain, mode (new|overwrite), extra_pairs, skip_guids, force.
	 * @return array{job:array, preflight:array}
	 */
	public static function configure( array $job, array $config ) {
		if ( 'review' !== $job['status'] ) {
			throw new RuntimeException( 'This job is not waiting for review.' );
		}
		$manifest = self::read_manifest( $job['state']['package'] );

		$domain = strtolower( trim( (string) $config['target_domain'] ) );
		$domain = preg_replace( '~^https?://~', '', $domain );
		$domain = trim( $domain, '/' );
		if ( ! preg_match( '/^[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)+$/', $domain ) ) {
			throw new InvalidArgumentException( sprintf( '"%s" is not a valid domain.', $domain ) );
		}

		$pairs = array();
		foreach ( preg_split( '/\R/', (string) $config['extra_pairs'] ) as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$parts = explode( '=>', $line, 2 );
			if ( 2 !== count( $parts ) || '' === trim( $parts[0] ) ) {
				throw new InvalidArgumentException( sprintf( 'Replacement lines must look like "old => new". Invalid: %s', $line ) );
			}
			$pairs[] = array( trim( $parts[0] ), trim( $parts[1] ) );
		}

		// Must be an installed theme's exact folder name (they can be mixed-case).
		$theme = isset( $config['theme'] ) ? (string) $config['theme'] : '';
		if ( ! MSE_Preflight::missing_themes( $manifest ) || ! array_key_exists( $theme, wp_get_themes() ) ) {
			$theme = '';
		}

		$params = array(
			'target_domain' => $domain,
			'theme'         => $theme,
			'mode'          => 'overwrite' === $config['mode'] ? 'overwrite' : 'new',
			'extra_pairs'   => $pairs,
			'old_hosts'     => ! empty( $config['replace_old_hosts'] ) && ! empty( $manifest['source']['old_hosts'] ) ? $manifest['source']['old_hosts'] : array(),
			'skip_guids'    => ! empty( $config['skip_guids'] ),
		);

		$checks = MSE_Preflight::run( $manifest, $params, $job['state']['package'] );
		$job['result']['preflight'] = $checks;
		if ( MSE_Preflight::has_errors( $checks ) && empty( $config['force'] ) ) {
			MSE_Jobs::save( $job );
			return array( 'job' => $job, 'preflight' => $checks, 'blocked' => true );
		}

		$job['params']  = array_merge( $job['params'], $params );
		$job['status']  = 'queued';
		$job['title']   = $manifest['source']['domain'] . ' → ' . $domain;
		$job['state']['phase'] = 'site';
		$job['progress'] = array( 'percent' => 0, 'label' => __( 'Queued', 'wordpress-multisite-exporter' ) );
		MSE_Jobs::log( $job, sprintf( 'Import queued into %s (%s).', $domain, 'overwrite' === $params['mode'] ? 'overwrite existing site' : 'new site' ) );
		MSE_Jobs::save( $job );
		return array( 'job' => $job, 'preflight' => $checks, 'blocked' => false );
	}

	// --------------------------------------------------------------- Steps.

	/**
	 * @param array $job      Job (by reference).
	 * @param float $deadline microtime() deadline.
	 */
	public static function step( array &$job, $deadline ) {
		// No e-mails during a migration. Creating a site makes core notify the
		// network admin, which fatals on hosts where mail() is disabled.
		add_filter( 'pre_wp_mail', '__return_false' );

		switch ( $job['state']['phase'] ) {
			case 'fetch':
				self::fetch( $job, $deadline );
				break;
			case 'inspect':
				self::inspect( $job );
				break;
			case 'site':
				self::setup_site( $job );
				break;
			case 'tables':
				self::create_tables( $job );
				break;
			case 'entries':
				self::entries( $job, $deadline );
				break;
			case 'finalize':
				self::finalize( $job );
				break;
			default:
				throw new RuntimeException( 'Unknown import phase.' );
		}
	}

	/**
	 * Downloads the package with HTTP range requests, one chunk per step.
	 */
	private static function fetch( array &$job, $deadline ) {
		$state   = &$job['state'];
		$package = $state['package'];

		while ( microtime( true ) < $deadline ) {
			clearstatcache( true, $package );
			$have = is_file( $package ) ? filesize( $package ) : 0;
			if ( $have !== $state['fetched'] ) {
				// Drop bytes from a request that died before state was saved.
				$fh = fopen( $package, 'c+b' );
				ftruncate( $fh, $state['fetched'] );
				fclose( $fh );
			}

			$tmp      = $package . '.part';
			$end      = $state['fetched'] + self::DOWNLOAD_CHUNK - 1;
			$response = wp_safe_remote_get(
				$job['params']['url'],
				array(
					'timeout'  => 120,
					'stream'   => true,
					'filename' => $tmp,
					'headers'  => array( 'Range' => 'bytes=' . $state['fetched'] . '-' . $end ),
				)
			);
			if ( is_wp_error( $response ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new RuntimeException( 'Download failed: ' . $response->get_error_message() );
			}
			$code = wp_remote_retrieve_response_code( $response );

			if ( 416 === $code && $state['total'] && $state['fetched'] >= $state['total'] ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$code = 0; // Already complete.
			} elseif ( 206 === $code ) {
				$range = wp_remote_retrieve_header( $response, 'content-range' );
				if ( preg_match( '~/(\d+)$~', (string) $range, $m ) ) {
					$state['total'] = (int) $m[1];
				}
			} elseif ( 200 === $code ) {
				if ( $state['fetched'] > 0 ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					throw new RuntimeException( 'The source server ignored the Range header mid-download. Upload the package manually instead.' );
				}
				$state['total'] = filesize( $tmp ); // The whole file arrived at once.
			} else {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new RuntimeException( sprintf( 'Download failed: HTTP %d. Check that the URL is correct and reachable from this server.', $code ) );
			}

			if ( $code ) {
				$in  = fopen( $tmp, 'rb' );
				$out = fopen( $package, 'ab' );
				$state['fetched'] += stream_copy_to_stream( $in, $out );
				fclose( $in );
				fclose( $out );
				unlink( $tmp );
			}

			$total           = max( 1, $state['total'] );
			$job['progress'] = array(
				'percent' => (int) floor( 100 * $state['fetched'] / $total ),
				'label'   => sprintf( __( 'Downloading… %1$s of %2$s', 'wordpress-multisite-exporter' ), size_format( $state['fetched'] ), size_format( $state['total'] ) ),
			);

			if ( $state['total'] && $state['fetched'] >= $state['total'] ) {
				MSE_Jobs::log( $job, sprintf( 'Download complete (%s).', size_format( $state['fetched'] ) ) );
				$state['phase'] = 'inspect';
				return;
			}
		}
	}

	/**
	 * Reads the manifest, runs the preflight check and waits for review.
	 */
	private static function inspect( array &$job ) {
		$manifest = self::read_manifest( $job['state']['package'] );
		$params   = array(
			'target_domain' => $manifest['source']['domain'],
			'mode'          => 'new',
		);
		if ( MSE_Preflight::find_site( $manifest['source']['domain'] ) ) {
			$params['mode'] = 'overwrite';
		}

		$job['title']  = $manifest['source']['domain'];
		$job['status'] = 'review';
		$job['result'] = array(
			'source'    => array(
				'domain'   => $manifest['source']['domain'],
				'blogname' => $manifest['source']['blogname'],
				'old_hosts' => isset( $manifest['source']['old_hosts'] ) ? $manifest['source']['old_hosts'] : array(),
				'blog_id'  => $manifest['source']['blog_id'],
				'network'  => $manifest['source']['network_domain'],
				'created'  => $manifest['created'],
				'theme'    => $manifest['theme']['name'],
				'theme_missing' => (bool) MSE_Preflight::missing_themes( $manifest ),
				'plugins'  => count( $manifest['plugins'] ),
				'tables'   => count( $manifest['tables'] ),
				'rows'     => array_sum( wp_list_pluck( $manifest['tables'], 'rows' ) ),
				'files'    => $manifest['files'],
			),
			'defaults'  => $params,
			'preflight' => MSE_Preflight::run( $manifest, $params, $job['state']['package'] ),
		);
		$job['progress'] = array( 'percent' => 0, 'label' => __( 'Waiting for review', 'wordpress-multisite-exporter' ) );
		MSE_Jobs::log( $job, sprintf( 'Package for %s inspected; waiting for review.', $manifest['source']['domain'] ) );
	}

	/**
	 * Creates the target site (or resolves the one to overwrite) and builds
	 * the replacement rules.
	 */
	private static function setup_site( array &$job ) {
		global $wpdb;
		$manifest = self::read_manifest( $job['state']['package'] );
		$domain   = $job['params']['target_domain'];
		$existing = MSE_Preflight::find_site( $domain );

		// A site this job created before an interrupted step is reused, so
		// Retry works instead of failing with "domain already exists".
		$own = $existing && ( ( isset( $job['state']['creating'] ) && $job['state']['creating'] === $domain ) || strtotime( $existing->registered . ' UTC' ) >= $job['created'] );

		if ( $existing && $own && 'new' === $job['params']['mode'] ) {
			$blog_id = (int) $existing->blog_id;
			MSE_Jobs::log( $job, sprintf( 'Reusing site %s (ID %d) created by this import.', $domain, $blog_id ) );
		} elseif ( $existing ) {
			if ( 'overwrite' !== $job['params']['mode'] ) {
				throw new RuntimeException( sprintf( 'A site with the domain %s already exists. Choose "overwrite" to replace it.', $domain ) );
			}
			if ( is_main_site( $existing->blog_id ) ) {
				throw new RuntimeException( 'Importing over the network\'s main site is not supported.' );
			}
			$blog_id = (int) $existing->blog_id;
			MSE_Jobs::log( $job, sprintf( 'Overwriting existing site %s (ID %d).', $domain, $blog_id ) );
		} else {
			$job['state']['creating'] = $domain;
			MSE_Jobs::save( $job );
			$blog_id = wp_insert_site(
				array(
					'domain'     => $domain,
					'path'       => '/',
					'network_id' => get_current_network_id(),
					'title'      => $manifest['source']['blogname'],
					'user_id'    => get_current_user_id(),
				)
			);
			if ( is_wp_error( $blog_id ) ) {
				throw new RuntimeException( 'Could not create the site: ' . $blog_id->get_error_message() );
			}
			MSE_Jobs::log( $job, sprintf( 'Created site %s (ID %d).', $domain, $blog_id ) );
		}

		switch_to_blog( $blog_id );
		$upload = wp_upload_dir( null, true, true );
		restore_current_blog();

		$src         = $manifest['source'];
		$scheme      = 0 === strpos( $src['home'], 'http://' ) ? 'http' : 'https';
		$target_home = $scheme . '://' . $domain;
		$target_up   = untrailingslashit( (string) wp_parse_url( $upload['baseurl'], PHP_URL_PATH ) );

		// Replacement rules, most specific first. Stored so a resumed job
		// uses exactly the same rules.
		$rules     = array();
		foreach ( $job['params']['extra_pairs'] as $pair ) {
			// Bare domains get the host-boundary matcher so "a.com" leaves "a.com.au" alone.
			$is_host = preg_match( '/^[a-z0-9\-]+(\.[a-z0-9\-]+)+$/i', $pair[0] );
			$rules[] = array( $is_host ? 'host' : 'str', $pair[0], $pair[1] );
		}
		foreach ( isset( $job['params']['old_hosts'] ) ? $job['params']['old_hosts'] : array() as $old ) {
			$rules[] = array( 'url', $old, $domain );
		}
		$src_host = $src['domain'] . untrailingslashit( $src['path'] );
		$hosts    = array_unique( array( $src_host, (string) wp_parse_url( $src['home'], PHP_URL_HOST ) . untrailingslashit( (string) wp_parse_url( $src['home'], PHP_URL_PATH ) ) ) );
		if ( $src['upload_path'] !== $target_up ) {
			foreach ( $hosts as $host ) {
				$rules[] = array( 'url_literal', $host . $src['upload_path'] . '/', $domain . $target_up . '/' );
			}
			if ( ! $src['is_main_site'] ) {
				$rules[] = array( 'str', $src['upload_path'] . '/', $target_up . '/' );
				$rules[] = array( 'str', str_replace( '/', '\\/', $src['upload_path'] . '/' ), str_replace( '/', '\\/', $target_up . '/' ) );
			}
		}
		$rules[] = array( 'str', $src['upload_basedir'], wp_normalize_path( $upload['basedir'] ) );
		$rules[] = array( 'str', $src['content_dir'], wp_normalize_path( WP_CONTENT_DIR ) );
		$rules[] = array( 'str', $src['abspath'], untrailingslashit( wp_normalize_path( ABSPATH ) ) );
		foreach ( $hosts as $host ) {
			$rules[] = array( 'url', $host, $domain );
		}

		$state                  = &$job['state'];
		$state['blog_id']       = $blog_id;
		$state['prefix']        = $wpdb->get_blog_prefix( $blog_id );
		$state['src_prefix']    = $src['blog_prefix'];
		$state['target_home']   = $target_home;
		$state['upload_dir']    = wp_normalize_path( $upload['basedir'] );
		$state['rules']         = $rules;
		$state['packet']        = max( 262144, min( 4194304, (int) ( $wpdb->get_var( 'SELECT @@max_allowed_packet' ) / 2 ) ) );
		$state['offset']        = 0;
		$state['current']       = null;
		$state['user_map']      = array();
		$state['user_idx']      = 0;
		$state['stats']         = array( 'rows' => 0, 'files' => 0, 'bytes' => 0, 'skipped' => 0, 'users_new' => 0, 'users_matched' => 0 );
		$state['phase']         = 'tables';
		$job['result']['target'] = array(
			'blog_id' => $blog_id,
			'url'     => $target_home,
		);

		$desc = array_map(
			function ( $p ) {
				return $p[0] . ' → ' . $p[1];
			},
			self::replacer( $state )->describe()
		);
		MSE_Jobs::log( $job, "Replacements:\n" . implode( "\n", $desc ) );
		self::progress( $job, 2, __( 'Creating tables…', 'wordpress-multisite-exporter' ) );
	}

	private static function create_tables( array &$job ) {
		global $wpdb;
		$state    = &$job['state'];
		$manifest = self::read_manifest( $state['package'] );
		self::prepare_session();

		$map = array();
		foreach ( $manifest['tables'] as $t ) {
			self::assert_table_name( $t['name'] );
			$map[ '`' . $state['src_prefix'] . $t['name'] . '`' ] = '`' . $state['prefix'] . $t['name'] . '`';
		}

		foreach ( $manifest['tables'] as $t ) {
			$target = $state['prefix'] . $t['name'];
			$create = strtr( $t['create'], $map );
			if ( 0 !== stripos( ltrim( $create ), 'CREATE TABLE `' . $target . '`' ) ) {
				throw new RuntimeException( sprintf( 'Unexpected table definition for %s.', $t['name'] ) );
			}
			$wpdb->query( "DROP TABLE IF EXISTS `$target`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $create ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( $wpdb->last_error ) {
				// Typical cause: MySQL 8 collations on MariaDB / older MySQL.
				$fallback = preg_replace(
					array( '/utf8mb4_0900_\w+/i', '/\butf8mb3\b/i' ),
					array( 'utf8mb4_unicode_520_ci', 'utf8' ),
					$create
				);
				$wpdb->query( $fallback ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				if ( $wpdb->last_error ) {
					throw new RuntimeException( sprintf( 'Could not create table %s: %s', $target, $wpdb->last_error ) );
				}
				MSE_Jobs::log( $job, sprintf( 'Table %s created with adjusted collation.', $target ), 'warning' );
			}
		}

		MSE_Jobs::log( $job, sprintf( 'Created %d tables.', count( $manifest['tables'] ) ) );
		$state['offset'] = self::first_entry_after_manifest( $state['package'] );
		$state['phase']  = 'entries';
		$state['rows_total'] = array_sum( wp_list_pluck( $manifest['tables'], 'rows' ) );
		$state['bytes_total'] = isset( $manifest['files']['bytes'] ) ? (int) $manifest['files']['bytes'] : 0;
		$state['files_total'] = isset( $manifest['files']['count'] ) ? (int) $manifest['files']['count'] : 0;
		$state['tables']      = wp_list_pluck( $manifest['tables'], 'name' );
		self::progress( $job, 5, __( 'Importing users…', 'wordpress-multisite-exporter' ) );
	}

	/**
	 * Processes package entries sequentially from the saved offset.
	 */
	private static function entries( array &$job, $deadline ) {
		$state = &$job['state'];
		$fh    = fopen( $state['package'], 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Cannot open the package file.' );
		}
		self::prepare_session();
		$replacer = self::replacer( $state );

		try {
			while ( microtime( true ) < $deadline ) {
				$entry = MSE_Tar::read_entry( $fh, $state['offset'] );
				if ( null === $entry ) {
					$state['phase'] = 'finalize';
					break;
				}
				$name = $entry['name'];

				if ( 'users.json' === $name ) {
					if ( ! self::import_users( $job, json_decode( MSE_Tar::read_data( $fh, $entry ), true ), $replacer, $deadline ) ) {
						break; // More users to process next step.
					}
				} elseif ( 0 === strpos( $name, 'db/' ) ) {
					self::import_rows( $job, $name, MSE_Tar::read_data( $fh, $entry ), $replacer );
				} elseif ( 0 === strpos( $name, 'files/' ) ) {
					if ( ! self::import_file( $job, $fh, $entry, $deadline ) ) {
						break; // Large file continues next step.
					}
				} elseif ( 'end.json' === $name ) {
					$state['complete'] = true;
				}
				$state['offset'] = $entry['next_offset'];
			}
		} finally {
			fclose( $fh );
		}

		$stats = $state['stats'];
		if ( $stats['files'] || $stats['bytes'] ) {
			$pct   = 40 + 58 * ( $state['bytes_total'] ? min( 1, $stats['bytes'] / $state['bytes_total'] ) : 1 );
			$label = sprintf( __( 'Copying media… %1$s / %2$s files', 'wordpress-multisite-exporter' ), number_format_i18n( $stats['files'] ), number_format_i18n( $state['files_total'] ) );
		} else {
			$pct   = 5 + ( $state['bytes_total'] ? 35 : 93 ) * min( 1, $stats['rows'] / max( 1, $state['rows_total'] ) );
			$label = sprintf( __( 'Importing database… %1$s / %2$s rows', 'wordpress-multisite-exporter' ), number_format_i18n( $stats['rows'] ), number_format_i18n( $state['rows_total'] ) );
		}
		self::progress( $job, $pct, $label );
	}

	/**
	 * Maps users by e-mail (then login), creating missing ones with their
	 * original password hash, and writes this site's role meta.
	 *
	 * @return bool True when all users are done.
	 */
	private static function import_users( array &$job, $data, MSE_Replacer $replacer, $deadline ) {
		global $wpdb;
		$state = &$job['state'];
		if ( ! is_array( $data ) || ! isset( $data['users'] ) ) {
			throw new RuntimeException( 'users.json is invalid.' );
		}
		$users = $data['users'];

		while ( $state['user_idx'] < count( $users ) ) {
			if ( microtime( true ) >= $deadline ) {
				return false;
			}
			$u      = $users[ $state['user_idx'] ];
			$row    = $u['user'];
			$old_id = (int) $row['ID'];

			$existing = $row['user_email'] ? get_user_by( 'email', $row['user_email'] ) : false;
			if ( ! $existing ) {
				$by_login = get_user_by( 'login', $row['user_login'] );
				if ( $by_login && ! $row['user_email'] ) {
					$existing = $by_login;
				}
			}

			if ( $existing ) {
				$new_id = (int) $existing->ID;
				$state['stats']['users_matched']++;
			} else {
				$login = $row['user_login'];
				for ( $i = 2; username_exists( $login ); $i++ ) {
					$login = $row['user_login'] . '-' . $i;
				}
				if ( $login !== $row['user_login'] ) {
					MSE_Jobs::log( $job, sprintf( 'Login "%s" is taken by a user with another e-mail; created "%s" for %s.', $row['user_login'], $login, $row['user_email'] ), 'warning' );
				}
				$nicename = $row['user_nicename'] ? $row['user_nicename'] : sanitize_title( $login );
				$base     = $nicename;
				for ( $i = 2; $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->users} WHERE user_nicename = %s", $nicename ) ); $i++ ) {
					$nicename = $base . '-' . $i;
				}
				$inserted = $wpdb->insert(
					$wpdb->users,
					array(
						'user_login'      => $login,
						'user_pass'       => $row['user_pass'],
						'user_nicename'   => $nicename,
						'user_email'      => $row['user_email'],
						'user_url'        => $replacer->replace( $row['user_url'] ),
						'user_registered' => $row['user_registered'],
						'user_status'     => (int) $row['user_status'],
						'display_name'    => $row['display_name'],
					)
				);
				if ( ! $inserted ) {
					throw new RuntimeException( sprintf( 'Could not create user %s: %s', $login, $wpdb->last_error ) );
				}
				$new_id = (int) $wpdb->insert_id;
				foreach ( $u['meta'] as $m ) {
					$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $new_id, 'meta_key' => $m[0], 'meta_value' => $replacer->replace( $m[1] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				}
				$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $new_id, 'meta_key' => 'primary_blog', 'meta_value' => $state['blog_id'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $new_id, 'meta_key' => 'source_domain', 'meta_value' => $job['params']['target_domain'] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$state['stats']['users_new']++;
			}

			foreach ( $u['site_meta'] as $m ) {
				$key = $state['prefix'] . $m[0];
				$wpdb->delete( $wpdb->usermeta, array( 'user_id' => $new_id, 'meta_key' => $key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
				$wpdb->insert( $wpdb->usermeta, array( 'user_id' => $new_id, 'meta_key' => $key, 'meta_value' => $replacer->replace( $m[1] ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
			}
			clean_user_cache( $new_id );

			$state['user_map'][ $old_id ] = $new_id;
			$state['user_idx']++;
		}

		MSE_Jobs::log( $job, sprintf( 'Users: %d matched to existing accounts, %d created.', $state['stats']['users_matched'], $state['stats']['users_new'] ) );
		return true;
	}

	/**
	 * Inserts one batch of rows with replacements applied.
	 */
	private static function import_rows( array &$job, $name, $gz, MSE_Replacer $replacer ) {
		global $wpdb;
		$state = &$job['state'];

		if ( ! preg_match( '~^db/([A-Za-z0-9_\-]+)/\d+\.json\.gz$~', $name, $m ) || ! in_array( $m[1], $state['tables'], true ) ) {
			throw new RuntimeException( sprintf( 'Unexpected database entry %s.', $name ) );
		}
		$table = $state['prefix'] . $m[1];
		$json  = gzdecode( $gz );
		$data  = false === $json ? null : json_decode( $json, true );
		if ( ! is_array( $data ) || empty( $data['cols'] ) ) {
			throw new RuntimeException( sprintf( 'Corrupt database entry %s.', $name ) );
		}

		$cols    = $data['cols'];
		$skip    = array();
		$rename  = null;
		if ( 'posts' === $m[1] && $job['params']['skip_guids'] ) {
			$skip[] = array_search( 'guid', $cols, true );
		}
		if ( 'options' === $m[1] ) {
			$rename = array_search( 'option_name', $cols, true );
		}

		$head  = "REPLACE INTO `$table` (" . implode( ', ', array_map( function ( $c ) {
			return '`' . str_replace( '`', '', $c ) . '`';
		}, $cols ) ) . ') VALUES ';
		$batch = array();
		$size  = 0;

		foreach ( $data['rows'] as $row ) {
			$values = array();
			foreach ( $row as $i => $value ) {
				if ( null === $value ) {
					$values[] = 'NULL';
					continue;
				}
				if ( is_array( $value ) ) {
					$bin      = base64_decode( $value['b'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
					$values[] = '' === $bin ? "''" : '0x' . bin2hex( $bin );
					continue;
				}
				if ( $i === $rename && 0 === strpos( $value, $state['src_prefix'] ) && $state['src_prefix'] !== $state['prefix'] ) {
					// e.g. wp_17_user_roles → wp_42_user_roles.
					$value = $state['prefix'] . substr( $value, strlen( $state['src_prefix'] ) );
				} elseif ( ! in_array( $i, $skip, true ) ) {
					$value = $replacer->replace( $value );
				}
				$values[] = "'" . $wpdb->_real_escape( $value ) . "'";
			}
			$sql     = '(' . implode( ',', $values ) . ')';
			$batch[] = $sql;
			$size   += strlen( $sql );
			if ( $size >= $state['packet'] ) {
				self::insert( $head, $batch, $table );
				$batch = array();
				$size  = 0;
			}
		}
		if ( $batch ) {
			self::insert( $head, $batch, $table );
		}
		$state['stats']['rows'] += count( $data['rows'] );
	}

	private static function insert( $head, array $batch, $table ) {
		global $wpdb;
		$sql                       = $wpdb->remove_placeholder_escape( $head . implode( ',', $batch ) );
		$wpdb->check_current_query = false;
		$wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $wpdb->last_error ) {
			throw new RuntimeException( sprintf( 'Database error importing into %s: %s', $table, $wpdb->last_error ) );
		}
	}

	/**
	 * Copies one file entry into the site's uploads folder (resumable).
	 *
	 * @return bool True when the file is complete.
	 */
	private static function import_file( array &$job, $fh, array $entry, $deadline ) {
		$state = &$job['state'];
		$rel   = substr( $entry['name'], 6 );
		$parts = explode( '/', str_replace( '\\', '/', $rel ) );

		$unsafe = '' === $rel || '/' === $rel[0] || in_array( '..', $parts, true ) || in_array( '', $parts, true ) || preg_match( '/[\x00-\x1f]/', $rel );
		$blocked = apply_filters( 'mse_import_blocked_file', (bool) preg_match( '/\.(php\d?|phtml|phar|pht|phps|cgi|pl|sh)$/i', $rel ), $rel );
		if ( $unsafe || $blocked ) {
			MSE_Jobs::log( $job, sprintf( 'Skipped file %s (%s).', $rel, $unsafe ? 'unsafe path' : 'executable file type' ), 'warning' );
			$state['stats']['skipped']++;
			return true;
		}

		$dest = $state['upload_dir'] . '/' . $rel;
		$cur  = $state['current'];
		if ( ! $cur || $cur['offset'] !== $entry['data_offset'] ) {
			if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
				throw new RuntimeException( 'Cannot create folder ' . dirname( $dest ) );
			}
			$cur = array(
				'offset'  => $entry['data_offset'],
				'written' => 0,
			);
		}

		$out = fopen( $dest, 'c+b' );
		if ( ! $out ) {
			throw new RuntimeException( 'Cannot write ' . $dest );
		}
		ftruncate( $out, $cur['written'] );
		fseek( $out, $cur['written'] );
		fseek( $fh, $entry['data_offset'] + $cur['written'] );

		while ( $cur['written'] < $entry['size'] ) {
			$data = fread( $fh, min( self::FILE_CHUNK, $entry['size'] - $cur['written'] ) );
			if ( false === $data || '' === $data ) {
				fclose( $out );
				throw new RuntimeException( 'Unexpected end of package while copying ' . $rel );
			}
			fwrite( $out, $data );
			$cur['written']           += strlen( $data );
			$state['stats']['bytes'] += strlen( $data );
			if ( $cur['written'] < $entry['size'] && microtime( true ) >= $deadline ) {
				fclose( $out );
				$state['current'] = $cur;
				return false;
			}
		}
		fclose( $out );
		$state['current'] = null;
		$state['stats']['files']++;
		return true;
	}

	/**
	 * Remaps author IDs, fixes core options and clears caches.
	 */
	private static function finalize( array &$job ) {
		global $wpdb;
		$state    = &$job['state'];
		$manifest = self::read_manifest( $state['package'] );
		$p        = $state['prefix'];
		$blog_id  = $state['blog_id'];
		$tables   = $state['tables'];

		if ( empty( $state['complete'] ) ) {
			MSE_Jobs::log( $job, 'The package has no end marker; it may have been exported incompletely.', 'warning' );
		}

		// Remap user IDs in one statement per column so chains (5→7, 7→9) are safe.
		$map = array_filter(
			$state['user_map'],
			function ( $new, $old ) {
				return (int) $new !== (int) $old;
			},
			ARRAY_FILTER_USE_BOTH
		);
		if ( $map ) {
			$case = 'CASE %s';
			foreach ( $map as $old => $new ) {
				$case .= sprintf( ' WHEN %d THEN %d', $old, $new );
			}
			$case .= ' ELSE %s END';
			$in    = implode( ',', array_map( 'intval', array_keys( $map ) ) );
			$cols  = array(
				'posts'    => 'post_author',
				'comments' => 'user_id',
				'links'    => 'link_owner',
			);
			foreach ( $cols as $table => $col ) {
				if ( in_array( $table, $tables, true ) ) {
					$wpdb->query( "UPDATE `{$p}{$table}` SET `$col` = " . sprintf( $case, "`$col`", "`$col`" ) . " WHERE `$col` IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				}
			}
			if ( in_array( 'postmeta', $tables, true ) ) {
				$wpdb->query( "UPDATE `{$p}postmeta` SET meta_value = " . sprintf( $case, 'meta_value', 'meta_value' ) . " WHERE meta_key = '_edit_last' AND meta_value IN ($in)" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			}
			MSE_Jobs::log( $job, sprintf( 'Remapped %d user IDs in content.', count( $map ) ) );
		}

		if ( in_array( 'postmeta', $tables, true ) ) {
			// Edit locks reference source user IDs; Elementor CSS is regenerated on demand.
			$wpdb->query( "DELETE FROM `{$p}postmeta` WHERE meta_key IN ('_edit_lock', '_elementor_css')" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( in_array( 'options', $tables, true ) ) {
			$opts = "`{$p}options`";
			foreach ( array( 'siteurl', 'home' ) as $name ) {
				$wpdb->query( $wpdb->prepare( "UPDATE $opts SET option_value = %s WHERE option_name = %s", $state['target_home'], $name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			$wpdb->query( "DELETE FROM $opts WHERE option_name IN ('rewrite_rules', '_elementor_global_css', '_elementor_assets_data') OR option_name LIKE '\\_transient\\_%' OR option_name LIKE '\\_site\\_transient\\_%'" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		if ( ! empty( $job['params']['theme'] ) && in_array( 'options', $tables, true ) ) {
			self::apply_theme( $job, $p . 'options', wp_get_theme( $job['params']['theme'] ) );
		}

		$flags = $manifest['source']['flags'];
		update_blog_details(
			$blog_id,
			array(
				'domain'       => $job['params']['target_domain'],
				'path'         => '/',
				'public'       => $flags['public'],
				'archived'     => $flags['archived'],
				'mature'       => $flags['mature'],
				'spam'         => $flags['spam'],
				'deleted'      => $flags['deleted'],
				'lang_id'      => $flags['lang_id'],
				'last_updated' => current_time( 'mysql', true ),
			)
		);

		clean_blog_cache( $blog_id );
		switch_to_blog( $blog_id );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		restore_current_blog();
		if ( wp_using_ext_object_cache() ) {
			wp_cache_flush();
		}

		$s             = $state['stats'];
		$job['status'] = 'done';
		$job['result']['target']['admin'] = get_admin_url( $blog_id );
		$job['result']['stats']           = $s;
		MSE_Jobs::log(
			$job,
			sprintf(
				'Import finished: %s rows, %s files (%s), %d skipped files. Site: %s',
				number_format_i18n( $s['rows'] ),
				number_format_i18n( $s['files'] ),
				size_format( $s['bytes'] ),
				$s['skipped'],
				$state['target_home']
			)
		);
		self::progress( $job, 100, __( 'Import complete', 'wordpress-multisite-exporter' ) );
	}

	// ------------------------------------------------------------ Helpers.

	/**
	 * Activates a replacement theme on the imported site and allows it there
	 * when it is not network-enabled.
	 */
	private static function apply_theme( array &$job, $table, WP_Theme $theme ) {
		global $wpdb;
		if ( ! $theme->exists() ) {
			MSE_Jobs::log( $job, sprintf( 'Replacement theme "%s" no longer exists; the site keeps its original theme setting.', $job['params']['theme'] ), 'warning' );
			return;
		}
		$set = function ( $name, $value ) use ( $wpdb, $table ) {
			$wpdb->query( $wpdb->prepare( "INSERT INTO `$table` (option_name, option_value, autoload) VALUES (%s, %s, 'yes') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)", $name, $value ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		};
		$set( 'template', $theme->get_template() );
		$set( 'stylesheet', $theme->get_stylesheet() );
		$set( 'current_theme', $theme->get( 'Name' ) );

		if ( ! array_key_exists( $theme->get_stylesheet(), WP_Theme::get_allowed_on_network() ) ) {
			$allowed = maybe_unserialize( $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM `$table` WHERE option_name = %s", 'allowedthemes' ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$allowed = is_array( $allowed ) ? $allowed : array();
			$allowed[ $theme->get_stylesheet() ] = true;
			if ( $theme->get_template() !== $theme->get_stylesheet() ) {
				$allowed[ $theme->get_template() ] = true;
			}
			$set( 'allowedthemes', serialize( $allowed ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		}
		MSE_Jobs::log( $job, sprintf( 'Theme "%1$s" is missing here; activated "%2$s" instead.', $job['result']['source']['theme'] ?? '', $theme->get( 'Name' ) ) );
	}

	/**
	 * Reads and validates manifest.json (always the first entry).
	 *
	 * @param string $package Package path.
	 * @return array
	 */
	public static function read_manifest( $package ) {
		static $cache = array();
		if ( isset( $cache[ $package ] ) ) {
			return $cache[ $package ];
		}
		$fh = fopen( $package, 'rb' );
		if ( ! $fh ) {
			throw new RuntimeException( 'Cannot open the package file.' );
		}
		try {
			$entry = MSE_Tar::read_entry( $fh, 0 );
			if ( ! $entry || 'manifest.json' !== $entry['name'] ) {
				throw new RuntimeException( 'This file is not a Multisite Exporter package (manifest.json missing).' );
			}
			$manifest = json_decode( MSE_Tar::read_data( $fh, $entry ), true );
		} finally {
			fclose( $fh );
		}
		if ( ! is_array( $manifest ) || empty( $manifest['source'] ) || empty( $manifest['format'] ) ) {
			throw new RuntimeException( 'The package manifest is invalid.' );
		}
		if ( (int) $manifest['format'] > MSE_Exporter::FORMAT ) {
			throw new RuntimeException( 'This package was created by a newer version of the plugin. Update the plugin on this network first.' );
		}
		$cache[ $package ] = $manifest;
		return $manifest;
	}

	private static function first_entry_after_manifest( $package ) {
		$fh    = fopen( $package, 'rb' );
		$entry = MSE_Tar::read_entry( $fh, 0 );
		fclose( $fh );
		return $entry['next_offset'];
	}

	/**
	 * @param array $state Job state with 'rules'.
	 * @return MSE_Replacer
	 */
	private static function replacer( array $state ) {
		$r = new MSE_Replacer();
		foreach ( $state['rules'] as $rule ) {
			list( $type, $from, $to ) = $rule;
			if ( 'url' === $type ) {
				$r->add_url( $from, $to, true );
			} elseif ( 'host' === $type ) {
				$r->add_host( $from, $to );
			} elseif ( 'url_literal' === $type ) {
				$r->add_url( $from, $to, false );
			} else {
				$r->add( $from, $to );
			}
		}
		return $r;
	}

	private static function prepare_session() {
		global $wpdb;
		$wpdb->query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		$wpdb->query( 'SET FOREIGN_KEY_CHECKS = 0' );
	}

	private static function assert_table_name( $name ) {
		if ( ! preg_match( '/^[A-Za-z0-9_\-]+$/', $name ) || in_array( $name, MSE_Exporter::global_tables(), true ) ) {
			throw new RuntimeException( sprintf( 'Refusing to import table "%s".', $name ) );
		}
	}

	private static function progress( array &$job, $percent, $label ) {
		$job['progress'] = array(
			'percent' => (int) round( $percent ),
			'label'   => $label,
		);
	}
}
