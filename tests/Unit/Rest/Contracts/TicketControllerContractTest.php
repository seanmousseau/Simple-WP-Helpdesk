<?php
/**
 * REST envelope contract tests for SWH\Rest\TicketController.
 *
 * Locks the exact shape of every documented response. Catches regressions like
 * v4.0.0's 409 envelope (where the data key changed shape without notifying
 * the React inbox, which then crashed on stale-version PATCH).
 *
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit\Rest\Contracts;

use SWH\Rest\TicketController;
use WP_Mock;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

final class TicketControllerContractTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_get_item_404_envelope_for_missing_ticket(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 999 ),
				'return' => null,
			)
		);

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/999' );
		$req->set_param( 'id', 999 );

		$response = ( new TicketController() )->get_item( $req );
		$this->assertSame( 404, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'not_found' );
		$this->assertSame( '', $problems, "404 envelope shape: $problems" );
	}

	public function test_get_item_404_envelope_for_wrong_post_type(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 7 ),
				'return' => (object) array(
					'ID'        => 7,
					'post_type' => 'post',  // wrong type — not a ticket.
				),
			)
		);

		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/7' );
		$req->set_param( 'id', 7 );

		$response = ( new TicketController() )->get_item( $req );
		$this->assertSame( 404, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'not_found' );
		$this->assertSame( '', $problems, "404 envelope (wrong post type): $problems" );
	}

	public function test_update_item_409_envelope_shape(): void {
		// This is the v4.0.0 regression test. The 409 envelope must include both
		// the `error` key AND a `current` key carrying the full current ticket
		// payload so the React inbox can reconcile state without a refetch.
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'            => 42,
					'post_type'     => 'helpdesk_ticket',
					'post_title'    => 'Subject',
					'post_content'  => 'Body',
					'post_date_gmt' => '2026-05-22 10:00:00',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_swh_version', true ),
				'return' => '5',
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array() ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 4 );  // stale
		$req->set_param( 'patch', array( 'status' => 'resolved' ) );

		$response = ( new TicketController() )->update_item( $req );
		$this->assertSame( 409, $response->get_status() );

		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_error( $data, 'version_conflict', array( 'current' ) );
		$this->assertSame( '', $problems, "409 envelope shape: $problems" );

		// `current` is the full ticket payload — must have a `version` field
		// (so the client knows what to retry with) and an `id`.
		$this->assertIsArray( $data['current'], '409 envelope: `current` must be an array' );
		$this->assertArrayHasKey( 'id', $data['current'], '409 envelope: current.id missing' );
		$this->assertArrayHasKey( 'version', $data['current'], '409 envelope: current.version missing' );
		$this->assertSame( 42, $data['current']['id'] );
		$this->assertSame( 5, $data['current']['version'], 'current.version must reflect server state, not client request' );
	}

	public function test_update_item_404_envelope_for_missing_ticket(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 0 ),
				'return' => null,
			)
		);

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/0' );
		$req->set_param( 'id', 0 );
		$req->set_param( 'expected_version', 1 );
		$req->set_param( 'patch', array( 'status' => 'closed' ) );

		$response = ( new TicketController() )->update_item( $req );
		$this->assertSame( 404, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'not_found' );
		$this->assertSame( '', $problems, "PATCH 404 envelope shape: $problems" );
	}

	public function test_create_reply_409_envelope_shape(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'            => 42,
					'post_type'     => 'helpdesk_ticket',
					'post_title'    => '',
					'post_content'  => '',
					'post_date_gmt' => '',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_swh_version', true ),
				'return' => '5',
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array() ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/42/reply' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 3 );
		$req->set_param( 'body', 'Thanks!' );

		$response = ( new TicketController() )->create_reply( $req );
		$this->assertSame( 409, $response->get_status() );
		$data = (array) $response->get_data();
		$problems = EnvelopeShape::describe_error( $data, 'version_conflict', array( 'current' ) );
		$this->assertSame( '', $problems, "POST /reply 409 envelope: $problems" );
		$this->assertSame( 5, $data['current']['version'] );
	}

	/**
	 * v4.0.2 (#434, #435) — PATCH with `assignee` writes the canonical
	 * `_ticket_assigned_to` meta key AND fires the swh_ticket_assigned action
	 * so the assignee-notification email path triggers. Regression-pins the
	 * v4.0.0 bug where the controller wrote `_ticket_assigned` (no `_to`) and
	 * skipped the lifecycle hook entirely.
	 */
	public function test_update_item_assignee_writes_canonical_meta_and_fires_action(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'            => 42,
					'post_type'     => 'helpdesk_ticket',
					'post_title'    => 'Subject',
					'post_content'  => 'Body',
					'post_date_gmt' => '2026-05-22 10:00:00',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_swh_version', true ),
				'return' => '5',
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array() ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		// Old assignee = 0 (unassigned), new = 11. swh_get_int_meta reads the
		// canonical key — must NOT be _ticket_assigned.
		WP_Mock::userFunction(
			'swh_get_int_meta',
			array(
				'args'   => array( 42, '_ticket_assigned_to' ),
				'return' => 0,
			)
		);
		// Canonical write must happen.
		WP_Mock::userFunction(
			'update_post_meta',
			array(
				'args'  => array( 42, '_ticket_assigned_to', 11 ),
				'times' => 1,
			)
		);
		// Version-bump side effect — accept any new value.
		WP_Mock::userFunction(
			'update_post_meta',
			array(
				'args' => array( 42, '_swh_version', \WP_Mock\Functions::type( 'int' ) ),
			)
		);

		// Lifecycle action must fire with (id, old, new).
		WP_Mock::expectAction( 'swh_ticket_assigned', 42, 0, 11 );

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 5 );  // matches current
		$req->set_param( 'patch', array( 'assignee' => 11 ) );

		( new TicketController() )->update_item( $req );

		WP_Mock::assertActionsCalled();
	}

	/**
	 * v4.0.2 (#436) — PATCH with `{ mark_read: true }` clears the _swh_unread
	 * post meta. Used by the drawer ~1.5s after open to auto-clear the unread
	 * indicator. Regression-pins the no-bump semantics (clearing read-state
	 * is not a content change other clients reconcile against).
	 */
	public function test_update_item_mark_read_deletes_unread_meta(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'            => 42,
					'post_type'     => 'helpdesk_ticket',
					'post_title'    => 'Subject',
					'post_content'  => 'Body',
					'post_date_gmt' => '2026-05-22 10:00:00',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_swh_version', true ),
				'return' => '7',
			)
		);
		WP_Mock::userFunction( 'get_post_meta', array( 'return' => '' ) );
		WP_Mock::userFunction( 'get_comments', array( 'return' => array() ) );
		WP_Mock::userFunction( 'wp_get_post_terms', array( 'return' => array() ) );

		// The load-bearing assertion: delete_post_meta MUST be called with the
		// _swh_unread key when mark_read is in the patch.
		WP_Mock::userFunction(
			'delete_post_meta',
			array(
				'args'  => array( 42, '_swh_unread' ),
				'times' => 1,
			)
		);

		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$req->set_param( 'expected_version', 7 );
		$req->set_param( 'patch', array( 'mark_read' => true ) );

		( new TicketController() )->update_item( $req );
	}

	public function test_create_reply_404_envelope(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 999 ),
				'return' => null,
			)
		);

		$req = new WP_REST_Request( 'POST', '/swh/v1/tickets/999/reply' );
		$req->set_param( 'id', 999 );
		$req->set_param( 'expected_version', 1 );
		$req->set_param( 'body', 'x' );

		$response = ( new TicketController() )->create_reply( $req );
		$this->assertSame( 404, $response->get_status() );
		$problems = EnvelopeShape::describe_error( (array) $response->get_data(), 'not_found' );
		$this->assertSame( '', $problems, "POST /reply 404 envelope: $problems" );
	}
}
