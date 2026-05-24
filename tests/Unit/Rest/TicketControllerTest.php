<?php
declare(strict_types=1);

namespace SWH\Tests\Unit\Rest;

use SWH\Rest\TicketController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class TicketControllerTest extends TestCase {
	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
	}
	protected function tearDown(): void { WP_Mock::tearDown(); }

	public function test_register_routes_registers_3_routes(): void {
		WP_Mock::userFunction( 'register_rest_route', [ 'times' => 3 ] );
		( new TicketController() )->register_routes();
	}

	public function test_get_item_returns_full_payload(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [
				'ID' => 42, 'post_title' => 'PDF download broken',
				'post_content' => 'Blank file on click',
				'post_date_gmt' => '2026-05-22 10:00:00',
				'post_type' => 'helpdesk_ticket',
			],
		] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_ticket_status', true ],   'return' => 'in_progress' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_ticket_priority', true ], 'return' => 'high' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_ticket_assigned', true ], 'return' => '3' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_ticket_email', true ],    'return' => 'bob@example.com' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ],     'return' => '4' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_ticket_attachments', true ], 'return' => array() ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_attachment_orignames', true ], 'return' => array() ] );
		WP_Mock::userFunction( 'get_comments', [ 'return' => array() ] );
		WP_Mock::userFunction( 'wp_get_post_terms', [ 'return' => array() ] );

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );

		$response = ( new TicketController() )->get_item( $req );
		$data     = $response->get_data();

		$this->assertSame( 42, $data['id'] );
		$this->assertSame( 4, $data['version'] );
		$this->assertSame( 'in_progress', $data['status'] );
		$this->assertArrayHasKey( 'thread', $data );
		$this->assertArrayHasKey( 'meta', $data );
	}

	public function test_update_item_409_on_version_mismatch(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [ 'ID' => 42, 'post_type' => 'helpdesk_ticket', 'post_title' => '', 'post_content' => '', 'post_date_gmt' => '' ],
		] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '5' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'return' => '' ] );
		WP_Mock::userFunction( 'get_comments', [ 'return' => array() ] );
		WP_Mock::userFunction( 'wp_get_post_terms', [ 'return' => array() ] );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 4 );
		$req->set_param( 'patch', array( 'status' => 'resolved' ) );

		$response = ( new TicketController() )->update_item( $req );
		$this->assertSame( 409, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'version_conflict', $data['error'] );
		$this->assertSame( 5, $data['current']['version'] );
	}

	public function test_update_item_200_on_version_match(): void {
		WP_Mock::userFunction( 'current_user_can', [ 'return' => true ] );
		WP_Mock::userFunction( 'get_post', [
			'args'   => [ 42 ],
			'return' => (object) [ 'ID' => 42, 'post_type' => 'helpdesk_ticket', 'post_title' => '', 'post_content' => '', 'post_date_gmt' => '' ],
		] );
		WP_Mock::userFunction( 'get_post_meta', [ 'args' => [ 42, '_swh_version', true ], 'return' => '5' ] );
		WP_Mock::userFunction( 'get_post_meta', [ 'return' => '' ] );
		WP_Mock::userFunction( 'get_comments', [ 'return' => array() ] );
		WP_Mock::userFunction( 'wp_get_post_terms', [ 'return' => array() ] );
		// swh_set_ticket_status is a plugin function; allow it to be called
		// (it may or may not be interceptable depending on load order).
		WP_Mock::userFunction( 'swh_set_ticket_status', [ 'return' => null ] );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 5 );
		$req->set_param( 'patch', array( 'status' => 'resolved' ) );

		$response = ( new TicketController() )->update_item( $req );
		$this->assertSame( 200, $response->get_status() );
	}
}
