<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use SWH\Rest\ViewsController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class ViewsControllerTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Views/ViewsRepository.php';
	}
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_register_routes_registers_4_routes(): void {
		// GET, POST, PATCH, DELETE — 4 separate register_rest_route calls.
		WP_Mock::userFunction( 'register_rest_route', [ 'times' => 4 ] );
		( new ViewsController() )->register_routes();
	}

	public function test_list_views_returns_merged(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		WP_Mock::userFunction( 'get_current_user_id', [ 'return' => 7 ] );
		WP_Mock::userFunction( 'get_user_meta', [ 'return' => array( array( 'id' => 'p1', 'name' => 'My' ) ) ] );
		WP_Mock::userFunction( 'get_option', [ 'return' => array( array( 'id' => 'g1', 'name' => 'Team' ) ) ] );

		$req      = new WP_REST_Request( 'GET', '/swh/v1/views' );
		$response = ( new ViewsController() )->list_views( $req );

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 2, $response->get_data()['views'] );
	}

	public function test_create_view_personal_default(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		WP_Mock::userFunction( 'get_current_user_id', [ 'return' => 7 ] );
		WP_Mock::userFunction( 'get_user_meta', [ 'return' => array() ] );
		WP_Mock::userFunction( 'update_user_meta', [ 'return' => true ] );
		WP_Mock::userFunction( 'wp_generate_uuid4', [ 'return' => 'new-uuid' ] );

		$req = new WP_REST_Request( 'POST', '/swh/v1/views' );
		$req->set_param( 'name', 'My new view' );
		$req->set_param( 'scope', 'personal' );
		$req->set_param( 'filters', array() );

		$response = ( new ViewsController() )->create_view( $req );

		$this->assertSame( 201, $response->get_status() );
		$this->assertSame( 'new-uuid', $response->get_data()['view']['id'] );
		$this->assertSame( 'personal', $response->get_data()['view']['scope'] );
	}

	public function test_create_global_view_requires_manage_options(): void {
		WP_Mock::userFunction(
			'current_user_can',
			[
				'return' => static function () {
					$args = func_get_args();
					return ( $args[0] ?? '' ) === 'edit_posts';
				},
			]
		);

		$req = new WP_REST_Request( 'POST', '/swh/v1/views' );
		$req->set_param( 'name', 'Global attempt' );
		$req->set_param( 'scope', 'global' );

		$response = ( new ViewsController() )->create_view( $req );

		$this->assertSame( 403, $response->get_status() );
	}
}
