<?php
/**
 * REST envelope contract tests for SWH\Rest\BulkController.
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\Contracts;

use SWH\Rest\BulkController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class BulkControllerContractTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_400_envelope_when_too_many_tickets(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'change_status' );
		// 201 IDs — one over the documented MAX_TICKETS=200 limit.
		$req->set_param( 'ticket_ids', range( 1, \SWH\Rest\BulkController::MAX_TICKETS + 1 ) );
		$req->set_param( 'version_map', array() );
		$req->set_param( 'params', array() );

		$response = ( new BulkController() )->bulk_action( $req );
		$this->assertSame( 400, $response->get_status() );

		$data = (array) $response->get_data();
		// 400 envelope must carry `error` AND `max` so the client can show "max N tickets per batch".
		$problems = EnvelopeShape::describe_error( $data, 'too_many_tickets', array( 'max' ) );
		$this->assertSame( '', $problems, "400 envelope: $problems" );
		$this->assertIsInt( $data['max'], '`max` must be an int' );
		$this->assertSame(
			\SWH\Rest\BulkController::MAX_TICKETS,
			$data['max'],
			'`max` must report the actual MAX_TICKETS constant'
		);
	}

	public function test_200_success_envelope_with_succeeded_and_conflicts(): void {
		// 200 response shape: { succeeded: [int], conflicts: [{id, version_now, error?}] }.
		// We won't stand up the full apply() path here — just hit a missing-ticket
		// case that puts the ID into conflicts with error=not_found.
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 999 ),
				'return' => null,
			)
		);

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'change_status' );
		$req->set_param( 'ticket_ids', array( 999 ) );
		$req->set_param( 'version_map', array() );
		$req->set_param( 'params', array() );

		$response = ( new BulkController() )->bulk_action( $req );
		$this->assertSame( 200, $response->get_status() );

		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_success( $data, array( 'succeeded', 'conflicts' ) );
		$this->assertSame( '', $problems, "200 envelope: $problems" );

		$this->assertIsArray( $data['succeeded'] );
		$this->assertIsArray( $data['conflicts'] );
		$this->assertCount( 0, $data['succeeded'], '999 should not have succeeded' );
		$this->assertCount( 1, $data['conflicts'] );

		$conflict = $data['conflicts'][0];
		$this->assertSame( 999, $conflict['id'] );
		$this->assertArrayHasKey( 'version_now', $conflict, 'each conflict must report current server version' );
		$this->assertArrayHasKey( 'error', $conflict );
		$this->assertSame( 'not_found', $conflict['error'] );
	}

	public function test_conflict_entry_for_stale_version_omits_error_field(): void {
		// When the conflict is purely a version mismatch (post exists, user has cap,
		// just stale), the conflict entry has { id, version_now } — no `error` key.
		// This contract matters because the React inbox uses absence-of-error to
		// distinguish "retry with current version" from "stop, this won't work".
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 7 ),
				'return' => (object) array(
					'ID'        => 7,
					'post_type' => 'helpdesk_ticket',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 7, '_swh_version', true ),
				'return' => '10',
			)
		);

		$req = new WP_REST_Request( 'POST', '/swh/v1/bulk-action' );
		$req->set_param( 'action', 'change_status' );
		$req->set_param( 'ticket_ids', array( 7 ) );
		$req->set_param( 'version_map', array( 7 => 9 ) );  // stale
		$req->set_param( 'params', array() );

		$response = ( new BulkController() )->bulk_action( $req );
		$this->assertSame( 200, $response->get_status() );
		$data = (array) $response->get_data();
		$this->assertCount( 1, $data['conflicts'] );
		$conflict = $data['conflicts'][0];
		$this->assertSame( 7, $conflict['id'] );
		$this->assertSame( 10, $conflict['version_now'] );
		$this->assertArrayNotHasKey(
			'error',
			$conflict,
			'pure version conflict entries must NOT carry an `error` key — that distinguishes them from auth/not-found conflicts'
		);
	}
}
