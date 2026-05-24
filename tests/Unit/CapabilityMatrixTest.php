<?php
/**
 * Capability matrix tests — covers gap #8 of the test coverage matrix.
 *
 * Three gates protect ticket data from unauthorized access:
 *
 *   1. TicketController::permissions_check — global `edit_posts` + per-ticket
 *      `edit_post` gate on the REST drawer endpoints. Pinning this guards
 *      against a technician with edit_posts but without edit_post on a
 *      specific ticket (restricted-to-assigned mode) reading another
 *      technician's ticket via REST.
 *
 *   2. swh_restrict_ticket_edit — admin-screen gate hooked to load-post.php.
 *      Calls wp_die(403) when a technician opens a ticket they aren't
 *      assigned to, IF `swh_restrict_to_assigned` is 'yes'.
 *
 *   3. The list-screen meta_query injection (also in class-ticket-list.php)
 *      — filters the admin list to only assigned tickets. This module is
 *      tested indirectly by gates #1 and #2; the meta_query itself is
 *      better covered by Playwright (gap #8 in matrix).
 *
 * Skipping the role/cap matrix in production cost us a v3.x technician
 * privilege escalation (#218); these tests pin the guarantees.
 *
 * @see docs/internal/test-coverage-matrix.md — gap #8
 * @see docs/internal/security-model.md — capability matrix
 * @package Simple_WP_Helpdesk
 */

declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SWH\Rest\TicketController;
use WP_Mock;
use WP_REST_Request;

/**
 * Marker exception used by stubbed wp_die so tests can observe the 403
 * branch of swh_restrict_ticket_edit() without exiting the process.
 */
final class CapStopException extends \RuntimeException {
	public int $status;
	public function __construct( int $status, string $msg ) {
		parent::__construct( $msg );
		$this->status = $status;
	}
}

final class CapabilityMatrixTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();

		if ( ! function_exists( 'absint' ) ) {
			require_once __DIR__ . '/_csat_absint_stub.php';
		}
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'admin/class-ticket-list.php';

		WP_Mock::userFunction( '__', array( 'return_arg' => 0 ) );
		WP_Mock::userFunction( 'esc_html__', array( 'return_arg' => 0 ) );

		// wp_die throws so the 403 branch is observable.
		WP_Mock::userFunction(
			'wp_die',
			array(
				'return' => function ( $msg = '', $title = '', $args = array() ) {
					$status = is_array( $args ) && isset( $args['response'] ) ? (int) $args['response'] : (
						is_int( $title ) ? $title : 0
					);
					throw new CapStopException( $status, (string) $msg );
				},
			)
		);
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
		$_GET = array();
	}

	/**
	 * Stubs the underlying get_option call that swh_get_option reads.
	 *
	 * swh_get_option(group, key) reads `swh_settings_<group>` and indexes by key.
	 * To control 'routing/restrict_to_assigned' from a test, the option array
	 * must be returned for the matching key.
	 */
	private function stub_routing_restrict( string $value ): void {
		WP_Mock::userFunction(
			'get_option',
			array(
				'return' => function ( $key, $default = false ) use ( $value ) {
					if ( 'swh_settings_routing' === $key ) {
						return array( 'restrict_to_assigned' => $value );
					}
					// Legacy flat key — also honor it so swh_get_string_option fallback works.
					if ( 'swh_restrict_to_assigned' === $key ) {
						return $value;
					}
					return $default;
				},
			)
		);
	}

	// ---------- TicketController::permissions_check ---------- //

	public function test_rest_permissions_rejects_user_without_edit_posts(): void {
		WP_Mock::userFunction(
			'current_user_can',
			array(
				'args'   => array( 'edit_posts' ),
				'return' => false,
			)
		);
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$this->assertFalse(
			( new TicketController() )->permissions_check( $req ),
			'A user without edit_posts must be rejected at the global gate.'
		);
	}

	public function test_rest_permissions_passes_admin_with_full_caps(): void {
		WP_Mock::userFunction( 'current_user_can', array( 'return' => true ) );
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$this->assertTrue(
			( new TicketController() )->permissions_check( $req ),
			'An admin with edit_posts + edit_post must pass.'
		);
	}

	public function test_rest_permissions_rejects_technician_without_edit_post_on_specific_ticket(): void {
		// Restricted-to-assigned mode: technician has edit_posts globally but
		// not edit_post(42) because they're not assigned to ticket 42.
		WP_Mock::userFunction(
			'current_user_can',
			array(
				'return' => function ( $cap, $id = null ) {
					if ( 'edit_posts' === $cap ) {
						return true;
					}
					if ( 'edit_post' === $cap && 42 === $id ) {
						return false;  // not assigned to this specific ticket
					}
					return false;
				},
			)
		);
		$req = new WP_REST_Request( 'PATCH', '/swh/v1/tickets/42' );
		$req->set_param( 'id', 42 );
		$this->assertFalse(
			( new TicketController() )->permissions_check( $req ),
			'A technician without edit_post on the specific ticket must be rejected — this is '
				. 'the load-bearing per-ticket gate that prevents reading another tech\'s ticket via REST '
				. 'in restricted-to-assigned mode.'
		);
	}

	public function test_rest_permissions_skips_per_ticket_check_when_id_absent(): void {
		// List endpoints have no `id` in the request. The per-ticket gate
		// must not fire — otherwise the inbox would be unreachable.
		WP_Mock::userFunction(
			'current_user_can',
			array(
				'args'   => array( 'edit_posts' ),
				'return' => true,
			)
		);
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets' );
		// No id param.
		$this->assertTrue(
			( new TicketController() )->permissions_check( $req ),
			'Endpoints without an id param must only run the global gate.'
		);
	}

	public function test_rest_permissions_skips_per_ticket_check_for_zero_id(): void {
		// id=0 is "no specific ticket" — must not trigger the per-ticket gate.
		WP_Mock::userFunction(
			'current_user_can',
			array(
				'args'   => array( 'edit_posts' ),
				'return' => true,
			)
		);
		$req = new WP_REST_Request( 'GET', '/swh/v1/tickets/0' );
		$req->set_param( 'id', 0 );
		$this->assertTrue(
			( new TicketController() )->permissions_check( $req ),
			'id=0 must not trigger the per-ticket gate (zero/negative is "no specific ticket").'
		);
	}

	// ---------- swh_restrict_ticket_edit ---------- //

	public function test_restrict_ticket_edit_no_op_when_setting_disabled(): void {
		$this->stub_routing_restrict( 'no' );
		// Should return without consulting wp_get_current_user — wp_die never fires.
		swh_restrict_ticket_edit();
		$this->assertTrue( true, 'Function must return cleanly when setting is off.' );
	}

	public function test_restrict_ticket_edit_no_op_for_administrator(): void {
		$this->stub_routing_restrict( 'yes' );
		WP_Mock::userFunction(
			'wp_get_current_user',
			array(
				'return' => (object) array(
					'ID'    => 1,
					'roles' => array( 'administrator' ),
				),
			)
		);
		swh_restrict_ticket_edit();
		$this->assertTrue(
			true,
			'Function must return cleanly when user is not a technician — the gate is technician-specific.'
		);
	}

	public function test_restrict_ticket_edit_allows_technician_assigned_to_ticket(): void {
		$this->stub_routing_restrict( 'yes' );
		WP_Mock::userFunction(
			'wp_get_current_user',
			array(
				'return' => (object) array(
					'ID'    => 7,
					'roles' => array( 'technician' ),
				),
			)
		);
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'        => 42,
					'post_type' => 'helpdesk_ticket',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_ticket_assigned_to', true ),
				'return' => '7',  // assigned to current user (meta values are strings)
			)
		);
		$_GET['post'] = '42';
		swh_restrict_ticket_edit();
		$this->assertTrue( true, 'Technician assigned to the ticket must pass.' );
	}

	public function test_restrict_ticket_edit_blocks_technician_not_assigned(): void {
		// The privilege-escalation guard. Technician + restricted-mode + not-assigned
		// must hit wp_die(403). If this regresses, technicians can edit any ticket.
		$this->stub_routing_restrict( 'yes' );
		WP_Mock::userFunction(
			'wp_get_current_user',
			array(
				'return' => (object) array(
					'ID'    => 7,
					'roles' => array( 'technician' ),
				),
			)
		);
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'        => 42,
					'post_type' => 'helpdesk_ticket',
				),
			)
		);
		WP_Mock::userFunction(
			'get_post_meta',
			array(
				'args'   => array( 42, '_ticket_assigned_to', true ),
				'return' => '9',  // assigned to a different user
			)
		);
		$_GET['post'] = '42';

		try {
			swh_restrict_ticket_edit();
			$this->fail( 'Expected wp_die(403) for technician accessing non-assigned ticket.' );
		} catch ( CapStopException $e ) {
			$this->assertSame(
				403,
				$e->status,
				'Must call wp_die with HTTP 403 — not 401, not 404. The page exists; the user is '
					. 'authenticated but not authorized for this resource.'
			);
		}
	}

	public function test_restrict_ticket_edit_ignores_non_helpdesk_posts(): void {
		// load-post.php fires for every CPT. The function must only act on
		// helpdesk_ticket posts so it doesn't accidentally block technicians
		// from editing other content they have caps on.
		$this->stub_routing_restrict( 'yes' );
		WP_Mock::userFunction(
			'wp_get_current_user',
			array(
				'return' => (object) array(
					'ID'    => 7,
					'roles' => array( 'technician' ),
				),
			)
		);
		WP_Mock::userFunction(
			'get_post',
			array(
				'args'   => array( 42 ),
				'return' => (object) array(
					'ID'        => 42,
					'post_type' => 'post',  // not a ticket
				),
			)
		);
		$_GET['post'] = '42';
		swh_restrict_ticket_edit();
		$this->assertTrue( true, 'Non-helpdesk posts must pass through silently.' );
	}
}
