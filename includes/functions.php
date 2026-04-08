<?php
/**
 * Core sync functions and PMPro integration hooks.
 *
 * Handles syncing membership level changes, profile updates,
 * and checkout events to MailerLite groups.
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

// ------------------------------------------------------------------
// Logging
// ------------------------------------------------------------------

/**
 * Log a message if logging is enabled.
 *
 * @param string $message Log message.
 */
function pmproml_log( $message ) {
	$options = get_option( 'pmproml_options', array() );
	if ( empty( $options['logging_enabled'] ) ) {
		return;
	}

	$log_dir = defined( 'PMPRO_DIR' ) ? PMPRO_DIR . '/logs/' : PMPROML_DIR . 'logs/';
	if ( ! is_dir( $log_dir ) ) {
		wp_mkdir_p( $log_dir );
	}

	$log_file = $log_dir . 'pmpro-mailerlite.log';
	$entry    = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";

	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	file_put_contents( $log_file, $entry, FILE_APPEND | LOCK_EX );
}

// ------------------------------------------------------------------
// Sync: Enqueue for User
// ------------------------------------------------------------------

/**
 * Enqueue a sync for a user, either via Action Scheduler or immediately.
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_groups Whether to sync group memberships.
 */
function pmproml_enqueue_sync_for_user( $user_id, $update_groups = true ) {
	$options = get_option( 'pmproml_options', array() );

	if ( ! empty( $options['background_sync'] ) && class_exists( 'PMPro_Action_Scheduler' ) ) {
		PMPro_Action_Scheduler::get_instance()->schedule(
			'pmproml_sync_subscriber_for_user',
			array( $user_id, $update_groups ),
			'pmproml_sync_tasks'
		);
	} else {
		pmproml_sync_subscriber_for_user( $user_id, $update_groups );
	}
}

/**
 * Register the Action Scheduler callback.
 */
function pmproml_register_action_scheduler() {
	add_action( 'pmproml_sync_subscriber_for_user', 'pmproml_sync_subscriber_for_user', 10, 2 );
}
add_action( 'init', 'pmproml_register_action_scheduler' );

// ------------------------------------------------------------------
// Sync: Core Logic
// ------------------------------------------------------------------

/**
 * Sync a single user to MailerLite.
 *
 * Creates or updates the subscriber and manages group memberships
 * based on their membership levels.
 *
 * @param int  $user_id      WordPress user ID.
 * @param bool $update_groups Whether to sync group memberships.
 */
function pmproml_sync_subscriber_for_user( $user_id, $update_groups = true ) {
	$api = PMPro_MailerLite_API::get_instance();
	if ( ! $api->is_connected() ) {
		return;
	}

	$user = get_userdata( $user_id );
	if ( ! $user ) {
		return;
	}

	$options = get_option( 'pmproml_options', array() );

	// Get the user's current membership levels.
	$levels    = pmpro_getMembershipLevelsForUser( $user_id );
	$level_ids = wp_list_pluck( $levels, 'id' );

	pmproml_log( "Syncing user {$user_id} ({$user->user_email}), levels: " . implode( ',', $level_ids ) );

	// ------------------------------------------------------------------
	// Build the group list for this user.
	// ------------------------------------------------------------------
	$subscribe_groups = array();

	// Groups for current levels.
	foreach ( $level_ids as $lid ) {
		$level_groups     = ! empty( $options[ 'level_' . $lid . '_groups' ] ) ? $options[ 'level_' . $lid . '_groups' ] : array();
		$subscribe_groups = array_merge( $subscribe_groups, $level_groups );
	}

	// If no membership, add to non-member groups.
	if ( empty( $level_ids ) ) {
		$nonmember_groups = ! empty( $options['users_groups'] ) ? $options['users_groups'] : array();
		$subscribe_groups = array_merge( $subscribe_groups, $nonmember_groups );
	}

	$subscribe_groups = array_unique( array_filter( $subscribe_groups ) );

	// ------------------------------------------------------------------
	// Build custom fields.
	// ------------------------------------------------------------------
	$field_keys = get_option( 'pmproml_custom_field_keys', array() );
	$fields     = array(
		'name'      => $user->first_name,
		'last_name' => $user->last_name,
	);

	if ( ! empty( $field_keys['pmpro_level_id'] ) ) {
		$fields[ $field_keys['pmpro_level_id'] ] = ! empty( $level_ids ) ? implode( ',', $level_ids ) : '';
	}

	if ( ! empty( $field_keys['pmpro_level_name'] ) ) {
		$level_names = wp_list_pluck( $levels, 'name' );
		$fields[ $field_keys['pmpro_level_name'] ] = ! empty( $level_names ) ? implode( ', ', $level_names ) : '';
	}

	/**
	 * Filter fields sent to MailerLite for a subscriber.
	 *
	 * @param array   $fields  Field data (includes built-in and custom fields).
	 * @param WP_User $user    The WordPress user.
	 * @param array   $levels  The user's membership levels.
	 */
	$fields = apply_filters( 'pmproml_subscriber_fields', $fields, $user, $levels );

	// ------------------------------------------------------------------
	// Upsert the subscriber.
	// ------------------------------------------------------------------

	// POST upsert is non-destructive — groups listed here are ADDED, not replaced.
	$subscriber_data = array(
		'email'  => $user->user_email,
		'fields' => $fields,
		'groups' => $subscribe_groups,
	);

	// Only set status to active if the admin has enabled it (default: yes).
	// When disabled, respects the MailerLite account's double opt-in settings.
	$status_mode = ! empty( $options['subscriber_status_mode'] ) ? $options['subscriber_status_mode'] : 'active';
	if ( 'active' === $status_mode ) {
		$subscriber_data['status'] = 'active';
	}

	/**
	 * Filter subscriber data before sending to MailerLite.
	 *
	 * @param array   $subscriber_data Data for the upsert.
	 * @param WP_User $user            The WordPress user.
	 * @param array   $levels          The user's membership levels.
	 */
	$subscriber_data = apply_filters( 'pmproml_subscriber_data', $subscriber_data, $user, $levels );

	$result = $api->upsert_subscriber( $subscriber_data );

	if ( is_wp_error( $result ) ) {
		pmproml_log( "Failed to upsert subscriber for user {$user_id}: " . $result->get_error_message() );
		return;
	}

	$subscriber_id     = ! empty( $result['data']['id'] ) ? $result['data']['id'] : '';
	$subscriber_status = ! empty( $result['data']['status'] ) ? $result['data']['status'] : '';

	if ( $subscriber_id ) {
		update_user_meta( $user_id, 'pmproml_subscriber_id', $subscriber_id );
	}

	pmproml_log( "Upserted subscriber {$subscriber_id} for user {$user_id} (status: {$subscriber_status})" );

	// Flag subscribers in problem states that the API cannot reactivate.
	$problem_states = array( 'bounced', 'junk', 'unsubscribed' );
	if ( in_array( $subscriber_status, $problem_states, true ) ) {
		pmproml_log( "WARNING: Subscriber {$subscriber_id} (user {$user_id}, {$user->user_email}) has status '{$subscriber_status}'. MailerLite cannot reactivate this subscriber via API — they must re-subscribe through a form or landing page." );
		pmproml_flag_problem_subscriber( $user_id, $user->user_email, $subscriber_status );
	} else {
		// Clear any previous flag if subscriber is now active.
		pmproml_clear_problem_subscriber( $user_id );
	}

	// ------------------------------------------------------------------
	// Handle group removal for old levels.
	// ------------------------------------------------------------------

	// POST upsert only adds groups — it doesn't remove.
	// We need to explicitly remove groups the user shouldn't be in.
	if ( $update_groups && $subscriber_id && ! empty( $options['unsubscribe'] ) && 'no' !== $options['unsubscribe'] ) {
		$all_configured_groups = pmproml_get_all_configured_groups();
		$groups_to_remove      = array_diff( $all_configured_groups, $subscribe_groups );

		foreach ( $groups_to_remove as $group_id ) {
			$api->remove_subscriber_from_group( $subscriber_id, $group_id );
		}

		if ( ! empty( $groups_to_remove ) ) {
			pmproml_log( "Removed subscriber {$subscriber_id} from groups: " . implode( ',', $groups_to_remove ) );
		}
	}
}

// ------------------------------------------------------------------
// Helpers
// ------------------------------------------------------------------

/**
 * Get all group IDs configured across all levels + non-member groups.
 *
 * @return array
 */
function pmproml_get_all_configured_groups() {
	$options    = get_option( 'pmproml_options', array() );
	$all_groups = array();

	if ( ! empty( $options['users_groups'] ) ) {
		$all_groups = array_merge( $all_groups, $options['users_groups'] );
	}

	$levels = pmpro_getAllLevels( true, true );
	foreach ( $levels as $level ) {
		$key = 'level_' . $level->id . '_groups';
		if ( ! empty( $options[ $key ] ) ) {
			$all_groups = array_merge( $all_groups, $options[ $key ] );
		}
	}

	$all_groups = array_unique( array_filter( $all_groups ) );

	/**
	 * Filter which group IDs are considered PMPro-controlled.
	 *
	 * Only PMPro-controlled groups are removed when a member loses a level.
	 * Groups not in this list are preserved even during level changes.
	 *
	 * @param array $all_groups All configured group IDs.
	 */
	return apply_filters( 'pmproml_controlled_group_ids', $all_groups );
}

// ------------------------------------------------------------------
// Problem Subscriber Tracking
// ------------------------------------------------------------------

/**
 * Flag a subscriber in a problem state (bounced, junk, unsubscribed).
 *
 * Stores in a transient so the admin page can display warnings.
 *
 * @param int    $user_id WordPress user ID.
 * @param string $email   Subscriber email.
 * @param string $status  MailerLite subscriber status.
 */
function pmproml_flag_problem_subscriber( $user_id, $email, $status ) {
	$problems = get_option( 'pmproml_problem_subscribers', array() );
	$problems[ $user_id ] = array(
		'email'  => $email,
		'status' => $status,
		'time'   => current_time( 'mysql' ),
	);
	update_option( 'pmproml_problem_subscribers', $problems, false );
}

/**
 * Clear a problem subscriber flag.
 *
 * @param int $user_id WordPress user ID.
 */
function pmproml_clear_problem_subscriber( $user_id ) {
	$problems = get_option( 'pmproml_problem_subscribers', array() );
	if ( isset( $problems[ $user_id ] ) ) {
		unset( $problems[ $user_id ] );
		update_option( 'pmproml_problem_subscribers', $problems, false );
	}
}

// ------------------------------------------------------------------
// PMPro Hooks
// ------------------------------------------------------------------

/**
 * Sync when membership levels change.
 *
 * @param array $old_user_levels Array of old levels keyed by user ID.
 */
function pmproml_pmpro_after_all_membership_level_changes( $old_user_levels ) {
	if ( empty( $old_user_levels ) || ! is_array( $old_user_levels ) ) {
		return;
	}

	foreach ( array_keys( $old_user_levels ) as $user_id ) {
		pmproml_enqueue_sync_for_user( intval( $user_id ), true );
	}
}
add_action( 'pmpro_after_all_membership_level_changes', 'pmproml_pmpro_after_all_membership_level_changes' );

/**
 * Sync when a user profile is updated.
 *
 * @param int $user_id WordPress user ID.
 */
function pmproml_profile_update( $user_id ) {
	$options = get_option( 'pmproml_options', array() );

	if ( empty( $options['sync_profile_update'] ) || 'no' === $options['sync_profile_update'] ) {
		return;
	}

	$update_groups = ( 'yes' === $options['sync_profile_update'] );
	pmproml_enqueue_sync_for_user( $user_id, $update_groups );
}
add_action( 'profile_update', 'pmproml_profile_update' );

/**
 * Sync when admin saves a member's profile via PMPro Edit Member.
 */
function pmproml_admin_member_edit() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce checked by PMPro.
	if ( empty( $_POST['pmpro_member_edit_panel'] ) || empty( $_POST['user_id'] ) ) {
		return;
	}
	pmproml_enqueue_sync_for_user( intval( $_POST['user_id'] ), true );
}
add_action( 'admin_init', 'pmproml_admin_member_edit', 20 );

/**
 * Subscribe new non-member users to non-member groups.
 *
 * @param int $user_id New user ID.
 */
function pmproml_user_register( $user_id ) {
	$options = get_option( 'pmproml_options', array() );

	// Don't sync during checkout — level change hook handles that.
	if ( did_action( 'pmpro_checkout_before_change_membership_level' ) ) {
		return;
	}

	if ( empty( $options['users_groups'] ) ) {
		return;
	}

	pmproml_enqueue_sync_for_user( $user_id, false );
}
add_action( 'user_register', 'pmproml_user_register' );
