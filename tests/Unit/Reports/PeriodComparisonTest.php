<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Reports;

use WP_Mock;
use PHPUnit\Framework\TestCase;

final class PeriodComparisonTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'admin/class-reporting.php';
	}
	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_prior_range_for_30d_is_30d_immediately_before(): void {
		$start = '2026-04-22';
		$end   = '2026-05-22';
		$prior = swh_report_prior_range( $start, $end );
		$this->assertSame( '2026-03-23', $prior['start'] );
		$this->assertSame( '2026-04-22', $prior['end'] );
	}

	public function test_prior_range_for_7d_is_7d_immediately_before(): void {
		$start = '2026-05-15';
		$end   = '2026-05-22';
		$prior = swh_report_prior_range( $start, $end );
		$this->assertSame( '2026-05-08', $prior['start'] );
		$this->assertSame( '2026-05-15', $prior['end'] );
	}

	public function test_prior_range_for_ytd_uses_same_period_last_year(): void {
		// Selected: Jan 1 2026 → May 22 2026; prior: Jan 1 2025 → May 22 2025.
		$prior = swh_report_prior_range( '2026-01-01', '2026-05-22', /* mode */ 'ytd' );
		$this->assertSame( '2025-01-01', $prior['start'] );
		$this->assertSame( '2025-05-22', $prior['end'] );
	}

	public function test_delta_percent_handles_zero_prior_gracefully(): void {
		// Prior was 0, current is 5 — must not divide by zero.
		$this->assertNull( swh_report_delta_percent( 5, 0 ) );
	}

	public function test_delta_percent_returns_signed_percent(): void {
		$this->assertSame( 25.0, swh_report_delta_percent( 50, 40 ) );
		$this->assertSame( -20.0, swh_report_delta_percent( 40, 50 ) );
		$this->assertSame( 0.0, swh_report_delta_percent( 100, 100 ) );
	}
}
