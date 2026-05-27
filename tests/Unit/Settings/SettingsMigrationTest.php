<?php
/**
 * Unit tests for \SWH\Settings\Migration — v4.0.0 schema upgrade routine (#356).
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;
use SWH\Settings\Migration;

/**
 * Tests for the one-shot grouped-options migration.
 */
class SettingsMigrationTest extends TestCase {

	public function setUp(): void {
		parent::setUp();
	}

	// -------------------------------------------------------------------------
	// Skip guard
	// -------------------------------------------------------------------------

	/**
	 * When swh_db_version is already '4.0.0', run() must be a no-op.
	 * No update_option calls should fire at all.
	 */
	public function test_skip_when_already_migrated(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '4.1.0' );

		// If update_option fires, WP_Mock will throw (unexpected call).
		WP_Mock::userFunction( 'update_option' )->never();

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	/**
	 * When swh_db_version is a higher version (e.g. 5.0.0), run() must also skip.
	 */
	public function test_skip_when_higher_version_already_migrated(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '5.0.0' );

		WP_Mock::userFunction( 'update_option' )->never();

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Migration: general group
	// -------------------------------------------------------------------------

	/**
	 * Keys in the 'general' group are collected and written to swh_settings_general.
	 */
	public function test_migrates_general_group_keys(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '0' );

		// Flat keys for the general group.
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_default_status', null )
			->andReturn( 'Open' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_resolved_status', null )
			->andReturn( 'Resolved' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_closed_status', null )
			->andReturn( 'Closed' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_reopened_status', null )
			->andReturn( 'Open' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_ticket_statuses', null )
			->andReturn( 'Open, In Progress, Resolved, Closed' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_default_priority', null )
			->andReturn( 'Medium' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_ticket_priorities', null )
			->andReturn( 'Low, Medium, High' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_autoclose_days', null )
			->andReturn( 3 );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_max_upload_size', null )
			->andReturn( 5 );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_max_upload_count', null )
			->andReturn( 5 );

		// Stub all other flat-key reads (return null → skipped, not written to bag).
		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		// We expect swh_settings_general to be written.
		WP_Mock::userFunction( 'update_option' )
			->with(
				'swh_settings_general',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag )
						&& isset( $bag['default_status'] )
						&& $bag['default_status'] === 'Open'
						&& isset( $bag['closed_status'] )
						&& $bag['closed_status'] === 'Closed';
				} )
			)
			->andReturn( true );

		// db_version write.
		WP_Mock::userFunction( 'update_option' )
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		// Allow any other group bag writes (email, portal, routing, tools, integrations).
		WP_Mock::userFunction( 'update_option' )
			->zeroOrMoreTimes()
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Migration: routing group
	// -------------------------------------------------------------------------

	/**
	 * Keys in the 'routing' group are collected and written to swh_settings_routing.
	 */
	public function test_migrates_routing_group_keys(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '0' );

		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_assignment_rules', null )
			->andReturn( [] );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_default_assignee', null )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_restrict_to_assigned', null )
			->andReturn( 'no' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_fallback_email', null )
			->andReturn( '' );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_ticket_page_id', null )
			->andReturn( 0 );
		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_token_expiration_days', null )
			->andReturn( 90 );

		// Stub remaining reads.
		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		// swh_settings_routing must be written.
		WP_Mock::userFunction( 'update_option' )
			->with(
				'swh_settings_routing',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag )
						&& array_key_exists( 'assignment_rules', $bag )
						&& array_key_exists( 'default_assignee', $bag )
						&& array_key_exists( 'restrict_to_assigned', $bag );
				} )
			)
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->zeroOrMoreTimes()
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Migration: portal group (has flat option swh_portal_theme in defaults)
	// -------------------------------------------------------------------------

	/**
	 * The portal group is seeded because swh_portal_theme exists as a flat option.
	 * Task 3.1 audit: no direct get_option calls, but it IS in swh_get_defaults().
	 */
	public function test_migrates_portal_group_when_flat_option_present(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '0' );

		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_portal_theme', null )
			->andReturn( 'light' );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		WP_Mock::userFunction( 'update_option' )
			->with(
				'swh_settings_portal',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag ) && isset( $bag['portal_theme'] ) && $bag['portal_theme'] === 'light';
				} )
			)
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->zeroOrMoreTimes()
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Migration: integrations group (has flat option swh_inbound_secret in defaults)
	// -------------------------------------------------------------------------

	/**
	 * The integrations group is seeded because swh_inbound_secret exists as a flat option.
	 * Task 3.1 audit: no direct get_option calls, but it IS in swh_get_defaults().
	 */
	public function test_migrates_integrations_group_when_flat_option_present(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '0' );

		WP_Mock::userFunction( 'get_option' )
			->with( 'swh_inbound_secret', null )
			->andReturn( 'abc123' );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		WP_Mock::userFunction( 'update_option' )
			->with(
				'swh_settings_integrations',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag ) && isset( $bag['inbound_secret'] ) && $bag['inbound_secret'] === 'abc123';
				} )
			)
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->zeroOrMoreTimes()
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// Null-value skipping
	// -------------------------------------------------------------------------

	/**
	 * Flat options that do not exist (get_option returns null) are skipped.
	 * No empty bags are written for groups whose flat options are all absent.
	 */
	public function test_skips_null_flat_options(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '0' );

		// All flat keys return null (option not set).
		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		// Bumping to TARGET_VERSION '4.1.0' triggers GROUP_DEFAULTS seeding for
		// integrations.api_keys = [] (v4.1.0 #365), so the integrations bag is
		// written even though no legacy flat keys produced a bag.
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				'swh_settings_integrations',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag )
						&& array_key_exists( 'api_keys', $bag )
						&& array() === $bag['api_keys'];
				} )
			)
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}

	// -------------------------------------------------------------------------
	// db_version bump
	// -------------------------------------------------------------------------

	/**
	 * run() always writes swh_db_version = '4.0.0' on a successful migration,
	 * even when there are no flat options to migrate.
	 */
	public function test_bumps_db_version_after_migration(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_db_version', '0' )
			->andReturn( '3.7.0' );

		WP_Mock::userFunction( 'get_option' )
			->zeroOrMoreTimes()
			->andReturn( null );

		// integrations.api_keys default seeded by GROUP_DEFAULTS (v4.1.0 #365).
		WP_Mock::userFunction( 'update_option' )
			->once()
			->with(
				'swh_settings_integrations',
				\Mockery::on( function ( $bag ) {
					return is_array( $bag ) && array_key_exists( 'api_keys', $bag );
				} )
			)
			->andReturn( true );

		WP_Mock::userFunction( 'update_option' )
			->once()
			->with( 'swh_db_version', '4.1.0' )
			->andReturn( true );

		( new Migration() )->run();

		$this->assertConditionsMet();
	}
}
