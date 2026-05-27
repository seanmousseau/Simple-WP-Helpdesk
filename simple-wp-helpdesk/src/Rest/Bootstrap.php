<?php
declare(strict_types=1);

namespace SWH\Rest;

use WP_Error;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;

use function add_action;
use function add_filter;
use function hash_hmac;
use function sanitize_text_field;
use function wp_salt;

/**
 * REST namespace bootstrap for the v4.1.0 public API surface.
 *
 * Phase 2 of the v4.1.0 plan. This class owns the `rest_api_init` hook for
 * the new `swh/v1` namespace. Phases 3–5 / 8 register their controllers via
 * {@see Bootstrap::register_controller()}; this class then dispatches
 * `register_routes()` on each controller exactly once during `rest_api_init`.
 *
 * **No routes are registered in Phase 2.** The namespace exists but is empty
 * — the bootstrap is wiring only. Controllers plug in later.
 *
 * @since 4.1.0
 */
final class Bootstrap {

	/** Public REST namespace for the v4.1.0 surface. */
	public const NAMESPACE = 'swh/v1';

	/**
	 * Controllers queued for registration. Populated by
	 * {@see register_controller()} before `rest_api_init` fires.
	 *
	 * @var array<int,WP_REST_Controller>
	 */
	private static array $controllers = array();

	/**
	 * Flag set to true after the `rest_api_init` hook has been attached so
	 * repeated `boot()` calls (e.g. during PHPUnit) do not stack listeners.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Attach the `rest_api_init` listener. Idempotent — safe to call from the
	 * plugin bootstrap on every request.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'rest_api_init', array( self::class, 'register_all' ) );
		// Phase 7 (#367): emit X-RateLimit-* on the 429 path. Success responses
		// emit headers via {@see \SWH\Rest\V1\ResponseDecorator}; the
		// over-limit path returns WP_Error, which WP serialises through
		// rest_post_dispatch — hook there so the headers ride along.
		add_filter( 'rest_post_dispatch', array( self::class, 'attach_rate_limit_headers' ), 10, 3 );
	}

	/**
	 * Queue a controller for registration on the `swh/v1` namespace.
	 *
	 * Phase 3+ controllers call this from their own file-level bootstrap so
	 * the registration order is explicit. The controller's
	 * `register_routes()` method is invoked when `rest_api_init` fires.
	 *
	 * @param WP_REST_Controller $controller Controller to register.
	 * @return void
	 */
	public static function register_controller( WP_REST_Controller $controller ): void {
		self::$controllers[] = $controller;
	}

	/**
	 * `rest_api_init` callback. Invokes `register_routes()` on every queued
	 * controller. Public so the action hook can target it as a class-string
	 * callable; not intended for direct callers.
	 *
	 * @return void
	 */
	public static function register_all(): void {
		foreach ( self::$controllers as $controller ) {
			$controller->register_routes();
		}
	}

	/**
	 * Default per-IP request budget (60s) for the public `settings/public`
	 * route. Locked at kickoff — not operator-configurable.
	 */
	public const PUBLIC_IP_RATE_LIMIT = 20;

	/**
	 * Permission wrapper for the public `settings/public` route. Applies a
	 * per-IP rate limit (Phase 7, #367) before allowing the anonymous read.
	 *
	 * IP source: `$_SERVER['REMOTE_ADDR']`. Missing / non-string falls back to
	 * the literal string `0.0.0.0` so the bucket key remains valid. The IP is
	 * hashed with `hash_hmac('sha256', $ip, wp_salt('nonce'))` before use so
	 * raw addresses never appear in transient keys (security-model.md).
	 *
	 * @param WP_REST_Request $request Incoming request (state is stashed here).
	 * @return true|WP_Error
	 */
	public static function settings_public_permission( WP_REST_Request $request ) {
		// REMOTE_ADDR is a server-provided IP; sanitize_text_field strips
		// anything that wouldn't survive a hash_hmac call. Fallback to 0.0.0.0
		// when the server didn't populate it (CLI, tests, exotic SAPIs).
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) && is_string( $_SERVER['REMOTE_ADDR'] )
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			? sanitize_text_field( (string) $_SERVER['REMOTE_ADDR'] )
			: '0.0.0.0';
		$hashed = hash_hmac( 'sha256', $raw_ip, wp_salt( 'nonce' ) );
		$state  = RateLimiter::check( 'swh_rl_ip_' . $hashed, self::PUBLIC_IP_RATE_LIMIT );
		$request->set_param( '_swh_rate_limit_state', $state );

		if ( ! $state['allowed'] ) {
			$retry_after = max( 1, $state['reset'] - time() );
			return new WP_Error(
				Errors::RATE_LIMITED,
				__( 'Rate limit exceeded. Try again later.', 'simple-wp-helpdesk' ),
				array(
					'status'      => 429,
					'retry_after' => $retry_after,
				)
			);
		}

		return true;
	}

	/**
	 * `rest_post_dispatch` callback that copies `X-RateLimit-*` headers onto
	 * responses when the request carries rate-limit state. Primarily needed
	 * for the 429 over-limit path; success responses already have the
	 * headers via {@see \SWH\Rest\V1\ResponseDecorator}.
	 *
	 * Also emits `Retry-After` when the response status is 429.
	 *
	 * @param WP_REST_Response|mixed $response Response object.
	 * @param mixed                  $server   REST server (unused).
	 * @param WP_REST_Request|mixed  $request  Originating request.
	 * @return WP_REST_Response|mixed
	 */
	public static function attach_rate_limit_headers( $response, $server, $request ) {
		unset( $server );
		if ( ! $response instanceof WP_REST_Response || ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		$state = $request->get_param( '_swh_rate_limit_state' );
		if ( ! is_array( $state ) || ! isset( $state['limit'], $state['remaining'], $state['reset'] ) ) {
			return $response;
		}
		if ( ! is_numeric( $state['limit'] ) || ! is_numeric( $state['remaining'] ) || ! is_numeric( $state['reset'] ) ) {
			return $response;
		}
		$response->header( 'X-RateLimit-Limit', (string) (int) $state['limit'] );
		$response->header( 'X-RateLimit-Remaining', (string) (int) $state['remaining'] );
		$response->header( 'X-RateLimit-Reset', (string) (int) $state['reset'] );

		if ( 429 === $response->get_status() ) {
			$retry_after = max( 1, ( (int) $state['reset'] ) - time() );
			$response->header( 'Retry-After', (string) $retry_after );
		}
		return $response;
	}

	/**
	 * Test-only: reset the queue + hook flag so PHPUnit tests can re-bootstrap
	 * without stale state. Not used in production.
	 *
	 * @return void
	 */
	public static function reset_for_tests(): void {
		self::$controllers = array();
		self::$hooked      = false;
	}
}
