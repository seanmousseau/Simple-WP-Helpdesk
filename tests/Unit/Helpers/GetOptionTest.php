<?php
/**
 * Unit tests for swh_get_option() in includes/helpers.php.
 *
 * Updated for v4.0.0 (#356): helper body now reads from `swh_settings_<group>`
 * array options instead of flat `swh_<key>` options.  The signature is unchanged.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for the v4.0.0 swh_get_option() grouped-storage read helper.
 */
class GetOptionTest extends TestCase {

	/**
	 * Load plugin helpers before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	/** Returns the value when the key exists in the grouped bag. */
	public function test_returns_option_value_when_present(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_general', [] )
			->andReturn( [ 'foo' => 'bar' ] );

		$this->assertSame( 'bar', swh_get_option( 'general', 'foo' ) );
	}

	/** Returns the $default when the key is absent from the bag. */
	public function test_returns_default_when_key_absent(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_general', [] )
			->andReturn( [] );

		$this->assertSame( 'baz', swh_get_option( 'general', 'foo', 'baz' ) );
	}

	/** Different groups read their own separate `swh_settings_<group>` bags. */
	public function test_different_groups_read_separate_bags(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_routing', [] )
			->andReturn( [ 'foo' => 'from-routing' ] );

		$this->assertSame( 'from-routing', swh_get_option( 'routing', 'foo' ) );
	}

	/** Returns null when no $default is given and the key is absent. */
	public function test_returns_null_when_no_default_and_key_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_settings_general', [] )
			->andReturn( [] );

		$this->assertNull( swh_get_option( 'general', 'missing_key' ) );
	}
}
