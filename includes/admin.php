<?php
/**
 * Admin settings page.
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the settings page under PMPro menu.
 */
function pmproml_admin_menu() {
	add_submenu_page(
		'pmpro-dashboard',
		__( 'PMPro MailerLite', 'pmpro-mailerlite' ),
		__( 'MailerLite', 'pmpro-mailerlite' ),
		'manage_options',
		'pmpro-mailerlite',
		'pmproml_settings_page'
	);
}
add_action( 'admin_menu', 'pmproml_admin_menu' );

/**
 * Register settings.
 */
function pmproml_admin_init() {
	register_setting( 'pmproml_options', 'pmproml_options', 'pmproml_options_validate' );
}
add_action( 'admin_init', 'pmproml_admin_init' );

/**
 * Validate and sanitize options on save.
 *
 * @param array $input Raw input from form.
 * @return array Sanitized options.
 */
function pmproml_options_validate( $input ) {
	$output = array();

	$output['api_key']                = ! empty( $input['api_key'] ) ? sanitize_text_field( $input['api_key'] ) : '';
	$output['sync_profile_update']    = ! empty( $input['sync_profile_update'] ) ? sanitize_text_field( $input['sync_profile_update'] ) : 'no';
	$output['unsubscribe']            = ! empty( $input['unsubscribe'] ) ? sanitize_text_field( $input['unsubscribe'] ) : 'no';
	$output['subscriber_status_mode'] = ! empty( $input['subscriber_status_mode'] ) ? sanitize_text_field( $input['subscriber_status_mode'] ) : 'active';
	$output['background_sync']        = ! empty( $input['background_sync'] ) ? 1 : 0;
	$output['logging_enabled']        = ! empty( $input['logging_enabled'] ) ? 1 : 0;

	// Non-member groups.
	$output['users_groups'] = ! empty( $input['users_groups'] ) ? array_map( 'sanitize_text_field', $input['users_groups'] ) : array();

	// Per-level groups.
	$levels = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_' . $level->id . '_groups';
		$output[ $key ] = ! empty( $input[ $key ] ) ? array_map( 'sanitize_text_field', $input[ $key ] ) : array();
	}

	// If API key changed, clear transients and ensure custom fields.
	$old_options = get_option( 'pmproml_options', array() );
	if ( $output['api_key'] !== ( $old_options['api_key'] ?? '' ) ) {
		delete_transient( 'pmproml_all_groups' );
		// Custom fields will be created on next sync if key is valid.
	}

	return $output;
}

/**
 * Render the settings page.
 */
function pmproml_settings_page() {
	$options = get_option( 'pmproml_options', array() );
	$api     = PMPro_MailerLite_API::get_instance();

	$force_refresh = ! empty( $_GET['pmproml_refresh'] );
	$groups        = $api->is_connected() ? $api->get_groups( $force_refresh ) : array();
	$levels        = function_exists( 'pmpro_getAllLevels' ) ? pmpro_getAllLevels( true, true ) : array();

	// Test connection on first load with key.
	$connection_valid = false;
	if ( $api->is_connected() ) {
		$connection_valid = ( ! empty( $groups ) || $api->test_connection() );
	}
	?>
	<div class="wrap pmpro_admin pmpro-admin">
		<h1><?php esc_html_e( 'MailerLite Integration', 'pmpro-mailerlite' ); ?></h1>

		<?php pmproml_admin_notices(); ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'pmproml_options' ); ?>

			<h2><?php esc_html_e( 'Authentication', 'pmpro-mailerlite' ); ?></h2>
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="pmproml_api_key"><?php esc_html_e( 'API Key', 'pmpro-mailerlite' ); ?></label>
					</th>
					<td>
						<input type="password" id="pmproml_api_key" name="pmproml_options[api_key]"
							value="<?php echo esc_attr( ! empty( $options['api_key'] ) ? $options['api_key'] : '' ); ?>"
							class="regular-text" autocomplete="off" />
						<p class="description">
							<?php esc_html_e( 'Find your API key in MailerLite under Integrations > MailerLite API.', 'pmpro-mailerlite' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Connection Status', 'pmpro-mailerlite' ); ?></th>
					<td>
						<?php if ( $api->is_connected() && $connection_valid ) : ?>
							<span class="pmproml-status pmproml-connected">
								&#10003; <?php esc_html_e( 'Connected to MailerLite', 'pmpro-mailerlite' ); ?>
							</span>
						<?php elseif ( $api->is_connected() && ! $connection_valid ) : ?>
							<span class="pmproml-status pmproml-disconnected">
								&#10007; <?php esc_html_e( 'API key is invalid or connection failed', 'pmpro-mailerlite' ); ?>
							</span>
						<?php else : ?>
							<span class="description"><?php esc_html_e( 'Enter your API key and save to connect.', 'pmpro-mailerlite' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<?php if ( $api->is_connected() && $connection_valid ) : ?>

				<hr />
				<h2>
					<?php esc_html_e( 'Group Settings', 'pmpro-mailerlite' ); ?>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=pmpro-mailerlite&pmproml_refresh=1' ) ); ?>" class="page-title-action">
						<?php esc_html_e( 'Refresh Groups', 'pmpro-mailerlite' ); ?>
					</a>
				</h2>
				<p class="description">
					<?php esc_html_e( 'MailerLite uses groups to organize subscribers. Assign groups to each membership level below.', 'pmpro-mailerlite' ); ?>
				</p>

				<?php if ( ! empty( $levels ) ) : ?>

					<h3><?php esc_html_e( 'Non-Member Groups', 'pmpro-mailerlite' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Users without a membership level will be added to these groups.', 'pmpro-mailerlite' ); ?></p>
					<table class="form-table">
						<tr>
							<th scope="row"><?php esc_html_e( 'Groups', 'pmpro-mailerlite' ); ?></th>
							<td>
								<?php
								$selected = ! empty( $options['users_groups'] ) ? $options['users_groups'] : array();
								pmproml_render_checkbox_list( 'pmproml_options[users_groups][]', $groups, $selected );
								?>
							</td>
						</tr>
					</table>

					<h3><?php esc_html_e( 'Membership Level Groups', 'pmpro-mailerlite' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Members will be added to the selected groups when they have the corresponding level.', 'pmpro-mailerlite' ); ?></p>

					<?php foreach ( $levels as $level ) : ?>
						<table class="form-table">
							<tr>
								<th scope="row"><?php echo esc_html( $level->name ); ?></th>
								<td>
									<?php
									$key      = 'level_' . $level->id . '_groups';
									$selected = ! empty( $options[ $key ] ) ? $options[ $key ] : array();
									pmproml_render_checkbox_list( "pmproml_options[{$key}][]", $groups, $selected );
									?>
								</td>
							</tr>
						</table>
					<?php endforeach; ?>

				<?php else : ?>
					<p><?php esc_html_e( 'No membership levels found. Create membership levels in PMPro first.', 'pmpro-mailerlite' ); ?></p>
				<?php endif; ?>

				<hr />
				<h2><?php esc_html_e( 'Sync Settings', 'pmpro-mailerlite' ); ?></h2>
				<table class="form-table">
					<tr>
						<th scope="row"><?php esc_html_e( 'Remove from Groups on Level Change', 'pmpro-mailerlite' ); ?></th>
						<td>
							<?php $unsub = ! empty( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes'; ?>
							<select name="pmproml_options[unsubscribe]">
								<option value="no" <?php selected( $unsub, 'no' ); ?>><?php esc_html_e( 'No', 'pmpro-mailerlite' ); ?></option>
								<option value="yes" <?php selected( $unsub, 'yes' ); ?>><?php esc_html_e( 'Yes (remove from old level groups)', 'pmpro-mailerlite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'When a member changes or loses a level, remove them from groups they no longer qualify for.', 'pmpro-mailerlite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Subscriber Status', 'pmpro-mailerlite' ); ?></th>
						<td>
							<?php $status_mode = ! empty( $options['subscriber_status_mode'] ) ? $options['subscriber_status_mode'] : 'active'; ?>
							<select name="pmproml_options[subscriber_status_mode]">
								<option value="active" <?php selected( $status_mode, 'active' ); ?>><?php esc_html_e( 'Always set to Active (bypasses double opt-in)', 'pmpro-mailerlite' ); ?></option>
								<option value="respect" <?php selected( $status_mode, 'respect' ); ?>><?php esc_html_e( 'Respect account settings (honor double opt-in)', 'pmpro-mailerlite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Controls whether new subscribers are set to Active immediately or follow your MailerLite account\'s double opt-in settings.', 'pmpro-mailerlite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Sync on Profile Update', 'pmpro-mailerlite' ); ?></th>
						<td>
							<?php $sync_profile = ! empty( $options['sync_profile_update'] ) ? $options['sync_profile_update'] : 'yes'; ?>
							<select name="pmproml_options[sync_profile_update]">
								<option value="no" <?php selected( $sync_profile, 'no' ); ?>><?php esc_html_e( 'No', 'pmpro-mailerlite' ); ?></option>
								<option value="subscriber_only" <?php selected( $sync_profile, 'subscriber_only' ); ?>><?php esc_html_e( 'Yes (subscriber data only)', 'pmpro-mailerlite' ); ?></option>
								<option value="yes" <?php selected( $sync_profile, 'yes' ); ?>><?php esc_html_e( 'Yes (subscriber data + groups)', 'pmpro-mailerlite' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'Sync subscriber data to MailerLite when a user updates their WordPress profile.', 'pmpro-mailerlite' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Process in Background', 'pmpro-mailerlite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pmproml_options[background_sync]" value="1"
									<?php checked( ! empty( $options['background_sync'] ) ); ?> />
								<?php esc_html_e( 'Use Action Scheduler for background processing (recommended).', 'pmpro-mailerlite' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Debug Logging', 'pmpro-mailerlite' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="pmproml_options[logging_enabled]" value="1"
									<?php checked( ! empty( $options['logging_enabled'] ) ); ?> />
								<?php esc_html_e( 'Enable debug logging for API calls and sync events.', 'pmpro-mailerlite' ); ?>
							</label>
							<?php
							$log_file = ( defined( 'PMPRO_DIR' ) ? PMPRO_DIR . '/logs/' : PMPROML_DIR . 'logs/' ) . 'pmpro-mailerlite.log';
							if ( file_exists( $log_file ) ) :
								?>
								<p class="description">
									<?php
									printf(
										/* translators: %s: Log file size */
										esc_html__( 'Log file size: %s', 'pmpro-mailerlite' ),
										esc_html( size_format( filesize( $log_file ) ) )
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

			<?php endif; ?>

			<?php submit_button(); ?>
		</form>
	</div>
	<?php
}

/**
 * Render a checkbox list for groups.
 *
 * @param string $name     Input name attribute.
 * @param array  $items    Items to display (each with 'id' and 'name').
 * @param array  $selected Currently selected item IDs.
 */
function pmproml_render_checkbox_list( $name, $items, $selected ) {
	if ( empty( $items ) ) {
		echo '<p class="description">' . esc_html__( 'No groups found. Create groups in MailerLite first.', 'pmpro-mailerlite' ) . '</p>';
		return;
	}

	// Cast selected to strings for comparison.
	$selected = array_map( 'strval', $selected );

	echo '<fieldset class="pmproml-checkbox-list">';
	foreach ( $items as $item ) {
		$id      = (string) $item['id'];
		$label   = $item['name'];
		$checked = in_array( $id, $selected, true ) ? ' checked' : '';
		printf(
			'<label><input type="checkbox" name="%s" value="%s"%s /> %s</label><br/>',
			esc_attr( $name ),
			esc_attr( $id ),
			$checked,
			esc_html( $label )
		);
	}
	echo '</fieldset>';
}

/**
 * Display admin notices.
 */
function pmproml_admin_notices() {
	if ( ! empty( $_GET['settings-updated'] ) ) {
		$api = PMPro_MailerLite_API::get_instance();
		if ( $api->is_connected() ) {
			// Ensure custom fields exist on first successful connection.
			$api->ensure_custom_fields();
		}
	}

	// Show problem subscriber warnings.
	$problems = get_option( 'pmproml_problem_subscribers', array() );
	if ( ! empty( $problems ) ) {
		$count = count( $problems );
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'MailerLite Sync Warning', 'pmpro-mailerlite' ); ?></strong>
			</p>
			<p>
				<?php
				printf(
					/* translators: %d: Number of problem subscribers */
					esc_html( _n(
						'%d subscriber could not be fully synced because their MailerLite status prevents reactivation via API. They must re-subscribe through a MailerLite form or landing page.',
						'%d subscribers could not be fully synced because their MailerLite status prevents reactivation via API. They must re-subscribe through a MailerLite form or landing page.',
						$count,
						'pmpro-mailerlite'
					) ),
					$count
				);
				?>
			</p>
			<details>
				<summary><?php esc_html_e( 'View affected subscribers', 'pmpro-mailerlite' ); ?></summary>
				<table class="widefat striped" style="margin-top: 8px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'User', 'pmpro-mailerlite' ); ?></th>
							<th><?php esc_html_e( 'Email', 'pmpro-mailerlite' ); ?></th>
							<th><?php esc_html_e( 'Status', 'pmpro-mailerlite' ); ?></th>
							<th><?php esc_html_e( 'Detected', 'pmpro-mailerlite' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $problems as $uid => $info ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $uid ) ); ?>">
										<?php echo esc_html( '#' . $uid ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $info['email'] ); ?></td>
								<td><code><?php echo esc_html( $info['status'] ); ?></code></td>
								<td><?php echo esc_html( $info['time'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</details>
			<p>
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=pmpro-mailerlite&pmproml_clear_problems=1' ), 'pmproml_clear_problems' ) ); ?>" class="button button-small">
					<?php esc_html_e( 'Dismiss All', 'pmpro-mailerlite' ); ?>
				</a>
			</p>
		</div>
		<?php
	}
}

/**
 * Handle clearing problem subscribers.
 */
function pmproml_handle_clear_problems() {
	if ( empty( $_GET['pmproml_clear_problems'] ) || empty( $_GET['_wpnonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'pmproml_clear_problems' ) || ! current_user_can( 'manage_options' ) ) {
		return;
	}
	delete_option( 'pmproml_problem_subscribers' );
	wp_safe_redirect( admin_url( 'admin.php?page=pmpro-mailerlite' ) );
	exit;
}
add_action( 'admin_init', 'pmproml_handle_clear_problems', 5 );
