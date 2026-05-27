<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Rest\Bootstrap;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function register_rest_route;

/**
 * Public REST controller for `swh/v1/settings/public`.
 *
 * Phase 5 of the v4.1.0 plan (#364). The ONLY publicly readable (no-auth)
 * endpoint in the v4.1.0 surface. Returns ONLY the allowlisted subset of
 * operator settings defined by {@see PublicSettingsAllowlist}.
 *
 * **Public — no auth.** `permission_callback` is `__return_true` because
 * WP REST requires a permission callback on every route, but the response
 * is intentionally readable by anonymous callers. Every key in the
 * response goes through the hard allowlist in
 * {@see PublicSettingsAllowlist::resolve()}. Phase 7 will add per-IP rate
 * limiting; Phase 5 ships the route + allowlist only.
 *
 * Route:
 *  - GET /swh/v1/settings/public — public-safe operator settings.
 *
 * @since 4.1.0
 */
final class SettingsController extends WP_REST_Controller {

	public const REST_BASE = 'settings';

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register the `/settings/public` route.
	 *
	 * NOTE: This is the only endpoint in `swh/v1` that does NOT carry
	 * `swh_rest_require()`. The route is intentionally public-readable —
	 * the `__return_true` permission callback is deliberate. The hard
	 * allowlist in {@see PublicSettingsAllowlist} is the only gate.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/public',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_public_settings' ),
					// PUBLIC ENDPOINT — see class docblock. Phase 7 (#367) wraps
					// the public permission callback with a per-IP rate limit
					// (20/min) keyed by an HMAC of the client IP so raw
					// addresses never appear in transient keys.
					'permission_callback' => array( Bootstrap::class, 'settings_public_permission' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Locked item schema for the public-settings payload.
	 *
	 * @return array<string,mixed>
	 */
	public function get_item_schema(): array {
		if ( null !== $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}
		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'public_settings',
			'type'       => 'object',
			'properties' => array(
				'priority_labels' => array(
					'description' => 'Configured priority labels (operator-defined).',
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'status_labels'   => array(
					'description' => 'Configured ticket status labels.',
					'type'        => 'array',
					'items'       => array( 'type' => 'string' ),
				),
				'sla_thresholds'  => array(
					'description' => 'SLA hour thresholds keyed by priority bucket.',
					'type'        => 'object',
					'properties'  => array(
						'low'    => array( 'type' => array( 'integer', 'null' ) ),
						'medium' => array( 'type' => array( 'integer', 'null' ) ),
						'high'   => array( 'type' => array( 'integer', 'null' ) ),
						'urgent' => array( 'type' => array( 'integer', 'null' ) ),
					),
				),
				'features'        => array(
					'description' => 'Feature flags derived from operator settings.',
					'type'        => 'object',
					'properties'  => array(
						'csat'        => array( 'type' => 'boolean' ),
						'portal'      => array( 'type' => 'boolean' ),
						'attachments' => array( 'type' => 'boolean' ),
					),
				),
			),
		);
		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * GET /swh/v1/settings/public — allowlisted subset only.
	 *
	 * @param WP_REST_Request $request Request object.
	 */
	public function get_public_settings( $request ): WP_REST_Response {
		$response = new WP_REST_Response( PublicSettingsAllowlist::resolve(), 200 );
		return ResponseDecorator::with_rate_limit_headers( $response, $request );
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new SettingsController() );
