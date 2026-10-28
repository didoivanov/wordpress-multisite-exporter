<?php
/**
 * Job persistence, storage folders and the time-boxed job runner.
 *
 * Every export/import is a job that advances in small steps driven by AJAX
 * "ticks" from the admin screen, so no single request runs long enough to
 * hit PHP or proxy timeouts.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Jobs {

	const INDEX_OPTION = 'mse_job_ids';
	const LOCK         = 'mse_runner_lock';
	const LOG_LIMIT    = 300;

	/** Statuses the runner will advance automatically. */
	const RUNNABLE = array( 'preparing', 'queued', 'running' );

	// ------------------------------------------------------------ Storage.

	/**
	 * Root folder for packages: wp-content/mse-packages.
	 *
	 * @return string
	 */
	public static function base_dir() {
		return apply_filters( 'mse_base_dir', WP_CONTENT_DIR . '/mse-packages' );
	}

	/**
	 * Public folder for finished exports. Files have unguessable names so
	 * another network can download them by URL.
	 *
	 * @return string
	 */
	public static function exports_dir() {
		return self::base_dir() . '/exports';
	}

	/**
	 * @return string
	 */
	public static function exports_url() {
		return apply_filters( 'mse_exports_url', content_url( 'mse-packages/exports' ) );
	}

	/**
	 * Private folder (random name, web access denied) for incoming packages
	 * and job working files.
	 *
	 * @return string
	 */
	public static function private_dir() {
		$token = get_site_option( 'mse_private_token' );
		if ( ! $token ) {
			$token = strtolower( wp_generate_password( 20, false ) );
			update_site_option( 'mse_private_token', $token );
		}
		return self::base_dir() . '/private-' . $token;
	}

	/**
	 * Folder an admin can drop .tar packages into via FTP/file manager.
	 *
	 * @return string
	 */
	public static function imports_dir() {
		return self::private_dir() . '/imports';
	}

	/**
	 * @param string $job_id Job ID.
	 * @return string
	 */
	public static function work_dir( $job_id ) {
		return self::private_dir() . '/work/' . $job_id;
	}

	/**
	 * Creates storage folders with protective index/.htaccess files.
	 */
	public static function ensure_dirs() {
		$deny = "# Multisite Exporter: no direct access.\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		// Public folders get no .htaccess: "Options" there causes HTTP 500 on
		// hosts without AllowOverride Options. index.php already blocks listings.
		$dirs = array(
			self::base_dir()              => '',
			self::exports_dir()           => '',
			self::private_dir()           => $deny,
			self::imports_dir()           => $deny,
			self::private_dir() . '/work' => $deny,
		);
		foreach ( $dirs as $dir => $htaccess ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new RuntimeException( sprintf( 'Cannot create folder %s. Check that wp-content is writable.', $dir ) );
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
			if ( $htaccess && ! file_exists( $dir . '/.htaccess' ) ) {
				file_put_contents( $dir . '/.htaccess', $htaccess );
			}
		}
	}

	/**
	 * Recursively deletes a folder inside the plugin's storage.
	 *
	 * @param string $dir Folder.
	 */
	public static function rrmdir( $dir ) {
		if ( ! $dir || ! is_dir( $dir ) || 0 !== strpos( wp_normalize_path( $dir ), wp_normalize_path( self::base_dir() ) ) ) {
			return;
		}
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() && ! $item->isLink() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	// --------------------------------------------------------------- Jobs.

	/**
	 * @param string $type   'export' or 'import'.
	 * @param string $title  Display title.
	 * @param array  $params Job parameters.
	 * @param string $status Initial status.
	 * @return array
	 */
	public static function create( $type, $title, array $params, $status ) {
		$job = array(
			'id'       => strtolower( wp_generate_password( 12, false ) ),
			'type'     => $type,
			'title'    => $title,
			'status'   => $status,
			'created'  => time(),
			'updated'  => time(),
			'params'   => $params,
			'state'    => array(),
			'progress' => array( 'percent' => 0, 'label' => __( 'Waiting…', 'wordpress-multisite-exporter' ) ),
			'result'   => array(),
			'error'    => '',
			'log'      => array(),
		);
		$ids   = self::ids();
		$ids[] = $job['id'];
		update_site_option( self::INDEX_OPTION, $ids );
		self::save( $job );
		return $job;
	}

	/**
	 * @return string[]
	 */
	public static function ids() {
		$ids = get_site_option( self::INDEX_OPTION, array() );
		return is_array( $ids ) ? array_values( $ids ) : array();
	}

	/**
	 * @param string $id Job ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		if ( ! preg_match( '/^[a-z0-9]{12}$/', (string) $id ) ) {
			return null;
		}
		$job = get_site_option( 'mse_job_' . $id );
		return is_array( $job ) ? $job : null;
	}

	/**
	 * @return array[]
	 */
	public static function all() {
		return array_values( array_filter( array_map( array( __CLASS__, 'get' ), self::ids() ) ) );
	}

	/**
	 * @param array $job Job.
	 */
	public static function save( array $job ) {
		$job['updated'] = time();
		update_site_option( 'mse_job_' . $job['id'], $job );
	}

	/**
	 * Deletes a job, its working files and (optionally) its package.
	 *
	 * @param string $id             Job ID.
	 * @param bool   $delete_package Remove the package file too.
	 */
	public static function delete( $id, $delete_package = true ) {
		$job = self::get( $id );
		if ( ! $job ) {
			return;
		}
		self::rrmdir( self::work_dir( $id ) );
		$package = isset( $job['state']['package'] ) ? $job['state']['package'] : '';
		if ( $delete_package && $package && is_file( $package ) && 0 === strpos( wp_normalize_path( $package ), wp_normalize_path( self::base_dir() ) ) ) {
			unlink( $package );
		}
		delete_site_option( 'mse_job_' . $id );
		update_site_option( self::INDEX_OPTION, array_values( array_diff( self::ids(), array( $id ) ) ) );
	}

	/**
	 * Appends a line to the job log.
	 *
	 * @param array  $job     Job (by reference).
	 * @param string $message Message.
	 * @param string $level   info|warning|error.
	 */
	public static function log( array &$job, $message, $level = 'info' ) {
		$job['log'][] = array( time(), $level, $message );
		if ( count( $job['log'] ) > self::LOG_LIMIT ) {
			$job['log'] = array_merge( array_slice( $job['log'], 0, 20 ), array_slice( $job['log'], -( self::LOG_LIMIT - 20 ) ) );
		}
	}

	/**
	 * Compact representation for the admin UI.
	 *
	 * @param array $job Job.
	 * @return array
	 */
	public static function summary( array $job ) {
		$out = array_intersect_key( $job, array_flip( array( 'id', 'type', 'title', 'status', 'created', 'updated', 'progress', 'result', 'error', 'params' ) ) );
		$out['log'] = array_slice( $job['log'], -8 );
		return $out;
	}

	// ------------------------------------------------------------- Runner.

	/**
	 * Seconds of work per AJAX request.
	 *
	 * @return int
	 */
	public static function time_budget() {
		$budget = 15;
		$max    = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 ) {
			$budget = min( $budget, (int) floor( $max * 0.5 ) );
		}
		return (float) apply_filters( 'mse_time_budget', max( 3, $budget ) );
	}

	/**
	 * Advances the first runnable job for up to the time budget.
	 *
	 * @return array{busy:bool, more:bool}
	 */
	public static function tick() {
		$budget = self::time_budget();
		$lock   = (int) get_site_transient( self::LOCK );
		if ( $lock && $lock > time() - ( $budget + 60 ) ) {
			return array( 'busy' => true, 'more' => true );
		}
		set_site_transient( self::LOCK, time(), $budget + 120 );

		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( $budget + 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_raise_memory_limit( 'admin' );

		$deadline = microtime( true ) + $budget;
		$job      = self::next_runnable();

		while ( $job && microtime( true ) < $deadline ) {
			try {
				if ( 'queued' === $job['status'] ) {
					$job['status'] = 'running';
				}
				if ( 'export' === $job['type'] ) {
					MSE_Exporter::step( $job, $deadline );
				} else {
					MSE_Importer::step( $job, $deadline );
				}
			} catch ( Throwable $e ) {
				$job['status'] = 'error';
				$job['error']  = $e->getMessage();
				self::log( $job, $e->getMessage(), 'error' );
			}
			self::save( $job );

			if ( ! in_array( $job['status'], self::RUNNABLE, true ) ) {
				$job = self::next_runnable();
			}
		}

		delete_site_transient( self::LOCK );
		return array( 'busy' => false, 'more' => (bool) self::next_runnable() );
	}

	/**
	 * @return array|null
	 */
	private static function next_runnable() {
		foreach ( self::all() as $job ) {
			if ( in_array( $job['status'], self::RUNNABLE, true ) ) {
				return $job;
			}
		}
		return null;
	}
}
