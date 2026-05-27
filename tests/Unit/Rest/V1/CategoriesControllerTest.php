<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\V1\CategoriesController;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\V1\CategoriesController} — Phase 5 of
 * v4.1.0 (#364), the `swh/v1/categories` read-only endpoint.
 */
final class CategoriesControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		WP_Mock::userFunction( 'swh_rest_require', array(
			'return' => function () { return '__return_true'; },
		) );
		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $p = '' ): string {
			return 'https://example.com/wp-json/' . ltrim( $p, '/' );
		} ) );

		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/CategoriesController.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_register_routes_uses_read_scope(): void {
		$registered = array();
		WP_Mock::userFunction( 'register_rest_route', array(
			'return' => function ( string $ns, string $route, array $args ) use ( &$registered ) {
				$registered[] = array( 'ns' => $ns, 'route' => $route, 'args' => $args );
				return true;
			},
		) );

		( new CategoriesController() )->register_routes();

		$this->assertCount( 1, $registered );
		$this->assertSame( '/categories', $registered[0]['route'] );
		foreach ( $registered[0]['args'] as $k => $v ) {
			if ( is_int( $k ) ) {
				$this->assertArrayHasKey( 'permission_callback', $v );
			}
		}
	}

	public function test_get_items_returns_locked_schema(): void {
		WP_Mock::userFunction( 'get_terms', array(
			'return' => array(
				new \WP_Term( array( 'term_id' => 3, 'name' => 'Billing', 'slug' => 'billing', 'parent' => 0, 'count' => 5 ) ),
				new \WP_Term( array( 'term_id' => 4, 'name' => 'Bug',     'slug' => 'bug',     'parent' => 0, 'count' => 12 ) ),
			),
		) );

		$response = ( new CategoriesController() )->get_items( new WP_REST_Request( 'GET', '/swh/v1/categories' ) );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data );

		$keys = array_keys( $data[0] );
		sort( $keys );
		$this->assertSame( array( 'count', 'id', 'name', 'parent', 'slug' ), $keys );
		$this->assertSame( 3, $data[0]['id'] );
		$this->assertSame( 'Billing', $data[0]['name'] );
		$this->assertSame( 'billing', $data[0]['slug'] );
		$this->assertSame( 0, $data[0]['parent'] );
		$this->assertSame( 5, $data[0]['count'] );

		// Description / meta must not leak.
		$flat = (string) json_encode( $data );
		$this->assertStringNotContainsString( 'description', $flat );
		$this->assertStringNotContainsString( 'taxonomy', $flat );
	}

	public function test_get_items_empty_taxonomy_returns_empty_array(): void {
		WP_Mock::userFunction( 'get_terms', array( 'return' => array() ) );
		$response = ( new CategoriesController() )->get_items( new WP_REST_Request( 'GET', '/swh/v1/categories' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}

	public function test_get_terms_wp_error_returns_empty_array(): void {
		// get_terms() may return a WP_Error on bad input; controller should
		// degrade to an empty list rather than blowing up.
		WP_Mock::userFunction( 'get_terms', array(
			'return' => new \WP_Error( 'invalid_taxonomy', 'no such taxonomy' ),
		) );
		$response = ( new CategoriesController() )->get_items( new WP_REST_Request( 'GET', '/swh/v1/categories' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}
}
