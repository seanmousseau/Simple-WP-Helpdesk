<?php
/**
 * Unit tests for swh_get_option() grouped-storage body (v4.0.0 #356).
 *
 * These tests describe the NEW behaviour after the body is rewritten to read
 * from `swh_settings_<group>` arrays.  They FAIL against v3.7's flat-read body
 * and PASS after Task 3.3 rewrites the helper.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for the v4.0.0 swh_get_option() grouped-storage read helper.
 */
class SwhGetOptionTest extends TestCase {

	/**
	 * Load plugin helpers before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	/** Reads the correct key from the grouped option bag when the bag exists. */
	public function test_reads_grouped_storage_when_present(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_general', [] )
			->andReturn( [ 'site_name' => 'Acme Support' ] );

		$this->assertSame( 'Acme Support', swh_get_option( 'general', 'site_name', 'fallback' ) );
	}

	/** Falls back to $default when the group bag exists but the key is absent. */
	public function test_falls_back_to_default_when_key_absent(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_general', [] )
			->andReturn( [] );

		$this->assertSame( 'fallback', swh_get_option( 'general', 'site_name', 'fallback' ) );
	}

	/** Falls back to $default when the group bag does not exist yet (option not written). */
	public function test_falls_back_to_default_when_group_empty(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_email', [] )
			->andReturn( [] );

		$this->assertSame( 'html', swh_get_option( 'email', 'email_format', 'html' ) );
	}

	/** Returns null when no $default supplied and the key is absent. */
	public function test_returns_null_when_no_default_and_no_value(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_email', [] )
			->andReturn( [] );

		$this->assertNull( swh_get_option( 'email', 'logo_url' ) );
	}

	/** Different groups read from their own separate option bags. */
	public function test_different_groups_read_separate_bags(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_tools', [] )
			->andReturn( [ 'spam_method' => 'recaptcha' ] );

		$this->assertSame( 'recaptcha', swh_get_option( 'tools', 'spam_method', 'honeypot' ) );
	}

	/** A stored falsy value (empty string) is returned, not the default. */
	public function test_returns_stored_empty_string_not_default(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_routing', [] )
			->andReturn( [ 'default_assignee' => '' ] );

		$this->assertSame( '', swh_get_option( 'routing', 'default_assignee', 'nobody' ) );
	}
}
