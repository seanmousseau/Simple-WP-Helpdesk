<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use SWH\Rest\BulkController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class BulkControllerTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
	}
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_register_route_registers_post_bulk_action(): void {
		WP_Mock::userFunction( 'register_rest_route', [
			'args'  => [ 'swh/v1', '/bulk-action', WP_Mock\Functions::type( 'array' ) ],
			'times' => 1,
		] );
		( new BulkController() )->register_routes();
		$this->addToAssertionCount( 1 );
	}

	public function test_bulk_action_applies_status_to_all_matching_versions(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		// _swh_version reads for TicketVersion::current() — all match.
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 10, '_swh_version', true ], 'return' => '1' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 11, '_swh_version', true ], 'return' => '2' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 12, '_swh_version', true ], 'return' => '3' ] );
		// Catch-all for any other get_post_meta calls (swh_set_ticket_status internals).
		WP_Mock::userFunction( 'get_post_meta', [ 'return' => '' ] );
		WP_Mock::userFunction( 'update_post_meta', [ 'return' => true ] );
		WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
		WP_Mock::userFunction( 'swh_set_ticket_status', [ 'return' => null ] );

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'status' );
		$req->set_param( 'params', array( 'status' => 'resolved' ) );
		$req->set_param( 'ticket_ids', array( 10, 11, 12 ) );
		$req->set_param( 'version_map', array( 10 => 1, 11 => 2, 12 => 3 ) );

		$response = ( new BulkController() )->bulk_action( $req );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( array( 10, 11, 12 ), $data['succeeded'] );
		$this->assertSame( array(), $data['conflicts'] );
	}

	public function test_bulk_action_separates_conflicts(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		// id 10: version matches (1); id 11: version mismatch (99 != 2); id 12: version matches (3).
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 10, '_swh_version', true ], 'return' => '1' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 11, '_swh_version', true ], 'return' => '99' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 12, '_swh_version', true ], 'return' => '3' ] );
		// Catch-all for internals.
		WP_Mock::userFunction( 'get_post_meta', [ 'return' => '' ] );
		WP_Mock::userFunction( 'update_post_meta', [ 'return' => true ] );
		WP_Mock::userFunction( 'do_action', [ 'return' => null ] );
		WP_Mock::userFunction( 'swh_set_ticket_status', [ 'return' => null ] );

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'status' );
		$req->set_param( 'params', array( 'status' => 'resolved' ) );
		$req->set_param( 'ticket_ids', array( 10, 11, 12 ) );
		$req->set_param( 'version_map', array( 10 => 1, 11 => 2, 12 => 3 ) );

		$response = ( new BulkController() )->bulk_action( $req );
		$data     = $response->get_data();

		$this->assertSame( array( 10, 12 ), $data['succeeded'] );
		$this->assertCount( 1, $data['conflicts'] );
		$this->assertSame( 11, $data['conflicts'][0]['id'] );
		$this->assertSame( 99, $data['conflicts'][0]['version_now'] );
	}

	public function test_bulk_action_rejects_over_200_tickets(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'status' );
		$req->set_param( 'params', array( 'status' => 'resolved' ) );
		$req->set_param( 'ticket_ids', range( 1, 201 ) );
		$req->set_param( 'version_map', array_combine( range( 1, 201 ), array_fill( 0, 201, 1 ) ) );

		$response = ( new BulkController() )->bulk_action( $req );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'too_many_tickets', $response->get_data()['error'] );
	}
}
