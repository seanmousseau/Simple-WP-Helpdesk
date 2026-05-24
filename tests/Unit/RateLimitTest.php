<?php
/**
 * Domain tests for swh_is_rate_limited().
 *
 * Pins the per-action-per-IP boundary behaviour: first call within the TTL
 * window returns false (allowed) and stores the lock; subsequent calls
 * return true (rate-limited) until the lock expires. Different action keys
 * are independent — closing a ticket does NOT lock the reopen path.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

final class RateLimitTest extends TestCase {

	/**
	 * @var array<string, mixed> In-memory option store used to simulate
	 *                            get_option/update_option across calls.
	 */
	private array $option_store = array();

	protected function setUp(): void {
		WP_Mock::setUp();
		$this->option_store = array();

		// Wire the plugin helpers up only once per test process.
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';

		// Stub the WP option store so the rate-limit function can persist
		// across calls within a single test. WP_Mock::userFunction always
		// returns the same value; we need stateful behaviour.
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( $key, $default = false ) {
					return $this->option_store[ $key ] ?? $default;
				},
			)
		);
		WP_Mock::userFunction(
			'update_option',
			array(
				'return' => function ( $key, $value ) {
					$this->option_store[ $key ] = $value;
					return true;
				},
			)
		);

		// Stable client IP so md5() key is deterministic across calls.
		WP_Mock::userFunction(
			'swh_get_client_ip',
			array( 'return' => '203.0.113.1' )
		);

		// The deprecated-filter wrapper just runs the value through unchanged
		// for these tests — we're testing the rate-limit boundary, not the
		// filter pipeline.
		WP_Mock::userFunction(
			'swh_apply_deprecated_filter',
			array(
				'return' => function ( $_tag, $args ) {
					return is_array( $args ) ? ( $args[0] ?? null ) : $args;
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_first_call_is_not_rate_limited(): void {
		$this->assertFalse(
			swh_is_rate_limited( 'portal_reply_42', 30 ),
			'First call within an empty store must be allowed.'
		);
	}

	public function test_second_call_within_ttl_is_rate_limited(): void {
		swh_is_rate_limited( 'portal_reply_42', 30 );
		$this->assertTrue(
			swh_is_rate_limited( 'portal_reply_42', 30 ),
			'Second call within the TTL window must be rate-limited.'
		);
	}

	public function test_different_action_key_is_not_blocked_by_close(): void {
		// This is the load-bearing v3.x regression test: closing a ticket
		// must NOT block the immediate-reopen path. Different action keys
		// keep independent locks.
		swh_is_rate_limited( 'portal_close_42', 30 );
		$this->assertFalse(
			swh_is_rate_limited( 'portal_reopen_42', 30 ),
			'A close-action lock must not prevent the reopen-action — they use independent per-action keys.'
		);
	}

	public function test_different_ticket_id_is_not_blocked(): void {
		// Cross-ticket isolation: rate-limiting ticket 42 must not affect 43.
		swh_is_rate_limited( 'portal_reply_42', 30 );
		$this->assertFalse(
			swh_is_rate_limited( 'portal_reply_43', 30 ),
			'A reply lock on ticket 42 must not prevent replies to a different ticket.'
		);
	}

	public function test_expired_lock_allows_new_call(): void {
		// Simulate an expired lock by writing a past timestamp into the store
		// at the key the function would generate.
		$key                       = 'swh_rl_' . md5( 'portal_reply_42_203.0.113.1' );
		$this->option_store[ $key ] = time() - 1;
		$this->assertFalse(
			swh_is_rate_limited( 'portal_reply_42', 30 ),
			'A lock whose stored expiry is in the past must NOT count as rate-limited.'
		);
	}

}
