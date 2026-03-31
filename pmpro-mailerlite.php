<?php
/**
 * Plugin Name: Paid Memberships Pro - MailerLite Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-mailerlite/
 * Description: Sync PMPro members with MailerLite groups.
 * Version: 1.0
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: pmpro-mailerlite
 * Domain Path: /languages
 *
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 6.7
 * Requires Plugins: paid-memberships-pro
 */

defined( 'ABSPATH' ) || exit;

define( 'PMPROML_VERSION', '1.0' );
define( 'PMPROML_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPROML_BASENAME', plugin_basename( __FILE__ ) );
define( 'PMPROML_URL', plugin_dir_url( __FILE__ ) );

/**
 * Load plugin files.
 */
function pmproml_load_plugin() {
	if ( ! defined( 'PMPRO_VERSION' ) ) {
		return;
	}

	require_once PMPROML_DIR . 'classes/class-pmpro-mailerlite-api.php';
	require_once PMPROML_DIR . 'includes/functions.php';
	require_once PMPROML_DIR . 'includes/admin.php';
}
add_action( 'plugins_loaded', 'pmproml_load_plugin' );

/**
 * Set default options on activation.
 */
function pmproml_activation() {
	if ( ! get_option( 'pmproml_options' ) ) {
		update_option( 'pmproml_options', array(
			'api_key'             => '',
			'sync_profile_update' => 'yes',
			'unsubscribe'         => 'yes',
			'background_sync'     => 1,
			'logging_enabled'     => 0,
		) );
	}
}
register_activation_hook( __FILE__, 'pmproml_activation' );

/**
 * Add plugin action links.
 */
function pmproml_plugin_action_links( $links ) {
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=pmpro-mailerlite' ) ),
		esc_html__( 'Settings', 'pmpro-mailerlite' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . PMPROML_BASENAME, 'pmproml_plugin_action_links' );

/**
 * Enqueue admin CSS.
 */
function pmproml_admin_enqueue_scripts( $hook ) {
	if ( false === strpos( $hook, 'pmpro-mailerlite' ) ) {
		return;
	}
	wp_enqueue_style( 'pmproml-admin', PMPROML_URL . 'css/admin.css', array(), PMPROML_VERSION );
}
add_action( 'admin_enqueue_scripts', 'pmproml_admin_enqueue_scripts' );

/**
 * Show admin notice if PMPro is not active.
 */
function pmproml_admin_notice_no_pmpro() {
	if ( defined( 'PMPRO_VERSION' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Paid Memberships Pro - MailerLite Add On requires Paid Memberships Pro to be installed and active.', 'pmpro-mailerlite' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'pmproml_admin_notice_no_pmpro' );
