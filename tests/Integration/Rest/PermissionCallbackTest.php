<?php
/**
 * Integration tests for REST permission_callback enforcement.
 *
 * The canonical example of what wp-phpunit unlocks that WP-Mock can't:
 * exercises the REAL WP REST server, dispatches real requests with real
 * auth state, and verifies the plugin's permission_callback actually
 * rejects unauthenticated or under-privileged users.
 *
 * The mocked-WP layer at `tests/Unit/Rest/Contracts/` tests controller
 * methods directly, BYPASSING the permission_callback — so a regression
 * where someone wires up a callback to `__return_true` would silently
 * slip through there. Here it can't.
 *
 * @package Simple_WP_Helpdesk
 */

namespace SWH\Tests\Integration\Rest;

use WP_REST_Request;

if ( ! class_exists( 'WP_UnitTestCase' ) ) {
	// Bootstrap not loaded (running outside the integration runner). Skip
	// silently rather than fataling so other suites can lint this file.
	return;
}

/**
 * @group integration
 * @group rest
 */
class PermissionCallbackTest extends \WP_UnitTestCase {

	public function test_inbox_endpoint_rejects_anonymous_request(): void {
		wp_set_current_user( 0 );  // explicit anonymous
		$request  = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$response = rest_do_request( $request );
		$this->assertEquals(
			401,
			$response->get_status(),
			'Anonymous GET /swh/v1/tickets must be rejected with 401, not silently allowed.'
		);
	}

	public function test_inbox_endpoint_accepts_admin(): void {
		$admin_id = $this->factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		$request  = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$response = rest_do_request( $request );
		$this->assertEquals(
			200,
			$response->get_status(),
			'Authenticated admin must reach the inbox handler.'
		);
	}

	public function test_inbox_endpoint_rejects_subscriber(): void {
		$subscriber_id = $this->factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber_id );

		$request  = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		$response = rest_do_request( $request );
		$this->assertEquals(
			403,
			$response->get_status(),
			'Subscriber (no edit_posts) must be rejected with 403.'
		);
	}

	public function test_ticket_get_endpoint_per_ticket_gate_blocks_unassigned_tech_in_restricted_mode(): void {
		// Load-bearing regression test for the restricted-to-assigned escape
		// hatch. A technician with edit_posts global but without edit_post
		// on a specific ticket must NOT be able to read it via REST. This is
		// the exact path that the mocked Contract tests can't exercise
		// because they bypass permission_callback entirely.
		update_option(
			'swh_settings_routing',
			array( 'restrict_to_assigned' => 'yes' )
		);

		$user_a = $this->factory()->user->create( array( 'role' => 'technician' ) );
		$user_b = $this->factory()->user->create( array( 'role' => 'technician' ) );
		$ticket = $this->factory()->post->create(
			array(
				'post_type'   => 'helpdesk_ticket',
				'post_status' => 'publish',
				'post_title'  => 'Restricted-mode test ticket',
			)
		);
		update_post_meta( $ticket, '_ticket_assigned_to', $user_a );

		// Read as user B (different technician — NOT the assignee).
		wp_set_current_user( $user_b );
		$request = new WP_REST_Request( 'GET', "/swh/v1/tickets/{$ticket}" );
		$request->set_param( 'id', $ticket );
		$response = rest_do_request( $request );

		$this->assertEquals(
			403,
			$response->get_status(),
			'Technician without edit_post on this ticket must be rejected in restricted-to-assigned mode.'
		);

		// Cleanup so other tests don't inherit the setting.
		delete_option( 'swh_settings_routing' );
	}
}
