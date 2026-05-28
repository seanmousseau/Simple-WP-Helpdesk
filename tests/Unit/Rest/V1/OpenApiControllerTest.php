<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\V1\OpenApiController;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\V1\OpenApiController} — Phase 8 of v4.1.0
 * (#368), the public `swh/v1/openapi.json` endpoint.
 *
 * What we exercise:
 *  - The route is registered as `/openapi.json` on the v4.1 namespace.
 *  - The permission callback is the same public-with-IP-rate-limit wrapper
 *    used by `/settings/public` (never `__return_true` — that would skip
 *    rate limiting on a public endpoint).
 *  - `get_spec()` returns a 200 response.
 *  - The body parses as JSON-able array with the expected top-level OpenAPI
 *    fields (`openapi`, `info`, `paths`).
 *  - `servers[0].variables.site_url.default` is rewritten to `home_url()`
 *    so Postman imports point at the actual site.
 */
final class OpenApiControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		WP_Mock::userFunction( 'home_url', array(
			'return' => 'https://example.test',
		) );
		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $p = '' ): string {
			return 'https://example.test/wp-json/' . ltrim( $p, '/' );
		} ) );

		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/OpenApiController.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_register_routes_registers_openapi_json(): void {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $ns, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new OpenApiController() )->register_routes();

		$this->assertCount( 1, $registered );
		$this->assertSame( 'swh/v1', $registered[0]['ns'] );
		$this->assertSame( '/openapi.json', $registered[0]['route'] );
	}

	public function test_permission_callback_matches_public_settings_wrapper(): void {
		// The endpoint is PUBLIC but rate-limited. It MUST share the same
		// permission wrapper as /settings/public so the per-IP bucket applies.
		// Using `__return_true` directly here would skip rate limiting.
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $ns, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new OpenApiController() )->register_routes();

		$found = false;
		foreach ( $registered[0]['args'] as $k => $v ) {
			if ( is_int( $k ) ) {
				$found = true;
				$this->assertSame(
					array( Bootstrap::class, 'settings_public_permission' ),
					$v['permission_callback']
				);
			}
		}
		$this->assertTrue( $found, 'method entry must exist on the route registration' );
	}

	public function test_get_spec_returns_200_with_openapi_envelope(): void {
		$response = ( new OpenApiController() )->get_spec( new WP_REST_Request( 'GET', '/swh/v1/openapi.json' ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertIsArray( $data );

		// JSON round-trip must succeed (no closures, no resources, no NaN).
		$json = json_encode( $data );
		$this->assertIsString( $json );
		$this->assertJson( $json );

		// Top-level OpenAPI fields.
		$this->assertArrayHasKey( 'openapi', $data );
		$this->assertArrayHasKey( 'info', $data );
		$this->assertArrayHasKey( 'paths', $data );
		$this->assertStringStartsWith( '3.1', (string) $data['openapi'] );
	}

	public function test_get_spec_includes_the_v1_ticket_paths(): void {
		$response = ( new OpenApiController() )->get_spec( new WP_REST_Request( 'GET', '/swh/v1/openapi.json' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( '/swh/v1/tickets', $data['paths'] );
		$this->assertArrayHasKey( '/swh/v1/tickets/{id}', $data['paths'] );
		$this->assertArrayHasKey( '/swh/v1/tickets/{id}/replies', $data['paths'] );
		$this->assertArrayHasKey( '/swh/v1/openapi.json', $data['paths'] );
	}

	public function test_get_spec_rewrites_server_site_url_to_home_url(): void {
		$response = ( new OpenApiController() )->get_spec( new WP_REST_Request( 'GET', '/swh/v1/openapi.json' ) );
		$data     = $response->get_data();

		$this->assertArrayHasKey( 'servers', $data );
		$this->assertSame(
			'https://example.test',
			$data['servers'][0]['variables']['site_url']['default'],
			'OpenApiController must rewrite the placeholder server variable to home_url() for Postman ergonomics.'
		);
	}

	public function test_get_spec_emits_rate_limit_headers_when_state_present(): void {
		// Phase 7 contract: when the request carries _swh_rate_limit_state, the
		// controller's response must surface the X-RateLimit-* headers.
		$request = new WP_REST_Request( 'GET', '/swh/v1/openapi.json' );
		$request->set_param( '_swh_rate_limit_state', array(
			'limit'     => 20,
			'remaining' => 17,
			'reset'     => 1716748800,
			'allowed'   => true,
		) );

		$response = ( new OpenApiController() )->get_spec( $request );
		$headers  = $response->get_headers();

		// Stub normalises header names to lower-case; production WP keeps
		// case-insensitive lookup so the wire result is identical.
		$normalised = array_change_key_case( $headers, CASE_LOWER );
		$this->assertSame( '20', (string) $normalised['x-ratelimit-limit'] );
		$this->assertSame( '17', (string) $normalised['x-ratelimit-remaining'] );
		$this->assertSame( '1716748800', (string) $normalised['x-ratelimit-reset'] );
	}
}
