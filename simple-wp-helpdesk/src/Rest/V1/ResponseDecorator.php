<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use WP_REST_Request;
use WP_REST_Response;

/**
 * Shared response shaping for the v4.1.0 `swh/v1` REST surface.
 *
 * Phase 7 of the v4.1.0 plan (#367). Centralises the `X-RateLimit-*` header
 * emission so each controller's success path is one decorator call rather
 * than four lines repeated six times.
 *
 * The state is set by {@see \SWH\Rest\Auth::check_request()} (key bucket) or
 * by the public `settings/public` permission wrapper (IP bucket) via
 * `WP_REST_Request::set_param( '_swh_rate_limit_state', $state )`. Controllers
 * never construct rate-limit state themselves.
 *
 * @since 4.1.0
 */
final class ResponseDecorator {

	/**
	 * Attach `X-RateLimit-Limit`, `X-RateLimit-Remaining`, and
	 * `X-RateLimit-Reset` headers to a response when the request carries
	 * rate-limit state. Returns the same response instance for fluent use.
	 *
	 * If the request lacks rate-limit state (e.g. an internal test caller, a
	 * code path where Phase 7 wiring was skipped, or a future route that has
	 * no rate limit), the response is returned unchanged. Header emission is
	 * silently skipped rather than erroring — the headers are informational,
	 * never load-bearing.
	 */
	public static function with_rate_limit_headers(
		WP_REST_Response $response,
		WP_REST_Request $request
	): WP_REST_Response {
		$state = $request->get_param( '_swh_rate_limit_state' );
		if ( ! is_array( $state ) ) {
			return $response;
		}
		if ( ! isset( $state['limit'], $state['remaining'], $state['reset'] ) ) {
			return $response;
		}
		if ( ! is_numeric( $state['limit'] ) || ! is_numeric( $state['remaining'] ) || ! is_numeric( $state['reset'] ) ) {
			return $response;
		}
		$response->header( 'X-RateLimit-Limit', (string) (int) $state['limit'] );
		$response->header( 'X-RateLimit-Remaining', (string) (int) $state['remaining'] );
		$response->header( 'X-RateLimit-Reset', (string) (int) $state['reset'] );
		return $response;
	}
}
