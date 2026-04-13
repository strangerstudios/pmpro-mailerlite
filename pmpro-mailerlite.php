<?php
/**
 * Plugin Name: Paid Memberships Pro - MailerLite Add On
 * Plugin URI: https://www.paidmembershipspro.com/add-ons/pmpro-mailerlite/
 * Description: Connect Paid Memberships Pro to MailerLite to add members as subscribers and manage groups automatically.
 * Version: 1.0
 * Author: Paid Memberships Pro
 * Author URI: https://www.paidmembershipspro.com
 * Text Domain: pmpro-mailerlite
 * Domain Path: /languages
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Requires PHP: 7.4
 * Requires at least: 5.6
 * Tested up to: 6.7
*/

defined( 'ABSPATH' ) || exit;

define( 'PMPROMAILERLITE_VERSION', '1.0' );
define( 'PMPROMAILERLITE_DIR', plugin_dir_path( __FILE__ ) );
define( 'PMPROMAILERLITE_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Load plugin files after all plugins have loaded.
 *
 * @since 1.0
 */
function pmpromailerlite_load_plugin() {
	if ( ! defined( 'PMPRO_VERSION' ) ) {
		return;
	}

	require_once PMPROMAILERLITE_DIR . 'classes/class-pmpro-mailerlite-api.php';
	require_once PMPROMAILERLITE_DIR . 'includes/functions.php';
	require_once PMPROMAILERLITE_DIR . 'includes/admin.php';
}
add_action( 'plugins_loaded', 'pmpromailerlite_load_plugin' );

/**
 * Show a notice after the plugin is activated.
 *
 * @since 1.0
 */
function pmpromailerlite_activation() {
	set_transient( 'pmpromailerlite-admin-notice', true, 5 );
}
register_activation_hook( __FILE__, 'pmpromailerlite_activation' );

/**
 * Admin notice on activation.
 *
 * @since 1.0
 */
function pmpromailerlite_admin_notice() {
	if ( get_transient( 'pmpromailerlite-admin-notice' ) ) {
		?>
		<div class="updated notice is-dismissible">
			<p>
				<?php
				esc_html_e( 'Thank you for activating the MailerLite Add On.', 'pmpro-mailerlite' );
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=pmpro-mailerlite' ) ) . '">';
				esc_html_e( 'Click here to configure settings.', 'pmpro-mailerlite' );
				echo '</a>';
				?>
			</p>
		</div>
		<?php
		delete_transient( 'pmpromailerlite-admin-notice' );
	}
}
add_action( 'admin_notices', 'pmpromailerlite_admin_notice' );

/**
 * Add a Settings link to the plugin action links.
 *
 * @since 1.0
 *
 * @param array $links Array of links.
 * @return array
 */
function pmpromailerlite_plugin_action_links( $links ) {
	if ( current_user_can( 'manage_options' ) ) {
		$new_links = array(
			'<a href="' . esc_url( admin_url( 'admin.php?page=pmpro-mailerlite' ) ) . '">' . esc_html__( 'Settings', 'pmpro-mailerlite' ) . '</a>',
		);
		$links = array_merge( $new_links, $links );
	}
	return $links;
}
add_filter( 'plugin_action_links_' . PMPROMAILERLITE_BASENAME, 'pmpromailerlite_plugin_action_links' );

/**
 * Add Docs and Support links to the plugin row meta.
 *
 * @since 1.0
 *
 * @param array  $links Array of links.
 * @param string $file  Plugin basename.
 * @return array
 */
function pmpromailerlite_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-mailerlite.php' ) !== false ) {
		$new_links = array(
			'<a href="https://www.paidmembershipspro.com/add-ons/pmpro-mailerlite/" title="' . esc_attr__( 'View Documentation', 'pmpro-mailerlite' ) . '">' . esc_html__( 'Docs', 'pmpro-mailerlite' ) . '</a>',
			'<a href="https://www.paidmembershipspro.com/support/" title="' . esc_attr__( 'Visit Customer Support Forum', 'pmpro-mailerlite' ) . '">' . esc_html__( 'Support', 'pmpro-mailerlite' ) . '</a>',
		);
		$links = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmpromailerlite_plugin_row_meta', 10, 2 );

/**
 * Show an admin notice if PMPro is not active.
 *
 * @since 1.0
 */
function pmpromailerlite_admin_notice_no_pmpro() {
	if ( defined( 'PMPRO_VERSION' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Paid Memberships Pro - MailerLite Add On requires Paid Memberships Pro to be installed and active.', 'pmpro-mailerlite' ); ?></p>
	</div>
	<?php
}
add_action( 'admin_notices', 'pmpromailerlite_admin_notice_no_pmpro' );
