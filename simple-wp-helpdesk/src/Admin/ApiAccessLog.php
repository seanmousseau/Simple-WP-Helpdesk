<?php
declare(strict_types=1);

namespace SWH\Admin;

use function get_post_meta;
use function swh_get_option;
use function update_post_meta;
use function wp_get_current_user;

/**
 * Opt-in per-ticket API access audit log.
 *
 * Phase 9 of the v4.1.0 plan (#369). When the operator enables the
 * `integrations.api_audit_log_enabled` toggle, every successful REST API call
 * that resolves to a single ticket id appends a small fixed-shape entry to the
 * `_swh_api_access_log` post meta on that ticket. FIFO-trimmed at 10 entries
 * per ticket.
 *
 * **Privacy posture.** The log records only:
 *   - ts (unix timestamp)
 *   - key id + key label (or 'wp_application_password' + user_login)
 *   - action ('read' | 'write')
 *   - route (path only — query string stripped)
 *
 * No request body, no query string, no IP address, no response body. See
 * docs/internal/security-model.md for the full privacy contract.
 *
 * **Call-site placement.** Controllers MUST invoke `record()` AFTER the
 * response has been built but BEFORE returning it, and ONLY on the success
 * path. Failed-auth requests never reach the controller body, so they are
 * implicitly excluded. List endpoints (collection GETs) MUST NOT call
 * `record()` — they have no single-ticket scope, and logging every list
 * request would exhaust the 10-entry budget across many tickets at once.
 *
 * @since 4.1.0
 */
final class ApiAccessLog {

	/** Post-meta key on `helpdesk_ticket` posts. */
	public const META_KEY = '_swh_api_access_log';

	/** Maximum entries retained per ticket (FIFO). */
	public const MAX_ENTRIES = 10;

	/** Sentinel `key_id` used when no API key is attached to the request. */
	public const WP_APP_PW_KEY_ID = 'wp_application_password';

	/**
	 * Per-request cache for `is_enabled()` — multiple controllers may call
	 * within a single REST dispatch; one settings read is plenty.
	 *
	 * @var bool|null
	 */
	private static ?bool $enabled_cache = null;

	/**
	 * Append a single audit entry to the ticket's access log.
	 *
	 * No-op when the toggle is off, when $ticket_id is non-positive, or when
	 * $action is not one of the locked verbs. Strips any query string off the
	 * route before persisting.
	 *
	 * @param int                     $ticket_id Ticket post ID.
	 * @param string                  $action    'read' or 'write'.
	 * @param string                  $route     `$request->get_route()` — query string permitted; will be stripped.
	 * @param array<mixed,mixed>|null $api_key   The API key entry stashed on the request by {@see \SWH\Rest\Auth::check_request()}, or null for WP-Application-Password / cookie auth.
	 */
	public static function record( int $ticket_id, string $action, string $route, ?array $api_key ): void {
		if ( ! self::is_enabled() ) {
			return;
		}
		if ( $ticket_id <= 0 ) {
			return;
		}
		if ( 'read' !== $action && 'write' !== $action ) {
			return;
		}

		$entry = array(
			'ts'        => time(),
			'key_id'    => self::resolve_key_id( $api_key ),
			'key_label' => self::resolve_key_label( $api_key ),
			'action'    => $action,
			'route'     => self::strip_query_string( $route ),
		);

		$existing  = get_post_meta( $ticket_id, self::META_KEY, true );
		$entries   = is_array( $existing ) ? array_values( $existing ) : array();
		$entries[] = $entry;
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, -self::MAX_ENTRIES );
		}
		update_post_meta( $ticket_id, self::META_KEY, $entries );
	}

	/**
	 * Whether the operator has enabled the audit log toggle.
	 *
	 * Reads `swh_settings_integrations.api_audit_log_enabled`. Cached per
	 * request to avoid re-reading the settings bag on every controller call.
	 */
	public static function is_enabled(): bool {
		if ( null !== self::$enabled_cache ) {
			return self::$enabled_cache;
		}
		$raw                 = swh_get_option( 'integrations', 'api_audit_log_enabled', false );
		self::$enabled_cache = (bool) $raw;
		return self::$enabled_cache;
	}

	/**
	 * Reset the per-request cache. Test-only seam.
	 *
	 * @internal
	 */
	public static function reset_cache_for_tests(): void {
		self::$enabled_cache = null;
	}

	/**
	 * Resolve the `key_id` field for a log entry.
	 *
	 * @param array<mixed,mixed>|null $api_key
	 */
	private static function resolve_key_id( ?array $api_key ): string {
		if ( is_array( $api_key ) && isset( $api_key['id'] ) && is_string( $api_key['id'] ) && '' !== $api_key['id'] ) {
			return $api_key['id'];
		}
		return self::WP_APP_PW_KEY_ID;
	}

	/**
	 * Resolve the `key_label` field for a log entry.
	 *
	 * @param array<mixed,mixed>|null $api_key
	 */
	private static function resolve_key_label( ?array $api_key ): string {
		if ( is_array( $api_key ) && isset( $api_key['label'] ) && is_string( $api_key['label'] ) && '' !== $api_key['label'] ) {
			return $api_key['label'];
		}
		if ( function_exists( 'wp_get_current_user' ) ) {
			$user = wp_get_current_user();
			if ( is_object( $user ) && isset( $user->user_login ) && is_string( $user->user_login ) && '' !== $user->user_login ) {
				return $user->user_login;
			}
		}
		return '';
	}

	/**
	 * Strip any query string from a route — the audit log records the path only.
	 */
	private static function strip_query_string( string $route ): string {
		$q = strpos( $route, '?' );
		if ( false === $q ) {
			return $route;
		}
		return substr( $route, 0, $q );
	}
}
