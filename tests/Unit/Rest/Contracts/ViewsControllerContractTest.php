<?php
/**
 * REST envelope contract tests for SWH\Rest\ViewsController.
 *
 * ViewsRepository is final and ViewsController instantiates it internally.
 * We follow the existing test pattern (ViewsControllerTest) and exercise the
 * controller through real repo + mocked WP storage functions, rather than
 * doubling the repo.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\Contracts;

use SWH\Rest\ViewsController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class ViewsControllerContractTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Views/ViewsRepository.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_create_403_envelope_when_non_admin_creates_global_view(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/views' );
		$req->set_param( 'scope', 'global' );
		$req->set_param( 'name', 'My Global View' );
		$req->set_param( 'filters', array() );

		$response = ( new ViewsController() )->create_view( $req );
		$this->assertSame( 403, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'forbidden' );
		$this->assertSame( '', $problems, "create global 403 envelope: $problems" );
	}

	public function test_create_201_envelope_for_global_view(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );
		WP_Mock::userFunction( 'update_option', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_generate_uuid4', array( 'return' => 'new-uuid' ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/views' );
		$req->set_param( 'scope', 'global' );
		$req->set_param( 'name', 'All Open' );
		$req->set_param( 'filters', array() );

		$response = ( new ViewsController() )->create_view( $req );
		$this->assertSame( 201, $response->get_status() );

		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_success( $data, array( 'view' ) );
		$this->assertSame( '', $problems, "create global 201 envelope: $problems" );
		$this->assertSame( 'global', $data['view']['scope'], 'controller must stamp scope=global on response' );
		$this->assertSame( 'new-uuid', $data['view']['id'], 'controller must echo back the generated id' );
	}

	public function test_create_201_envelope_for_personal_view(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_current_user_id', array( 'return' => 7 ) );
		WP_Mock::userFunction( 'get_user_meta', array( 'return' => array() ) );
		WP_Mock::userFunction( 'update_user_meta', array( 'return' => true ) );
		WP_Mock::userFunction( 'wp_generate_uuid4', array( 'return' => 'p-uuid' ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/views' );
		$req->set_param( 'scope', 'personal' );
		$req->set_param( 'name', 'Mine' );
		$req->set_param( 'filters', array() );

		$response = ( new ViewsController() )->create_view( $req );
		$this->assertSame( 201, $response->get_status() );

		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_success( $data, array( 'view' ) );
		$this->assertSame( '', $problems, "create personal 201 envelope: $problems" );
		$this->assertSame( 'personal', $data['view']['scope'] );
	}

	public function test_update_404_envelope_when_view_missing(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );  // no views exist.

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/views/glb_404' );
		$req->set_param( 'id', 'glb_404' );
		$req->set_param( 'scope', 'global' );
		$req->set_param( 'patch', array( 'name' => 'X' ) );

		$response = ( new ViewsController() )->update_view( $req );
		$this->assertSame( 404, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'not_found' );
		$this->assertSame( '', $problems, "update 404 envelope: $problems" );
	}

	public function test_update_403_envelope_when_non_admin_patches_global(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/views/glb_1' );
		$req->set_param( 'id', 'glb_1' );
		$req->set_param( 'scope', 'global' );
		$req->set_param( 'patch', array( 'name' => 'X' ) );

		$response = ( new ViewsController() )->update_view( $req );
		$this->assertSame( 403, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'forbidden' );
		$this->assertSame( '', $problems, "update 403 envelope: $problems" );
	}

	public function test_delete_envelope_carries_deleted_bool_on_success(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_option',
			array( 'return' => array( array( 'id' => 'glb_1', 'name' => 'X', 'filters' => array() ) ) )
		);
		WP_Mock::userFunction( 'update_option', array( 'return' => true ) );

		$req = new WP_REST_Request( 'DELETE', '/swh/v1/views/glb_1' );
		$req->set_param( 'id', 'glb_1' );
		$req->set_param( 'scope', 'global' );

		$response = ( new ViewsController() )->delete_view( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_success( $data, array( 'deleted' ) );
		$this->assertSame( '', $problems, "delete 200 envelope: $problems" );
		$this->assertTrue( $data['deleted'] );
	}

	public function test_delete_404_envelope_with_deleted_false_when_id_missing(): void {
		// Contract: delete uses { deleted: bool } envelope and 200/404 status,
		// NOT the error envelope. Lets the client distinguish "already gone"
		// from "operation rejected".
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction( 'get_option', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'DELETE', '/swh/v1/views/glb_missing' );
		$req->set_param( 'id', 'glb_missing' );
		$req->set_param( 'scope', 'global' );

		$response = ( new ViewsController() )->delete_view( $req );
		$this->assertSame( 404, $response->get_status() );
		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_success( $data, array( 'deleted' ) );
		$this->assertSame( '', $problems, "delete 404 envelope: $problems" );
		$this->assertFalse( $data['deleted'] );
	}

	public function test_delete_403_envelope_when_non_admin_deletes_global(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => false ) );

		$req = new WP_REST_Request( 'DELETE', '/swh/v1/views/glb_1' );
		$req->set_param( 'id', 'glb_1' );
		$req->set_param( 'scope', 'global' );

		$response = ( new ViewsController() )->delete_view( $req );
		$this->assertSame( 403, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'forbidden' );
		$this->assertSame( '', $problems, "delete 403 envelope: $problems" );
	}
}
