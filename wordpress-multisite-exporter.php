<?php
/**
 * Plugin Name:       Multisite Exporter
 * Plugin URI:        https://github.com/didoivanov/wordpress-multisite-exporter
 * Description:       Export single sites from a WordPress multisite network and import them into another multisite network: database, users and media, with plugin/theme compatibility checks.
 * Version:           0.1.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            didoivanov
 * License:           GPL-2.0-or-later
 * Network:           true
 * Text Domain:       wordpress-multisite-exporter
 * Update URI:        https://github.com/didoivanov/wordpress-multisite-exporter
 *
 * @package MultisiteExporter
 */

defined( 'ABSPATH' ) || exit;

define( 'MSE_VERSION', '0.1.1' );
define( 'MSE_FILE', __FILE__ );
define( 'MSE_DIR', plugin_dir_path( __FILE__ ) );
define( 'MSE_URL', plugin_dir_url( __FILE__ ) );

require_once MSE_DIR . 'includes/class-mse-tar.php';
require_once MSE_DIR . 'includes/class-mse-replacer.php';
require_once MSE_DIR . 'includes/class-mse-jobs.php';
require_once MSE_DIR . 'includes/class-mse-exporter.php';
require_once MSE_DIR . 'includes/class-mse-importer.php';
require_once MSE_DIR . 'includes/class-mse-preflight.php';
require_once MSE_DIR . 'includes/class-mse-updater.php';
require_once MSE_DIR . 'includes/class-mse-admin.php';

MSE_Updater::init();

if ( is_multisite() ) {
	MSE_Admin::init();
} else {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Multisite Exporter only works on a WordPress multisite network.', 'wordpress-multisite-exporter' ) . '</p></div>';
		}
	);
}
