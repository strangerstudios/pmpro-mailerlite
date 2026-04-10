<?php
/**
 * MailerLite API wrapper.
 *
 * Uses the MailerLite "new" API (connect.mailerlite.com/api).
 * Authentication is a simple Bearer token (API key).
 *
 * @since 1.0
 */

defined( 'ABSPATH' ) || exit;

class PMPro_MailerLite_API {

	/**
	 * Singleton instance.
	 *
	 * @var PMPro_MailerLite_API|null
	 */
	private static $instance = null;

	/**
	 * API base URL.
	 *
	 * @var string
	 */
	private $api_url = 'https://connect.mailerlite.com/api';

	/**
	 * API key.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Whether the API is connected and ready.
	 *
	 * @var bool
	 */
	private $connected = false;

	/**
	 * Cached groups.
	 *
	 * @var array|null
	 */
	private $groups_cache = null;

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0
	 *
	 * @return PMPro_MailerLite_API
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor. Loads the stored API key.
	 */
	private function __construct() {
		$options = get_option( 'pmpromailerlite_options', array() );
		if ( ! empty( $options['api_key'] ) ) {
			$this->api_key   = $options['api_key'];
			$this->connected = true;
		}
	}

	/**
	 * Check if the API is connected (key is set).
	 *
	 * @since 1.0
	 *
	 * @return bool
	 */
	public function is_connected() {
		return $this->connected;
	}

	/**
	 * Make an API request.
	 *
	 * @since 1.0
	 *
	 * @param string $endpoint Relative endpoint (e.g. '/subscribers').
	 * @param string $method   HTTP method.
	 * @param array  $body     Request body (for POST/PUT/PATCH).
	 * @param array  $query    Query parameters.
	 * @return array|WP_Error Decoded response body or WP_Error on failure.
	 */
	public function request( $endpoint, $method = 'GET', $body = array(), $query = array() ) {
		if ( empty( $this->api_key ) ) {
			return new WP_Error( 'not_connected', __( 'MailerLite API key not configured.', 'pmpro-mailerlite' ) );
		}

		$url = $this->api_url . $endpoint;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
				'User-Agent'    => 'PMPro-MailerLite/' . PMPROMAILERLITE_VERSION,
			),
			'timeout' => 15,
		);

		if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			pmpromailerlite_debug_log( "API error ({$method} {$endpoint}): " . $response->get_error_message() );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		// 204 No Content is a success response (e.g. DELETE).
		if ( 204 === $code ) {
			return array();
		}

		// Handle rate limiting (120 requests/minute).
		if ( 429 === $code ) {
			$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
			pmpromailerlite_debug_log( "Rate limited ({$method} {$endpoint}). Retry-After: {$retry_after}" );
			return new WP_Error( 'rate_limited', __( 'MailerLite API rate limit reached. The request will be retried.', 'pmpro-mailerlite' ), array( 'status' => 429, 'retry_after' => $retry_after ) );
		}

		if ( $code < 200 || $code >= 300 ) {
			$error_message = ! empty( $data['message'] ) ? $data['message'] : "HTTP {$code}";
			pmpromailerlite_debug_log( "API error ({$method} {$endpoint}): {$error_message}" );
			return new WP_Error( 'api_error', $error_message, array( 'status' => $code, 'response' => $data ) );
		}

		return $data;
	}

	// ------------------------------------------------------------------
	// Groups
	// ------------------------------------------------------------------

	/**
	 * Get all groups from MailerLite with transient caching.
	 *
	 * @since 1.0
	 *
	 * @param bool $force_refresh Skip the cache.
	 * @return array
	 */
	public function get_groups( $force_refresh = false ) {
		if ( null !== $this->groups_cache && ! $force_refresh ) {
			return $this->groups_cache;
		}

		$cached = get_transient( 'pmpromailerlite_all_groups' );
		if ( false !== $cached && ! $force_refresh ) {
			$this->groups_cache = $cached;
			return $cached;
		}

		$all_groups = array();
		$page       = 1;

		while ( true ) {
			$result = $this->request( '/groups', 'GET', array(), array(
				'limit' => 100,
				'page'  => $page,
			) );

			if ( is_wp_error( $result ) ) {
				return array();
			}

			if ( ! empty( $result['data'] ) ) {
				foreach ( $result['data'] as $group ) {
					$all_groups[] = array(
						'id'           => $group['id'],
						'name'         => $group['name'],
						'active_count' => ! empty( $group['active_count'] ) ? $group['active_count'] : 0,
					);
				}
			}

			if ( empty( $result['meta']['next_cursor'] ) && empty( $result['links']['next'] ) ) {
				break;
			}
			$page++;

			// Safety valve.
			if ( $page > 50 ) {
				break;
			}
		}

		usort( $all_groups, function( $a, $b ) {
			return strcasecmp( $a['name'], $b['name'] );
		} );

		$this->groups_cache = $all_groups;
		set_transient( 'pmpromailerlite_all_groups', $all_groups, 12 * HOUR_IN_SECONDS );

		return $all_groups;
	}

	/**
	 * Remove a subscriber from a group.
	 *
	 * @since 1.0
	 *
	 * @param string $subscriber_id Subscriber ID.
	 * @param string $group_id      Group ID.
	 * @return array|WP_Error
	 */
	public function remove_subscriber_from_group( $subscriber_id, $group_id ) {
		return $this->request( "/subscribers/{$subscriber_id}/groups/{$group_id}", 'DELETE' );
	}

	// ------------------------------------------------------------------
	// Subscribers
	// ------------------------------------------------------------------

	/**
	 * Create or update a subscriber (upsert).
	 *
	 * POST /subscribers is a non-destructive upsert in MailerLite —
	 * omitted fields and groups are not removed.
	 *
	 * @since 1.0
	 *
	 * @param array $subscriber_data Subscriber data.
	 * @return array|WP_Error Response data.
	 */
	public function upsert_subscriber( $subscriber_data ) {
		return $this->request( '/subscribers', 'POST', $subscriber_data );
	}

	/**
	 * Get a subscriber by email.
	 *
	 * @since 1.0
	 *
	 * @param string $email Email address.
	 * @return array|null Subscriber data or null if not found.
	 */
	public function get_subscriber_by_email( $email ) {
		$result = $this->request( '/subscribers/' . rawurlencode( $email ), 'GET' );

		if ( is_wp_error( $result ) ) {
			return null;
		}

		return ! empty( $result['data'] ) ? $result['data'] : null;
	}

	/**
	 * Delete a subscriber.
	 *
	 * @since 1.0
	 *
	 * @param string $subscriber_id Subscriber ID.
	 * @return array|WP_Error
	 */
	public function delete_subscriber( $subscriber_id ) {
		return $this->request( '/subscribers/' . $subscriber_id, 'DELETE' );
	}
}
