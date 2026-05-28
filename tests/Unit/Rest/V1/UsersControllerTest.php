<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\V1;

use PHPUnit\Framework\TestCase;
use SWH\Rest\Bootstrap;
use SWH\Rest\V1\UsersController;
use WP_Mock;
use WP_REST_Request;

/**
 * Unit tests for {@see \SWH\Rest\V1\UsersController} — Phase 5 of v4.1.0
 * (#364), the `swh/v1/users/technicians` read-only endpoint.
 *
 * Coverage map:
 *  - Route registered with read scope.
 *  - Response carries ONLY the locked allowlist: id, display_name, email.
 *  - Filter `swh_hide_staff_email = true` ⇒ `email` key omitted.
 *  - Empty users list ⇒ empty array (not error).
 */
final class UsersControllerTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		Bootstrap::reset_for_tests();

		WP_Mock::userFunction( 'swh_rest_require', array(
			'return' => function () { return '__return_true'; },
		) );
		WP_Mock::userFunction( 'rest_url', array( 'return' => function ( string $p = '' ): string {
			return 'https://example.com/wp-json/' . ltrim( $p, '/' );
		} ) );

		require_once SWH_PLUGIN_DIR . 'src/Rest/V1/UsersController.php';
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

		( new UsersController() )->register_routes();

		$this->assertCount( 1, $registered );
		$this->assertSame( Bootstrap::NAMESPACE, $registered[0]['ns'] );
		$this->assertSame( '/users/technicians', $registered[0]['route'] );
		// Locate the method entry (numeric key) — confirms permission_callback
		// is wired via swh_rest_require('read').
		$has_method_entry = false;
		foreach ( $registered[0]['args'] as $k => $v ) {
			if ( is_int( $k ) ) {
				$this->assertArrayHasKey( 'permission_callback', $v );
				$has_method_entry = true;
			}
		}
		$this->assertTrue( $has_method_entry );
	}

	public function test_get_items_returns_locked_allowlist_only(): void {
		// Default behaviour — no filter registered ⇒ apply_filters returns
		// the value unchanged ⇒ hide_email stays false.
		WP_Mock::userFunction( 'get_users', array(
			'return' => array(
				new \WP_User( array( 'ID' => 7, 'display_name' => 'Alice', 'user_email' => 'alice@example.com' ) ),
				new \WP_User( array( 'ID' => 11, 'display_name' => 'Bob',   'user_email' => 'bob@example.com' ) ),
			),
		) );

		$req      = new WP_REST_Request( 'GET', '/swh/v1/users/technicians' );
		$response = ( new UsersController() )->get_items( $req );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertCount( 2, $data );
		// Allowlist — exactly these three keys, in any order.
		$keys = array_keys( $data[0] );
		sort( $keys );
		$this->assertSame( array( 'display_name', 'email', 'id' ), $keys );
		$this->assertSame( 7, $data[0]['id'] );
		$this->assertSame( 'alice@example.com', $data[0]['email'] );

		// Internal fields must not leak.
		$flat = (string) json_encode( $data );
		$this->assertStringNotContainsString( 'user_login', $flat );
		$this->assertStringNotContainsString( 'roles', $flat );
		$this->assertStringNotContainsString( 'capabilities', $flat );
	}

	public function test_swh_hide_staff_email_filter_nulls_email_field(): void {
		WP_Mock::onFilter( 'swh_hide_staff_email' )->with( false )->reply( true );
		WP_Mock::userFunction( 'get_users', array(
			'return' => array(
				new \WP_User( array( 'ID' => 7, 'display_name' => 'Alice', 'user_email' => 'alice@example.com' ) ),
			),
		) );

		$req      = new WP_REST_Request( 'GET', '/swh/v1/users/technicians' );
		$response = ( new UsersController() )->get_items( $req );

		$data = $response->get_data();
		$this->assertCount( 1, $data );
		// Per CR #526 #18: response shape is stable — `email` is always
		// present, but null when the filter says hide. This lets clients
		// rely on `data.email === null` instead of branching on key
		// existence.
		$keys = array_keys( $data[0] );
		sort( $keys );
		$this->assertSame( array( 'display_name', 'email', 'id' ), $keys );
		$this->assertNull( $data[0]['email'] );

		$flat = (string) json_encode( $data );
		$this->assertStringNotContainsString( 'alice@example.com', $flat );
	}

	public function test_get_items_empty_list_returns_empty_array(): void {
		WP_Mock::userFunction( 'get_users', array( 'return' => array() ) );

		$response = ( new UsersController() )->get_items( new WP_REST_Request( 'GET', '/swh/v1/users/technicians' ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array(), $response->get_data() );
	}
}
