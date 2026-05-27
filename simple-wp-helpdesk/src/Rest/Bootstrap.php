<?php
declare(strict_types=1);

namespace SWH\Rest;

use WP_REST_Controller;

use function add_action;

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
