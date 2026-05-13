<?php
/**
 * Unit tests for v3.7.0 deprecation helpers.
 *
 * Covers swh_apply_deprecated_filter() and swh_do_deprecated_action()
 * from includes/deprecations.php (issue #393).
 *
 * The helpers are thin wrappers around WP core's apply_filters_deprecated()
 * and do_action_deprecated(). WP-Mock does not stub those functions, so this
 * test asserts the wrapper's pass-through behaviour by mocking the WP-core
 * functions directly and checking the version-tag format + replacement
 * message defaulting.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for the deprecation helper wrappers.
 */
class DeprecationHelpersTest extends TestCase {

	/**
	 * Load the deprecations file before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/deprecations.php';
	}

	// -------------------------------------------------------------------------
	// swh_apply_deprecated_filter
	// -------------------------------------------------------------------------

	/** Helper delegates to apply_filters_deprecated() and returns its result. */
	public function test_apply_deprecated_filter_returns_filtered_value(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with( 'swh_legacy_filter', array( 'value', 'ctx' ), 'SWH 3.7', 'swh_new_filter', 'Use swh_new_filter instead.' )
			->andReturn( 'filtered-value' );

		$result = swh_apply_deprecated_filter( 'swh_legacy_filter', array( 'value', 'ctx' ), '3.7', 'swh_new_filter' );

		$this->assertSame( 'filtered-value', $result );
		$this->assertConditionsMet();
	}

	/** Custom $message overrides the default "Use X instead." message. */
	public function test_apply_deprecated_filter_respects_custom_message(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with( 'swh_legacy_filter', array( 'value' ), 'SWH 3.7', 'swh_new_filter', 'Custom guidance for integrators.' )
			->andReturn( 'value' );

		$result = swh_apply_deprecated_filter(
			'swh_legacy_filter',
			array( 'value' ),
			'3.7',
			'swh_new_filter',
			'Custom guidance for integrators.'
		);

		$this->assertSame( 'value', $result );
		$this->assertConditionsMet();
	}

	/** When no replacement is provided, default message references "the documented replacement". */
	public function test_apply_deprecated_filter_default_message_without_replacement(): void {
		WP_Mock::userFunction( 'apply_filters_deprecated' )
			->once()
			->with( 'swh_legacy_filter', array( 'value' ), 'SWH 3.7', '', 'Use the documented replacement instead.' )
			->andReturn( 'value' );

		$result = swh_apply_deprecated_filter( 'swh_legacy_filter', array( 'value' ), '3.7' );

		$this->assertSame( 'value', $result );
		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// swh_do_deprecated_action
	// -------------------------------------------------------------------------

	/** Helper delegates to do_action_deprecated() with the SWH-prefixed version tag. */
	public function test_do_deprecated_action_delegates_to_core(): void {
		WP_Mock::userFunction( 'do_action_deprecated' )
			->once()
			->with( 'swh_legacy_action', array( 42 ), 'SWH 3.7', 'swh_ticket_status_changed', 'Use swh_ticket_status_changed instead.' );

		swh_do_deprecated_action( 'swh_legacy_action', array( 42 ), '3.7', 'swh_ticket_status_changed' );

		$this->assertConditionsMet();
	}

	/** Custom $message overrides the default for actions too. */
	public function test_do_deprecated_action_respects_custom_message(): void {
		WP_Mock::userFunction( 'do_action_deprecated' )
			->once()
			->with( 'swh_legacy_action', array( 42 ), 'SWH 3.7', 'swh_ticket_status_changed', 'Action renamed in v3.7.' );

		swh_do_deprecated_action(
			'swh_legacy_action',
			array( 42 ),
			'3.7',
			'swh_ticket_status_changed',
			'Action renamed in v3.7.'
		);

		$this->assertConditionsMet();
	}
}
