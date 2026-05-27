<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use function apply_filters;
use function get_option;

/**
 * Hard allowlist of settings exposable via the public REST endpoint.
 *
 * **SECURITY-CRITICAL.** This file is the single source of truth for which
 * settings keys are safe to expose to UNAUTHENTICATED callers via
 * `GET /swh/v1/settings/public`. Wildcard exposure is forbidden. Adding a
 * key here is a deliberate decision; reviewers MUST confirm the value can
 * never contain secrets (webhook secrets, API keys, SMTP creds, email
 * template bodies, etc.).
 *
 * **Review burden when adding a key:**
 *   1. Edit this file (add to {@see map()} + a reader).
 *   2. Edit `docs/internal/security-model.md` — "Public settings allowlist".
 *   3. Add (or extend) a test in
 *      `tests/Unit/Rest/V1/PublicSettingsAllowlistTest.php`.
 *
 * The drift-detector test
 * {@see \SWH\Tests\Unit\Rest\V1\PublicSettingsAllowlistTest::test_allowlist_only_contains_locked_keys()}
 * fails immediately if a key is added without updating the locked list
 * captured in the test — preventing a silent secret leak.
 *
 * Wildcards / prefix matches / "loop over all `swh_*` options" patterns are
 * forbidden. The allowlist is an explicit array. Phase 5 of the v4.1.0
 * release plan (#364).
 *
 * @since 4.1.0
 */
final class PublicSettingsAllowlist {

	/**
	 * Mapping: response-key => callable that reads + sanitises the value.
	 *
	 * Each reader MUST return ONLY the public-safe shape — no raw option
	 * dump. Readers are responsible for casting, sanitising, and filtering
	 * to the locked sub-schema documented in `api-contract.md`.
	 *
	 * @return array<string,callable>
	 */
	public static function map(): array {
		return array(
			'priority_labels' => array( self::class, 'read_priority_labels' ),
			'status_labels'   => array( self::class, 'read_status_labels' ),
			'sla_thresholds'  => array( self::class, 'read_sla_thresholds' ),
			'features'        => array( self::class, 'read_features' ),
		);
	}

	/**
	 * Resolve the allowlist into a response payload.
	 *
	 * Iterates {@see map()} exactly — keys in the response are guaranteed to
	 * be a subset of the allowlist keys.
	 *
	 * @return array<string,mixed>
	 */
	public static function resolve(): array {
		$out = array();
		foreach ( self::map() as $key => $cb ) {
			$out[ $key ] = call_user_func( $cb );
		}
		return $out;
	}

	/**
	 * Configured ticket priority labels (e.g. ["Low", "Medium", "High"]).
	 *
	 * @return string[]
	 */
	public static function read_priority_labels(): array {
		$raw = get_option( 'swh_ticket_priorities', 'Low, Medium, High' );
		return self::split_csv( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * Configured ticket status labels.
	 *
	 * @return string[]
	 */
	public static function read_status_labels(): array {
		$raw = get_option( 'swh_ticket_statuses', 'Open, In Progress, Resolved, Closed' );
		return self::split_csv( is_string( $raw ) ? $raw : '' );
	}

	/**
	 * SLA thresholds in hours.
	 *
	 * Today the plugin stores a single warn/breach pair, not per-priority
	 * values. The locked response shape exposes the breach hours under
	 * `high` (the most common operator interpretation) and the warn hours
	 * under `medium`; `low` and `urgent` are null until per-priority
	 * thresholds are added in a future release.
	 *
	 * @return array{low:int|null,medium:int|null,high:int|null,urgent:int|null}
	 */
	public static function read_sla_thresholds(): array {
		$warn   = get_option( 'swh_sla_warn_hours', '4' );
		$breach = get_option( 'swh_sla_breach_hours', '8' );
		return array(
			'low'    => null,
			'medium' => is_numeric( $warn ) ? (int) $warn : null,
			'high'   => is_numeric( $breach ) ? (int) $breach : null,
			'urgent' => null,
		);
	}

	/**
	 * Feature flags derived from operator settings. Booleans only — no
	 * configuration values, secrets, or labels.
	 *
	 * @return array{csat:bool,portal:bool,attachments:bool}
	 */
	public static function read_features(): array {
		$csat_raw        = get_option( 'swh_csat_enabled', true );
		$portal_page_id  = get_option( 'swh_ticket_page_id', 0 );
		$upload_count    = get_option( 'swh_max_upload_count', 5 );

		return array(
			'csat'        => self::truthy( $csat_raw ),
			'portal'      => is_numeric( $portal_page_id ) && (int) $portal_page_id > 0,
			'attachments' => is_numeric( $upload_count ) && (int) $upload_count > 0,
		);
	}

	/**
	 * Split a comma-separated label list into a trimmed string[].
	 *
	 * @return string[]
	 */
	private static function split_csv( string $raw ): array {
		$parts = array_map( 'trim', explode( ',', $raw ) );
		$out   = array();
		foreach ( $parts as $p ) {
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * Cast a stored option to a boolean using the lenient WP convention.
	 *
	 * @param mixed $v Raw option value.
	 */
	private static function truthy( $v ): bool {
		if ( is_bool( $v ) ) {
			return $v;
		}
		if ( is_numeric( $v ) ) {
			return (int) $v > 0;
		}
		if ( is_string( $v ) ) {
			$norm = strtolower( trim( $v ) );
			return in_array( $norm, array( '1', 'yes', 'true', 'on' ), true );
		}
		return false;
	}
}
