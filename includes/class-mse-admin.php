<?php
/**
 * Network Admin screen and AJAX endpoints.
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

class MSE_Admin {

	const CAP   = 'manage_network_options';
	const NONCE = 'mse-admin';

	public static function init() {
		add_action( 'network_admin_menu', array( __CLASS__, 'menu' ) );
		$actions = array( 'state', 'tick', 'export', 'import_urls', 'import_local', 'upload_start', 'upload_chunk', 'configure', 'job_action' );
		foreach ( $actions as $action ) {
			add_action( 'wp_ajax_mse_' . $action, array( __CLASS__, 'ajax' ) );
		}
	}

	public static function menu() {
		$hook = add_menu_page(
			__( 'Multisite Exporter', 'wordpress-multisite-exporter' ),
			__( 'Site Migrator', 'wordpress-multisite-exporter' ),
			self::CAP,
			'mse',
			array( __CLASS__, 'render' ),
			'dashicons-migrate',
			30
		);
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'mse-admin', MSE_URL . 'assets/admin.css', array(), MSE_VERSION );
		wp_enqueue_script( 'mse-admin', MSE_URL . 'assets/admin.js', array(), MSE_VERSION, true );
		wp_localize_script(
			'mse-admin',
			'MSE',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'chunkSize' => self::chunk_size(),
				'themes'    => array_map(
					function ( $theme ) {
						return $theme->get( 'Name' );
					},
					wp_get_themes()
				),
			)
		);
	}

	/**
	 * Upload chunk size: 5 MB, or less if PHP's upload limit is lower.
	 *
	 * @return int
	 */
	private static function chunk_size() {
		$max = (int) wp_max_upload_size();
		return (int) max( 262144, min( 5242880, $max - 131072 ) );
	}

	// -------------------------------------------------------------- Screen.

	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'wordpress-multisite-exporter' ) );
		}
		$dir_error = '';
		try {
			MSE_Jobs::ensure_dirs();
		} catch ( Exception $e ) {
			$dir_error = $e->getMessage();
		}
		$sites   = get_sites( array( 'number' => 0, 'network_id' => get_current_network_id(), 'orderby' => 'domain' ) );
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$release = MSE_Updater::release();
		$local   = self::local_packages();
		?>
		<div class="wrap mse">
			<h1><?php esc_html_e( 'Multisite Exporter', 'wordpress-multisite-exporter' ); ?> <span class="mse-version">v<?php echo esc_html( MSE_VERSION ); ?></span></h1>

			<?php if ( $dir_error ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $dir_error ); ?></p></div>
			<?php endif; ?>
			<?php if ( $release && version_compare( $release['version'], MSE_VERSION, '>' ) ) : ?>
				<div class="notice notice-info"><p>
					<?php
					printf(
						/* translators: 1: version, 2: link */
						esc_html__( 'Version %1$s is available. %2$s', 'wordpress-multisite-exporter' ),
						esc_html( $release['version'] ),
						'<a href="' . esc_url( network_admin_url( 'plugins.php?plugin_status=upgrade' ) ) . '">' . esc_html__( 'Update now', 'wordpress-multisite-exporter' ) . '</a>'
					);
					?>
				</p></div>
			<?php endif; ?>

			<div class="notice notice-warning inline mse-keep-open" hidden><p><strong><?php esc_html_e( 'Jobs are running. Keep this tab open: work only advances while this page is open.', 'wordpress-multisite-exporter' ); ?></strong></p></div>

			<nav class="nav-tab-wrapper">
				<a href="#export" class="nav-tab" data-tab="export"><?php esc_html_e( 'Export', 'wordpress-multisite-exporter' ); ?></a>
				<a href="#import" class="nav-tab" data-tab="import"><?php esc_html_e( 'Import', 'wordpress-multisite-exporter' ); ?></a>
			</nav>

			<section class="mse-tab" data-tab="export">
				<p class="description">
					<?php esc_html_e( 'Select sites to export. Each site becomes one package (.tar) with its database tables, users and media. Exports run one after another.', 'wordpress-multisite-exporter' ); ?>
					<?php if ( false !== $free ) : ?>
						<?php
						/* translators: %s: size */
						printf( esc_html__( 'Free disk space: %s.', 'wordpress-multisite-exporter' ), esc_html( size_format( $free ) ) );
						?>
					<?php endif; ?>
				</p>
				<div class="mse-toolbar">
					<input type="search" class="mse-filter" placeholder="<?php esc_attr_e( 'Filter sites…', 'wordpress-multisite-exporter' ); ?>">
					<label><input type="checkbox" class="mse-media" checked> <?php esc_html_e( 'Include media files', 'wordpress-multisite-exporter' ); ?></label>
					<button type="button" class="button button-primary mse-export-btn" disabled><?php esc_html_e( 'Export selected', 'wordpress-multisite-exporter' ); ?></button>
					<span class="mse-selected-count"></span>
				</div>
				<div class="mse-sites-wrap">
					<table class="widefat striped mse-sites">
						<thead><tr>
							<td class="check-column"><input type="checkbox" class="mse-check-all"></td>
							<th><?php esc_html_e( 'ID', 'wordpress-multisite-exporter' ); ?></th>
							<th><?php esc_html_e( 'Domain', 'wordpress-multisite-exporter' ); ?></th>
							<th><?php esc_html_e( 'Title', 'wordpress-multisite-exporter' ); ?></th>
							<th><?php esc_html_e( 'Last updated', 'wordpress-multisite-exporter' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $sites as $site ) : ?>
							<?php $details = get_blog_details( $site->blog_id ); ?>
							<tr data-search="<?php echo esc_attr( strtolower( $site->domain . $site->path . ' ' . $details->blogname . ' ' . $site->blog_id ) ); ?>">
								<th class="check-column"><input type="checkbox" class="mse-site" value="<?php echo esc_attr( $site->blog_id ); ?>"></th>
								<td><?php echo esc_html( $site->blog_id ); ?><?php echo is_main_site( $site->blog_id ) ? ' <span class="mse-badge">' . esc_html__( 'main', 'wordpress-multisite-exporter' ) . '</span>' : ''; ?></td>
								<td><?php echo esc_html( $site->domain . untrailingslashit( $site->path ) ); ?></td>
								<td><?php echo esc_html( $details->blogname ); ?></td>
								<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $site->last_updated ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<h2><?php esc_html_e( 'Export jobs', 'wordpress-multisite-exporter' ); ?></h2>
				<div class="mse-jobs" data-type="export"><p class="description"><?php esc_html_e( 'No exports yet.', 'wordpress-multisite-exporter' ); ?></p></div>
			</section>

			<section class="mse-tab" data-tab="import" hidden>
				<div class="mse-cards">
					<div class="mse-card">
						<h3><?php esc_html_e( '1. From URL (recommended)', 'wordpress-multisite-exporter' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Paste package URLs from the source network\'s Export tab, one per line. This server downloads them directly, so file size limits do not apply.', 'wordpress-multisite-exporter' ); ?></p>
						<textarea class="mse-urls large-text" rows="4" placeholder="https://cms1.com/wp-content/mse-packages/exports/….tar"></textarea>
						<p><button type="button" class="button button-primary mse-urls-btn"><?php esc_html_e( 'Add packages', 'wordpress-multisite-exporter' ); ?></button></p>
					</div>
					<div class="mse-card">
						<h3><?php esc_html_e( '2. Upload from your computer', 'wordpress-multisite-exporter' ); ?></h3>
						<p class="description">
							<?php
							/* translators: %s: size */
							printf( esc_html__( 'Files are sent in %s pieces, so large packages work despite PHP upload limits.', 'wordpress-multisite-exporter' ), esc_html( size_format( self::chunk_size() ) ) );
							?>
						</p>
						<input type="file" class="mse-file" accept=".tar" multiple>
						<p><button type="button" class="button mse-upload-btn"><?php esc_html_e( 'Upload', 'wordpress-multisite-exporter' ); ?></button></p>
					</div>
					<div class="mse-card">
						<h3><?php esc_html_e( '3. Already on this server', 'wordpress-multisite-exporter' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Packages copied (e.g. with a file manager) into:', 'wordpress-multisite-exporter' ); ?><br><code><?php echo esc_html( str_replace( ABSPATH, '', MSE_Jobs::imports_dir() ) ); ?></code></p>
						<?php if ( $local ) : ?>
							<ul class="mse-local">
								<?php foreach ( $local as $file => $size ) : ?>
									<li><code><?php echo esc_html( $file ); ?></code> (<?php echo esc_html( size_format( $size ) ); ?>) <button type="button" class="button button-small mse-local-btn" data-file="<?php echo esc_attr( $file ); ?>"><?php esc_html_e( 'Use', 'wordpress-multisite-exporter' ); ?></button></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							<p><em><?php esc_html_e( 'No unused packages found.', 'wordpress-multisite-exporter' ); ?></em></p>
						<?php endif; ?>
					</div>
				</div>
				<h2>
					<?php esc_html_e( 'Import jobs', 'wordpress-multisite-exporter' ); ?>
					<span class="mse-start-all-wrap" hidden>
						<button type="button" class="button mse-start-all"><?php esc_html_e( 'Start all ready imports with default settings', 'wordpress-multisite-exporter' ); ?></button>
						<label class="mse-all-theme-label"><?php esc_html_e( 'Replacement for missing themes:', 'wordpress-multisite-exporter' ); ?>
							<select class="mse-all-theme">
								<option value=""><?php esc_html_e( '— none (skip those imports) —', 'wordpress-multisite-exporter' ); ?></option>
								<?php foreach ( wp_get_themes() as $slug => $theme ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $theme->get( 'Name' ) ); ?></option>
								<?php endforeach; ?>
							</select>
						</label>
					</span>
				</h2>
				<div class="mse-jobs" data-type="import"><p class="description"><?php esc_html_e( 'No imports yet.', 'wordpress-multisite-exporter' ); ?></p></div>
			</section>

			<p class="mse-footer">
				<?php
				printf(
					/* translators: %s: GitHub link */
					esc_html__( 'Updates are delivered from %s.', 'wordpress-multisite-exporter' ),
					'<a href="https://github.com/' . esc_attr( MSE_Updater::REPO ) . '" target="_blank" rel="noopener">GitHub</a>'
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * .tar files in the imports folder not yet attached to a job.
	 *
	 * @return array filename => size
	 */
	private static function local_packages() {
		$used = array();
		foreach ( MSE_Jobs::all() as $job ) {
			if ( ! empty( $job['state']['package'] ) ) {
				$used[] = basename( $job['state']['package'] );
			}
		}
		$out = array();
		foreach ( (array) glob( MSE_Jobs::imports_dir() . '/*.tar' ) as $path ) {
			if ( $path && ! in_array( basename( $path ), $used, true ) ) {
				$out[ basename( $path ) ] = filesize( $path );
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------- AJAX.

	public static function ajax() {
		if ( ! current_user_can( self::CAP ) || ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied or session expired. Reload the page.', 'wordpress-multisite-exporter' ) ), 403 );
		}
		$action = substr( current_filter(), strlen( 'wp_ajax_mse_' ) );
		$req    = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing

		try {
			$data = call_user_func( array( __CLASS__, 'ajax_' . $action ), $req );
			wp_send_json_success( $data );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	private static function state_payload( $extra = array() ) {
		$jobs = array_map( array( 'MSE_Jobs', 'summary' ), MSE_Jobs::all() );
		return array_merge(
			array(
				'jobs'     => $jobs,
				'runnable' => (bool) array_filter(
					$jobs,
					function ( $j ) {
						return in_array( $j['status'], MSE_Jobs::RUNNABLE, true );
					}
				),
			),
			$extra
		);
	}

	private static function ajax_state() {
		return self::state_payload();
	}

	private static function ajax_tick() {
		$tick = MSE_Jobs::tick();
		return self::state_payload( array( 'busy' => $tick['busy'] ) );
	}

	private static function ajax_export( $req ) {
		$ids = array_filter( array_map( 'intval', (array) ( isset( $req['sites'] ) ? $req['sites'] : array() ) ) );
		if ( ! $ids ) {
			throw new InvalidArgumentException( __( 'Select at least one site.', 'wordpress-multisite-exporter' ) );
		}
		MSE_Jobs::ensure_dirs();
		foreach ( $ids as $id ) {
			MSE_Exporter::create_job( $id, ! empty( $req['media'] ) && 'false' !== $req['media'] );
		}
		return self::state_payload();
	}

	private static function ajax_import_urls( $req ) {
		$urls = array_filter( array_map( 'trim', preg_split( '/\R/', isset( $req['urls'] ) ? (string) $req['urls'] : '' ) ) );
		if ( ! $urls ) {
			throw new InvalidArgumentException( __( 'Enter at least one URL.', 'wordpress-multisite-exporter' ) );
		}
		foreach ( $urls as $url ) {
			MSE_Importer::create_from_url( $url );
		}
		return self::state_payload();
	}

	private static function ajax_import_local( $req ) {
		MSE_Importer::create_from_local( isset( $req['file'] ) ? (string) $req['file'] : '' );
		return self::state_payload();
	}

	private static function ajax_upload_start( $req ) {
		$name = isset( $req['name'] ) ? (string) $req['name'] : '';
		if ( ! preg_match( '/\.tar$/i', $name ) ) {
			throw new InvalidArgumentException( __( 'Only .tar packages created by this plugin can be imported.', 'wordpress-multisite-exporter' ) );
		}
		$job = MSE_Importer::create_upload( $name, (int) $req['size'] );
		return array( 'id' => $job['id'] );
	}

	private static function ajax_upload_chunk( $req ) {
		$job = MSE_Jobs::get( isset( $req['id'] ) ? $req['id'] : '' );
		if ( ! $job ) {
			throw new InvalidArgumentException( 'Unknown job.' );
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || ! is_uploaded_file( $_FILES['chunk']['tmp_name'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
			throw new RuntimeException( __( 'The chunk did not arrive. The server upload limit may be lower than expected.', 'wordpress-multisite-exporter' ) );
		}
		$job = MSE_Importer::receive_chunk( $job, (int) $req['offset'], $_FILES['chunk']['tmp_name'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		clearstatcache();
		return array(
			'received' => filesize( $job['state']['package'] ),
			'status'   => $job['status'],
		);
	}

	private static function ajax_configure( $req ) {
		$ids = isset( $req['all'] ) ? wp_list_pluck( array_filter( MSE_Jobs::all(), function ( $j ) {
			return 'review' === $j['status'];
		} ), 'id' ) : array( isset( $req['id'] ) ? $req['id'] : '' );

		$results = array();
		foreach ( $ids as $id ) {
			$job = MSE_Jobs::get( $id );
			if ( ! $job ) {
				throw new InvalidArgumentException( 'Unknown job.' );
			}
			$config = isset( $req['all'] ) ? array_merge( $job['result']['defaults'], array( 'extra_pairs' => '', 'replace_old_hosts' => true, 'skip_guids' => true, 'force' => false, 'theme' => isset( $req['theme'] ) ? $req['theme'] : '' ) ) : array(
				'target_domain'     => isset( $req['target_domain'] ) ? $req['target_domain'] : '',
				'mode'              => isset( $req['mode'] ) ? $req['mode'] : 'new',
				'extra_pairs'       => isset( $req['extra_pairs'] ) ? $req['extra_pairs'] : '',
				'replace_old_hosts' => ! empty( $req['replace_old_hosts'] ) && 'false' !== $req['replace_old_hosts'],
				'theme'             => isset( $req['theme'] ) ? $req['theme'] : '',
				'skip_guids'    => ! empty( $req['skip_guids'] ) && 'false' !== $req['skip_guids'],
				'force'         => ! empty( $req['force'] ) && 'false' !== $req['force'],
			);
			$res       = MSE_Importer::configure( $job, $config );
			$results[] = array(
				'id'      => $id,
				'blocked' => $res['blocked'],
			);
		}
		return self::state_payload( array( 'configured' => $results ) );
	}

	private static function ajax_job_action( $req ) {
		$job = MSE_Jobs::get( isset( $req['id'] ) ? $req['id'] : '' );
		if ( ! $job ) {
			throw new InvalidArgumentException( 'Unknown job.' );
		}
		switch ( isset( $req['do'] ) ? $req['do'] : '' ) {
			case 'delete':
				MSE_Jobs::delete( $job['id'], 'false' !== ( isset( $req['package'] ) ? $req['package'] : 'true' ) );
				break;
			case 'pause':
				if ( in_array( $job['status'], MSE_Jobs::RUNNABLE, true ) ) {
					$job['state']['resume_status'] = $job['status'];
					$job['status']                 = 'paused';
					MSE_Jobs::log( $job, 'Paused.' );
					MSE_Jobs::save( $job );
				}
				break;
			case 'resume':
			case 'retry':
				if ( in_array( $job['status'], array( 'paused', 'error' ), true ) ) {
					$phase         = isset( $job['state']['phase'] ) ? $job['state']['phase'] : '';
					$job['status'] = ! empty( $job['state']['resume_status'] ) ? $job['state']['resume_status'] : ( in_array( $phase, array( 'fetch', 'inspect' ), true ) ? 'preparing' : 'running' );
					$job['error']  = '';
					unset( $job['state']['resume_status'] );
					MSE_Jobs::log( $job, 'Resumed.' );
					MSE_Jobs::save( $job );
				}
				break;
			case 'log':
				return array( 'log' => $job['log'] );
			default:
				throw new InvalidArgumentException( 'Unknown action.' );
		}
		return self::state_payload();
	}
}
