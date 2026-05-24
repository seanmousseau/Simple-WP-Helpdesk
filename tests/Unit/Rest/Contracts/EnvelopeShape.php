<?php
/**
 * Single source of truth for REST response envelope shapes.
 *
 * Tests assert envelopes against the canonical signatures defined here.
 * If a controller changes its response shape, the test fails and the author
 * must either (a) restore the old shape, or (b) update both this signature
 * AND the corresponding row in docs/internal/api-contract.md AND notify any
 * JS consumers (admin-inbox, portal-v2, reports-v2).
 *
 * Envelopes are intentionally minimal: top-level `error` key for failures,
 * resource-named key for success (`view`, `views`, `tickets`, etc.). No
 * Microsoft-style { success: false } pattern — the HTTP status code carries
 * the success bit, the body carries detail.
 *
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Unit\Rest\Contracts;

final class EnvelopeShape {

	/**
	 * Asserts an error envelope matches `{ error: <expected_code>, ...extras }`.
	 *
	 * @param array<string, mixed> $data         Response data.
	 * @param string               $expected_code Expected value of the `error` key.
	 * @param string[]             $extra_keys    Optional additional top-level keys the envelope must include.
	 */
	public static function describe_error(
		array $data,
		string $expected_code,
		array $extra_keys = array()
	): string {
		$problems = array();
		if ( ! array_key_exists( 'error', $data ) ) {
			$problems[] = "missing top-level 'error' key";
		} elseif ( $data['error'] !== $expected_code ) {
			$problems[] = "error code is '" . self::stringify( $data['error'] ) . "' (expected '$expected_code')";
		}
		foreach ( $extra_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$problems[] = "missing required extra key '$key'";
			}
		}
		// Disallow unexpected top-level keys (other than 'error' and declared extras).
		$allowed = array_merge( array( 'error' ), $extra_keys );
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				$problems[] = "unexpected top-level key '$key' (not in error envelope contract)";
			}
		}
		return implode( '; ', $problems );
	}

	/**
	 * Asserts a success envelope contains all of $required_keys at the top level
	 * and that there are no other top-level keys.
	 *
	 * @param array<string, mixed> $data          Response data.
	 * @param string[]             $required_keys Keys that must be present (exact set).
	 */
	public static function describe_success(
		array $data,
		array $required_keys
	): string {
		$problems = array();
		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				$problems[] = "missing required key '$key'";
			}
		}
		foreach ( array_keys( $data ) as $key ) {
			if ( ! in_array( $key, $required_keys, true ) ) {
				$problems[] = "unexpected top-level key '$key' (not in contract)";
			}
		}
		return implode( '; ', $problems );
	}

	private static function stringify( mixed $value ): string {
		if ( is_string( $value ) ) {
			return $value;
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		return '<' . gettype( $value ) . '>';
	}
}
