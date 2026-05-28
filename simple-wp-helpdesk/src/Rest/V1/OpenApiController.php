<?php
declare(strict_types=1);

namespace SWH\Rest\V1;

use SWH\Rest\Bootstrap;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function file_get_contents;
use function home_url;
use function is_array;
use function json_decode;
use function register_rest_route;

/**
 * Public REST controller for `swh/v1/openapi.json`.
 *
 * Phase 8 of the v4.1.0 plan (#368). Serves the machine-readable OpenAPI 3.1
 * contract as JSON so tools like Postman / Insomnia can one-click import.
 *
 * **Source of truth.** The human-edited file is `docs/openapi.yaml`. The JSON
 * form (`docs/openapi.json`) is a committed build artifact produced by
 * `bin/openapi-sync.sh` and kept in sync by CI. This controller reads the
 * JSON because PHP's `ext-yaml` is not guaranteed on shared WP hosts;
 * shipping a pre-parsed artifact avoids both the runtime dep and an
 * inline YAML parser.
 *
 * **Public — no auth.** Like `/settings/public`, this route is intentionally
 * readable by anonymous callers. It is rate-limited per-IP via the same
 * bucket as `/settings/public` (Phase 7, #367); raw IPs never appear in
 * transient keys.
 *
 * **`servers[].variables.site_url` is rewritten on the fly** so the response
 * matches `home_url()` of the host site, not the placeholder `example.com`
 * in the committed spec. This lets a Postman user point Postman straight at
 * the integrator's actual WP instance without manual edits.
 *
 * @since 4.1.0
 */
final class OpenApiController extends WP_REST_Controller {

	public const REST_BASE = 'openapi.json';

	/**
	 * Candidate paths to the committed JSON spec artifact, in resolution order.
	 *
	 * 1. **Plugin-root** (`simple-wp-helpdesk/openapi.json`) — production
	 *    layout. The release zip and any `wp plugin install` flow only ship
	 *    the plugin directory, so the spec must live inside it. `bin/openapi-sync.sh`
	 *    writes this file alongside the canonical `docs/openapi.json`.
	 * 2. **Monorepo dev layout** (`docs/openapi.json`) — kept as a fallback so
	 *    `git clone` + `wp plugin activate` against the repo root still works
	 *    even before someone runs the sync script.
	 *
	 * The minimal stub inside `get_spec()` is the last-resort safety net; if
	 * it fires in any real install we want to know about it.
	 */
	private const SPEC_PATHS = array(
		'openapi.json',
		'../docs/openapi.json',
	);

	public function __construct() {
		$this->namespace = Bootstrap::NAMESPACE;
		$this->rest_base = self::REST_BASE;
	}

	/**
	 * Register the `/openapi.json` route.
	 *
	 * Note the slightly unusual `rest_base` — it contains a literal `.json`.
	 * That's intentional: the route URL is the artifact's natural filename,
	 * which is what Postman/Insomnia auto-import patterns expect.
	 */
	public function register_routes(): void {
		register_rest_route(
			Bootstrap::NAMESPACE,
			'/' . self::REST_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_spec' ),
					// Public — same per-IP rate-limit wrapper as /settings/public.
					'permission_callback' => array( Bootstrap::class, 'settings_public_permission' ),
				),
			)
		);
	}

	/**
	 * Read the committed JSON spec, rewrite `servers[].variables.site_url` to
	 * `home_url()`, and emit the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response
	 */
	public function get_spec( WP_REST_Request $request ): WP_REST_Response {
		$raw = false;
		foreach ( self::SPEC_PATHS as $candidate ) {
			$spec_path = SWH_PLUGIN_DIR . $candidate;
			if ( is_readable( $spec_path ) ) {
				// file_get_contents() on a known plugin-bundled local path —
				// not a remote URL fetch. wp_remote_get() does not apply.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$raw = file_get_contents( $spec_path );
				if ( is_string( $raw ) && '' !== $raw ) {
					break;
				}
				$raw = false;
			}
		}

		// Fallback: minimal stub so a misconfigured deploy still returns a
		// valid OpenAPI document (200) rather than a 5xx. We never expect
		// this to fire — `bin/openapi-sync.sh` writes the spec into the
		// plugin root and CI enforces sync. Log loudly in WP_DEBUG so we
		// notice in dev if the bundled artifact has gone missing.
		$spec = array(
			'openapi' => '3.1.0',
			'info'    => array(
				'title'   => 'Simple WP Helpdesk REST API',
				'version' => defined( 'SWH_VERSION' ) ? (string) SWH_VERSION : 'unknown',
			),
			'paths'   => array(),
		);

		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				$spec = $decoded;
			}
		} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log(
				'[SWH] OpenApiController: bundled openapi.json not found at any of '
				. implode( ', ', self::SPEC_PATHS )
				. ' (relative to SWH_PLUGIN_DIR). Serving minimal stub.'
			);
		}

		// Rewrite the placeholder server variable so Postman points at this site.
		if (
			isset( $spec['servers'] ) && is_array( $spec['servers'] )
			&& isset( $spec['servers'][0] ) && is_array( $spec['servers'][0] )
			&& isset( $spec['servers'][0]['variables'] ) && is_array( $spec['servers'][0]['variables'] )
			&& isset( $spec['servers'][0]['variables']['site_url'] ) && is_array( $spec['servers'][0]['variables']['site_url'] )
		) {
			$spec['servers'][0]['variables']['site_url']['default'] = home_url();
		}

		$response = new WP_REST_Response( $spec, 200 );
		return ResponseDecorator::with_rate_limit_headers( $response, $request );
	}
}

// Register the controller with the v4.1.0 namespace bootstrap. Bootstrap's
// own rest_api_init listener (priority 10) dispatches register_routes() on
// every queued controller.
Bootstrap::register_controller( new OpenApiController() );
