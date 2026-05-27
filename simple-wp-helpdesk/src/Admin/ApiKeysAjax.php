<?php
declare(strict_types=1);

namespace SWH\Admin;

use SWH\Rest\ApiKeys;
use InvalidArgumentException;

use function add_action;
use function check_ajax_referer;
use function current_user_can;
use function esc_html__;
use function sanitize_text_field;
use function wp_send_json_error;
use function wp_send_json_success;
use function wp_unslash;

/**
 * Admin-AJAX endpoints for API key management.
 *
 * UI half of issue #365 (storage half landed in Phase 1's {@see ApiKeys}).
 *
 * Endpoints:
 *   - `wp_ajax_swh_api_key_generate` — create a new key, return plaintext ONCE.
 *   - `wp_ajax_swh_api_key_revoke`   — revoke a key permanently.
 *
 * Both endpoints require:
 *   1. A valid nonce (`swh_api_keys` action via the `_nonce` field).
 *   2. `manage_options` capability.
 *
 * The plaintext key is returned in the `generate` response body and never
 * persisted, logged, or re-derivable. The UI must show it exactly once and
 * scrub it from the DOM on modal close. See `docs/internal/security-model.md`.
 *
 * Net-new admin PHP lives under `src/Admin/` per Phase 0 §1 recommendation.
 *
 * @since 4.1.0
 */
final class ApiKeysAjax {

	/** Nonce action shared by both endpoints. */
	public const NONCE_ACTION = 'swh_api_keys';

	/** Nonce field name in the POST body. */
	public const NONCE_FIELD = '_nonce';

	/** Maximum operator-supplied label length (mirrors {@see ApiKeys}). */
	private const MAX_LABEL_LEN = 60;

	/** Minimum per-key rate limit (requests/min). */
	private const RATE_LIMIT_MIN = 1;

	/** Maximum per-key rate limit (requests/min). */
	private const RATE_LIMIT_MAX = 10000;

	/** Regex for the stable id format ApiKeys::generate() produces. */
	private const ID_PATTERN = '/^k_[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

	/**
	 * Wire up the AJAX action hooks. Idempotent — safe to call multiple times.
	 *
	 * Both actions are gated to `manage_options`; the `nopriv` variants are
	 * intentionally NOT registered (anonymous callers must not reach either
	 * endpoint).
	 */
	public static function boot(): void {
		add_action( 'wp_ajax_swh_api_key_generate', array( self::class, 'handle_generate' ) );
		add_action( 'wp_ajax_swh_api_key_revoke', array( self::class, 'handle_revoke' ) );
	}

	/**
	 * `wp_ajax_swh_api_key_generate` handler.
	 *
	 * @return void Exits via wp_send_json_*.
	 */
	public static function handle_generate(): void {
		self::assert_request();

		$label      = self::read_string( 'label' );
		$scope      = self::read_string( 'scope' );
		$rate_limit = self::read_optional_int( 'rate_limit' );

		if ( '' === $label ) {
			self::error( 'swh_apikey_label_required', esc_html__( 'Label is required.', 'simple-wp-helpdesk' ), 400, array( 'field' => 'label' ) );
		}
		if ( strlen( $label ) > self::MAX_LABEL_LEN ) {
			self::error(
				'swh_apikey_label_too_long',
				/* translators: %d: Maximum label length. */
				sprintf( esc_html__( 'Label must be %d characters or fewer.', 'simple-wp-helpdesk' ), self::MAX_LABEL_LEN ),
				400,
				array( 'field' => 'label' )
			);
		}
		if ( ! in_array( $scope, array( 'read', 'write' ), true ) ) {
			self::error( 'swh_apikey_scope_invalid', esc_html__( 'Scope must be "read" or "write".', 'simple-wp-helpdesk' ), 400, array( 'field' => 'scope' ) );
		}
		if ( null !== $rate_limit && ( $rate_limit < self::RATE_LIMIT_MIN || $rate_limit > self::RATE_LIMIT_MAX ) ) {
			self::error(
				'swh_apikey_rate_limit_out_of_range',
				/* translators: 1: minimum rate limit, 2: maximum rate limit. */
				sprintf( esc_html__( 'Rate limit must be between %1$d and %2$d, or blank for the default.', 'simple-wp-helpdesk' ), self::RATE_LIMIT_MIN, self::RATE_LIMIT_MAX ),
				400,
				array( 'field' => 'rate_limit' )
			);
		}

		try {
			$result = ApiKeys::generate( $label, $scope, $rate_limit );
		} catch ( InvalidArgumentException $e ) {
			// Defence in depth — ApiKeys also validates. Should not reach here.
			self::error( 'swh_apikey_generate_failed', esc_html__( 'Could not generate API key.', 'simple-wp-helpdesk' ), 400 );
		}

		// `entry` already excludes the hash per ApiKeys::generate() contract.
		wp_send_json_success(
			array(
				'plaintext' => $result['plaintext'],
				'entry'     => $result['entry'],
			)
		);
	}

	/**
	 * `wp_ajax_swh_api_key_revoke` handler.
	 *
	 * @return void Exits via wp_send_json_*.
	 */
	public static function handle_revoke(): void {
		self::assert_request();

		$id = self::read_string( 'id' );
		if ( '' === $id || 1 !== preg_match( self::ID_PATTERN, $id ) ) {
			self::error( 'swh_apikey_id_invalid', esc_html__( 'Invalid key id.', 'simple-wp-helpdesk' ), 400, array( 'field' => 'id' ) );
		}

		$ok = ApiKeys::revoke( $id );
		if ( ! $ok ) {
			self::error( 'swh_apikey_revoke_failed', esc_html__( 'Could not revoke key — it may already be revoked or no longer exist.', 'simple-wp-helpdesk' ), 400 );
		}

		wp_send_json_success( array( 'revoked_at' => time() ) );
	}

	/**
	 * Shared nonce + capability gate for both endpoints. Exits on failure.
	 */
	private static function assert_request(): void {
		// check_ajax_referer with $die=false returns false on bad/missing nonce.
		$nonce_ok = check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD, false );
		if ( false === $nonce_ok ) {
			self::error( 'swh_apikey_bad_nonce', esc_html__( 'Security check failed.', 'simple-wp-helpdesk' ), 403 );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			self::error( 'swh_apikey_forbidden', esc_html__( 'You do not have permission to manage API keys.', 'simple-wp-helpdesk' ), 403 );
		}
	}

	/**
	 * Read + sanitise a string POST field. Returns '' if missing.
	 */
	private static function read_string( string $key ): string {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assert_request().
			return '';
		}
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( ! is_string( $raw ) ) {
			return '';
		}
		return trim( sanitize_text_field( $raw ) );
	}

	/**
	 * Read an optional integer POST field. Empty/missing → null.
	 */
	private static function read_optional_int( string $key ): ?int {
		if ( ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in assert_request().
			return null;
		}
		$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return null;
			}
		}
		if ( ! is_numeric( $raw ) ) {
			// Out-of-range signal — let validation surface a friendly error.
			return self::RATE_LIMIT_MAX + 1;
		}
		return (int) $raw;
	}

	/**
	 * Emit a structured error envelope and exit.
	 *
	 * @param string              $code    Locked error code (`swh_apikey_*`).
	 * @param string              $message Human-readable message.
	 * @param int                 $status  HTTP status.
	 * @param array<string,mixed> $extra   Optional additional fields (e.g. `field`).
	 */
	private static function error( string $code, string $message, int $status, array $extra = array() ): void {
		$payload = array_merge(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$extra
		);
		wp_send_json_error( $payload, $status );
	}
}
