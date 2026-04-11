<?php
/**
 * Core sync functions and PMPro integration hooks.
 *
 * Handles syncing membership level changes and profile updates
 * to MailerLite groups.
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Logging
// ------------------------------------------------------------------

/**
 * Get the location of the PMPro MailerLite log file.
 *
 * @since 1.0
 *
 * @return string The log file path.
 */
function pmpromailerlite_get_log_file_path() {
	return apply_filters( 'pmpromailerlite_log_file_path', pmpro_get_restricted_file_path( 'logs', 'pmpro-mailerlite.log' ) );
}

/**
 * Maybe add an entry to the debug log.
 *
 * @since 1.0
 *
 * @param string $message The log message.
 */
function pmpromailerlite_debug_log( $message ) {
	$options          = get_option( 'pmpromailerlite_options', array() );
	$enable_debug_log = isset( $options['enable_debug_log'] ) ? $options['enable_debug_log'] : 'no';
	if ( 'yes' !== $enable_debug_log ) {
		return;
	}

	$logstr    = "Logged On: " . date_i18n( "m/d/Y H:i:s" ) . "\n" . $message . "\n-------------\n";
	$logfile   = pmpromailerlite_get_log_file_path();
	$loghandle = fopen( $logfile, "a+" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	if ( $loghandle ) {
		fwrite( $loghandle, $logstr ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $loghandle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

// ------------------------------------------------------------------
// Sync: Enqueue for User
// ------------------------------------------------------------------

/**
 * Enqueue a sync for a user, either via Action Scheduler or immediately.
 *
 * @since 1.0
 *
 * @param int  $user_id       WordPress user ID.
 * @param bool $update_groups Whether to sync group memberships.
 */
function pmpromailerlite_enqueue_sync_for_user( $user_id, $update_groups = true ) {
	$options      = get_option( 'pmpromailerlite_options', array() );
	$enable_async = isset( $options['enable_async'] ) ? $options['enable_async'] : 'yes';

	if ( 'no' === $enable_async || ! class_exists( 'PMPro_Action_Scheduler' ) ) {
		pmpromailerlite_sync_subscriber_for_user( $user_id, $update_groups );
		return;
	}

	PMPro_Action_Scheduler::instance()->maybe_add_task(
		'pmpromailerlite_sync_subscriber_for_user',
		array(
			'user_id'       => $user_id,
			'update_groups' => $update_groups,
		),
		'pmpromailerlite_sync_tasks'
	);
}
add_action( 'pmpromailerlite_sync_subscriber_for_user', 'pmpromailerlite_sync_subscriber_for_user', 10, 2 );

// ------------------------------------------------------------------
// Sync: Core Logic
// ------------------------------------------------------------------

/**
 * Sync a single user to MailerLite.
 *
 * Creates or updates the subscriber and manages group memberships
 * based on their current membership levels.
 *
 * @since 1.0
 *
 * @param int  $user_id       WordPress user ID.
 * @param bool $update_groups Whether to sync group memberships.
 */
function pmpromailerlite_sync_subscriber_for_user( $user_id, $update_groups = true ) {
	$api = PMPro_MailerLite_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$options = get_option( 'pmpromailerlite_options', array() );

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	// Build log message as we go.
	$log  = "Updating subscriber for user ID {$user_id} (email: {$user->user_email}). ";
	$log .= "Current level IDs: " . ( ! empty( $level_ids ) ? implode( ', ', $level_ids ) : 'none' ) . ". ";

	// If the user has no membership levels and is not already a subscriber, bail.
	$subscriber_id = get_user_meta( $user_id, 'pmpromailerlite_subscriber_id', true );
	if ( empty( $level_ids ) && empty( $subscriber_id ) ) {
		$log .= "User has no membership levels and is not a subscriber. No action taken. ";
		pmpromailerlite_debug_log( $log );
		return;
	}

	// ------------------------------------------------------------------
	// Build the group list for this user based on current levels.
	// ------------------------------------------------------------------
	$subscribe_groups = array();
	foreach ( $level_ids as $lid ) {
		$level_groups     = ! empty( $options[ 'level_groups_' . $lid ] ) ? $options[ 'level_groups_' . $lid ] : array();
		$subscribe_groups = array_merge( $subscribe_groups, $level_groups );
	}
	$subscribe_groups = array_unique( array_filter( $subscribe_groups ) );
	$log .= "Groups to assign: " . ( ! empty( $subscribe_groups ) ? implode( ', ', $subscribe_groups ) : 'none' ) . ". ";

	// ------------------------------------------------------------------
	// Build subscriber data.
	// ------------------------------------------------------------------
	$subscriber_data = array(
		'email'  => $user->user_email,
		'fields' => array(
			'name'      => empty( $user->first_name ) ? $user->user_login : $user->first_name,
			'last_name' => $user->last_name,
		),
		'groups' => $subscribe_groups,
	);

	// Optionally bypass double opt-in by setting status to Active.
	$status_mode = isset( $options['subscriber_status_mode'] ) ? $options['subscriber_status_mode'] : 'active';
	if ( 'active' === $status_mode ) {
		$subscriber_data['status'] = 'active';
	}

	/**
	 * Filter subscriber data before sending to MailerLite.
	 *
	 * @since 1.0
	 *
	 * @param array   $subscriber_data Data for the upsert.
	 * @param WP_User $user            The WordPress user.
	 * @param array   $levels          The user's membership levels.
	 */
	$subscriber_data = apply_filters( 'pmpromailerlite_subscriber_data', $subscriber_data, $user, $levels );
	$log .= "Subscriber data: " . print_r( $subscriber_data, true ) . ". "; // phpcs:ignore WordPress.PHP.DevelopmentFunctions

	// ------------------------------------------------------------------
	// Upsert the subscriber.
	// POST /subscribers is non-destructive — groups listed here are ADDED, not replaced.
	// ------------------------------------------------------------------
	$result = $api->upsert_subscriber( $subscriber_data );

	if ( is_wp_error( $result ) ) {
		$log .= "Error upserting subscriber: " . $result->get_error_message() . ". ";
		pmpromailerlite_debug_log( $log );
		return;
	}

	$subscriber_id     = ! empty( $result['data']['id'] ) ? $result['data']['id'] : '';
	$subscriber_status = ! empty( $result['data']['status'] ) ? $result['data']['status'] : '';

	if ( $subscriber_id ) {
		update_user_meta( $user_id, 'pmpromailerlite_subscriber_id', $subscriber_id );
	}
	$log .= "Upserted subscriber ID {$subscriber_id} (status: {$subscriber_status}). ";

	// Log a warning for subscribers in states that the API cannot reactivate.
	$problem_states = array( 'bounced', 'junk', 'unsubscribed' );
	if ( in_array( $subscriber_status, $problem_states, true ) ) {
		$log .= "WARNING: Subscriber {$subscriber_id} has status '{$subscriber_status}'. MailerLite cannot reactivate this subscriber via API — they must re-subscribe through a form or landing page. ";
	}

	// ------------------------------------------------------------------
	// Handle group removal for levels the user no longer holds.
	// POST upsert only adds groups — it does not remove.
	// We explicitly remove groups the user should no longer be in.
	// ------------------------------------------------------------------
	$unsubscribe = isset( $options['unsubscribe'] ) ? $options['unsubscribe'] : 'yes';
	if ( $update_groups && $subscriber_id && 'yes' === $unsubscribe ) {
		$controlled_group_ids = pmpromailerlite_get_controlled_group_ids();

		/**
		 * Filter the group IDs that PMPro controls.
		 *
		 * Only PMPro-controlled groups are removed when a member loses a level.
		 * Groups not in this list are preserved even during level changes.
		 *
		 * @since 1.0
		 *
		 * @param array $controlled_group_ids All configured group IDs.
		 */
		$controlled_group_ids = apply_filters( 'pmpromailerlite_controlled_group_ids', $controlled_group_ids );

		$groups_to_remove = array_diff( $controlled_group_ids, $subscribe_groups );
		$log .= "Groups to remove: " . ( ! empty( $groups_to_remove ) ? implode( ', ', $groups_to_remove ) : 'none' ) . ". ";

		foreach ( $groups_to_remove as $group_id ) {
			$response = $api->remove_subscriber_from_group( $subscriber_id, $group_id );
			if ( is_wp_error( $response ) ) {
				$log .= "Error removing group ID {$group_id}: " . $response->get_error_message() . ". ";
			} else {
				$log .= "Removed group ID {$group_id}. ";
			}
		}
	} elseif ( 'no' === $unsubscribe ) {
		$log .= "Group removal is disabled. ";
	}

	pmpromailerlite_debug_log( $log );
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all group IDs configured across all membership levels.
 *
 * @since 1.0
 *
 * @return array
 */
function pmpromailerlite_get_controlled_group_ids() {
	$options = get_option( 'pmpromailerlite_options', array() );

	// Use the pre-computed list saved on settings save when available.
	if ( ! empty( $options['level_groups_all'] ) ) {
		return array_unique( array_filter( $options['level_groups_all'] ) );
	}

	// Fallback: compute from per-level keys.
	$all_groups = array();
	$levels     = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_groups_' . $level->id;
		if ( ! empty( $options[ $key ] ) ) {
			$all_groups = array_merge( $all_groups, $options[ $key ] );
		}
	}

	return array_unique( array_filter( $all_groups ) );
}

// ------------------------------------------------------------------
// PMPro Hooks
// ------------------------------------------------------------------

/**
 * When a user's membership level changes, sync their data to MailerLite.
 *
 * Fires after the membership level change is confirmed.
 *
 * @since 1.0
 *
 * @param array $old_users_and_levels Array of user IDs and their old levels.
 */
function pmpromailerlite_sync_users_after_all_membership_level_changes( $old_users_and_levels ) {
	if ( empty( $old_users_and_levels ) || ! is_array( $old_users_and_levels ) ) {
		return;
	}

	foreach ( array_keys( $old_users_and_levels ) as $user_id ) {
		pmpromailerlite_enqueue_sync_for_user( intval( $user_id ), true );
	}
}
add_action( 'pmpro_after_all_membership_level_changes', 'pmpromailerlite_sync_users_after_all_membership_level_changes', 10, 1 );

/**
 * When a user's profile is updated, sync their data to MailerLite.
 *
 * @since 1.0
 *
 * @param int $user_id WordPress user ID.
 */
function pmpromailerlite_sync_user_on_profile_update( $user_id ) {
	$options                = get_option( 'pmpromailerlite_options', array() );
	$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
	if ( 'no' === $update_on_profile_save ) {
		return;
	}

	pmpromailerlite_enqueue_sync_for_user( $user_id, 'subscriber_only' !== $update_on_profile_save );
}
add_action( 'profile_update', 'pmpromailerlite_sync_user_on_profile_update', 10, 1 );

/**
 * When user fields are saved from the PMPro Edit Member screen, sync their data to MailerLite.
 *
 * PMPro's user fields panel saves directly to user meta without firing profile_update,
 * so we detect when a user-fields panel was saved and trigger the sync.
 *
 * Runs at priority 20 to fire after PMPro's save at priority 10.
 *
 * @since 1.0
 */
function pmpromailerlite_sync_user_on_edit_member_user_fields_save() {
	if ( empty( $_REQUEST['page'] ) || 'pmpro-member' !== $_REQUEST['page'] ) {
		return;
	}

	if ( empty( $_POST ) ) {
		return;
	}

	$panel_slug = empty( $_REQUEST['pmpro_member_edit_panel'] ) ? '' : sanitize_text_field( $_REQUEST['pmpro_member_edit_panel'] );
	if ( empty( $panel_slug ) || strpos( $panel_slug, 'user-fields-' ) !== 0 ) {
		return;
	}

	if ( empty( $_REQUEST['pmpro_member_edit_saved_panel_nonce'] ) || ! wp_verify_nonce( $_REQUEST['pmpro_member_edit_saved_panel_nonce'], 'pmpro_member_edit_saved_panel_' . $panel_slug ) ) {
		return;
	}

	$user_id = empty( $_REQUEST['user_id'] ) ? 0 : intval( $_REQUEST['user_id'] );
	if ( empty( $user_id ) ) {
		return;
	}

	$options                = get_option( 'pmpromailerlite_options', array() );
	$update_on_profile_save = isset( $options['update_on_profile_save'] ) ? $options['update_on_profile_save'] : 'yes';
	if ( 'no' === $update_on_profile_save ) {
		return;
	}

	pmpromailerlite_enqueue_sync_for_user( $user_id, 'subscriber_only' !== $update_on_profile_save );
}
add_action( 'admin_init', 'pmpromailerlite_sync_user_on_edit_member_user_fields_save', 20 );
