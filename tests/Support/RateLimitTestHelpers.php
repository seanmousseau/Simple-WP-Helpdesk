<?php
/**
 * Test-suite helpers for clearing rate-limit state between tests.
 *
 * Phase 7 of the v4.1.0 plan (#367). Centralises the per-test cleanup that
 * the existing {@see \SWH\Tests\Unit\RateLimitTest} was doing by hand (manual
 * `delete_transient` calls + per-test option-store reset). Loaded by
 * `tests/bootstrap.php` so every PHPUnit test file has the helper available.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

if ( ! function_exists( 'swh_test_clear_rate_limits' ) ) {

	/**
	 * Clear all SWH REST rate-limit state — transients AND the wp_options
	 * fallback floor written by {@see \SWH\Rest\RateLimiter::write()}.
	 *
	 * The unit-test stack runs under WP_Mock with a per-test in-memory
	 * transient + option store. Real WP environments (Playwright / e2e)
	 * exercise the same helper via a CLI shim (Phase 10). Implementation
	 * tries every prefix used by the v4.1 surface:
	 *
	 *   - `swh_rl_api_*`  — per-key buckets ({@see \SWH\Rest\Auth})
	 *   - `swh_rl_ip_*`   — per-IP buckets ({@see \SWH\Rest\Bootstrap})
	 *   - `swh_rl_*`      — legacy `swh_is_rate_limited()` buckets
	 *   - `_swh_rl_*`     — wp_options fallback floor (written by RateLimiter)
	 *
	 * In a WP-Mock environment with no transient/option store wired up, this
	 * is a no-op — the helper is safe to call unconditionally.
	 */
	function swh_test_clear_rate_limits(): void {
		global $wpdb;

		// 1. Transient store. WP exposes no "delete-all-with-prefix" primitive
		// short of a direct DB query — try the documented per-key path when a
		// database is available, otherwise rely on per-test option-store reset.
		if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) ) {
			$prefix = property_exists( $wpdb, 'options' ) ? (string) $wpdb->options : 'wp_options';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				"DELETE FROM {$prefix} WHERE option_name LIKE '\\_transient\\_swh\\_rl\\_%'"
				. " OR option_name LIKE '\\_transient\\_timeout\\_swh\\_rl\\_%'"
				. " OR option_name LIKE '\\_swh\\_rl\\_%'"
				// Legacy un-prefixed rows written by swh_is_rate_limited() — these
				// existed before the RateLimiter class added the leading underscore
				// prefix; clean them up too so the broad-stroke reset is honest.
				. " OR option_name LIKE 'swh\\_rl\\_%'"
			);
			// phpcs:enable
		}

		// 2. WP_Mock test environment — call the RateLimiter's own per-bucket
		// reset for any buckets a test explicitly tracked. Tests that need
		// surgical control can call \SWH\Rest\RateLimiter::reset_bucket_for_tests()
		// directly. This helper is the broad-stroke "fresh state" reset.
		if ( class_exists( '\\SWH\\Rest\\RateLimiter' ) ) {
			// Nothing more to do — bucket-name enumeration requires a DB hit
			// (covered by branch above) or a per-test tracking layer. The
			// option-store fixtures most tests use are reset between tests.
			return;
		}
	}
}
