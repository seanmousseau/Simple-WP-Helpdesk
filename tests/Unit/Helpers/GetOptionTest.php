<?php
/**
 * Unit tests for swh_get_option() in includes/helpers.php.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for the v3.7.0 swh_get_option() advisory-group read helper.
 */
class GetOptionTest extends TestCase {

	/**
	 * Load plugin helpers before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	/** Returns the option value when present. */
	public function test_returns_option_value_when_present(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_foo', null )
			->andReturn( 'bar' );

		$this->assertSame( 'bar', swh_get_option( 'general', 'foo' ) );
	}

	/** Returns the default when the option is absent. */
	public function test_returns_default_when_option_absent(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_foo', 'baz' )
			->andReturn( 'baz' );

		$this->assertSame( 'baz', swh_get_option( 'general', 'foo', 'baz' ) );
	}

	/** The $group argument is advisory in v3.7 and does not affect the read. */
	public function test_group_argument_is_ignored(): void {
		WP_Mock::userFunction( 'get_option' )
			->twice()
			->with( 'swh_foo', null )
			->andReturn( 'shared' );

		$this->assertSame( 'shared', swh_get_option( 'routing', 'foo' ) );
		$this->assertSame( 'shared', swh_get_option( 'general', 'foo' ) );
	}
}
