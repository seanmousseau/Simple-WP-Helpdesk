<?php
/**
 * Unit tests for reporting dashboard functions in admin/class-reporting.php.
 *
 * swh_report_status_breakdown() uses WP_Query (stubbed in tests/bootstrap.php).
 * swh_report_avg_resolution_time() uses $wpdb (mocked inline via anonymous class).
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for swh_report_status_breakdown() and swh_report_avg_resolution_time().
 */
class ReportingTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'admin/class-reporting.php';

		// swh_get_statuses() routes through swh_apply_deprecated_filter()
		// (v4.0.0 #360); stub apply_filters_deprecated to return arg[0].
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->andReturnUsing(
				static function ( $hook, $args ) {
					return $args[0];
				}
			);
	}

	public function tearDown(): void {
		parent::tearDown();
		// Clean up the $wpdb global set by individual tests.
		unset( $GLOBALS['wpdb'] );
	}

	// -------------------------------------------------------------------------
	// swh_report_status_breakdown — return shape
	// -------------------------------------------------------------------------

	/**
	 * Returns an array keyed by status label with integer ticket counts.
	 *
	 * The WP_Query stub (tests/bootstrap.php) returns found_posts = 0 for
	 * every query, so every status maps to 0. The test only verifies the
	 * shape (array of string → int pairs) rather than specific counts.
	 */
	public function test_status_breakdown_returns_array(): void {
		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn( $key, $default = false ) => $default );

		WP_Mock::userFunction( 'apply_filters' )
			->andReturnArg( 1 );

		$result = swh_report_status_breakdown();

		// v4.0 shape: { data, range, prior, prior_range }.
		$this->assertIsArray( $result, 'Return value must be an array' );
		$this->assertArrayHasKey( 'data', $result );
		$this->assertArrayHasKey( 'range', $result );
		$this->assertArrayHasKey( 'prior', $result );
		$this->assertArrayHasKey( 'prior_range', $result );

		$data = $result['data'];
		$this->assertIsArray( $data, 'data must be an array' );
		$this->assertNotEmpty( $data, 'At least one status must be present' );
		foreach ( $data as $status => $count ) {
			$this->assertIsString( $status, 'Status key must be a string' );
			$this->assertIsInt( $count, 'Count must be an integer' );
		}
	}

	// -------------------------------------------------------------------------
	// swh_report_avg_resolution_time — row exclusion logic
	// -------------------------------------------------------------------------

	/**
	 * Rows where resolved_ts <= open_ts are excluded from the average.
	 *
	 * The SQL INNER JOIN already filters tickets without _resolved_timestamp.
	 * This test verifies the PHP-side guard that also discards chronologically
	 * invalid rows (resolved_ts at zero or before the ticket was opened).
	 *
	 * @see #136
	 */
	public function test_avg_resolution_excludes_no_timestamp(): void {
		global $wpdb;

		$open_time = strtotime( '2026-01-01 10:00:00' );

		// Valid row: resolved exactly 1 hour after opening.
		$valid_row                = new stdClass();
		$valid_row->post_date_gmt = '2026-01-01 10:00:00';
		$valid_row->resolved_ts   = (string) ( $open_time + 3600 );

		// Invalid row: resolved_ts is zero — chronologically impossible; must be excluded.
		$bad_row                = new stdClass();
		$bad_row->post_date_gmt = '2026-01-01 10:00:00';
		$bad_row->resolved_ts   = '0';

		$wpdb = $this->build_wpdb_mock( array( $valid_row, $bad_row ) );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn( $key, $default = '' ) => $default );

		// Call the inner _query helper directly — it tests the exclusion logic
		// without the v4.0 range-wrapper overhead.
		$result = swh_report_avg_resolution_time_query( '2026-01-01', '2026-01-31' );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'avg_seconds', $result );
		$this->assertArrayHasKey( 'count', $result );
		$this->assertSame( 1, $result['count'], 'Only the valid row (resolved_ts > open_ts) must be counted' );
		$this->assertSame( 3600, $result['avg_seconds'] );
	}

	/**
	 * Returns the zero-safe shape when no matching tickets exist in the window.
	 */
	public function test_avg_resolution_empty_result(): void {
		global $wpdb;
		$wpdb = $this->build_wpdb_mock( array() );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn( $key, $default = '' ) => $default );

		// Use the inner _query helper for the same reason as above.
		$result = swh_report_avg_resolution_time_query( '2026-01-01', '2026-01-31' );

		$this->assertSame( 0, $result['avg_seconds'] );
		$this->assertSame( 0, $result['count'] );
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Builds a minimal $wpdb stand-in that returns pre-seeded rows from get_results().
	 *
	 * @param array<int, object> $rows Rows to return from get_results().
	 * @return object
	 */
	private function build_wpdb_mock( array $rows ): object {
		return new class( $rows ) {
			/** @var string */
			public string $posts = 'wp_posts';
			/** @var string */
			public string $postmeta = 'wp_postmeta';
			/** @var array<int, object> */
			private array $rows;

			/** @param array<int, object> $rows */
			public function __construct( array $rows ) {
				$this->rows = $rows;
			}

			/** @param mixed ...$args */
			public function prepare( string $query, ...$args ): string {
				return $query;
			}

			/** @return array<int, object> */
			public function get_results( string $query ): array {
				return $this->rows;
			}
		};
	}
}
