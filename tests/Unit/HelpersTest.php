<?php
/**
 * Unit tests for typed wrapper helpers in includes/helpers.php.
 *
 * @package Simple_WP_Helpdesk
 */

use WP_Mock\Tools\TestCase;

/**
 * Tests for swh_get_string_meta, swh_get_int_meta, swh_get_string_option,
 * swh_get_int_option, swh_get_string_comment_meta, and swh_get_defaults.
 */
class HelpersTest extends TestCase {

	/**
	 * Load plugin helpers before each test.
	 */
	public function setUp(): void {
		parent::setUp();
		require_once SWH_PLUGIN_DIR . 'includes/helpers.php';
	}

	// -------------------------------------------------------------------------
	// swh_get_string_meta
	// -------------------------------------------------------------------------

	/** Returns a string when meta contains a scalar. */
	public function test_get_string_meta_returns_string(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->with( 42, '_ticket_status', true )
			->andReturn( 'Open' );

		$this->assertSame( 'Open', swh_get_string_meta( 42, '_ticket_status' ) );
	}

	/** Returns empty string when meta is false (missing key). */
	public function test_get_string_meta_false_returns_empty(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( false );

		$this->assertSame( '', swh_get_string_meta( 1, '_nonexistent' ) );
	}

	/** Returns empty string when meta is an array (non-scalar). */
	public function test_get_string_meta_array_returns_empty(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( array( 'a', 'b' ) );

		$this->assertSame( '', swh_get_string_meta( 1, '_some_key' ) );
	}

	/** Coerces integer meta to string. */
	public function test_get_string_meta_int_coerced(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( 99 );

		$this->assertSame( '99', swh_get_string_meta( 1, '_some_int' ) );
	}

	// -------------------------------------------------------------------------
	// swh_get_int_meta
	// -------------------------------------------------------------------------

	/** Returns an integer for numeric string meta. */
	public function test_get_int_meta_numeric_string(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( '7' );

		$this->assertSame( 7, swh_get_int_meta( 1, '_some_key' ) );
	}

	/** Returns 0 for empty string meta. */
	public function test_get_int_meta_empty_string_returns_zero(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( '' );

		$this->assertSame( 0, swh_get_int_meta( 1, '_empty' ) );
	}

	/** Returns 0 when meta is false. */
	public function test_get_int_meta_false_returns_zero(): void {
		WP_Mock::userFunction( 'get_post_meta' )
			->once()
			->andReturn( false );

		$this->assertSame( 0, swh_get_int_meta( 1, '_missing' ) );
	}

	// -------------------------------------------------------------------------
	// swh_get_string_option
	// -------------------------------------------------------------------------

	/** Returns string option value. */
	public function test_get_string_option_returns_value(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->with( 'swh_default_status', '' )
			->andReturn( 'Open' );

		$this->assertSame( 'Open', swh_get_string_option( 'swh_default_status' ) );
	}

	/**
	 * Returns fallback when WP returns the fallback (option not set).
	 *
	 * In real WordPress, get_option($key, $fallback) returns $fallback when the
	 * option doesn't exist, not false. We simulate that here.
	 */
	public function test_get_string_option_fallback_when_option_missing(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->andReturnUsing( fn( $key, $fallback = false ) => $fallback );

		$this->assertSame( 'fallback', swh_get_string_option( 'nonexistent', 'fallback' ) );
	}

	/**
	 * Returns fallback when option is stored as a non-scalar (e.g. serialized array).
	 *
	 * If a WP option unexpectedly holds an array, swh_get_string_option should return
	 * the fallback rather than casting the array to string.
	 */
	public function test_get_string_option_fallback_on_array(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->andReturn( array( 'unexpected', 'array' ) );

		$this->assertSame( 'default', swh_get_string_option( 'bad_opt', 'default' ) );
	}

	// -------------------------------------------------------------------------
	// swh_get_int_option
	// -------------------------------------------------------------------------

	/** Returns integer option value. */
	public function test_get_int_option_returns_value(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->andReturn( '5' );

		$this->assertSame( 5, swh_get_int_option( 'swh_max_upload_count' ) );
	}

	/** Returns fallback when option is not set (WP returns the fallback argument). */
	public function test_get_int_option_fallback(): void {
		WP_Mock::userFunction( 'get_option' )
			->once()
			->andReturnUsing( fn( $key, $fallback = 0 ) => $fallback );

		$this->assertSame( 3, swh_get_int_option( 'missing_opt', 3 ) );
	}

	// -------------------------------------------------------------------------
	// swh_get_string_comment_meta
	// -------------------------------------------------------------------------

	/** Returns string comment meta. */
	public function test_get_string_comment_meta_returns_value(): void {
		WP_Mock::userFunction( 'get_comment_meta' )
			->once()
			->with( 10, '_is_internal_note', true )
			->andReturn( '1' );

		$this->assertSame( '1', swh_get_string_comment_meta( 10, '_is_internal_note' ) );
	}

	/** Returns empty string when comment meta is false. */
	public function test_get_string_comment_meta_false_returns_empty(): void {
		WP_Mock::userFunction( 'get_comment_meta' )
			->once()
			->andReturn( false );

		$this->assertSame( '', swh_get_string_comment_meta( 10, '_missing' ) );
	}

	// -------------------------------------------------------------------------
	// swh_get_defaults
	// -------------------------------------------------------------------------

	/** Returns an array. */
	public function test_get_defaults_returns_array(): void {
		$defaults = swh_get_defaults();
		$this->assertIsArray( $defaults );
	}

	/** Contains expected keys. */
	public function test_get_defaults_has_required_keys(): void {
		$defaults = swh_get_defaults();
		$required = array(
			'swh_default_status',
			'swh_default_priority',
			'swh_closed_status',
			'swh_resolved_status',
			'swh_spam_method',
			'swh_recaptcha_type',
			'swh_recaptcha_project_id',
			'swh_recaptcha_api_key',
			'swh_recaptcha_threshold',
		);
		foreach ( $required as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "Missing default key: {$key}" );
		}
	}

	/** ReCAPTCHA Enterprise defaults are correct. */
	public function test_get_defaults_recaptcha_enterprise_values(): void {
		$defaults = swh_get_defaults();
		$this->assertSame( 'v2', $defaults['swh_recaptcha_type'] );
		$this->assertSame( '', $defaults['swh_recaptcha_project_id'] );
		$this->assertSame( '', $defaults['swh_recaptcha_api_key'] );
		$this->assertSame( '0.5', $defaults['swh_recaptcha_threshold'] );
	}

	/** Consecutive calls return structurally equal arrays with the same keys. */
	public function test_get_defaults_returns_same_instance(): void {
		$first  = swh_get_defaults();
		$second = swh_get_defaults();
		$this->assertSame( $first, $second );
		$this->assertArrayHasKey( 'swh_closed_status', $first );
	}
}
