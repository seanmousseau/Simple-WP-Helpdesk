<?php
declare(strict_types=1);

namespace SWH\Rest;

use function delete_option;
use function delete_transient;
use function get_option;
use function get_transient;
use function set_transient;
use function time;
use function update_option;

/**
 * Per-bucket rolling rate limiter for the v4.1.0 `swh/v1` REST surface.
 *
 * Phase 7 of the v4.1.0 plan (#367). Two callers populate the bucket key:
 *
 *  - {@see Auth::check_request()} — successful API-key auth calls
 *    `RateLimiter::check( "swh_rl_api_{$key_id}", $limit )` and stashes the
 *    returned state on the request via
 *    `WP_REST_Request::set_param( '_swh_rate_limit_state', $state )`.
 *  - {@see Bootstrap::settings_public_permission()} — wraps the
 *    `settings/public` route's permission callback to call
 *    `RateLimiter::check( "swh_rl_ip_" . hash_hmac('sha256', $ip, wp_salt('nonce')), 20 )`
 *    with the same state-on-request mechanism.
 *
 * The state is later consumed by {@see ResponseDecorator::with_rate_limit_headers()}
 * which emits `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
 * `X-RateLimit-Reset` on every controller success response.
 *
 * **Storage.** Tries WP transients first; persists a parallel `wp_options`
 * row (`_swh_rl_<bucket>`) as a backing floor for sites without persistent
 * object cache where transient TTLs are honoured but transient reads can
 * return false under cache pressure. Read path prefers the transient.
 *
 * **Value shape.** `{ count: int, window_start: unix_ts }` — a fixed window,
 * not a sliding one. When `now >= window_start + 60` the window rolls and
 * the counter resets to 1.
 *
 * **Bucket key is the caller's responsibility.** This class never sees a raw
 * IP — invariant locked at kickoff. Callers are responsible for hashing IPs
 * via `hash_hmac('sha256', $ip, wp_salt('nonce'))` before constructing the
 * `swh_rl_ip_*` key.
 *
 * @since 4.1.0
 */
final class RateLimiter {

	/** Rolling-window length in seconds. */
	private const WINDOW_SECONDS = 60;

	/** Option prefix for the wp_options fallback floor. */
	private const OPTION_PREFIX = '_swh_rl_';

	/**
	 * Check + increment a rate-limit bucket.
	 *
	 * Always returns a state array — even on the over-limit path — so callers
	 * can emit `X-RateLimit-*` headers on the 429 response.
	 *
	 * **Concurrency.** The increment is read-modify-write and is NOT atomic
	 * under concurrent requests. On sites with persistent object cache
	 * (Redis/Memcached) the underlying transient `set` is effectively
	 * last-writer-wins; on the wp_options floor it is `update_option`. In the
	 * worst case a small burst of concurrent requests at the boundary of the
	 * rolling window may each see the pre-increment count and write the same
	 * `count + 1`, letting one or two extra requests through before the next
	 * window. The limiter is sized for abuse-prevention, not for accounting,
	 * so a single-digit overshoot under contention is acceptable. If a
	 * stricter floor is ever required we'd switch the count to a scalar key
	 * and use `wp_cache_incr()` for atomic increments.
	 *
	 * @param string $bucket_key Already-prefixed: `swh_rl_api_<id>` or `swh_rl_ip_<hash>`.
	 * @param int    $limit      Max requests per 60-second rolling window.
	 * @return array{ allowed: bool, remaining: int, reset: int, limit: int }
	 */
	public static function check( string $bucket_key, int $limit ): array {
		if ( $limit < 1 ) {
			$limit = 1;
		}

		$now   = time();
		$state = self::read( $bucket_key );

		if ( null === $state ) {
			// Fresh window.
			$state = array(
				'count'        => 1,
				'window_start' => $now,
			);
			self::write( $bucket_key, $state );
			return array(
				'allowed'   => true,
				'remaining' => max( 0, $limit - 1 ),
				'reset'     => $now + self::WINDOW_SECONDS,
				'limit'     => $limit,
			);
		}

		$count        = (int) $state['count'];
		$window_start = (int) $state['window_start'];

		// Roll the window if it expired.
		if ( $now >= $window_start + self::WINDOW_SECONDS ) {
			$state = array(
				'count'        => 1,
				'window_start' => $now,
			);
			self::write( $bucket_key, $state );
			return array(
				'allowed'   => true,
				'remaining' => max( 0, $limit - 1 ),
				'reset'     => $now + self::WINDOW_SECONDS,
				'limit'     => $limit,
			);
		}

		// Increment within the active window.
		++$count;
		$state['count'] = $count;
		self::write( $bucket_key, $state );

		$allowed = $count <= $limit;
		return array(
			'allowed'   => $allowed,
			'remaining' => max( 0, $limit - $count ),
			'reset'     => $window_start + self::WINDOW_SECONDS,
			'limit'     => $limit,
		);
	}

	/**
	 * Read the current bucket state. Prefers transient; falls back to option
	 * row when the transient is missing (covers sites whose persistent cache
	 * dropped the value but where the option floor still has it).
	 *
	 * @return array{count:int,window_start:int}|null
	 */
	private static function read( string $bucket_key ): ?array {
		$value = get_transient( $bucket_key );
		if ( is_array( $value ) && isset( $value['count'], $value['window_start'] ) && is_numeric( $value['count'] ) && is_numeric( $value['window_start'] ) ) {
			return array(
				'count'        => (int) $value['count'],
				'window_start' => (int) $value['window_start'],
			);
		}

		// Option fallback floor — used when the transient store evicted under
		// memory pressure but the row is still valid time-wise.
		$option_key = self::OPTION_PREFIX . $bucket_key;
		$option     = get_option( $option_key, null );
		if ( is_array( $option ) && isset( $option['count'], $option['window_start'] ) && is_numeric( $option['count'] ) && is_numeric( $option['window_start'] ) ) {
			$window_start = (int) $option['window_start'];
			if ( time() < $window_start + self::WINDOW_SECONDS ) {
				return array(
					'count'        => (int) $option['count'],
					'window_start' => $window_start,
				);
			}
			// Expired floor — delete it so wp_options doesn't grow unbounded
			// on sites without persistent object cache. Window + a small grace
			// (handled by the comparison above) means anything reaching here
			// is past the rolling window and safe to discard.
			delete_option( $option_key );
		}

		return null;
	}

	/**
	 * Persist the bucket state to both transient (primary) and option (floor).
	 *
	 * @param string                            $bucket_key Bucket key.
	 * @param array{count:int,window_start:int} $state      State payload.
	 */
	private static function write( string $bucket_key, array $state ): void {
		set_transient( $bucket_key, $state, self::WINDOW_SECONDS );
		update_option( self::OPTION_PREFIX . $bucket_key, $state, false );
	}

	/**
	 * Test-only helper. Clears a single bucket from both stores. Production
	 * code uses TTL expiry — never call this from controller paths.
	 *
	 * @internal
	 */
	public static function reset_bucket_for_tests( string $bucket_key ): void {
		delete_transient( $bucket_key );
		delete_option( self::OPTION_PREFIX . $bucket_key );
	}
}
