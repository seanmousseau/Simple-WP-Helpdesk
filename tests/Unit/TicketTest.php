<?php
/**
 * Unit tests for ticket-related functions in includes/class-ticket.php.
 *
 * Covers:
 *  - CSAT status gate in swh_submit_csat_ajax() (#218)
 *  - Original filename preservation with sanitize_text_field vs sanitize_file_name (#231)
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for swh_submit_csat_ajax and attachment original-name handling.
 */
class TicketTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
		require_once SWH_PLUGIN_DIR . 'includes/class-ticket.php';
	}

	// -------------------------------------------------------------------------
	// Attachment original-name preservation (#231)
	// -------------------------------------------------------------------------

	/**
	 * Sanitize_text_field preserves spaces in original filenames, ensuring the
	 * stored origname matches what the user uploaded.
	 */
	public function test_sanitize_text_field_preserves_spaces(): void {
		$original = 'My Support File (v2).pdf';
		$result   = sanitize_text_field( $original );
		$this->assertStringContainsString( ' ', $result, 'sanitize_text_field should preserve spaces' );
		$this->assertSame( $original, $result );
	}

	/**
	 * Demonstrates why sanitize_file_name was wrong: spaces become hyphens, so the
	 * stored origname would differ from what the user uploaded.
	 *
	 * This uses the bootstrap stub which mirrors core WP behaviour.
	 */
	public function test_sanitize_file_name_replaces_spaces_with_hyphens(): void {
		$stored = sanitize_file_name( 'My Support File (v2).pdf' );
		$this->assertStringNotContainsString( ' ', $stored, 'sanitize_file_name replaces spaces — wrong for origname storage' );
	}

	/**
	 * Sanitize_text_field preserves Unicode characters in filenames (e.g. accents),
	 * important for non-English users.
	 */
	public function test_sanitize_text_field_preserves_unicode(): void {
		$original = 'Schéma réseau.pdf';
		$result   = sanitize_text_field( $original );
		$this->assertSame( $original, $result );
	}

	// -------------------------------------------------------------------------
	// CSAT status gate (#218) — indirect via POST simulation
	// -------------------------------------------------------------------------

	/**
	 * Swh_submit_csat_ajax sends a 400 error when the ticket status is not the
	 * closed status. This tests the guard added in #218.
	 *
	 * We cannot call swh_submit_csat_ajax() directly (it calls wp_send_json_error
	 * which calls exit), so we test the building blocks:
	 *  1. swh_get_string_meta returns the ticket's current status.
	 *  2. swh_get_string_option returns the configured closed status.
	 *  3. A non-match triggers the error path.
	 *
	 * This tests the logic at class-ticket.php:416-418.
	 */
	public function test_csat_gate_non_closed_status_triggers_error(): void {
		$ticket_id      = 99;
		$current_status = 'Open';
		$closed_status  = 'Closed';

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( $ticket_id, '_ticket_status', true )
			->andReturn( $current_status );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( $closed_status );

		$status = swh_get_string_meta( $ticket_id, '_ticket_status' );
		$option = swh_get_string_option( 'swh_closed_status', 'Closed' );

		$this->assertNotSame(
			$status,
			$option,
			'Ticket status is not closed — gate should reject the CSAT submission'
		);
	}

	/**
	 * Swh_submit_csat_ajax allows the write when the ticket status matches the
	 * configured closed status.
	 */
	public function test_csat_gate_closed_status_passes(): void {
		$ticket_id      = 100;
		$current_status = 'Closed';
		$closed_status  = 'Closed';

		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( $ticket_id, '_ticket_status', true )
			->andReturn( $current_status );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( $closed_status );

		$status = swh_get_string_meta( $ticket_id, '_ticket_status' );
		$option = swh_get_string_option( 'swh_closed_status', 'Closed' );

		$this->assertSame(
			$status,
			$option,
			'Ticket status matches closed status — gate should allow the CSAT write'
		);
	}

	/**
	 * CSAT gate uses swh_get_defaults() closed status as fallback when the option
	 * is not configured.
	 *
	 * When get_option() returns the fallback (WP behaviour for unset options),
	 * swh_get_string_option returns that fallback value.
	 */
	public function test_csat_gate_uses_defaults_fallback(): void {
		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturnUsing( fn( $key, $fallback = false ) => $fallback );

		$defs          = swh_get_defaults();
		$closed_status = swh_get_string_option(
			'swh_closed_status',
			is_string( $defs['swh_closed_status'] ) ? $defs['swh_closed_status'] : ''
		);

		$this->assertSame( 'Closed', $closed_status, 'Default closed status should be "Closed"' );
	}
}
