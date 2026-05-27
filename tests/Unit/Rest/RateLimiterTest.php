<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use PHPUnit\Framework\TestCase;
use SWH\Rest\RateLimiter;
use WP_Mock;

/**
 * Unit tests for {@see \SWH\Rest\RateLimiter}.
 *
 * Phase 7 of the v4.1.0 plan (#367). Pins the window-rolling, over-limit,
 * and bucket-independence behaviours that the Auth + Bootstrap rate-limit
 * paths depend on.
 *
 * Strategy: in-memory transient + option stores so we can drive the
 * RateLimiter across many calls deterministically without a WP database.
 */
final class RateLimiterTest extends TestCase {

	/** @var array<string,mixed> */
	private array $transients = array();

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->transients = array();
		$this->options    = array();

		WP_Mock::userFunction(
			'get_transient',
			array(
				'return' => function ( string $name ) {
					return $this->transients[ $name ] ?? false;
				},
			)
		);
		WP_Mock::userFunction(
			'set_transient',
			array(
				'return' => function ( string $name, $value, int $ttl = 0 ) {
					$this->transients[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_transient',
			array(
				'return' => function ( string $name ) {
					unset( $this->transients[ $name ] );
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( string $name, $default = null ) {
					return $this->options[ $name ] ?? $default;
				},
			)
		);
		WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( string $name, $value ) {
					$this->options[ $name ] = $value;
					return true;
				},
			)
		);
		WP_Mock::userFunction(
			'delete_option',
			array(
				'return' => function ( string $name ) {
					unset( $this->options[ $name ] );
					return true;
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_first_request_in_fresh_window_is_allowed_with_full_remaining(): void {
		$state = RateLimiter::check( 'swh_rl_api_test1', 5 );
		$this->assertTrue( $state['allowed'] );
		$this->assertSame( 4, $state['remaining'] );
		$this->assertSame( 5, $state['limit'] );
		$this->assertGreaterThanOrEqual( time(), $state['reset'] );
	}

	public function test_over_limit_request_is_rejected_with_zero_remaining(): void {
		$bucket = 'swh_rl_api_overlimit';
		for ( $i = 0; $i < 5; $i++ ) {
			$state = RateLimiter::check( $bucket, 5 );
			$this->assertTrue( $state['allowed'], "Request $i should be allowed" );
		}
		// 6th request — over limit.
		$state = RateLimiter::check( $bucket, 5 );
		$this->assertFalse( $state['allowed'], '6th request must be rejected' );
		$this->assertSame( 0, $state['remaining'] );
		$this->assertSame( 5, $state['limit'] );
	}

	public function test_reset_is_monotonically_non_decreasing_within_window(): void {
		$bucket = 'swh_rl_api_reset';
		$first  = RateLimiter::check( $bucket, 10 );
		$second = RateLimiter::check( $bucket, 10 );
		$this->assertSame( $first['reset'], $second['reset'], 'Reset must be stable inside a window' );
	}

	public function test_window_rollover_resets_count_and_advances_reset(): void {
		$bucket = 'swh_rl_api_roll';
		RateLimiter::check( $bucket, 3 );
		RateLimiter::check( $bucket, 3 );

		// Manually age the stored window past the 60s boundary so the next
		// check rolls.
		$stored                                = $this->transients[ $bucket ];
		$aged_window_start                     = time() - 61;
		$stored['window_start']                = $aged_window_start;
		$this->transients[ $bucket ]           = $stored;
		$this->options[ '_swh_rl_' . $bucket ] = $stored;
		$pre_rollover_reset                    = $aged_window_start + 60;

		$rolled = RateLimiter::check( $bucket, 3 );
		$this->assertTrue( $rolled['allowed'] );
		$this->assertSame( 2, $rolled['remaining'], 'Rolled window starts fresh — count back to 1' );
		// New reset must be ~60s in the future relative to the aged window's
		// reset point. With a 61s age delta the new reset is at least 60s later.
		$this->assertGreaterThanOrEqual(
			$pre_rollover_reset + 60,
			$rolled['reset'],
			'Reset jumped forward by ~60s when the window rolled'
		);
	}

	public function test_separate_buckets_are_independent(): void {
		// Exhaust bucket A.
		for ( $i = 0; $i < 4; $i++ ) {
			RateLimiter::check( 'swh_rl_api_A', 3 );
		}
		// Bucket B is unaffected.
		$b = RateLimiter::check( 'swh_rl_ip_B', 5 );
		$this->assertTrue( $b['allowed'] );
		$this->assertSame( 4, $b['remaining'] );
	}

	public function test_per_key_limit_override_takes_precedence(): void {
		$bucket = 'swh_rl_api_override';
		// First call with custom limit of 2.
		$state = RateLimiter::check( $bucket, 2 );
		$this->assertSame( 2, $state['limit'] );
		$this->assertSame( 1, $state['remaining'] );
		$state = RateLimiter::check( $bucket, 2 );
		$this->assertTrue( $state['allowed'] );
		$this->assertSame( 0, $state['remaining'] );
		$state = RateLimiter::check( $bucket, 2 );
		$this->assertFalse( $state['allowed'], 'Override of 2 must block the 3rd request' );
	}

	public function test_limit_clamped_to_minimum_of_one(): void {
		$state = RateLimiter::check( 'swh_rl_api_zero', 0 );
		$this->assertSame( 1, $state['limit'] );
	}

	public function test_reset_bucket_for_tests_clears_state(): void {
		$bucket = 'swh_rl_api_reset_helper';
		for ( $i = 0; $i < 3; $i++ ) {
			RateLimiter::check( $bucket, 3 );
		}
		$state = RateLimiter::check( $bucket, 3 );
		$this->assertFalse( $state['allowed'], 'Bucket exhausted' );

		RateLimiter::reset_bucket_for_tests( $bucket );
		$fresh = RateLimiter::check( $bucket, 3 );
		$this->assertTrue( $fresh['allowed'] );
		$this->assertSame( 2, $fresh['remaining'], 'Post-reset behaves like fresh state' );
	}

	public function test_returned_state_contains_all_four_fields(): void {
		$state = RateLimiter::check( 'swh_rl_api_shape', 7 );
		$this->assertArrayHasKey( 'allowed', $state );
		$this->assertArrayHasKey( 'remaining', $state );
		$this->assertArrayHasKey( 'reset', $state );
		$this->assertArrayHasKey( 'limit', $state );
	}
}
