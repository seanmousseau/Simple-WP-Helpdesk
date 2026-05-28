<?php
declare(strict_types=1);

namespace SWH\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WP_Mock;

/**
 * Baseline regression coverage for {@see swh_update_ticket_fields()} — the
 * context-neutral mutation helper extracted from `swh_save_ticket_data()` in
 * v4.1.0 Phase 3.
 *
 * The helper is the single canonical mutation choke point. These tests lock
 * the observable behaviour the legacy admin save path used to produce inline:
 *
 *  - Status mutations route through `swh_set_ticket_status()` (which writes
 *    `_ticket_status`, bumps `_swh_version`, and fires the lifecycle hooks).
 *  - Priority writes touch `_ticket_priority`.
 *  - Assignee writes touch `_ticket_assigned_to` and fire `swh_ticket_assigned`.
 *  - Invalid values are rejected without writes (whitelist match against
 *    `swh_get_statuses()` / `swh_get_priorities()` / role list).
 *  - Resolved-timestamp bookkeeping mirrors the legacy logic.
 *
 * Strategy: load the real `helpers.php` (so `swh_get_statuses`, the meta
 * accessors, and `swh_set_ticket_status` resolve to real implementations) and
 * stub the lower-level WP API surface (`get_post_meta`, `update_post_meta`,
 * `get_option`, …). This gives a faithful integration of the helper without a
 * full WP test bootstrap, while keeping every observable side effect lockable
 * via `WP_Mock`.
 */
final class UpdateTicketFieldsTest extends TestCase {

	protected function setUp(): void {
		WP_Mock::setUp();

		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
		require_once SWH_PLUGIN_DIR . 'src/Concurrency/TicketVersion.php';
		require_once SWH_PLUGIN_DIR . 'includes/ticket-update-fields.php';

		// Real swh_get_statuses/swh_get_priorities ultimately call get_option.
		WP_Mock::userFunction( 'get_option', array(
			'return' => function ( string $key, $default = false ) {
				switch ( $key ) {
					case 'swh_ticket_statuses':
						return 'Open, In Progress, Resolved, Closed';
					case 'swh_ticket_priorities':
						return 'Low, Medium, High, Urgent';
					case 'swh_resolved_status':
						return 'Resolved';
					case 'swh_settings_general':
						return array(
							'ticket_statuses'   => 'Open, In Progress, Resolved, Closed',
							'ticket_priorities' => 'Low, Medium, High, Urgent',
							'resolved_status'   => 'Resolved',
							'closed_status'     => 'Closed',
						);
					default:
						return $default;
				}
			},
		) );
		WP_Mock::userFunction( 'apply_filters', array( 'return' => function ( $tag, $value ) { return $value; } ) );
		WP_Mock::userFunction( 'apply_filters_deprecated', array( 'return' => function ( $tag, $args ) { return $args[0]; } ) );
		WP_Mock::userFunction( 'has_filter', array( 'return' => false ) );
		WP_Mock::userFunction( '_deprecated_hook', array( 'return' => null ) );
		// TicketVersion::bump() calls get_post_meta(_swh_version) then update_post_meta.
		// Allow the version-read fallback to succeed in every test.
		WP_Mock::userFunction( 'get_post_meta', array(
			'args'   => array( \WP_Mock\Functions::type( 'int' ), '_swh_version', true ),
			'return' => '0',
		) );
	}

	protected function tearDown(): void {
		WP_Mock::tearDown();
	}

	// -----------------------------------------------------------------------
	// Test fixtures
	// -----------------------------------------------------------------------

	/**
	 * Set up the minimum stubs every test needs — a valid helpdesk_ticket
	 * post lookup plus the meta reads the helper depends on. Returns the
	 * mocked post object so individual tests can override fields if needed.
	 */
	private function stub_ticket( int $id = 42, string $old_status = 'Open', int $old_assignee = 0 ): object {
		$post = (object) array(
			'ID'        => $id,
			'post_type' => 'helpdesk_ticket',
		);
		WP_Mock::userFunction( 'get_post', array( 'args' => array( $id ), 'return' => $post ) );
		WP_Mock::userFunction( 'get_post_meta', array(
			'args'   => array( $id, '_ticket_status', true ),
			'return' => $old_status,
		) );
		WP_Mock::userFunction( 'get_post_meta', array(
			'args'   => array( $id, '_ticket_assigned_to', true ),
			'return' => $old_assignee,
		) );
		// `_swh_last_activity_at` write happens unconditionally on status change.
		WP_Mock::userFunction( 'update_post_meta', array(
			'args' => array( $id, '_swh_last_activity_at', \WP_Mock\Functions::type( 'int' ) ),
		) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args' => array( $id, '_swh_version', \WP_Mock\Functions::type( 'int' ) ),
		) );
		return $post;
	}

	// -----------------------------------------------------------------------
	// Status — routes through swh_set_ticket_status (writes _ticket_status)
	// -----------------------------------------------------------------------

	public function test_status_change_writes_ticket_status_meta(): void {
		$this->stub_ticket( 42, 'Open' );
		// The single load-bearing side effect: _ticket_status is written.
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_status', 'In Progress' ),
			'times' => 1,
		) );
		// Allow resolved-timestamp branch to be a no-op for Open → In Progress.
		WP_Mock::userFunction( 'delete_post_meta', array( 'times' => 0 ) );

		$result = swh_update_ticket_fields( 42, array( 'status' => 'In Progress' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['errors'] );
		$this->assertContains( 'status', $result['applied'] );
	}

	public function test_status_rejected_when_not_in_configured_list(): void {
		$this->stub_ticket( 42, 'Open' );

		$result = swh_update_ticket_fields( 42, array( 'status' => 'Bogus' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'status', $result['errors'] );
		$this->assertNotContains( 'status', $result['applied'] );
	}

	// -----------------------------------------------------------------------
	// Priority
	// -----------------------------------------------------------------------

	public function test_priority_write_when_value_allowed(): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_priority', 'High' ),
			'times' => 1,
		) );

		$result = swh_update_ticket_fields( 42, array( 'priority' => 'High' ) );

		$this->assertTrue( $result['ok'] );
		$this->assertContains( 'priority', $result['applied'] );
	}

	public function test_priority_rejected_when_not_in_configured_list(): void {
		$this->stub_ticket( 42 );

		$result = swh_update_ticket_fields( 42, array( 'priority' => 'Bogus' ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'priority', $result['errors'] );
	}

	/**
	 * #532 — priority matching is case-insensitive; the stored value is the
	 * operator-configured label (canonical casing).
	 *
	 * @dataProvider provide_priority_aliases
	 */
	public function test_priority_case_insensitive_resolves_to_configured_label( string $input ): void {
		$this->stub_ticket( 42 );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_priority', 'Medium' ),
			'times' => 1,
		) );

		$result = swh_update_ticket_fields( 42, array( 'priority' => $input ) );

		$this->assertTrue( $result['ok'], 'priority "' . $input . '" should validate' );
		$this->assertContains( 'priority', $result['applied'] );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public function provide_priority_aliases(): array {
		return array(
			'lowercase' => array( 'medium' ),
			'allcaps'   => array( 'MEDIUM' ),
			'titlecase' => array( 'Medium' ),
		);
	}

	// -----------------------------------------------------------------------
	// Assignee — admin/technician role gate + swh_ticket_assigned action
	// -----------------------------------------------------------------------

	public function test_assignee_write_fires_action_on_change(): void {
		$this->stub_ticket( 42, 'Open', 0 );

		$user = (object) array( 'roles' => array( 'technician' ) );
		WP_Mock::userFunction( 'get_userdata', array( 'args' => array( 7 ), 'return' => $user ) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_assigned_to', 7 ),
			'times' => 1,
		) );
		WP_Mock::expectAction( 'swh_ticket_assigned', 42, 0, 7 );

		$result = swh_update_ticket_fields( 42, array( 'assignee' => 7 ) );

		$this->assertTrue( $result['ok'] );
		$this->assertContains( 'assignee', $result['applied'] );
	}

	public function test_assignee_rejected_when_role_is_not_admin_or_technician(): void {
		$this->stub_ticket( 42 );

		$user = (object) array( 'roles' => array( 'subscriber' ) );
		WP_Mock::userFunction( 'get_userdata', array( 'args' => array( 99 ), 'return' => $user ) );

		$result = swh_update_ticket_fields( 42, array( 'assignee' => 99 ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'assignee', $result['errors'] );
	}

	public function test_assignee_zero_clears_meta(): void {
		$this->stub_ticket( 42, 'Open', 7 );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_ticket_assigned_to', '' ),
			'times' => 1,
		) );
		WP_Mock::expectAction( 'swh_ticket_assigned', 42, 7, 0 );

		$result = swh_update_ticket_fields( 42, array( 'assignee' => 0 ) );

		$this->assertTrue( $result['ok'] );
	}

	// -----------------------------------------------------------------------
	// Resolved-timestamp bookkeeping
	// -----------------------------------------------------------------------

	public function test_resolved_timestamp_stamped_when_transitioning_to_resolved(): void {
		$this->stub_ticket( 42, 'Open' );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args' => array( 42, '_ticket_status', 'Resolved' ),
		) );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args'  => array( 42, '_resolved_timestamp', \WP_Mock\Functions::type( 'int' ) ),
			'times' => 1,
		) );

		$result = swh_update_ticket_fields( 42, array( 'status' => 'Resolved' ) );

		$this->assertTrue( $result['ok'] );
	}

	public function test_resolved_timestamp_cleared_when_leaving_resolved(): void {
		$this->stub_ticket( 42, 'Resolved' );
		WP_Mock::userFunction( 'update_post_meta', array(
			'args' => array( 42, '_ticket_status', 'Open' ),
		) );
		WP_Mock::userFunction( 'delete_post_meta', array(
			'args'  => array( 42, '_resolved_timestamp' ),
			'times' => 1,
		) );

		$result = swh_update_ticket_fields( 42, array( 'status' => 'Open' ) );

		$this->assertTrue( $result['ok'] );
	}

	// -----------------------------------------------------------------------
	// Guards
	// -----------------------------------------------------------------------

	public function test_returns_error_for_zero_id(): void {
		$result = swh_update_ticket_fields( 0, array( 'status' => 'Open' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'ticket_id', $result['errors'] );
	}

	public function test_returns_error_when_post_is_not_a_helpdesk_ticket(): void {
		WP_Mock::userFunction( 'get_post', array(
			'args'   => array( 42 ),
			'return' => (object) array( 'ID' => 42, 'post_type' => 'post' ),
		) );
		$result = swh_update_ticket_fields( 42, array( 'status' => 'Open' ) );
		$this->assertFalse( $result['ok'] );
		$this->assertArrayHasKey( 'ticket_id', $result['errors'] );
	}

	public function test_empty_patch_is_a_noop_success(): void {
		$this->stub_ticket( 42 );
		$result = swh_update_ticket_fields( 42, array() );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( array(), $result['applied'] );
	}
}
