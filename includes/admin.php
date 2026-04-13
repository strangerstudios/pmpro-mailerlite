<?php
/**
 * Admin settings page.
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Add MailerLite settings link to PMPro settings menu.
 *
 * @since 1.0
 */
function pmpromailerlite_admin_menu() {
	add_submenu_page(
		'pmpro-dashboard',
		__( 'MailerLite', 'pmpro-mailerlite' ),
		__( 'MailerLite', 'pmpro-mailerlite' ),
		'manage_options',
		'pmpro-mailerlite',
		'pmpromailerlite_settings_page'
	);
}
add_action( 'admin_menu', 'pmpromailerlite_admin_menu' );

/**
 * Render the MailerLite settings page.
 *
 * @since 1.0
 */
function pmpromailerlite_settings_page() {
	// Get existing options.
	$options = get_option( 'pmpromailerlite_options', array() );

	// Get all PMPro levels.
	$pmpro_levels = pmpro_getAllLevels( true );
	$pmpro_levels = pmpro_sort_levels_by_order( $pmpro_levels );

	// Handle form submission.
	if ( isset( $_POST['pmpromailerlite_settings_nonce'] ) && wp_verify_nonce( $_POST['pmpromailerlite_settings_nonce'], 'pmpromailerlite_save_settings' ) ) {
		// Check if the API key changed so we can clear the group cache.
		$old_api_key     = isset( $options['api_key'] ) ? $options['api_key'] : '';
		$new_api_key     = empty( $_POST['api_key'] ) ? '' : sanitize_text_field( wp_unslash( $_POST['api_key'] ) );
		$api_key_changed = ( $old_api_key !== $new_api_key );

		// Sanitize and save all general settings.
		$options['api_key']                = $new_api_key;
		$options['update_on_profile_save'] = empty( $_POST['update_on_profile_save'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['update_on_profile_save'] ) );
		$options['unsubscribe']            = empty( $_POST['unsubscribe'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['unsubscribe'] ) );
		$options['subscriber_status_mode'] = empty( $_POST['subscriber_status_mode'] ) ? 'active' : sanitize_text_field( wp_unslash( $_POST['subscriber_status_mode'] ) );
		$options['enable_async']           = empty( $_POST['enable_async'] ) ? 'yes' : sanitize_text_field( wp_unslash( $_POST['enable_async'] ) );
		$options['enable_debug_log']       = empty( $_POST['enable_debug_log'] ) ? 'no' : sanitize_text_field( wp_unslash( $_POST['enable_debug_log'] ) );

		// Save per-level group assignments if the groups section was rendered.
		if ( ! empty( $_POST['level_groups_shown'] ) ) {
			$options['level_groups_all'] = array();
			foreach ( $pmpro_levels as $level ) {
				$key             = 'level_groups_' . $level->id;
				$options[ $key ] = empty( $_POST[ $key ] ) ? array() : array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ $key ] ) );
				$options['level_groups_all'] = array_merge( $options['level_groups_all'], $options[ $key ] );
			}
			$options['level_groups_all'] = array_unique( $options['level_groups_all'] );
		}

		update_option( 'pmpromailerlite_options', $options );

		if ( $api_key_changed ) {
			// Clear cached groups so they are re-fetched with the new key.
			delete_transient( 'pmpromailerlite_all_groups' );
		}

		// If debug logging was enabled, write a log entry to confirm it works.
		pmpromailerlite_debug_log( 'PMPro MailerLite settings updated.' );

		// Show a success message.
		echo '<div class="updated"><p>' . esc_html__( 'Settings saved.', 'pmpro-mailerlite' ) . '</p></div>';
	}

	// Get the API instance. Called after save so it reads the fresh option.
	$api = PMPro_MailerLite_API::get_instance();
	?>
	<div class="wrap pmpro_admin">
		<h1><?php esc_html_e( 'MailerLite Settings', 'pmpro-mailerlite' ); ?></h1>
		<p>
			<?php
			$docs_link = '<a title="' . esc_attr__( 'Paid Memberships Pro - MailerLite Add On Documentation', 'pmpro-mailerlite' ) . '" target="_blank" rel="nofollow noopener" href="https://www.paidmembershipspro.com/add-ons/pmpro-mailerlite/?utm_source=plugin&utm_medium=pmpro-mailerlite&utm_campaign=add-ons&utm_content=pmpro-mailerlite-settings">' . esc_html__( 'MailerLite Add On documentation', 'pmpro-mailerlite' ) . '</a>';
			// translators: %s: Link to MailerLite Add On documentation.
			printf( esc_html__( 'Learn more about these settings in the %s.', 'pmpro-mailerlite' ), $docs_link ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?>
		</p>

		<form method="post" action="">
			<div class="pmpro_section" data-visibility="shown" data-activated="true">
				<div class="pmpro_section_toggle">
					<button class="pmpro_section-toggle-button" type="button" aria-expanded="true">
						<span class="dashicons dashicons-arrow-up-alt2"></span>
						<?php esc_html_e( 'General Settings', 'pmpro-mailerlite' ); ?>
					</button>
				</div>
				<div class="pmpro_section_inside">
					<table class="form-table">
						<tr>
							<th scope="row">
								<label for="api_key"><?php esc_html_e( 'API Key', 'pmpro-mailerlite' ); ?></label>
							</th>
							<td>
								<input type="password" name="api_key" id="api_key" value="<?php echo esc_attr( isset( $options['api_key'] ) ? $options['api_key'] : '' ); ?>" class="regular-text" autocomplete="off">
								<p class="description">
									<?php esc_html_e( 'Your API key is used to connect your MailerLite account to this membership site.', 'pmpro-mailerlite' ); ?>
									<a href="https://app.mailerlite.com/integrations/api/" target="_blank" rel="noopener"><?php esc_html_e( 'Find your API key in MailerLite under Integrations > MailerLite API.', 'pmpro-mailerlite' ); ?></a>
								</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="update_on_profile_save"><?php esc_html_e( 'Sync on Profile Update', 'pmpro-mailerlite' ); ?></label></th>
							<td>
								<?php
								$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
								?>
								<select name="update_on_profile_save" id="update_on_profile_save">
									<option value="yes" <?php selected( $update_on_profile_save, 'yes' ); ?>><?php esc_html_e( 'Yes, sync subscriber data and groups', 'pmpro-mailerlite' ); ?></option>
									<option value="subscriber_only" <?php selected( $update_on_profile_save, 'subscriber_only' ); ?>><?php esc_html_e( 'Yes, sync subscriber data only', 'pmpro-mailerlite' ); ?></option>
									<option value="no" <?php selected( $update_on_profile_save, 'no' ); ?>><?php esc_html_e( 'No, do not sync anything on profile update', 'pmpro-mailerlite' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Choose what to sync to MailerLite when a user profile is updated in WordPress.', 'pmpro-mailerlite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="unsubscribe"><?php esc_html_e( 'Remove Groups When Membership Changes', 'pmpro-mailerlite' ); ?></label></th>
							<td>
								<?php
								$unsubscribe = isset( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes';
								?>
								<select name="unsubscribe" id="unsubscribe">
									<option value="yes" <?php selected( $unsubscribe, 'yes' ); ?>><?php esc_html_e( 'Yes, remove groups that no longer apply', 'pmpro-mailerlite' ); ?></option>
									<option value="no" <?php selected( $unsubscribe, 'no' ); ?>><?php esc_html_e( 'No, never remove groups', 'pmpro-mailerlite' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'When enabled, the integration will remove groups in MailerLite when they no longer match the current membership.', 'pmpro-mailerlite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="subscriber_status_mode"><?php esc_html_e( 'Subscriber Status', 'pmpro-mailerlite' ); ?></label></th>
							<td>
								<?php
								$subscriber_status_mode = isset( $options['subscriber_status_mode'] ) ? $options['subscriber_status_mode'] : 'active';
								?>
								<select name="subscriber_status_mode" id="subscriber_status_mode">
									<option value="active" <?php selected( $subscriber_status_mode, 'active' ); ?>><?php esc_html_e( 'Always set to Active (bypasses double opt-in)', 'pmpro-mailerlite' ); ?></option>
									<option value="respect" <?php selected( $subscriber_status_mode, 'respect' ); ?>><?php esc_html_e( 'Respect account settings (honor double opt-in)', 'pmpro-mailerlite' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Controls whether new subscribers are set to Active immediately or follow your MailerLite account\'s double opt-in settings.', 'pmpro-mailerlite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="enable_async"><?php esc_html_e( 'Process Updates in the Background', 'pmpro-mailerlite' ); ?></label></th>
							<td>
								<?php
								$enable_async = isset( $options['enable_async'] ) ? $options['enable_async'] : 'yes';
								?>
								<select name="enable_async" id="enable_async">
									<option value="yes" <?php selected( $enable_async, 'yes' ); ?>><?php esc_html_e( 'Yes, run updates in the background', 'pmpro-mailerlite' ); ?></option>
									<option value="no" <?php selected( $enable_async, 'no' ); ?>><?php esc_html_e( 'No, run updates immediately', 'pmpro-mailerlite' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'When enabled, subscriber updates and group changes will run in the background using Action Scheduler. This can improve performance during checkout, profile updates, and membership changes.', 'pmpro-mailerlite' ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label><?php esc_html_e( 'Debug Logging', 'pmpro-mailerlite' ); ?></label></th>
							<td>
								<?php
								$enable_debug_log = isset( $options['enable_debug_log'] ) ? $options['enable_debug_log'] : 'no';
								?>
								<select name="enable_debug_log" id="enable_debug_log">
									<option value="yes" <?php selected( $enable_debug_log, 'yes' ); ?>><?php esc_html_e( 'Yes, enable debug logging', 'pmpro-mailerlite' ); ?></option>
									<option value="no" <?php selected( $enable_debug_log, 'no' ); ?>><?php esc_html_e( 'No, disable debug logging', 'pmpro-mailerlite' ); ?></option>
								</select>
								<p class="description">
									<?php
									esc_html_e( 'When enabled, the integration will write debug details to the log to help troubleshoot issues.', 'pmpro-mailerlite' );
									if ( 'yes' === $enable_debug_log ) {
										$log_file_link = add_query_arg(
											array(
												'pmpro_restricted_file_dir' => 'logs',
												'pmpro_restricted_file'     => 'pmpro-mailerlite.log',
											),
											home_url()
										);
										echo ' <a href="' . esc_url( $log_file_link ) . '" target="_blank">' . esc_html__( 'Download log.', 'pmpro-mailerlite' ) . '</a>';
									}
									?>
								</p>
							</td>
						</tr>
					</table>
				</div>
			</div>

			<div class="pmpro_section" data-visibility="<?php echo empty( $options['api_key'] ) ? 'hidden' : 'shown'; ?>" data-activated="<?php echo empty( $options['api_key'] ) ? 'false' : 'true'; ?>">
				<div class="pmpro_section_toggle">
					<button class="pmpro_section-toggle-button" type="button" aria-expanded="<?php echo empty( $options['api_key'] ) ? 'false' : 'true'; ?>">
						<span class="dashicons <?php echo empty( $options['api_key'] ) ? 'dashicons-arrow-down-alt2' : 'dashicons-arrow-up-alt2'; ?>"></span>
						<?php esc_html_e( 'Assign Groups', 'pmpro-mailerlite' ); ?>
					</button>
				</div>
				<div class="pmpro_section_inside" style="<?php echo empty( $options['api_key'] ) ? 'display:none;' : ''; ?>">
					<?php
					$force_refresh = ! empty( $_GET['pmpromailerlite_refresh_groups'] );
					$groups        = $api->is_connected() ? $api->get_groups( $force_refresh ) : array();

					if ( ! $api->is_connected() ) {
						echo '<div class="pmpro_message pmpro_error"><p>' . esc_html__( 'Enter your API key above and save to connect to MailerLite.', 'pmpro-mailerlite' ) . '</p></div>';
					} elseif ( empty( $groups ) ) {
						?>
						<p>
							<?php esc_html_e( 'No groups found in your MailerLite account.', 'pmpro-mailerlite' ); ?>
							<a href="<?php echo esc_url( add_query_arg( 'pmpromailerlite_refresh_groups', '1' ) ); ?>">
								<?php esc_html_e( 'Click here to refresh groups', 'pmpro-mailerlite' ); ?>
							</a>
						</p>
						<?php
					} else {
						?>
						<p>
							<?php echo esc_html__( 'Select the MailerLite groups to assign to members when they are added to each membership level.', 'pmpro-mailerlite' ) . ' '; ?>
							<a href="<?php echo esc_url( add_query_arg( 'pmpromailerlite_refresh_groups', '1' ) ); ?>">
								<?php esc_html_e( 'Click here to refresh groups', 'pmpro-mailerlite' ); ?>
							</a>
						</p>
						<input type="hidden" name="level_groups_shown" value="1">
						<table class="form-table">
							<?php
							foreach ( $pmpro_levels as $level ) {
								$key             = 'level_groups_' . $level->id;
								$selected_groups = isset( $options[ $key ] ) ? array_map( 'strval', (array) $options[ $key ] ) : array();
								?>
								<tr>
									<th scope="row"><?php echo esc_html( $level->name ); ?></th>
									<td>
										<?php
										$classes = array( 'pmpro_checkbox_box' );
										if ( count( $groups ) > 5 ) {
											$classes[] = 'pmpro_scrollable';
										}
										?>
										<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
											<?php
											foreach ( $groups as $group ) {
												$checked = in_array( (string) $group['id'], $selected_groups, true ) ? 'checked' : '';
												?>
												<div class="pmpro_clickable">
													<input type="checkbox" id="level_groups_<?php echo esc_attr( $level->id ); ?>_<?php echo esc_attr( $group['id'] ); ?>" name="level_groups_<?php echo esc_attr( $level->id ); ?>[]" value="<?php echo esc_attr( $group['id'] ); ?>" <?php echo esc_attr( $checked ); ?>>
													<label for="level_groups_<?php echo esc_attr( $level->id ); ?>_<?php echo esc_attr( $group['id'] ); ?>">
														<?php echo esc_html( $group['name'] ); ?>
													</label>
												</div>
												<?php
											}
											?>
										</div>
									</td>
								</tr>
								<?php
							}
							?>
						</table>
						<?php
					}
					?>
				</div>
			</div>

			<?php wp_nonce_field( 'pmpromailerlite_save_settings', 'pmpromailerlite_settings_nonce' ); ?>
			<input type="submit" class="button button-primary" value="<?php esc_attr_e( 'Save Changes', 'pmpro-mailerlite' ); ?>">
		</form>
	</div>
	<?php
}

