<?php
declare(strict_types=1);

namespace SWH\Rest;

use WP_Error;
use WP_REST_Request;

use function current_user_can;
use function is_user_logged_in;

/**
 * REST authentication + authorization for the v4.1.0 `swh/v1` namespace.
 *
 * Phase 2 of the v4.1.0 plan. This is the only place where API key bearer
 * tokens are extracted from incoming requests and matched against
 * {@see ApiKeys}. Every Phase 3+ controller route uses the
 * {@see swh_rest_require()} factory to install `check_request()` as its
 * `permission_callback`.
 *
 * Logic order (locked by docs/internal/plan_v4.1.0.md Phase 2):
 *
 *  1. Logged-in WP user with the `edit_posts` capability → `true`.
 *  2. Logged-in WP user without `edit_posts` → CAPABILITY_REQUIRED (403).
 *  3. No / malformed `Authorization` header → AUTH_REQUIRED (401).
 *  4. Bearer token that does not start with `swhk_` → AUTH_INVALID (401).
 *  5. Bearer token that does not resolve to an active entry → AUTH_INVALID (401).
 *  6. Scope mismatch (`read` key on a `write` route) → SCOPE_INSUFFICIENT (403).
 *  7. On success: call {@see ApiKeys::touch_last_used()}, stash the entry on
 *     the request for downstream attribution, return `true`.
 *
 * **API key attribution.** A successful API-key auth stashes the full key
 * entry on the request via `WP_REST_Request::set_param( '_swh_api_key', $entry )`.
 * This is intentional — Phase 9's audit log will read the entry from the
 * controller (`$request->get_param( '_swh_api_key' )`) without re-resolving
 * the token. The leading underscore signals "internal, not a public param";
 * the JSON schema for every controller route excludes underscore-prefixed
 * params from documented input.
 *
 * **No plaintext logging.** This class never writes the `Authorization`
 * header value or the extracted bearer token to any log sink. The only place
 * the plaintext exists is inside the in-memory request scope.
 *
 * **Testability.** {@see check_request()} accepts an optional `ApiKeyLookup`
 * callable so unit tests can inject a deterministic stub for
 * `ApiKeys::find_by_plaintext()`. Production code uses the default which
 * delegates to the static class method.
 *
 * **Per-request idempotency (v4.1.0 fix).** WP REST invokes a route's
 * `permission_callback` more than once per HTTP request. Specifically,
 * `rest_send_allow_header()` in `wp-includes/rest-api.php` (lines 866-898)
 * walks every handler bound to the matched route and re-invokes its
 * `permission_callback` to build the response's `Allow:` header. There is no
 * filter to opt out — it is part of WP's REST contract.
 *
 * The naive implementation therefore ran `password_verify()` (bcrypt cost 12),
 * `RateLimiter::check()` (rate-limit increment), and the `_swh_api_key` +
 * `_swh_rate_limit_state` stash 2-3× per request. Effective rate-limit became
 * 30/min instead of the documented 60/min.
 *
 * Fix: cache the per-scope result on the request via
 * `WP_REST_Request::set_param( '_swh_auth_result_<scope>', $result )` and
 * short-circuit at the top of {@see check_request()}. The cache is
 * scope-aware because a single route may bind two handlers with different
 * required scopes; each must answer independently but only do the auth + rate
 * limit work once. The actual logic lives in the private helper
 * {@see compute_check()}.
 *
 * @since 4.1.0
 */
final class Auth {

	/** Bearer-token prefix (matches {@see ApiKeys}). */
	private const PLAINTEXT_PREFIX = 'swhk_';

	/** The capability that gates logged-in user access for every v4.1 route. */
	private const REQUIRED_CAPABILITY = 'edit_posts';

	/** Default per-key rate-limit budget (requests / 60s rolling window). */
	private const DEFAULT_RATE_LIMIT = 60;

	/**
	 * Allowed scopes and their satisfies-set. A `write` key satisfies both
	 * `read` and `write` routes; a `read` key satisfies only `read` routes.
	 *
	 * @var array<string,array<int,string>>
	 */
	private const SCOPE_GRANTS = array(
		'read'  => array( 'read' ),
		'write' => array( 'read', 'write' ),
	);

	/**
	 * Check a request for permission to access a route requiring `$scope`.
	 *
	 * @param WP_REST_Request $request         Incoming REST request.
	 * @param string          $scope           Required scope ('read' or 'write').
	 * @param callable|null   $api_key_lookup  Test seam — function ( string $plaintext ): ?array.
	 *                                         Defaults to {@see ApiKeys::find_by_plaintext()}.
	 * @return true|WP_Error
	 */
	public static function check_request(
		WP_REST_Request $request,
		string $scope,
		?callable $api_key_lookup = null
	) {
		// Request-level idempotency. WP REST invokes permission_callback more
		// than once per HTTP request — `rest_send_allow_header()` runs it once
		// per handler on the matched route to build the `Allow:` header (see
		// wp-includes/rest-api.php lines 866-898). Without this guard, the
		// bcrypt verify inside ApiKeys::find_by_plaintext, RateLimiter::check
		// (which increments), and the request stashes would all fire 2-3×
		// per request and the effective rate-limit would be ~half what's
		// documented.
		//
		// Cache key is per-scope: a route can bind a GET handler that wants
		// `read` and a PATCH handler that wants `write`. Both run under the
		// Allow-header walk and must answer independently — but each scope
		// answer only needs to be computed once.
		$cache_key = '_swh_auth_result_' . $scope;
		$cached    = $request->get_param( $cache_key );
		if ( true === $cached || $cached instanceof \WP_Error ) {
			return $cached;
		}

		$result = self::compute_check( $request, $scope, $api_key_lookup );
		$request->set_param( $cache_key, $result );
		return $result;
	}

	/**
	 * Compute the permission result for a request + scope. This is the body of
	 * {@see check_request()} extracted into a helper so the public entrypoint
	 * can wrap it in a per-request idempotency cache without changing
	 * observable behavior on any of the eight return paths.
	 *
	 * @param WP_REST_Request $request         Incoming REST request.
	 * @param string          $scope           Required scope ('read' or 'write').
	 * @param callable|null   $api_key_lookup  Test seam — see {@see check_request()}.
	 * @return true|WP_Error
	 */
	private static function compute_check(
		WP_REST_Request $request,
		string $scope,
		?callable $api_key_lookup = null
	) {
		// 1 + 2: logged-in WP user path. If a user is logged in we resolve
		// auth via WP's own capability system. We do NOT fall through to the
		// API key path on a logged-in user — that would let a low-capability
		// user smuggle a higher-capability bearer token to elevate
		// privileges within the same session.
		if ( is_user_logged_in() ) {
			if ( current_user_can( self::REQUIRED_CAPABILITY ) ) {
				return true;
			}
			return Errors::respond(
				Errors::CAPABILITY_REQUIRED,
				403,
				__( 'You do not have permission to access this endpoint.', 'simple-wp-helpdesk' )
			);
		}

		// 3: extract the Authorization header.
		$header = (string) $request->get_header( 'authorization' );
		if ( '' === $header ) {
			return Errors::respond(
				Errors::AUTH_REQUIRED,
				401,
				__( 'Authentication required.', 'simple-wp-helpdesk' )
			);
		}

		// Header must be exactly "Bearer <token>" with a single space.
		if ( 0 !== strncasecmp( $header, 'Bearer ', 7 ) ) {
			return Errors::respond(
				Errors::AUTH_REQUIRED,
				401,
				__( 'Authentication required.', 'simple-wp-helpdesk' )
			);
		}

		$plaintext = trim( substr( $header, 7 ) );
		if ( '' === $plaintext ) {
			return Errors::respond(
				Errors::AUTH_REQUIRED,
				401,
				__( 'Authentication required.', 'simple-wp-helpdesk' )
			);
		}

		// 4: format check on the plaintext. The prefix is public — a wrong
		// prefix is a malformed token, not a credential leak.
		if ( 0 !== strncmp( $plaintext, self::PLAINTEXT_PREFIX, strlen( self::PLAINTEXT_PREFIX ) ) ) {
			return Errors::respond(
				Errors::AUTH_INVALID,
				401,
				__( 'Invalid API key.', 'simple-wp-helpdesk' )
			);
		}

		// 5: resolve the token. Lookup is constant-time inside ApiKeys.
		$lookup = $api_key_lookup ?? static fn ( string $p ): ?array => ApiKeys::find_by_plaintext( $p );
		$entry  = $lookup( $plaintext );
		if ( ! is_array( $entry ) ) {
			return Errors::respond(
				Errors::AUTH_INVALID,
				401,
				__( 'Invalid API key.', 'simple-wp-helpdesk' )
			);
		}

		// 6: scope check.
		$entry_scope = isset( $entry['scope'] ) && is_string( $entry['scope'] ) ? $entry['scope'] : '';
		if ( ! self::scope_satisfies( $entry_scope, $scope ) ) {
			return Errors::respond(
				Errors::SCOPE_INSUFFICIENT,
				403,
				__( 'API key scope is insufficient for this endpoint.', 'simple-wp-helpdesk' )
			);
		}

		// 7a: touch last_used (rate-limited inside ApiKeys to once / 5 min).
		$id = isset( $entry['id'] ) && is_string( $entry['id'] ) ? $entry['id'] : '';
		if ( '' !== $id ) {
			ApiKeys::touch_last_used( $id );
		}

		// 7b: stash the entry on the request. Phase 9 audit log reads this.
		$request->set_param( '_swh_api_key', $entry );

		// 8: per-key rate limit (Phase 7, #367). The per-key override on the
		// entry wins over the 60/min default. State is always stashed on the
		// request so {@see \SWH\Rest\V1\ResponseDecorator::with_rate_limit_headers()}
		// can emit `X-RateLimit-*` on success AND so the rest_post_dispatch
		// filter in Bootstrap can attach them to the 429 response on the
		// over-limit path.
		$override = isset( $entry['rate_limit'] ) && is_numeric( $entry['rate_limit'] )
			? (int) $entry['rate_limit']
			: 0;
		$limit    = $override > 0 ? $override : self::DEFAULT_RATE_LIMIT;
		$state    = RateLimiter::check( 'swh_rl_api_' . $id, $limit );
		$request->set_param( '_swh_rate_limit_state', $state );

		if ( ! $state['allowed'] ) {
			$retry_after = max( 1, $state['reset'] - time() );
			return new \WP_Error(
				Errors::RATE_LIMITED,
				__( 'API rate limit exceeded. Try again later.', 'simple-wp-helpdesk' ),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		return true;
	}

	/**
	 * Convenience factory used by the global {@see swh_rest_require()} helper.
	 *
	 * Returns a closure suitable for use as a `permission_callback`. The
	 * indirection lets controllers register routes with a one-line lambda
	 * while the actual implementation stays in {@see check_request()}.
	 *
	 * @param string $scope Required scope ('read' or 'write').
	 * @return callable(WP_REST_Request): (true|WP_Error)
	 */
	public static function require_scope( string $scope ): callable {
		return static function ( WP_REST_Request $request ) use ( $scope ) {
			return self::check_request( $request, $scope );
		};
	}

	/**
	 * Returns true when the key's actual scope is sufficient for the route's
	 * required scope. Uses {@see SCOPE_GRANTS} so a `write` key satisfies
	 * both `read` and `write` routes.
	 *
	 * @param string $actual    Scope persisted on the key entry.
	 * @param string $required  Scope demanded by the route.
	 * @return bool
	 */
	private static function scope_satisfies( string $actual, string $required ): bool {
		$grants = self::SCOPE_GRANTS[ $actual ] ?? array();
		return in_array( $required, $grants, true );
	}
}
