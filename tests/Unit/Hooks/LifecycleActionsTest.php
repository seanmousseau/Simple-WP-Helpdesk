<?php
/**
 * Unit tests for v3.7.0 ticket lifecycle action hooks (issue #361).
 *
 * Covers the lifecycle actions dispatched through helpers in
 * includes/helpers.php so they can be exercised without spinning up the
 * surrounding admin/portal/cron environments:
 *   - swh_ticket_status_changed / swh_ticket_closed / swh_ticket_reopened
 *     (via swh_set_ticket_status())
 *   - swh_ticket_replied (via swh_fire_ticket_replied())
 *   - swh_ticket_assigned (via swh_apply_assignment_rules())
 *
 * Not covered here (deferred to E2E):
 *   - swh_sla_breached    — fires inside a cron loop; covered by the
 *     Playwright SLA section.
 *   - swh_csat_submitted  — fires from a direct do_action() inside the
 *     CSAT AJAX handler; covered by the Playwright CSAT section.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests that every lifecycle action fires under the documented condition
 * and stays silent under no-op conditions.
 */
class LifecycleActionsTest extends TestCase {

	/**
	 * Load plugin helpers before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	// -------------------------------------------------------------------------
	// swh_ticket_status_changed / swh_ticket_closed / swh_ticket_reopened
	// -------------------------------------------------------------------------

	/** Status change from Open → Closed fires status_changed and closed. */
	public function test_status_change_to_closed_fires_changed_and_closed(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_status', true )
			->andReturn( 'Open' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_status', 'Closed' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_closed_status', 'Closed' )
			->andReturn( 'Closed' );

		WP_Mock::expectAction( 'swh_ticket_status_changed', 42, 'Open', 'Closed' );
		WP_Mock::expectAction( 'swh_ticket_closed', 42, 'Open' );

		swh_set_ticket_status( 42, 'Closed' );

		$this->assertConditionsMet();
	}

	/** Status change from Closed → Open fires status_changed and reopened. */
	public function test_status_change_from_closed_fires_reopened(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_status', true )
			->andReturn( 'Closed' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_status', 'Open' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_closed_status', 'Closed' )
			->andReturn( 'Closed' );

		WP_Mock::expectAction( 'swh_ticket_status_changed', 42, 'Closed', 'Open' );
		WP_Mock::expectAction( 'swh_ticket_reopened', 42, 'Closed' );

		swh_set_ticket_status( 42, 'Open' );

		$this->assertConditionsMet();
	}

	/** Status change between two non-closed statuses fires changed only. */
	public function test_intermediate_status_fires_changed_only(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_status', true )
			->andReturn( 'Open' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_status', 'In Progress' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_closed_status', 'Closed' )
			->andReturn( 'Closed' );

		WP_Mock::expectAction( 'swh_ticket_status_changed', 42, 'Open', 'In Progress' );

		swh_set_ticket_status( 42, 'In Progress' );

		$this->assertConditionsMet();
	}

	/** Status set to existing value does NOT fire any action (no-op). */
	public function test_no_op_status_does_not_fire(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_status', true )
			->andReturn( 'Open' );
		WP_Mock::userFunction( 'update_post_meta' )->never();

		// No expectAction calls — none should fire.

		swh_set_ticket_status( 42, 'Open' );

		$this->assertConditionsMet();
	}

	/** Initial status set (no prior meta) writes meta but does not fire changed. */
	public function test_initial_status_set_does_not_fire_changed(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_status', true )
			->andReturn( '' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_status', 'Open' );

		// No expectAction — first set should be silent.

		swh_set_ticket_status( 42, 'Open' );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_ticket_replied
	// -------------------------------------------------------------------------

	/** Comment by a user with edit_post cap reports is_staff_reply = true. */
	public function test_replied_with_staff_user_fires_with_staff_true(): void {
		WP_Mock::userFunction( 'user_can' )
			->with( 7, 'edit_post', 42 )
			->andReturn( true );

		WP_Mock::expectAction( 'swh_ticket_replied', 42, 99, true );

		swh_fire_ticket_replied( 42, 99, 7 );

		$this->assertConditionsMet();
	}

	/** Comment by an author without edit_post cap reports is_staff_reply = false. */
	public function test_replied_with_non_staff_fires_with_staff_false(): void {
		WP_Mock::userFunction( 'user_can' )
			->with( 7, 'edit_post', 42 )
			->andReturn( false );

		WP_Mock::expectAction( 'swh_ticket_replied', 42, 99, false );

		swh_fire_ticket_replied( 42, 99, 7 );

		$this->assertConditionsMet();
	}

	/** System-generated comment (author_id = 0) reports is_staff_reply = false. */
	public function test_replied_with_system_author_fires_with_staff_false(): void {
		WP_Mock::expectAction( 'swh_ticket_replied', 42, 99, false );

		swh_fire_ticket_replied( 42, 99, 0 );

		$this->assertConditionsMet();
	}

	/** Falsy comment_id short-circuits — no action fires. */
	public function test_replied_with_falsy_comment_id_does_not_fire(): void {
		// No expectAction — nothing should fire when wp_insert_comment returned 0/false.

		swh_fire_ticket_replied( 42, 0, 7 );

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_ticket_assigned (assignment-rules path)
	// -------------------------------------------------------------------------

	/** Assignment rules dispatching to a new user fires swh_ticket_assigned. */
	public function test_assignment_rules_fire_assigned_on_change(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_assignment_rules', array() )
			->andReturn( array( array( 'category_term_id' => 5, 'assignee_user_id' => 11 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'helpdesk_category', array( 'fields' => 'ids' ) )
			->andReturn( array( 5 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_assigned_to', true )
			->andReturn( '0' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_assigned_to', 11 );

		WP_Mock::expectAction( 'swh_ticket_assigned', 42, 0, 11 );

		swh_apply_assignment_rules( 42 );

		$this->assertConditionsMet();
	}

	/** Assignment rules picking the same assignee does NOT fire (no-op). */
	public function test_assignment_rules_no_op_does_not_fire(): void {
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_assignment_rules', array() )
			->andReturn( array( array( 'category_term_id' => 5, 'assignee_user_id' => 11 ) ) );
		WP_Mock::userFunction( 'wp_get_post_terms' )
			->with( 42, 'helpdesk_category', array( 'fields' => 'ids' ) )
			->andReturn( array( 5 ) );
		WP_Mock::userFunction( 'get_post_meta' )
			->with( 42, '_ticket_assigned_to', true )
			->andReturn( '11' );
		WP_Mock::userFunction( 'update_post_meta' )
			->once()
			->with( 42, '_ticket_assigned_to', 11 );

		// No expectAction — assignee unchanged.

		swh_apply_assignment_rules( 42 );

		$this->assertConditionsMet();
	}
}
